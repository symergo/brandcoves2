<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Models\DailyPickSet;
use App\Services\Cove\CommunityCoves;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Coves a search term matches, for the row above the products.
 *
 * The owner's request of 2026-09-26: a search for "koffie" should also show the
 * Coves about coffee, both the ones we wrote (dailies, gift personas, guides,
 * advice, shop and brand Coves) and the lists people published (Community
 * Coves). Published only, this market only. See docs/features/search.md,
 * "Coves above the products".
 *
 * Postgres full text in the market's language (`bc_text_config`), the same
 * stemming the product search uses, over the words a reader sees on a card:
 * an editorial Cove's title (weight A) and blurb (B), a Community Cove's public
 * title. There is no index on these: a market holds hundreds of Coves, not
 * hundreds of thousands, so a scan costs a few milliseconds, and the answer is
 * cached per term for ten minutes on top. No AI.
 */
class CoveMatches
{
    /** Cards in the row. Six fit one line on a desktop and scroll on a phone. */
    public const LIMIT = 6;

    /**
     * Places kept for Community Coves when there are any, so a term with six
     * editorial matches still shows that other people made something too.
     */
    private const COMMUNITY_SLOTS = 2;

    /** Ten minutes: a new Cove appears soon enough, and a popular term is one query. */
    private const TTL = 600;

    public function __construct(private readonly CommunityCoves $community) {}

    /**
     * @return list<array{kind: string, title: string, url: string, image: string|null}>
     */
    public function for(Market $market, string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        return Cache::remember(
            'search-coves:'.$market->value.':'.md5(mb_strtolower($term)),
            self::TTL,
            function () use ($market, $term): array {
                $community = $this->community($market, $term);
                $editorial = $this->editorial($market, $term, self::LIMIT - min(self::COMMUNITY_SLOTS, count($community)));

                return array_slice([...$editorial, ...$community], 0, self::LIMIT);
            },
        );
    }

    /**
     * @return list<array{kind: string, title: string, url: string, image: string|null}>
     */
    private function editorial(Market $market, string $term, int $limit): array
    {
        $vector = "setweight(to_tsvector(bc_text_config(?), coalesce(theme_title, '')), 'A')"
            ." || setweight(to_tsvector(bc_text_config(?), coalesce(theme_blurb, '')), 'B')";

        $coves = DailyPickSet::query()
            ->forMarket($market)
            ->published()
            ->whereNotNull('slug')
            ->whereRaw("({$vector}) @@ websearch_to_tsquery(bc_text_config(?), ?)", [$market->value, $market->value, $market->value, $term])
            ->orderByRaw("ts_rank({$vector}, websearch_to_tsquery(bc_text_config(?), ?)) DESC", [$market->value, $market->value, $market->value, $term])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'kind', 'slug', 'theme_title']);

        $images = $this->firstImages($coves->pluck('id')->all());

        return $coves->map(fn (DailyPickSet $cove): array => [
            'kind' => $cove->kind->value,
            'title' => (string) $cove->theme_title,
            'url' => '/'.$market->value.'/'.$cove->kind->path((string) $cove->slug, $market),
            'image' => $images[$cove->id] ?? null,
        ])->values()->all();
    }

    /**
     * @return list<array{kind: string, title: string, url: string, image: string|null}>
     */
    private function community(Market $market, string $term): array
    {
        return array_map(fn (array $card): array => [
            'kind' => 'community',
            'title' => (string) $card['title'],
            'url' => (string) $card['url'],
            'image' => $card['image'] ?? null,
        ], $this->community->matching($market, $term, self::LIMIT));
    }

    /**
     * The picture of each Cove's first pick that has one, in one query.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function firstImages(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('daily_picks')
            ->join('product_groups', 'product_groups.id', '=', 'daily_picks.group_id')
            ->whereIn('daily_picks.set_id', $ids)
            ->whereNotNull('product_groups.image_url')
            ->orderBy('daily_picks.set_id')
            ->orderBy('daily_picks.rank')
            ->selectRaw('DISTINCT ON (daily_picks.set_id) daily_picks.set_id, product_groups.image_url')
            ->pluck('image_url', 'set_id')
            ->map(fn ($url) => (string) $url)
            ->all();
    }
}
