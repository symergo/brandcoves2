<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;

/**
 * Giftable products the suggestion engine can find, one offer each, for the
 * persona tests (demand, budget bands, has everything).
 */
trait MakesGiftShelf
{
    /** @param list<string> $tags */
    protected function giftable(string $title, int $price, array $tags = [], string $category = 'Cadeaus', Market $market = Market::BeNl): ProductGroup
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => $market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => $category,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    /**
     * An AI client that fails the test the moment anything asks it for
     * anything: none of this may spend on a model (invariant 1).
     */
    protected function forbidAi(): void
    {
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldReceive('json', 'chat')->never();
            $mock->shouldReceive('isEnabled')->andReturn(true);
        });
    }
}
