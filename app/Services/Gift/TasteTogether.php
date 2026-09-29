<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Enums\TasteSource;
use App\Models\Recipient;
use App\Models\TasteInvite;
use App\Models\TasteRun;
use App\Support\ShareCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * This or that, played by several people about one person.
 *
 * A giver makes a link for one of their people; whoever holds it plays This
 * or that about that person, anonymously; each play's choices are kept
 * against the link, and the combined profile is worked out again from all of
 * them whenever it is shown (TasteProfiler::combined). The giver sees how many
 * played and what they found together, and can add it to the person the way
 * "Save for" does. See docs/features/taste-together.md.
 *
 * ## What is never done
 *
 * - **No profile from a browser.** A run stores ids and what was pressed; the
 *   tags and prices are read from the catalogue at the moment of combining,
 *   the same rule as the one-person tool.
 * - **Nothing about a player reaches the giver.** Not a name, not a time, not
 *   which run said what: a count and the combined result. The row carries a
 *   hash of the player's cookie identity, salted with the invite, only so a
 *   second play replaces the first.
 * - **A player never writes to the person.** Only the giver's "Add to" does,
 *   so a link that leaked cannot fill somebody's profile with nonsense.
 */
final class TasteTogether
{
    /**
     * How many different people one link takes.
     *
     * A family or an office is under twenty; past that the link has escaped
     * the group it was made for, and the extra runs would be strangers'
     * guesses. Twenty-five leaves room for a large family without letting a
     * link posted somewhere public turn into a poll.
     */
    public const MAX_PARTICIPANTS = 25;

    public function __construct(private readonly TasteChoiceReader $reader) {}

    /** The person's latest link, open or stopped, or null when there never was one. */
    public function latest(Recipient $recipient): ?TasteInvite
    {
        return TasteInvite::query()
            ->where('recipient_id', $recipient->id)
            ->latest('id')
            ->first();
    }

