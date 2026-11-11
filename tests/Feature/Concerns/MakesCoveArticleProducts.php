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
 * A be-nl product an article Cove can build from: worth showing, two shops,
 * one in-stock offer from `$this->merchant`, which the using test creates in
 * setUp().
 *
 * Shared by the article, redo and calendar tests. Not the same fixture as
 * MakesCoveBriefProducts: the values differ (shop count, `worth_showing`,
 * surprise score), and the build tests may depend on them.
 */
trait MakesCoveArticleProducts
{
    private function product(string $title, int $price, string $brand, string $category = 'audio'): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'brand' => $brand,
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            'surprise_score' => 50,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
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
