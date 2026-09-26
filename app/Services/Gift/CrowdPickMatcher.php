<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\RecipientType;

/**
 * The rules of "people shopping for someone like this picked it", with no
 * database: which kinds of person a brief describes, and how a product's
 * counts turn into a score. Pure, so every rule is a unit test
 * (tests/Unit/CrowdPickMatcherTest.php). The lookup is {@see CrowdPicks}.
 *
 * ## A kind of person is one fact or two
 *
 * A brief for "papa, 50-64, likes cooking and cycling, for his birthday" is
 * the facts `recipient:father`, `age:50-64`, `interest:cooking`,
 * `interest:cycling` and `occasion:birthday`, and every pair of them. The
 * nightly count (CountListSignals::writeCrowdPicks) keys lists the same way.
 * Two facts at most: a triple would need five people who all shopped for a
 * father of fifty who cooks, which at today's numbers is nobody, and a pair
 * already says what a single tag cannot.
 */
final class CrowdPickMatcher
{
    /**
     * Interests of the brief taken into account, in the brief's order.
     *
     * Four, Find a gift's own limit on what it retrieves by, so that a
     * brief with eight interests does not look up 45 pairs, most of them
     * about interests the page will not show.
     */
    private const MAX_INTERESTS = 4;

    /**
     * What a match on one fact is worth against a match on two.
     *
     * Less than a pair, because "people picked this for a father" is true of
     * a lot of things fathers get, while "for a father who likes cooking" is
     * the owner's example of the thing this feature is for.
     */
    private const SINGLE = 0.6;

    private const PAIR = 1.0;

    /**
     * How sure a count is, from the threshold (0.7) to four times it (1.0).
     *
     * On a log scale, because the fifth person agreeing tells us more than the
     * fiftieth. Never below 0.7: at the threshold the count is already the
     * evidence the rest of the site trusts ("saved by 5 people").
     */
    private const CONFIDENCE_FLOOR = 0.7;

    private const CONFIDENCE_FULL_AT = 4.0;

    /**
     * The facts a brief states, as gift tags, sorted in byte order.
     *
     * Only facts a list can also carry: a relationship, an age band and an
     * interest the wizard offers, and an occasion from the tag vocabulary.
     * An interest typed as free text ("oude motoren") matches no list, since
     * lists are counted by the same closed vocabulary.
     *
     * @return list<string>
     */
    public function facts(TasteBrief $brief): array
    {
        $facts = [];

        $relationship = RecipientType::tryFrom(mb_strtolower(trim((string) $brief->relationship)));

        if ($relationship !== null) {
            $facts[] = GiftTags::recipient($relationship->value);
        }

        if ($brief->ageBand !== null && in_array($brief->ageBand, GiftTags::AGE_BANDS, true)) {
            $facts[] = GiftTags::age($brief->ageBand);
        }

        $interests = 0;

        foreach ($brief->interests as $interest) {
            $known = Interest::tryFrom(mb_strtolower(trim($interest)));

            if ($known !== null && $interests < self::MAX_INTERESTS) {
                $facts[] = GiftTags::interest($known->value);
                $interests++;
            }
        }

        if ($brief->occasion !== null && trim($brief->occasion) !== '') {
            $occasion = GiftTags::occasion($brief->occasion);

            if (in_array($occasion, GiftTags::all(), true)) {
                $facts[] = $occasion;
            }
        }

        $facts = array_values(array_unique($facts));
        sort($facts, SORT_STRING);

        return $facts;
    }

    /**
     * Every kind of person the brief describes: each fact, and each pair.
     *
     * @return list<string>
     */
    public function contexts(TasteBrief $brief): array
    {
        $facts = $this->facts($brief);
        $contexts = $facts;

        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                // Facts are sorted, so $a < $b: the same order the count uses.
                $contexts[] = $a.'+'.$b;
            }
        }

        return $contexts;
    }

    /**
     * Counted rows to picks: per product, its best context decides the
     * strength, and every context that matched says which facts agree.
     *
     * A row under the threshold is dropped here as well as in the count. The
     * threshold is the privacy guarantee, and a table written under a lower
     * setting (or by hand) must not be able to point at a single person's
     * list.
     *
     * @param  iterable<array{group_id: int, context: string, owners: int}>  $rows
     * @return array<int, CrowdPick> group id => pick, strongest first
     */
    public function score(iterable $rows, int $minOwners): array
    {
        $minOwners = max(1, $minOwners);

        /** @var array<int, array{strength: float, owners: int, tags: array<string, true>}> $best */
        $best = [];

        foreach ($rows as $row) {
            $owners = (int) $row['owners'];

            if ($owners < $minOwners) {
                continue;
            }

            $tags = explode('+', (string) $row['context']);
            $strength = (count($tags) >= 2 ? self::PAIR : self::SINGLE) * $this->confidence($owners, $minOwners);
            $id = (int) $row['group_id'];

            $current = $best[$id] ?? ['strength' => 0.0, 'owners' => 0, 'tags' => []];

            if ($strength > $current['strength']) {
                $current['strength'] = $strength;
                $current['owners'] = $owners;
            }

            foreach ($tags as $tag) {
                $current['tags'][$tag] = true;
            }

            $best[$id] = $current;
        }

        uasort($best, fn (array $a, array $b) => $b['strength'] <=> $a['strength']);

        $picks = [];

        foreach ($best as $id => $pick) {
            $tags = array_keys($pick['tags']);
            sort($tags, SORT_STRING);
            $picks[$id] = new CrowdPick($id, round($pick['strength'], 4), $pick['owners'], $tags);
        }

        return $picks;
    }

    private function confidence(int $owners, int $minOwners): float
    {
        $ratio = $owners / $minOwners;

        return min(1.0, self::CONFIDENCE_FLOOR + (1 - self::CONFIDENCE_FLOOR) * log($ratio) / log(self::CONFIDENCE_FULL_AT));
    }
}
