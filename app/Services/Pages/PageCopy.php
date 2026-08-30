<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Enums\Market;
use App\Models\PageBlock;
use App\Models\PageBlockVariant;
use App\Services\Pages\Context\PageContext;
use App\Services\Pages\Placeholders\Level;
use App\Services\Pages\Placeholders\PlaceholderRegistry;
use App\Services\Pages\Placeholders\Value;
use App\Services\Pages\Regions\RegionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a page's regions into the blocks a reader actually sees.
 *
 * ## Rotation, on two axes
 *
 * **Across pages.** Thousands of search pages opening with one identical
 * sentence is a pattern a crawler sees in a single sample. The rotation key is
 * the page's own identity — the search term, the brand slug — so two pages
 * drawing from the same three phrasings reliably get different ones.
 *
 * **Over time.** A corpus that never changes is a corpus that was written once.
 * The period folds into the seed, so the whole site's copy reshuffles on a
 * cadence with nobody touching it.
 *
 * ## Why not randomise per request
 *
 * It is the obvious reading of "rotate" and the one thing that would hurt. A
 * page whose wording changes on every load cannot be cached, flickers for anyone
 * hitting back or opening two tabs, and shows a crawler a different document on
 * every fetch — which reads as an unstable page rather than a fresh one. A
 * search engine's judgement of "this content changes" is about substance, not
 * about which of three synonyms for "compare" is in paragraph two.
 *
 * So the draw is deterministic given (block, page, period), and the *period* is
 * what moves.
 *
 * ## The two guards, and the order they run in
 *
 * A block is skipped when a **condition** it requires is false, and a *phrasing*
 * is skipped when a **placeholder** it names has no value here. Both exist
 * because a sentence mentioning a number is making a claim about it: ":reduced
 * products are below their median" on a page with nothing reduced does not read
 * as a gap, it reads as "0 products", which is false.
 *
 * The order matters and is the opposite of the obvious one. **Filter first, then
 * draw.** Draw first and check second, and a block with two phrasings — one
 * naming `:percent`, one not — vanishes on a discount-free page roughly half the
 * time, depending on which one the hash happened to pick. Filtering first means
 * the block renders whenever *any* phrasing can, and the weighting applies
 * within the set that survived. It also hands an editor "write a fallback
 * phrasing that needs no number" for free, which is the most useful thing this
 * system can offer them.
 *
 * ## No floor underneath
 *
 * A region with no blocks renders nothing. There is no language-file fallback,
 * deliberately: fixed system text was the thing this replaced. What stands in
 * for it is `PageRegionsTest`, which fails the build if a region marked
 * `requiresContent` is empty in any language — an accidental wipe is a red
 * build rather than four hundred words silently leaving thousands of indexed
 * pages.
 */
class PageCopy
{
    /**
     * An hour.
     *
     * Only an administrator's save writes here, and the models flush on save —
     * so nothing else can make this stale, and nobody waits. The old copy bank
     * held two minutes because the language file was underneath it and the cost
     * of staleness was an old sentence; here the cost would be no sentence, and
     * the flush is owned by the model rather than by one screen.
     */
    private const CACHE_TTL = 3600;

    /** @var array<string, array<string, list<array<string, mixed>>>> language => "page.region" => blocks */
    private array $memo = [];

    /**
     * Every region of a page, rendered.
     *
     * @return array<string, list<array{kind: string, parts: list<array<string, mixed>>}>>
     */
    public function forPage(PageContext $context): array
    {
        $out = [];

        foreach (RegionRegistry::forPage($context->page()) as $region) {
            $out[$region->key] = $this->forRegion($region->page, $region->key, $context);
        }

        return $out;
    }

    /**
     * One region, rendered.
     *
     * @return list<array{kind: string, parts: list<array<string, mixed>>}>
     */
    public function forRegion(string $page, string $region, PageContext $context): array
    {
        $blocks = $this->stored($context->market->language())["{$page}.{$region}"] ?? [];
        $rendered = [];

        foreach ($blocks as $block) {
            $parts = $this->render($block, $context);

            if ($parts !== []) {
                $rendered[] = ['kind' => $block['kind'], 'parts' => $parts];
            }
        }

        return $rendered;
    }

