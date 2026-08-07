<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\Vibe;
use App\Models\GiftAngle;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Gift\AngleMap;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Grows the interest → query map, one market per night.
 *
 * This is the shape the AI invariant recommends for every model-backed feature:
 * the expensive, non-deterministic step runs in a job and writes rows; the
 * request path only ever reads them. The gift engine therefore stays pure, fast
 * and free no matter how popular it gets. See docs/features/ai-invariant.md.
 *
 * ## Batched per market, not per interest
 *
 * 5 markets × 20 interests × 4 vibe states is 400 combinations, against a cap
 * of 20 calls a day for this feature. One call per market covering the stalest
 * interests fits inside the cap with room for retries, and the model does a
 * better job when it can see several interests at once — it stops repeating
 * "cadeau voor..." across all of them.
 *
 * ## With AI switched off
 *
 * Nothing happens, deliberately. The curated seed in {@see AngleMap} is written
 * to be sufficient on its own — this job makes results *broader*, not
 * *possible*. Faking widening from the catalogue would push results toward what
 * is already well stocked, which is the opposite of the point.
 */
class WidenGiftAngles implements ShouldQueue
{
    use Queueable;

    private const FEATURE = 'gift_angles';

    /** How many interests one call covers. Enough to be useful, small enough to stay coherent. */
    private const BATCH = 5;

    public int $tries = 2;

    public function __construct(public Market $market, public ?Vibe $vibe = null) {}

    public function handle(AiClient $ai, AngleMap $map): void
    {
        if (! $ai->isEnabled()) {
            Log::info('Gift angle widening skipped: AI disabled', ['market' => $this->market->value]);

            return;
        }

        $interests = $this->stalest();

        if ($interests === []) {
            return;
        }

        try {
            $response = $ai->json(
                self::FEATURE,
                $this->system(),
                $this->prompt($interests, $map),
                schemaHint: ['angles' => [['interest' => 'cooking', 'queries' => ['...']]]],
                maxTokens: 1500,
            );
        } catch (AiUnavailable $e) {
            // Expected: cap reached, key missing, model hiccup. The seed keeps
            // serving, so this is a quiet no-op rather than a failure.
            Log::info('Gift angle widening unavailable', [
                'market' => $this->market->value,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $this->store($response['angles'] ?? []);
    }

    /**
     * The interests whose rows are oldest or missing.
     *
     * Ordering by staleness means every interest gets refreshed eventually
     * without keeping a cursor anywhere — the timestamp on the row *is* the
     * cursor, and it survives a redeploy for free.
     *
     * @return list<Interest>
     */
    private function stalest(): array
    {
        $freshness = GiftAngle::query()
            ->forMarket($this->market)
            ->when(
                $this->vibe === null,
                fn ($q) => $q->whereNull('vibe'),
                fn ($q) => $q->where('vibe', $this->vibe?->value),
            )
            ->pluck('updated_at', 'interest');

        $interests = Interest::cases();

        usort($interests, function (Interest $a, Interest $b) use ($freshness): int {
            // Never-widened interests sort first: a missing row is infinitely
            // stale, and covering the gaps beats refreshing what already works.
            $left = $freshness[$a->value] ?? null;
            $right = $freshness[$b->value] ?? null;

            if ($left === null && $right === null) {
                return 0;
            }
            if ($left === null) {
                return -1;
            }
            if ($right === null) {
                return 1;
            }

            return $left <=> $right;
        });

        return array_slice($interests, 0, self::BATCH);
    }

    private function system(): string
    {
        return <<<'TXT'
        You expand a gift interest into the product search terms that would find
        real, buyable presents in a European electronics-and-homeware catalogue.

        Rules:
        - Return concrete product nouns, not themes. "statief" not "fotografie cadeau".
        - No brand names: the catalogue changes and a brand query goes stale.
        - Nothing that is a consumable, a spare part, a warranty or a phone case.
        - 8 to 12 terms per interest.
        TXT;
    }

    /** @param list<Interest> $interests */
    private function prompt(array $interests, AngleMap $map): string
    {
        $language = $this->market->language();
        $lines = [];

        foreach ($interests as $interest) {
            // The seed is shown so the model adds to it rather than restating
            // it. Without this the same six words come back every night.
            $lines[] = sprintf(
                '- %s (already have: %s)',
                $interest->value,
                implode(', ', $map->seedFor($interest)),
            );
        }

        return "Market: {$this->market->value}. Write the search terms in {$language}.\n"
            .($this->vibe === null
                ? "No particular style.\n"
                : "The gift should feel: {$this->vibe->value}.\n")
            ."\nInterests:\n".implode("\n", $lines);
    }

    /** @param array<int, mixed> $angles */
    private function store(array $angles): void
    {
        $valid = Interest::values();

        foreach ($angles as $angle) {
            if (! is_array($angle)) {
                continue;
            }

            $interest = mb_strtolower(trim((string) ($angle['interest'] ?? '')));

            // A model can return an interest we never asked about. Dropping it
            // is right: the wizard only offers the closed vocabulary, so a row
            // outside it would never be read.
            if (! in_array($interest, $valid, true)) {
                continue;
            }

            $queries = array_values(array_filter(array_map(
                fn ($q) => trim((string) $q),
                (array) ($angle['queries'] ?? []),
            ), fn (string $q) => $q !== '' && mb_strlen($q) <= 60));

            if ($queries === []) {
                continue;
            }

            GiftAngle::updateOrCreate(
                [
                    'market' => $this->market->value,
                    'interest' => $interest,
                    'vibe' => $this->vibe?->value,
                ],
                ['queries' => $queries, 'source' => 'ai'],
            );
        }
    }
}
