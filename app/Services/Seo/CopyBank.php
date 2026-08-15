<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\CopyTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves a copy slot to one of its variants, and rotates between them.
 *
 * ## The two axes, and why both matter
 *
 * **Across pages.** Thousands of brand pages opening with one identical sentence
 * is a pattern a crawler sees in a single sample. The rotation key is the page's
 * own identity — the brand slug, the search term — so two pages drawing from the
 * same three variants reliably get different ones.
 *
 * **Over time.** A corpus that never changes is a corpus that was written once.
 * The period is folded into the seed, so the whole site's copy reshuffles on a
 * cadence without anyone touching it.
 *
 * ## Why not simply randomise per request
 *
 * It is the obvious reading of "rotate constantly" and it is the one thing that
 * would hurt. A page whose wording changes on every load:
 *
 *  - **cannot be cached**, at the edge or in the browser;
 *  - **flickers for a human** who hits back, or opens the same page in two tabs;
 *  - **shows a crawler a different document on every fetch**, which reads as an
 *    unstable page rather than a fresh one — and a search engine's judgement of
 *    "this content changes" is about substance, not about which of three
 *    synonyms for "compare" is in paragraph two.
 *
 * So the draw is deterministic given (slot, page, period) and the *period* is
 * what moves. `COPY_ROTATION=weekly` by default: every page's copy is stable for
 * a crawl window and the corpus is visibly different a month later. `daily` and
 * `monthly` are available, and `static` pins each page to one variant forever —
 * which is the right setting if you ever want to A/B a rewrite rather than churn.
 *
 * ## Fallback is load-bearing
 *
 * A slot with no enabled variant resolves to the language file. That means the
 * `copy_templates` table can be empty, half-filled or wrong and every page still
 * renders the copy the site shipped with. An editor cannot break a page by
 * deleting a row, which is what makes the table safe to hand over.
 */
class CopyBank
{
    /**
     * How long the drawable set is cached.
     *
     * Short, because the cost of a stale edit is an editor refreshing twice and
     * concluding the admin does not work. Two minutes is long enough to collapse
     * the query across a crawl burst and short enough to feel immediate.
     */
    private const CACHE_TTL = 120;

    /** @var array<string, array<string, list<array{body: string, weight: int}>>> language => slotKey => variants */
    private array $memo = [];

    /**
     * The copy for one slot, with placeholders filled.
     *
     * @param  array<string, string|int|null>  $replace
     * @param  string  $rotationKey  the page's identity — brand slug, search term
     */
    public function line(string $surface, string $slot, Market $market, array $replace, string $rotationKey): string
    {
        $body = $this->body($surface, $slot, $market, $rotationKey);

        if ($body === null) {
            return $this->fallback($surface, $slot, $market, $replace);
        }

        return $this->interpolate($body, $replace);
    }

    /**
     * The chosen variant's raw body, or null when the file should answer.
     */
    private function body(string $surface, string $slot, Market $market, string $rotationKey): ?string
    {
        $variants = $this->variants($market->language())["{$surface}.{$slot}"] ?? [];

        if ($variants === []) {
            return null;
        }

        return $this->draw($variants, $this->seed($surface, $slot, $rotationKey));
    }

    /**
     * A weighted draw that is stable for the seed.
     *
     * Weights are relative rather than percentages: raising one variant does not
     * force an edit to its siblings, which is what makes the admin usable.
     *
     * @param  list<array{body: string, weight: int}>  $variants
     */
    private function draw(array $variants, string $seed): string
    {
        $total = array_sum(array_column($variants, 'weight'));

        if ($total <= 0) {
            return $variants[0]['body'];
        }

        // 32 bits of the digest, scaled into the weight range. Not modulo on the
        // raw hash: with unequal weights that biases toward the first variant.
        $point = (int) floor((hexdec(substr(hash('sha256', $seed), 0, 8)) / 0xFFFFFFFF) * $total);
        $point = min($point, $total - 1);

        $running = 0;

        foreach ($variants as $variant) {
            $running += $variant['weight'];

            if ($point < $running) {
                return $variant['body'];
            }
        }

        // Unreachable while weights are positive integers; returning the last
        // variant is still better than returning nothing.
        return $variants[array_key_last($variants)]['body'];
    }

