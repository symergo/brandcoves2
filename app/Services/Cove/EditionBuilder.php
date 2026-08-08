<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Guides\CoveMarkup;
use App\Services\Guides\GuideBuilder;
use App\Services\Guides\TopicMiner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Assembles one day's edition: a puzzle, a themed set of finds, and a guide.
 *
 * The three beats are one machine, not three features on a page. The puzzle is
 * interesting *because* the product is unusual, which is the Serendipity
 * Engine's output; the guide answers what people searched for while looking at
 * exactly this sort of thing. See docs/features/daily-cove.md.
 */
class EditionBuilder
{
    private const FEATURE = 'daily_picks';

    public function __construct(
        private readonly AiClient $ai,
        private readonly TopicMiner $miner,
        private readonly GuideBuilder $guides,
        private readonly ObservanceCalendar $calendar,
        private readonly CoveMarkup $markup,
    ) {}

    /**
     * Build (or rebuild) the edition for a date.
     *
     * Idempotent: re-running for the same day updates in place rather than
     * creating a second edition. The scheduler retries, redeploys interrupt
     * jobs, and an operator will run this by hand — none of those may produce
     * two editions for one Tuesday.
     */
    public function build(Market $market, ?CarbonImmutable $date = null): ?DailyPickSet
    {
        $date = $date ?? CarbonImmutable::today();
        $perDay = (int) config('brandcoves.picks.per_day');

        /*
         * An approved plan outranks the calendar, which outranks the model.
         *
         * In that order because it is the order of how much a human meant it: a
         * person who scheduled this day beats a recurring observance, which
         * beats a line generated at 06:00. Drafts are excluded — the clock
         * coming round is not a reason to publish someone thinking out loud.
         */
        $plan = CovePlan::approvedFor($market, $date);

        // A themed day gives the edition a shape the Serendipity Engine cannot
        // invent on its own, and a reason to open a shopping page that is
        // better than "Tuesday".
        $observance = $this->calendar->on($date, $market);

        $finds = $this->finds($market, $perDay, $observance, $plan);

        if (count($finds) < 3) {
            // A three-item edition is worse than no edition. Publishing a thin
            // one on a bad catalogue day trains people that the page is not
            // worth opening.
            Log::warning('Edition skipped: not enough finds', [
                'market' => $market->value,
                'found' => count($finds),
            ]);

            return null;
        }

        $challenge = $this->challenge($market, $finds);

        /*
         * An observance names the day; otherwise the model (or the curated
         * rotation) names it.
         *
         * The observance wins because it is a fact about the date that a reader
         * can recognise, and a generated line competing with "International Pet
         * Day" loses every time.
         */
        $theme = match (true) {
            $plan !== null => [
                'title' => $plan->title,
                'blurb' => $plan->blurb,
                'slug' => Str::slug($plan->title).'-'.$plan->id,
                'source' => 'planned',
            ],
            $observance !== null => [
                'title' => $observance->title($market),
                'blurb' => $observance->blurb($market),
                'slug' => $observance->slug(),
                'source' => 'observance',
            ],
            default => $this->theme($market, $finds),
        };

        $editorial = $this->editorial($market, $finds, $observance, $theme['title']);

        return DB::transaction(function () use ($market, $date, $finds, $challenge, $theme, $editorial): DailyPickSet {
            $edition = DailyPickSet::updateOrCreate(
                ['market' => $market->value, 'drop_date' => $date->toDateString()],
                [
                    'theme_title' => $theme['title'],
                    'theme_blurb' => $theme['blurb'],
                    'theme_slug' => $theme['slug'],
                    'theme_source' => $theme['source'],
                    'editorial' => $editorial['text'],
                    'editorial_source' => $editorial['source'],
                    'challenge_group_id' => $challenge?->id,
                    'challenge_price' => $challenge?->min_price,
                    'challenge_reveal' => null,
                    'guide_id' => $this->guide($market)?->id,
                    'status' => PublishStatus::Published->value,
                    'published_at' => $date->setTimeFromTimeString(
                        (string) config('brandcoves.picks.drop_time')
                    ),
                ],
            );

            $edition->picks()->delete();

            foreach ($finds as $rank => $group) {
                DailyPick::create([
                    'set_id' => $edition->id,
                    'group_id' => $group->id,
                    'rank' => $rank + 1,
                    'slug' => Str::slug($group->title).'-'.$group->id,
                    'surprise_score' => $group->surprise_score,
                    'score_breakdown' => $group->surprise_breakdown,
                    'discount_percent' => $group->discountPercent(),
                ]);
            }

            DB::table('used_themes')->insertOrIgnore([
                'market' => $market->value,
                'theme_slug' => $theme['slug'],
                'used_on' => $date->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $edition;
        });
    }

    /**
     * Today's finds, from the Serendipity Engine, minus everything recently shown.
     *
     * @return list<ProductGroup>
     */
    private function finds(
        Market $market,
        int $count,
        ?Observance $observance = null,
        ?CovePlan $plan = null,
    ): array {
        $memoryDays = (int) config('brandcoves.picks.memory_days');

        /*
         * The rolling memory is what makes this a column rather than a feed.
         * Repeating a product inside three months is the single clearest signal
         * that nobody is choosing these — and it is the first thing a returning
         * visitor notices, because they remember the odd ones.
         */
        $recent = DB::table('daily_picks')
            ->where('created_at', '>=', now()->subDays($memoryDays))
            ->whereNotNull('group_id')
            ->pluck('group_id');

        /*
         * Pinned products lead, and are exempt from the repeat memory.
         *
         * The entire point of curation is to override a score, so a pin the
         * ranker could veto would not be a pin. If an editor wants to show
         * something again, that is a decision and not an accident — which is
         * exactly what the rolling memory exists to prevent for everything the
         * engine picks on its own.
         */
        $pinned = $plan === null || $plan->pinned_group_ids === []
            ? collect()
            : ProductGroup::query()
                ->forMarket($market)
                ->presentable()
                ->whereIn('id', $plan->pinned_group_ids)
                ->get();

        $queries = array_values(array_unique([
            ...($plan?->queries ?? []),
            ...($observance?->queries ?? []),
        ]));

        $themed = $queries === []
            ? collect()
            : $this->matching($market, $queries, $recent->merge($pinned->pluck('id')), $count);

        $rest = ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->whereNotIn('id', $recent)
            ->when($themed->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $themed->pluck('id')))
            ->orderByDesc('surprise_score')
            // Three times the target, so the set can be trimmed for variety
            // without dropping to the bottom of the ranking.
            ->limit($count * 3)
            ->get();

        /*
         * Themed finds lead; the rest fill the edition.
         *
         * A bias, not a filter. An edition that can only show pet products on a
         * thin catalogue day is an edition that fails to publish, and a page
         * that did not appear is worse than one where two of seven finds are
         * off-theme.
         */
        /*
         * Pinned first, then themed, then the rest.
         *
         * `spread` trims for category variety but never reorders, so a pin
         * keeps its place at the top of the edition.
         */
        return $this->spread($pinned->concat($themed)->concat($rest)->unique('id')->all(), $count);
    }

    /**
     * The edition's long-form copy.
     *
     * This is what makes a Cove worth reading rather than scrolling. The finds
     * are the substance; the prose is what connects them, and connecting them
     * is a judgement a ranking function cannot make.
     *
     * Stored with its link tokens unresolved, so the anchors follow the market
     * the page is read in and a product that later disappears degrades to plain
     * text rather than leaving a dead link in a row nobody revisits.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{text: string|null, source: string}
     */
    private function editorial(Market $market, array $finds, ?Observance $observance, string $title): array
    {
        if (! $this->ai->isEnabled()) {
            /*
             * No filler.
             *
             * A templated paragraph that says nothing is worse than no
             * paragraph — it costs the reader time and teaches them to skip
             * the section permanently. The finds stand on their own.
             */
            return ['text' => null, 'source' => 'none'];
        }

        $allowed = $this->linkAllowlist($market, $finds);

        try {
            $response = $this->ai->json(
                self::FEATURE,
                $this->editorialSystem()."\n\n".$this->markup->promptContract($allowed),
                $this->editorialPrompt($market, $finds, $observance, $title),
                schemaHint: ['editorial' => "First paragraph.\n\nSecond paragraph."],
                maxTokens: 1200,
            );
        } catch (AiUnavailable $e) {
            Log::info('Cove editorial unavailable', ['reason' => $e->getMessage()]);

            return ['text' => null, 'source' => 'none'];
        }

        $text = trim(strip_tags((string) ($response['editorial'] ?? '')));

        return $text === ''
            ? ['text' => null, 'source' => 'none']
            : ['text' => Str::limit($text, 4000, ''), 'source' => 'ai'];
    }

    /**
     * What the model is allowed to link to.
     *
     * Everything in today's edition, plus the brands behind it and the
     * observance's own queries. Nothing else — a token outside this list is
     * stripped to plain text by CoveMarkup, so the worst a hallucination costs
     * is an unlinked phrase.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{brands: list<string>, searches: list<string>, products: array<int, array{slug: string, title: string}>}
     */
    private function linkAllowlist(Market $market, array $finds, ?Observance $observance = null): array
    {
        $products = [];
        $brands = [];

        foreach ($finds as $group) {
            $products[$group->id] = ['slug' => $group->slug, 'title' => $group->title];

            if ($group->brand !== null) {
                $brands[] = $group->brand;
            }
        }

        $searches = array_values(array_unique(array_filter([
            ...($observance?->queries ?? []),
            ...array_map(fn (ProductGroup $g) => $g->category, $finds),
        ])));

        return [
            'brands' => array_values(array_unique($brands)),
            'searches' => $searches,
            'products' => $products,
        ];
    }

    private function editorialSystem(): string
    {
        return <<<'TXT'
        You write the short editorial that opens a daily shopping column about
        unusual products. Two or three paragraphs.

        Voice: dry, specific, quietly amused. You are pointing at odd things and
        explaining why they are worth a second look. You are not selling.

        Rules:
        - Only discuss the products listed below. Never invent one, and never
          invent a price, a rating or a claim about quality.
        - No prices at all: they change, and the page renders live ones.
        - No "amazing", no exclamation marks, no rhetorical questions.
        - Do not list the products in order. Pick two or three worth a sentence
          and let the rest speak for themselves.
        TXT;
    }

    /** @param list<ProductGroup> $finds */
    private function editorialPrompt(Market $market, array $finds, ?Observance $observance, string $title): string
    {
        $lines = [];

        foreach ($finds as $group) {
            $lines[] = sprintf(
                '- [[product:%d]] %s (%s)',
                $group->id,
                $group->title,
                $group->category ?? 'uncategorised',
            );
        }

        return implode("\n", array_filter([
            "Language: {$market->language()}.",
            "Today's title: {$title}",
            $observance === null ? null : "The occasion: {$observance->key}.",
            '',
            "Today's finds:",
            implode("\n", $lines),
        ]));
    }

    /**
     * Surprising finds that also match the day's theme.
     *
     * Still gated on `surprise_score`: the point of the Cove is the find, and a
     * themed day is a lens on that rather than a licence to show the obvious
     * pet bed everyone has seen.
     *
     * @param  list<string>  $queries
     * @param  Collection<int, int>  $recent
     * @return Collection<int, ProductGroup>
     */
    private function matching(Market $market, array $queries, $recent, int $count)
    {
        $tsquery = implode(' OR ', array_map('trim', $queries));

        return ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->whereNotIn('id', $recent)
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)',
                    [$tsquery]
                ))
            ->orderByDesc('surprise_score')
            ->limit($count * 2)
            ->get();
    }

    /**
     * Trim a ranked list to one per category where possible.
     *
     * Seven finds that all come from the same corner of the catalogue is a
     * narrower day than seven from seven corners, even when the narrow set
     * scores higher. Same reasoning as the gift engine's MMR, applied more
     * simply because the ranking here is one-dimensional.
     *
     * @param  list<ProductGroup>  $ranked
     * @return list<ProductGroup>
     */
    private function spread(array $ranked, int $count): array
    {
        $picked = [];
        $seen = [];

        foreach ($ranked as $group) {
            $key = $group->category ?? 'unknown';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $picked[] = $group;

            if (count($picked) === $count) {
                return $picked;
            }
        }

        // Backfill from the remainder if the catalogue genuinely lacks the
        // variety — a short edition is worse than a slightly repetitive one.
        foreach ($ranked as $group) {
            if (count($picked) === $count) {
                break;
            }

            if (! in_array($group, $picked, true)) {
                $picked[] = $group;
            }
        }

        return $picked;
    }

    /**
     * The product whose price gets guessed.
     *
     * Picked from the finds so the puzzle and the set are the same story, and
     * the frozen answer is the group's cheapest price at build time.
     *
     * COMPLIANCE: only a group with at least one offer from a source that
     * permits retained pricing can be the subject. A source that requires a live
     * re-fetch cannot have its price frozen for twelve hours and then scored
     * against — the answer might no longer be the answer.
     * See docs/features/amazon-compliance.md.
     *
     * @param  list<ProductGroup>  $finds
     */
    private function challenge(Market $market, array $finds): ?ProductGroup
    {
        $storable = array_values(array_filter(
            Source::values(),
            fn (string $s) => Source::from($s)->allowsPriceTracking(),
        ));

        foreach ($finds as $group) {
            if ($group->min_price === null || $group->min_price < 1000) {
                // Under €10 the bands are meaninglessly wide and the guess is
                // not interesting.
                continue;
            }

            $eligible = DB::table('products')
                ->where('group_id', $group->id)
                ->where('status', ProductStatus::Active->value)
                ->whereIn('source', $storable)
                ->where('price', $group->min_price)
                ->exists();

            if ($eligible) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Today's theme line.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{title: string, blurb: string|null, slug: string, source: string}
     */
    private function theme(Market $market, array $finds): array
    {
        $fallback = $this->curatedTheme($market);

        if (! $this->ai->isEnabled()) {
            return $fallback;
        }

        try {
            $response = $this->ai->json(
                self::FEATURE,
                <<<'TXT'
                You name a daily set of unusual products found in a shopping
                catalogue. One short title and one sentence.

                Rules:
                - Describe what these things have in common, honestly. If they
                  have nothing in common, say that — "Seven things with nothing
                  in common" is a better title than a forced theme.
                - Never invent a product, a price or a claim about quality.
                - No exclamation marks, no "amazing", no "you won't believe".
                TXT,
                'Language: '.$market->language()."\n\nToday's finds:\n- ".
                    implode("\n- ", array_map(fn (ProductGroup $g) => $g->title, $finds))."\n\n".
                    'Avoid these recently used themes: '.implode(', ', $this->recentThemes($market)),
                schemaHint: ['title' => '...', 'blurb' => '...'],
                maxTokens: 300,
            );
        } catch (AiUnavailable $e) {
            Log::info('Theme unavailable, using curated rotation', ['reason' => $e->getMessage()]);

            return $fallback;
        }

        $title = trim(strip_tags((string) ($response['title'] ?? '')));

        if ($title === '') {
            return $fallback;
        }

        return [
            'title' => Str::limit($title, 80, ''),
            'blurb' => Str::limit(trim(strip_tags((string) ($response['blurb'] ?? ''))), 200, '') ?: null,
            'slug' => Str::slug($title),
            'source' => 'ai',
        ];
    }

    /** @return list<string> */
    private function recentThemes(Market $market): array
    {
        return DB::table('used_themes')
            ->where('market', $market->value)
            ->where('used_on', '>=', now()->subDays((int) config('brandcoves.picks.theme_memory_days')))
            ->pluck('theme_slug')
            ->all();
    }

    /**
     * The no-AI theme.
     *
     * Dated rather than random, so a rerun of the same day produces the same
     * edition — idempotence has to survive the fallback path too.
     *
     * @return array{title: string, blurb: string|null, slug: string, source: string}
     */
    private function curatedTheme(Market $market): array
    {
        $key = 'site.daily.themes.'.((int) CarbonImmutable::today()->dayOfYear % 7);
        $title = __($key, [], $market->language());

        return [
            'title' => $title,
            'blurb' => null,
            'slug' => Str::slug($title).'-'.CarbonImmutable::today()->format('W'),
            'source' => 'curated',
        ];
    }

    /**
     * Today's guide, built if a topic is ripe.
     *
     * Returns an existing recent guide rather than forcing a new one every day:
     * topics ripen at the speed of search volume, not at the speed of the
     * calendar, and a guide a week is a healthier rate than a guide a day.
     */
    private function guide(Market $market): ?Guide
    {
        $topic = $this->miner->ripest($market);

        if ($topic === null) {
            return Guide::query()
                ->where('market', $market->value)
                ->where('status', PublishStatus::Published->value)
                ->latest('published_at')
                ->first();
        }

        return $this->guides->build($topic)
            ?? Guide::query()
                ->where('market', $market->value)
                ->where('status', PublishStatus::Published->value)
                ->latest('published_at')
                ->first();
    }
}
