<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a buying guide for one topic.
 *
 * The shortlist is chosen by us; only the prose is written by a model, and only
 * from a structured brief we assemble. **The model never sees a blank page and
 * never invents a product, a price or a link** — it is handed the shortlist we
 * already picked and asked for a sentence about each. That is the difference
 * between a guide and a hallucination with a buy button.
 *
 * With `AI_ENABLED=false` the guide still publishes, using template copy. The
 * shortlist is the substance; the prose is presentation.
 */
class GuideBuilder
{
    private const FEATURE = 'guide_copy';

    /** Long enough to be a real comparison, short enough that every entry earns its place. */
    private const ITEMS = 7;

    public function __construct(private readonly AiClient $ai) {}

    public function build(GuideTopic $topic): ?Guide
    {
        $market = $topic->market instanceof Market ? $topic->market : Market::from($topic->market);
        $shortlist = $this->shortlist($market, $topic->topic);

        if (count($shortlist) < 5) {
            // The topic looked ripe when it was mined and is not now — stock
            // moves. Better to skip than to publish a three-item "best of".
            Log::info('Guide skipped: too few products', ['topic' => $topic->topic]);

            return null;
        }

        $copy = $this->copy($market, $topic->topic, $shortlist);

        return DB::transaction(function () use ($topic, $market, $shortlist, $copy): Guide {
            $guide = Guide::updateOrCreate(
                ['market' => $market->value, 'slug' => $this->slug($market, $topic->topic)],
                [
                    'title' => $copy['title'],
                    'intro' => $copy['intro'],
                    'body_md' => $copy['body'],
                    'source_queries' => (array) $topic->member_queries,
                    'source_volume' => $topic->search_volume,
                    'meta_description' => Str::limit($copy['intro'], 155, ''),
                    'focus_keyphrase' => $topic->topic,
                    'faq' => $copy['faq'],
                    'status' => PublishStatus::Published->value,
                    'published_at' => now(),
                    'last_checked_at' => now(),
                ],
            );

            // Rebuilt wholesale rather than diffed: ranks are positional, and a
            // partial update leaves a guide whose #3 is missing.
            $guide->items()->delete();

            foreach ($shortlist as $rank => $group) {
                GuideItem::create([
                    'guide_id' => $guide->id,
                    'group_id' => $group->id,
                    'rank' => $rank + 1,
                    'editorial_copy' => $copy['items'][$rank]['copy'] ?? null,
                    'verdict' => $copy['items'][$rank]['verdict'] ?? null,
                    'unavailable' => false,
                ]);
            }

            $topic->update(['status' => 'published', 'guide_id' => $guide->id]);

            return $guide;
        });
    }