    /**
     * One block, or nothing.
     *
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function render(array $block, PageContext $context): array
    {
        foreach ($block['conditions'] as $key) {
            // Unknown keys are false, in PageContext::condition(). A block whose
            // gate was renamed out from under it must stop rendering, not start
            // rendering unconditionally on every page in the market.
            if (! $context->condition((string) $key)) {
                return [];
            }
        }

        $usable = [];

        foreach ($block['variants'] as $variant) {
            if ($this->available($variant['body'], $block['kind'], $context)) {
                $usable[] = $variant;
            }
        }

        if ($usable === []) {
            return [];
        }

        $body = $this->draw($usable, $this->seed($block, $context));

        return $this->split($body, $context);
    }

    /**
     * Can this page say this sentence?
     *
     * Checked against the raw body, before anything is substituted, because
     * afterwards `:reduced` has already become "0" and there is nothing left to
     * test.
     */
    private function available(string $body, string $kind, PageContext $context): bool
    {
        foreach (PlaceholderRegistry::namesIn($body) as $name) {
            $function = PlaceholderRegistry::find($name);

            if ($function === null) {
                /*
                 * A `:word` nobody registered.
                 *
                 * Left alone rather than treated as missing: it is far more
                 * likely to be prose that happens to contain a colon than a
                 * placeholder somebody deleted, and hiding a paragraph because
                 * of a punctuation mark would be baffling. It renders literally,
                 * which is visible, and the admin refuses to save one.
                 */
                continue;
            }

            // A heading is a heading. Anchors and chip rows do not belong in one,
            // and the admin refuses them — this is the second line of defence,
            // for a block that arrived some other way.
            if ($kind === PageBlock::HEADING && $function->level() === Level::Block) {
                return false;
            }

            $value = $context->resolve($function);

            if ($kind === PageBlock::HEADING && ! $value->isInline()) {
                return false;
            }

            if ($function->absent()->hides($value->raw())) {
                return false;
            }
        }

        return true;
    }

    /**
     * A body becomes a list of parts, never a string of markup.
     *
     * The alternative — letting an editor type an anchor — is refused
     * everywhere in this codebase, and the reasoning does not weaken because the
     * author is a colleague rather than a model: an admin form that renders
     * arbitrary markup is one stored `<script>` from being the worst hole in the
     * site, reached through the one screen we tell people is safe to hand over.
     *
     * Longest name first at each position, which is what stops `:count` being
     * matched inside `:count_shops` and leaving a dangling `_shops`.
     *
     * @return list<array<string, mixed>>
     */
    private function split(string $body, PageContext $context): array
    {
        $names = array_keys(PlaceholderRegistry::all());
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $parts = [];
        $buffer = '';
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            if ($body[$i] !== ':') {
                $buffer .= $body[$i];
                $i++;

                continue;
            }

            $matched = null;

            foreach ($names as $name) {
                if (substr($body, $i + 1, strlen($name)) === $name) {
                    // Not a prefix of a longer word: `:count` in `:countdown`
                    // is not the placeholder, it is prose.
                    $next = $body[$i + 1 + strlen($name)] ?? '';

                    if (! preg_match('/[a-z0-9_]/', $next)) {
                        $matched = $name;
                        break;
                    }
                }
            }

            if ($matched === null) {
                $buffer .= $body[$i];
                $i++;

                continue;
            }

            $value = $context->resolve(PlaceholderRegistry::find($matched));

            if ($value->type === Value::TEXT) {
                $buffer .= $value->text;
            } else {
                if (trim($buffer) !== '') {
                    $parts[] = ['t' => Value::TEXT, 'v' => $buffer];
                }

                $buffer = '';
                $parts[] = $value->toPart();
            }

            $i += 1 + strlen($matched);
        }

        if (trim($buffer) !== '') {
            $parts[] = ['t' => Value::TEXT, 'v' => $buffer];
        }

