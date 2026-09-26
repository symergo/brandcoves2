<?php

declare(strict_types=1);

namespace App\Services\Ideas;

use App\Models\OfflineIdea;
use App\Services\Gift\GiftTags;
use App\Services\Gift\HasEverything;
use App\Services\Gift\TasteBrief;

/**
 * Which approved offline ideas fit a brief: "ideas without a shop" under the
 * Find a gift's and This or that's results.
 *
 * Only approved ideas, only the brief's own market, and only an idea that
 * shares something with the brief: an interest, who it is for, or the
 * occasion. An idea the reviewer tagged with nothing never shows, because
 * there is nothing to say it fits anybody; the review screen refuses to
 * approve one for that reason.
 *
 * What leaves here is the id and the reviewer's wording. Never the key, the
 * count, or anything anybody typed. See docs/features/offline-ideas.md.
 */
class OfflineIdeaPicker
{
    /**
     * An interest is what a brief is mostly about, so it weighs most; who
     * and what for narrow it.
     */
    private const INTEREST = 2;

    private const RECIPIENT = 1;

    private const OCCASION = 1;

    /** Done or used up, for someone who has everything: as strong as an interest. */
    private const USED_UP = 2;

    /**
     * Enough to rank a market's approved ideas in PHP. A reviewer approves
     * them one at a time, so a market will hold dozens, not thousands.
     */
    private const READ_AT_MOST = 500;

    /** @return list<array{id: int, title: string}> */
    public function forBrief(TasteBrief $brief, ?int $limit = null): array
    {
        $limit ??= (int) config('giftcoves.offline_ideas.shown', 3);

        $wanted = [];

        foreach ($brief->interests as $interest) {
            $wanted[GiftTags::interest($interest)] = self::INTEREST;
        }

        if ($brief->relationship !== null && $brief->relationship !== '') {
            $wanted[GiftTags::recipient($brief->relationship)] = self::RECIPIENT;
        }

        if ($brief->occasion !== null && $brief->occasion !== '') {
            $wanted[GiftTags::occasion($brief->occasion)] = self::OCCASION;
        }

        if (($wanted === [] && ! $brief->hasEverything) || $limit < 1) {
            return [];
        }

        // Someone who has everything: an idea that is done or used up (a
        // workshop, a tasting) fits them whatever it is tagged with, and
        // weighs as much as a shared interest. See has-everything.md.
        $usedUp = $brief->hasEverything ? app(HasEverything::class) : null;

        // Avoid entries come as interests ("gaming") from the wizard and as
        // tags ("interest:gaming") from a taste profile.
        $avoid = array_flip(array_map(
            fn (string $a) => str_contains($a, ':') ? $a : GiftTags::interest($a),
            $brief->avoid,
        ));

        $scored = [];

        $ideas = OfflineIdea::query()
            ->approved()
            ->where('market', $brief->market->value)
            ->orderBy('id')
            ->limit(self::READ_AT_MOST)
            ->get(['id', 'title', 'tags', 'price_band']);

        foreach ($ideas as $idea) {
            $tags = (array) $idea->tags;

            if (array_intersect_key(array_flip($tags), $avoid) !== []) {
                continue;
            }

            if ($idea->price_band !== null && ! $idea->price_band->fits($brief->budgetMin, $brief->budgetMax)) {
                continue;
            }

            $score = 0;

            foreach ($tags as $tag) {
                $score += $wanted[$tag] ?? 0;
            }

            if ($usedUp !== null && $usedUp->matches((string) $idea->title)) {
                $score += self::USED_UP;
            }

            if ($score > 0) {
                $scored[] = [$score, $idea];
            }
        }

        // Best fit first; the older idea first among equals, so the block
        // does not reshuffle on every "Four more".
        usort($scored, fn (array $a, array $b) => [$b[0], $a[1]->id] <=> [$a[0], $b[1]->id]);

        return array_map(
            fn (array $pair) => ['id' => $pair[1]->id, 'title' => (string) $pair[1]->title],
            array_slice($scored, 0, $limit),
        );
    }
}
