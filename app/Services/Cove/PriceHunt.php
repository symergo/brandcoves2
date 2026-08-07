<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\ChallengeAttempt;
use App\Models\DailyPickSet;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily price guess.
 *
 * One unusual product, price hidden, a few tries, then a shareable grid. The
 * game is not bolted on to the site — it *is* the site's asset made playable.
 * We can run it because we hold real, current, multi-shop prices; a content site
 * cannot, and a single retailer running it would just be advertising.
 *
 * Everything here is server-authoritative. The answer never reaches the client
 * before the round is over, because a price in a JSON payload is a price in
 * DevTools.
 */
class PriceHunt
{
    /**
     * Tries per day.
     *
     * Four, not six. Price has a continuous answer space, so each band already
     * narrows it enormously — six tries makes the puzzle trivially solvable and
     * a solved-every-day streak is worth nothing.
     */
    public const MAX_ATTEMPTS = 4;

    /**
     * Record a guess and return the round's state.
     *
     * @return array{band: string, over: bool, solved: bool, attemptsLeft: int, finished: bool, answer: int|null}
     */
    public function guess(DailyPickSet $edition, Owner $owner, int $guessCents): array
    {
        $answer = (int) $edition->challenge_price;

        $attempt = $this->attemptFor($edition, $owner);

        if ($this->isFinished($attempt)) {
            return $this->state($attempt, $answer, revealed: true);
        }

        $band = GuessBand::classify($guessCents, $answer);
        $over = $guessCents > $answer;

        $guesses = [...(array) $attempt->guesses, $guessCents];
        $bands = [...(array) $attempt->bands, ['band' => $band->value, 'over' => $over]];

        $attempt->update([
            'guesses' => $guesses,
            'bands' => $bands,
            'attempts' => count($guesses),
            'solved' => $attempt->solved || $band->solves(),
        ]);

        return $this->state($attempt->fresh(), $answer, revealed: $this->isFinished($attempt->fresh()));
    }

    /** The round as it stands, without recording anything. */
    public function state(?ChallengeAttempt $attempt, int $answer, ?bool $revealed = null): array
    {
        if ($attempt === null) {
            return [
                'band' => null,
                'over' => false,
                'solved' => false,
                'attemptsLeft' => self::MAX_ATTEMPTS,
                'finished' => false,
                'answer' => null,
                'bands' => [],
            ];
        }

        $finished = $revealed ?? $this->isFinished($attempt);
        $bands = (array) $attempt->bands;
        $last = $bands === [] ? null : $bands[array_key_last($bands)];

        return [
            'band' => $last['band'] ?? null,
            'over' => (bool) ($last['over'] ?? false),
            'solved' => (bool) $attempt->solved,
            'attemptsLeft' => max(0, self::MAX_ATTEMPTS - (int) $attempt->attempts),
            'finished' => $finished,
            /*
             * The answer is withheld until the round is over.
             *
             * Not hidden in the UI — absent from the payload. A price sent
             * "for later" is a price anyone can read in DevTools, and one
             * person doing that ruins the shared-puzzle premise for everyone
             * they post their grid to.
             */
            'answer' => $finished ? $answer : null,
            'bands' => $bands,
        ];
    }

    public function isFinished(?ChallengeAttempt $attempt): bool
    {
        if ($attempt === null) {
            return false;
        }

        return $attempt->solved || $attempt->attempts >= self::MAX_ATTEMPTS;
    }

    public function attemptFor(DailyPickSet $edition, Owner $owner): ChallengeAttempt
    {
        return ChallengeAttempt::firstOrCreate(
            ['set_id' => $edition->id, ...$owner->attributes('user_id', 'anon_id')],
            [
                'market' => $edition->market->value,
                // The edition's date, not today's: someone playing an archived
                // round should not have it count toward the current streak.
                'played_on' => $edition->drop_date->toDateString(),
            ],
        );
    }

    public function existingAttempt(DailyPickSet $edition, Owner $owner): ?ChallengeAttempt
    {
        if (! $owner->exists()) {
            return null;
        }

        return $owner->scope(ChallengeAttempt::query(), 'user_id', 'anon_id')
            ->where('set_id', $edition->id)
            ->first();
    }

    /**
     * The share artefact.
     *
     * A score, not a link-beg. Nobody feels marketed to by a row of squares, and
     * the grid carries no spoiler — so posting it costs the poster nothing,
     * which is the entire reason this loop works when a "share this deal!"
     * button does not.
     */
    public function shareGrid(ChallengeAttempt $attempt, string $editionLabel): string
    {
        $row = '';

        foreach ((array) $attempt->bands as $entry) {
            $band = GuessBand::tryFrom((string) ($entry['band'] ?? '')) ?? GuessBand::Cold;
            $row .= $band->emoji((bool) ($entry['over'] ?? false));
        }

        $score = $attempt->solved ? $attempt->attempts.'/'.self::MAX_ATTEMPTS : 'X/'.self::MAX_ATTEMPTS;

        return "Brandcoves {$editionLabel} {$score}\n{$row}";
    }

    /**
     * Consecutive days played, ending today or yesterday.
     *
     * Derived from the attempt rows, never stored as a counter. A stored streak
     * drifts, gets corrupted by a timezone bug or a double-write, and then has
     * to be repaired by hand for individual users. A query over distinct dates
     * cannot be wrong — it is recomputed from the only facts that exist.
     *
     * @return array{current: int, longest: int}
     */
    public function streak(Owner $owner): array
    {
        if (! $owner->exists()) {
            return ['current' => 0, 'longest' => 0];
        }

        $dates = $owner->scope(ChallengeAttempt::query(), 'user_id', 'anon_id')
            ->where('attempts', '>', 0)
            ->orderByDesc('played_on')
            ->pluck('played_on')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return ['current' => 0, 'longest' => 0];
        }

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $current = 0;
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            if ($previous !== null && $this->isDayBefore($date, $previous)) {
                $run++;
            } else {
                $run = 1;
            }

            $longest = max($longest, $run);

            // The current streak only survives if the most recent play was
            // today or yesterday — a streak you broke last week is not current.
            if ($current === 0 && ($dates[0] === $today || $dates[0] === $yesterday)) {
                $current = $run;
            } elseif ($current > 0 && $run === $current + 1) {
                $current = $run;
            }

            $previous = $date;
        }

        return ['current' => $current, 'longest' => $longest];
    }

    private function isDayBefore(string $earlier, string $later): bool
    {
        return CarbonImmutable::parse($later)->subDay()->toDateString() === $earlier;
    }

    /**
     * How everyone else did today, for the reveal screen.
     *
     * "62% got it" is what makes a shared grid a conversation rather than a
     * broadcast — it tells the reader whether their own result was any good.
     *
     * @return array{players: int, solvedPercent: int|null}
     */
    public function communityResult(DailyPickSet $edition): array
    {
        $row = DB::table('challenge_attempts')
            ->where('set_id', $edition->id)
            ->where('attempts', '>', 0)
            ->selectRaw('count(*) as players, count(*) FILTER (WHERE solved) as solved')
            ->first();

        $players = (int) ($row->players ?? 0);

        return [
            'players' => $players,
            // Withheld below a handful of players: "100% got it" from two people
            // is noise presented as a fact.
            'solvedPercent' => $players >= 5
                ? (int) round(((int) $row->solved / $players) * 100)
                : null,
        ];
    }
}