        return $parts;
    }

    /**
     * A weighted draw that is stable for the seed.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function draw(array $variants, string $seed): string
    {
        $total = array_sum(array_column($variants, 'weight'));

        if ($total <= 0) {
            return (string) $variants[0]['body'];
        }

        // 32 bits of the digest, scaled into the weight range. Not modulo on the
        // raw hash: with unequal weights that biases toward the first variant.
        $point = (int) floor((hexdec(substr(hash('sha256', $seed), 0, 8)) / 0xFFFFFFFF) * $total);
        $point = min($point, $total - 1);

        $running = 0;

        foreach ($variants as $variant) {
            $running += (int) $variant['weight'];

            if ($point < $running) {
                return (string) $variant['body'];
            }
        }

        return (string) $variants[array_key_last($variants)]['body'];
    }

    /**
     * What the choice is a function of.
     *
     * The block id is in it so two blocks on one page do not move in lockstep —
     * without it a page draws the second phrasing of everything, and the
     * "variety" is three distinct documents rather than many.
     *
     * @param  array<string, mixed>  $block
     */
    private function seed(array $block, PageContext $context): string
    {
        return implode('|', [
            $block['page'],
            $block['region'],
            (string) $block['id'],
            mb_strtolower(trim($context->rotationKey)),
            $this->period(),
        ]);
    }

    /**
     * The current rotation period, as a string that changes on the cadence.
     *
     * `COPY_ROTATION` keeps its name. Renaming it is a one-line change that
     * silently reverts both Coolify apps to the default the moment they deploy,
     * because the variable they hold no longer matches anything.
     */
    public function period(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match ((string) config('giftcoves.copy.rotation', 'weekly')) {
            // One phrasing per page, forever. The setting for comparing two
            // rewrites rather than churning through them.
            'static' => 'fixed',
            'daily' => $now->format('Y-z'),
            'monthly' => $now->format('Y-m'),
            // ISO week, so the boundary is a Monday rather than a ragged
            // seven-day window from whenever the setting changed.
            default => $now->format('o-\WW'),
        };
    }

    /**
     * Every block for a language, keyed `page.region`, in reading order.
     *
     * One query per language per hour, memoised per request on top — a render
     * asks for three regions and each would otherwise be a cache round-trip.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function stored(string $language): array
    {
        if (isset($this->memo[$language])) {
            return $this->memo[$language];
        }

        return $this->memo[$language] = $this->read($language);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function read(string $language): array
    {
        /*
         * The whole `Cache::remember` call is wrapped, not just the query.
         *
         * `package:discover` boots the application during the Docker build,
         * where there is no Postgres and no Redis. The cache falls back to the
         * database driver and the throw arrives from the *cache lookup*, several
         * frames before anything touches `page_blocks` — so a try around the
         * query alone catches nothing. Same idiom as PromptBank and
         * AiSettingsStore, and it is load-bearing at deploy time.
         */
        try {
            return Cache::remember(
                "bc:page-blocks:{$language}",
                self::CACHE_TTL,
                fn (): array => $this->query($language),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function query(string $language): array
    {
        $out = [];

        PageBlock::query()
            ->shown()
            ->where('language', $language)
            ->with(['variants' => fn ($q) => $q->drawable()->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(function (PageBlock $block) use (&$out): void {
                $variants = $block->variants
                    ->map(fn (PageBlockVariant $v) => [
                        'body' => $v->body,
                        'weight' => max(1, $v->weight),
                    ])
                    ->values()
                    ->all();

                // A block with no drawable phrasing has nothing to say. Kept out
                // of the cached set entirely rather than filtered at render
                // time, so the hot path never sees it.
                if ($variants === []) {
                    return;
                }

                $out["{$block->page}.{$block->region}"][] = [
                    'id' => $block->id,
                    'page' => $block->page,
                    'region' => $block->region,
                    'kind' => $block->kind,
                    'conditions' => $block->conditions ?? [],
                    'variants' => $variants,
                ];
            });

        return $out;
    }

    /**
     * Drop the cached template.
     *
     * Both halves. This is bound `scoped()`, so the instance that answered a
     * moment ago is still in the container holding its memo — under Octane that
     * is a whole request, and in the admin screen it is the same Livewire
     * round-trip that just saved, which would go on to re-render from the copy
     * it had already read. The old `CopyBank::flush()` forgot this, and the
     * symptom was an editor saving, reloading, and concluding the admin did not
     * work.
     */
    public static function flush(): void
    {
        foreach (Market::languages() as $language) {
            Cache::forget("bc:page-blocks:{$language}");
        }

        try {
            app()->forgetInstance(self::class);
        } catch (Throwable $e) {
            // Never let a cache concern break a save.
            Log::warning('Could not forget the PageCopy instance: '.$e->getMessage());
        }
    }

    /**
     * Render one body with sample values, for the admin preview.
     *
     * Uses the given body rather than the rotation, because the editor is asking
     * "what does *this* phrasing look like", not "what would this page show".
     *
     * @return list<array<string, mixed>>
     */
    public function preview(string $body): array
    {
        $names = array_keys(PlaceholderRegistry::all());
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $parts = [];
        $buffer = '';
        $remaining = $body;

        while ($remaining !== '') {
            $matchedName = null;
            $offset = null;

            foreach ($names as $name) {
                $at = strpos($remaining, ':'.$name);

                if ($at !== false && ($offset === null || $at < $offset)) {
                    $next = $remaining[$at + 1 + strlen($name)] ?? '';

                    if (! preg_match('/[a-z0-9_]/', $next)) {
                        $offset = $at;
                        $matchedName = $name;
                    }
                }
            }

            if ($matchedName === null) {
                $buffer .= $remaining;
                break;
            }

            $buffer .= substr($remaining, 0, $offset);
            $value = PlaceholderRegistry::find($matchedName)->sample();

            if ($value->type === Value::TEXT) {
                $buffer .= $value->text;
            } else {
                if (trim($buffer) !== '') {
                    $parts[] = ['t' => Value::TEXT, 'v' => $buffer];
                }

                $buffer = '';
                $parts[] = $value->toPart();
            }

            $remaining = substr($remaining, $offset + 1 + strlen($matchedName));
        }

        if (trim($buffer) !== '') {
            $parts[] = ['t' => Value::TEXT, 'v' => $buffer];
        }

        return $parts;
    }
}
