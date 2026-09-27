<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The products waiting for gift tags, for the admin's tagging page and the
 * `GET /products/to-tag` listing (docs/features/gift-tags.md, "The tagging
 * queue").
 *
 * Two sources, because they are two reasons a product needs judging now:
 *
 * - `lists`: saved to a wish list recently. Somebody wanted it, so it is the
 *   product most likely to be suggested to the next person, and the first
 *   worth tagging. The rules' giftable verdict is not asked: a saved product is
 *   judged whatever the rules said. Only the product comes out, never the list
 *   or who saved it.
 * - `new`: first seen in the catalogue recently and giftable by the rules. The
 *   rules' rejects are left out, as they were from the whole-catalogue pass.
 *
 * "Waiting" is `gift_tags_at IS NULL`: nobody has judged it. Empty tags alone
 * would keep a product judged "a gift, no tag fits" in the queue for ever.
 *
 * Nothing here tags anything. The page hands a person a prompt to run in a
 * Claude session, which reads this list and posts over the editorial API, so
 * no model runs on the server (invariant 1, and the gift-tags rule).
 */
class TaggingQueue
{
    public const LISTS = 'lists';

    public const NEW = 'new';

    public const SOURCES = [self::LISTS, self::NEW];

    /** @return Builder<ProductGroup> */
    public function query(Market $market, string $source, int $days): Builder
    {
        $since = now()->subDays(max(1, $days));

        $query = ProductGroup::query()
            ->forMarket($market)
            ->whereNull('gift_tags_at');

        return match ($source) {
            self::LISTS => $query->whereIn('id', DB::table('wishlist_items')
                ->select('group_id')
                ->whereNotNull('group_id')
                ->where('created_at', '>=', $since)),
            self::NEW => $query
                ->where('giftable', true)
                ->where('first_seen_at', '>=', $since),
            default => throw new InvalidArgumentException("Unknown tagging source: {$source}"),
        };
    }

    public function count(Market $market, string $source, int $days): int
    {
        return $this->query($market, $source, $days)->count();
    }

    /**
     * One page of the queue, oldest id first, paged by id so a writer posting
     * as they go never meets the same product twice.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Market $market, string $source, int $days, int $limit = 200, ?int $after = null): array
    {
        return $this->query($market, $source, $days)
            ->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->get(['id', 'market', 'slug', 'title', 'display_title', 'brand', 'category', 'min_price', 'giftable'])
            ->map(fn (ProductGroup $group): array => [
                'id' => $group->id,
                'title' => $group->displayTitle(),
                'brand' => $group->brand,
                'category' => $group->category,
                'minPriceCents' => $group->min_price,
                'giftableByRules' => $group->giftable,
                'url' => $group->path(),
            ])
            ->all();
    }
}