    /**
     * The open link for this person, made when there is none.
     *
     * One open link at a time: a second press hands back the same link, so
     * the answers are not split over two. After stopping a link, a new one
     * starts afresh, with a new address and no answers.
     */
    public function open(Recipient $recipient, Market $market): TasteInvite
    {
        $latest = $this->latest($recipient);

        if ($latest !== null && $latest->isOpen()) {
            return $latest;
        }

        // The unique index is the guarantee; a collision at 50 bits is a retry.
        for ($attempt = 0; ; $attempt++) {
            try {
                return TasteInvite::create([
                    'recipient_id' => $recipient->id,
                    'token' => ShareCode::make(),
                    'market' => $market->value,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    public function stop(Recipient $recipient): void
    {
        TasteInvite::query()
            ->where('recipient_id', $recipient->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** An open link by its token, or null: a stopped link is a link that does not exist. */
    public function byToken(string $token): ?TasteInvite
    {
        return TasteInvite::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->with('recipient')
            ->first();
    }

    /** Whether this player may still add a run: they already have one, or there is room. */
    public function hasRoomFor(TasteInvite $invite, string $participant): bool
    {
        return $invite->runs()->where('participant_hash', $participant)->exists()
            || $invite->runs()->count() < self::MAX_PARTICIPANTS;
    }

    /**
     * Keep one player's choices. A second play by the same player replaces
     * their first, so nobody counts twice by playing again.
     *
     * Only choices that answered something are kept: a skip teaches nothing
     * (TasteChoice), and the one-person page sends skips only so the same
     * cards are not shown twice, which no longer matters once the run is over.
     *
     * @param  list<array<string, mixed>>  $raw  as the page sent them, already validated
     * @return bool false when the link is full
     */
    public function record(TasteInvite $invite, string $participant, array $raw, int $answered): bool
    {
        return DB::transaction(function () use ($invite, $participant, $raw, $answered): bool {
            // Serialises the count-then-insert against another player at the same moment.
            TasteInvite::query()->whereKey($invite->id)->lockForUpdate()->first();

            if (! $this->hasRoomFor($invite, $participant)) {
                return false;
            }

            TasteRun::query()->updateOrCreate(
                ['taste_invite_id' => $invite->id, 'participant_hash' => $participant],
                ['choices' => $this->answeredOnly($raw), 'answered' => $answered],
            );

            return true;
        });
    }

    /** Everything the link's players chose, as one profile, read from the catalogue now. */
    public function combined(TasteInvite $invite): TasteProfile
    {
        $runs = $invite->runs()->get(['id', 'choices'])
            ->map(fn (TasteRun $run) => $this->reader->read(array_values((array) $run->choices), $invite->market))
            ->all();

        return TasteProfiler::fromConfig()->combined(array_values($runs));
    }

    /**
     * Add the combined result to the person, the way "Save for" does.
     *
     * The taste goes through `describeTaste()` as a guess, so it is refused
     * when the person described their own taste; it adds to what is stored and
     * takes away only contradictions (TasteProfile::mergedWith). The budget is
     * written directly: what the group will spend is the giver's fact, not the
     * person's taste. Mirrors TasteController::save().
     *
     * @return array{written: bool, profile: TasteProfile}|null null when nothing was learned
     */
    public function apply(TasteInvite $invite): ?array
    {
        $profile = $this->combined($invite);

        if ($profile->isEmpty()) {
            return null;
        }

        $recipient = $invite->recipient;

        $written = $recipient->describeTaste($profile->mergedWith([
            'interests' => $recipient->interests,
            'avoid' => $recipient->avoid,
            'preferences' => $recipient->preferences,
        ]), TasteSource::Suggested);

        if ($profile->budgetMin !== null) {
            $recipient->update([
                'budget_min' => $profile->budgetMin,
                'budget_max' => $profile->budgetMax,
            ]);
        }

        $invite->update(['applied_at' => now()]);

        return ['written' => $written, 'profile' => $profile];
    }

    /**
     * What the giver sees on the person's list: the link, how many played, and
     * what they found together. Never a run on its own, never when anyone
     * played: a count and a combined result.
     *
     * Asks the catalogue only when somebody played, so a list page with no
     * link, or a link nobody used yet, costs one small query.
     *
     * @return array{url: string|null, open: bool, players: int, max: int, profile: array<string, mixed>|null, thin: bool, applied: bool, urls: array{open: string, stop: string, apply: string}}
     */
    public function forGiver(Recipient $recipient, string $marketPrefix): array
    {
        $invite = $this->latest($recipient);
        $players = $invite === null ? 0 : $invite->runs()->count();
        $profile = $players > 0 && $invite !== null ? $this->combined($invite) : null;
        $base = "{$marketPrefix}/recipients/{$recipient->id}/taste-together";

        return [
            'url' => $invite !== null && $invite->isOpen()
                ? url("/{$invite->market->value}/t/{$invite->token}")
                : null,
            'open' => $invite !== null && $invite->isOpen(),
            'players' => $players,
            'max' => self::MAX_PARTICIPANTS,
            'profile' => $profile?->toArray(),
            'thin' => $profile !== null && $profile->answered < TasteDeck::EXPLORE,
            'applied' => $invite?->applied_at !== null,
            'urls' => [
                'open' => $base,
                'stop' => $base,
                'apply' => "{$base}/apply",
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function answeredOnly(array $raw): array
    {
        $kept = [];

        foreach ($raw as $round) {
            $picked = $round['picked'] ?? null;
            $verdict = $round['verdict'] ?? null;

            if ($picked === null && $verdict === null) {
                continue;
            }

            $kept[] = array_filter([
                'shown' => array_values(array_map('intval', (array) ($round['shown'] ?? []))),
                'picked' => $picked === null ? null : (int) $picked,
                'verdict' => $verdict === null ? null : (string) $verdict,
            ], fn ($v) => $v !== null);
        }

        return $kept;
    }
}
