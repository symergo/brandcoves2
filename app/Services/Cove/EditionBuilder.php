<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Guides\GuideBuilder;
use App\Services\Guides\TopicMiner;
use Carbon\CarbonImmutable;
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

        $finds = $this->finds($market, $perDay);

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
        $theme = $this->theme($market, $finds);

        return DB::transaction(function () use ($market, $date, $finds, $challenge, $theme): DailyPickSet {
            $edition = DailyPickSet::updateOrCreate(
                ['market' => $market->value, 'drop_date' => $date->toDateString()],
                [
                    'theme_title' => $theme['title'],
                    'theme_blurb' => $theme['blurb'],
                    'theme_slug' => $theme['slug'],
                    'theme_source' => $theme['source'],
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
    private function finds(Market $market, int $count): array
    {
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

        return ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->whereNotIn('id', $recent)
            ->orderByDesc('surprise_score')
            // Three times the target, so the set can be trimmed for variety
            // without dropping to the bottom of the ranking.
            ->limit($count * 3)
            ->get()
            ->pipe(fn ($groups) => $this->spread($groups->all(), $count));
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