    /**
     * The seed: what the choice is a function of.
     *
     * The slot is in it so two slots on the same page do not move in lockstep —
     * without it, a page would draw variant 2 of everything, and the "variety"
     * would be three distinct documents rather than many.
     */
    private function seed(string $surface, string $slot, string $rotationKey): string
    {
        return implode('|', [$surface, $slot, mb_strtolower(trim($rotationKey)), $this->period()]);
    }

    /**
     * The current rotation period, as a string that changes on the cadence.
     */
    public function period(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match ((string) config('giftcoves.copy.rotation', 'weekly')) {
            // One variant per page, forever. The setting to use when comparing
            // two rewrites rather than churning through them.
            'static' => 'fixed',
            'daily' => $now->format('Y-z'),
            'monthly' => $now->format('Y-m'),
            // ISO week, so the boundary is a Monday rather than a ragged
            // seven-day window from whenever the setting was changed.
            default => $now->format('o-\WW'),
        };
    }

    /**
     * Drawable variants for a language, keyed `surface.slot`.
     *
     * One query per language per two minutes, memoised per request on top —
     * `PageNarrative` asks for a dozen slots on one render and each of those
     * would otherwise be a cache round-trip.
     *
     * @return array<string, list<array{body: string, weight: int}>>
     */
    private function variants(string $language): array
    {
        if (isset($this->memo[$language])) {
            return $this->memo[$language];
        }

        return $this->memo[$language] = Cache::remember(
            "bc:copy:{$language}",
            self::CACHE_TTL,
            function () use ($language): array {
                $out = [];

                CopyTemplate::query()
                    ->drawable()
                    ->where('language', $language)
                    // Ordered, so the cumulative walk in draw() is deterministic
                    // regardless of what Postgres feels like returning.
                    ->orderBy('id')
                    ->get(['surface', 'slot', 'body', 'weight'])
                    ->each(function (CopyTemplate $t) use (&$out): void {
                        $out["{$t->surface}.{$t->slot}"][] = [
                            'body' => $t->body,
                            'weight' => max(1, $t->weight),
                        ];
                    });

                return $out;
            },
        );
    }

    /** The language file, which is where the shipped copy lives. */
    private function fallback(string $surface, string $slot, Market $market, array $replace): string
    {
        $namespace = CopySlots::namespaceFor($surface);

        if ($namespace === null) {
            return '';
        }

        return __("{$namespace}.{$slot}", $this->clean($replace), $market->language());
    }

    /**
     * Fill placeholders.
     *
     * Done here rather than by `__()` because a database body has not been
     * through the translator, and the two must substitute identically or a slot
     * would read differently depending on whether an editor had touched it.
     *
     * Longest key first: without it `:count` inside `:count_shops` would be
     * replaced, leaving a dangling `_shops`.
     *
     * @param  array<string, string|int|null>  $replace
     */
    private function interpolate(string $body, array $replace): string
    {
        $replace = $this->clean($replace);

        uksort($replace, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($replace as $key => $value) {
            $body = str_replace(':'.$key, (string) $value, $body);
        }

        return $body;
    }

    /**
     * Nulls dropped, empty strings kept.
     *
     * Exactly what `__()` did before this class existed, and the distinction
     * matters: a dropped key leaves `:shop` visible in the sentence, where an
     * empty one blanks it out. Both mean a guard is wrong — a slot rendered
     * without the fact it needs — but a reader seeing ":shop" is the worse of the
     * two, and matching the old behaviour means this refactor cannot introduce
     * that on a page it did not already affect.
     *
     * @param  array<string, string|int|null>  $replace
     * @return array<string, string|int>
     */
    private function clean(array $replace): array
    {
        return array_filter($replace, fn ($v) => $v !== null);
    }

    /**
     * Drop the cached set.
     *
     * Called when admin saves, so an editor sees their change on the next reload
     * rather than up to two minutes later.
     */
    public static function flush(): void
    {
        foreach (Market::cases() as $market) {
            Cache::forget('bc:copy:'.$market->language());
        }
    }

    /**
     * Render a slot with sample values, for the admin preview.
     *
     * Uses the row's own body rather than the rotation, because the editor is
     * asking "what does *this* line look like", not "what would this page show".
     *
     * @param  array<string, string|int|null>  $sample
     */
    public function preview(string $body, array $sample): string
    {
        return $this->interpolate($body, $sample);
    }
}
