<?php

declare(strict_types=1);

namespace App\Services\Gift;

use Illuminate\Contracts\Session\Session;

/**
 * "Show me something else" — and never that one again.
 *
 * The copy promises this absolutely (`gift_cove.whisperer_step2`: *"what you
 * rejected is never offered again"*) and nothing kept it. The rejected list was
 * client state, posted back with each swap, and the swap's own response
 * destroyed it: the Wizard posts without `preserveState`, so Inertia rebuilt
 * the component and the accumulator went back to empty. Every second swap could
 * therefore return the pick that had just been thrown away.
 *
 * ## Session, not the database
 *
 * A rejection is a passing opinion during one sitting. It is not worth a row,
 * it is not data anybody should be able to ask us for later, and a table would
 * have to be taught to `bc:prune-personal-data` and then justified in the
 * privacy policy. The session already expires on its own.
 *
 * The one-word client fix — adding `preserveState` — was rejected because it
 * only holds until the visitor does something ordinary. A reload, a
 * back-navigation or the "Try again" button all wipe component state, and the
 * promise is unconditional.
 *
 * ## Bucketed per brief
 *
 * Describing your mother and then a colleague in the same sitting must not have
 * one poison the other: they are different questions with different right
 * answers, and a single session-wide list would quietly narrow the second one.
 */
class RejectionMemory
{
    /** Where the buckets live. */
    private const KEY = 'gift.rejected';

    /**
     * Ids remembered per brief.
     *
     * Fifteen swaps is a visitor who is not going to be satisfied by the
     * sixteenth; past that the brief is the problem, not the picks. Bounded
     * because a session store is visitor-controlled input and an unbounded list
     * in a cookie-backed session is a way to make every subsequent request
     * large.
     */
    private const PER_BRIEF = 60;

    /** How many briefs are remembered at once, least-recently-used first out. */
    private const BUCKETS = 5;

    public function __construct(private readonly Session $session) {}

    /**
     * A stable handle for one brief.
     *
     * Hashed rather than stored: the brief describes a real person, and the
     * session is one more place it does not need to be readable. Normalised so
     * that re-answering the same questions in a different order is the same
     * brief rather than a new one with an empty memory.
     */
    public function key(TasteBrief $brief): string
    {
        $shape = [
            'market' => $brief->market->value,
            'interests' => $this->sorted($brief->interests),
            'vibe' => $brief->vibe?->value,
            'values' => $this->sorted($brief->values),
            'avoid' => $this->sorted($brief->avoid),
            'budgetMin' => $brief->budgetMin,
            'budgetMax' => $brief->budgetMax,
            'relationship' => $brief->relationship,
            'occasion' => $brief->occasion,
            'ageBand' => $brief->ageBand,
        ];

        return substr(hash('sha256', (string) json_encode($shape)), 0, 32);
    }

    /** @return list<int> */
    public function all(string $key): array
    {
        $buckets = $this->buckets();

        return array_values(array_map('intval', $buckets[$key] ?? []));
    }

    public function remember(string $key, int ...$ids): void
    {
        if ($ids === []) {
            return;
        }

        $buckets = $this->buckets();

        $merged = array_values(array_unique([...($buckets[$key] ?? []), ...$ids]));

        // Newest kept: an old rejection mattering less than a recent one is the
        // right trade when something has to go.
        if (count($merged) > self::PER_BRIEF) {
            $merged = array_slice($merged, -self::PER_BRIEF);
        }

        // Re-inserted at the end so this bucket counts as the most recently
        // used one, which is what makes the eviction below an LRU.
        unset($buckets[$key]);
        $buckets[$key] = $merged;

        if (count($buckets) > self::BUCKETS) {
            $buckets = array_slice($buckets, -self::BUCKETS, preserve_keys: true);
        }

        $this->session->put(self::KEY, $buckets);
    }

    /** Start over — which is what the button says. */
    public function forget(string $key): void
    {
        $buckets = $this->buckets();
        unset($buckets[$key]);

        $this->session->put(self::KEY, $buckets);
    }

    /** Every bucket, dropped entirely. */
    public function flush(): void
    {
        $this->session->forget(self::KEY);
    }

    /** @return array<string, list<int>> */
    private function buckets(): array
    {
        $buckets = $this->session->get(self::KEY, []);

        return is_array($buckets) ? $buckets : [];
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
