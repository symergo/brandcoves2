<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * A product for the editorial API's brief and item tests: one shop, one
 * in-stock offer carrying its brand, from `$this->merchant`, which the using
 * test creates in setUp(). be-nl unless a market is given.
 *
 * Not the same fixture as MakesCoveArticleProducts: the values differ (shop
 * count, `worth_showing`, surprise score), and the build tests may depend on
 * them.
 */
trait MakesCoveBriefProducts
{
    private function product(string $title, int $price, string $brand, ?Market $market = null): ProductGroup
    {
        $market ??= Market::BeNl;

        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => 60,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => $market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'brand' => $brand,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