    /**
     * The shortlist.
     *
     * Chosen for usefulness, not for score: a guide that lists seven versions of
     * the same thing at seven prices is the same failure the gift engine's MMR
     * exists to prevent. One per brand, cheapest-first within a brand, and only
     * products a reader can actually compare across shops.
     *
     * @return list<ProductGroup>
     */
    private function shortlist(Market $market, string $topic): array
    {
        $candidates = ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw(
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)',
                    [$topic]
                ))
            // Comparable first: the reason to read this guide here rather than
            // anywhere else is that every entry carries several shops' prices.
            ->orderByDesc('merchant_count')
            ->orderByRaw('word_similarity(?, product_groups.title) DESC', [$topic])
            ->limit(60)
            ->get();

        $picked = [];
        $brands = [];

        foreach ($candidates as $group) {
            $brand = $group->brand ?? 'unbranded';

            if (isset($brands[$brand])) {
                continue;
            }

            $brands[$brand] = true;
            $picked[] = $group;

            if (count($picked) === self::ITEMS) {
                break;
            }
        }

        // Price order, so the guide reads as a ladder rather than a ranking we
        // would then have to defend.
        usort($picked, fn (ProductGroup $a, ProductGroup $b) => ($a->min_price ?? 0) <=> ($b->min_price ?? 0));

        return $picked;
    }

    /**
     * Editorial copy, or a template fallback.
     *
     * @param  list<ProductGroup>  $shortlist
     * @return array{title: string, intro: string, body: string|null, faq: array|null, items: list<array{copy: string|null, verdict: string|null}>}
     */
    private function copy(Market $market, string $topic, array $shortlist): array
    {
        $fallback = $this->templateCopy($market, $topic, $shortlist);

        if (! $this->ai->isEnabled()) {
            return $fallback;
        }

        try {
            $response = $this->ai->json(
                self::FEATURE,
                $this->system($market),
                $this->prompt($market, $topic, $shortlist),
                schemaHint: [
                    'title' => '...',
                    'intro' => '...',
                    'how_to_choose' => '...',
                    'faq' => [['q' => '...', 'a' => '...']],
                    'items' => [['verdict' => 'Best for ...', 'copy' => '...']],
                ],
                maxTokens: 2500,
            );
        } catch (AiUnavailable $e) {
            Log::info('Guide copy unavailable, using template', ['reason' => $e->getMessage()]);

            return $fallback;
        }

        $items = [];
        foreach ($shortlist as $index => $group) {
            $items[] = [
                'copy' => $this->clean($response['items'][$index]['copy'] ?? null),
                'verdict' => $this->clean($response['items'][$index]['verdict'] ?? null, 60),
            ];
        }

        return [
            'title' => $this->clean($response['title'] ?? null, 120) ?? $fallback['title'],
            'intro' => $this->clean($response['intro'] ?? null, 400) ?? $fallback['intro'],
            'body' => $this->clean($response['how_to_choose'] ?? null, 3000),
            'faq' => $this->faq($response['faq'] ?? null),
            'items' => $items,
        ];
    }

    private function system(Market $market): string
    {
        return <<<'TXT'
        You write the prose for a buying guide on a price-comparison site.

        You are given a shortlist that has already been chosen. Your job is the
        words, not the products.

        Hard rules:
        - Never mention a product, brand, price or feature that is not in the
          brief. If you do not know something, do not say it.
        - Never claim a product is "the best" outright. Say what it is best FOR.
        - No superlatives you cannot support, no invented test results, no
          "we tested" — nothing was tested.
        - Prices move. Never write a number; the page renders live prices.
        - Two sentences per item, maximum.
        TXT;
    }

    /** @param list<ProductGroup> $shortlist */
    private function prompt(Market $market, string $topic, array $shortlist): string
    {
        $lines = [];

        foreach ($shortlist as $index => $group) {
            // Structured facts only. The model gets what we know and nothing to
            // fill gaps with.
            $lines[] = sprintf(
                '%d. %s | brand: %s | category: %s | sold by %d shop(s)',
                $index + 1,
                $group->title,
                $group->brand ?? 'unknown',
                $group->category ?? 'unknown',
                $group->merchant_count,
            );
        }

        return "Topic: {$topic}\nLanguage: {$market->language()}\n\nShortlist:\n".implode("\n", $lines);
    }

    /**
     * @param  list<ProductGroup>  $shortlist
     * @return array{title: string, intro: string, body: string|null, faq: array|null, items: list<array{copy: string|null, verdict: string|null}>}
     */
    private function templateCopy(Market $market, string $topic, array $shortlist): array
    {
        return [
            'title' => __('site.guides.template_title', ['topic' => $topic], $market->language()),
            'intro' => __('site.guides.template_intro', [
                'topic' => $topic,
                'count' => count($shortlist),
            ], $market->language()),
            'body' => null,
            'faq' => null,
            // No copy at all rather than filler. An empty line under a product
            // is honest; a generated sentence that says nothing is not.
            'items' => array_map(fn () => ['copy' => null, 'verdict' => null], $shortlist),
        ];
    }

    /** @return list<array{q: string, a: string}>|null */
    private function faq(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $faq = [];

        foreach ($raw as $entry) {
            $q = $this->clean($entry['q'] ?? null, 200);
            $a = $this->clean($entry['a'] ?? null, 600);

            // Both halves or neither: a half-empty Q&A pair renders as a broken
            // FAQPage and Google will say so.
            if ($q !== null && $a !== null) {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }

        return $faq === [] ? null : $faq;
    }

    private function clean(mixed $value, int $limit = 1200): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function slug(Market $market, string $topic): string
    {
        return Str::slug(__('site.guides.slug_prefix', [], $market->language()).'-'.$topic);
    }
}
