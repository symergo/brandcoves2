<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "biggest drops" column beside the Daily Cove.
 *
 * Ranked on percentage alone it filled with silicone phone cases — a €25 median
 * down to €4.70 is an honest 81% and a useless thing to show. The percentage was
 * never the problem; what it was applied to was.
 *
 * Every rule below removes one specific kind of junk, and each is worth a test
 * because they are thresholds: they rot silently as a catalogue changes, and
 * nothing about the page would look wrong if one stopped applying.
 */
class DailyDealsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DailyPickSet::create([
            'market' => Market::BeNl,
            'drop_date' => now()->toDateString(),
            'theme_title' => 'Today',
            'theme_slug' => 'today',
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subHour(),
        ]);
    }

    private function group(array $overrides = []): ProductGroup
    {
        static $n = 0;
        $n++;

        return ProductGroup::factory()->create(array_merge([
            'market' => Market::BeNl,
            'identity_key' => "deal-{$n}",
            'title' => "Thing {$n}",
            'brand' => "Brand {$n}",
            'image_url' => "https://example.test/{$n}.jpg",
            'in_stock' => true,
            'giftable' => true,
            'merchant_count' => 2,
            'min_price' => 5000,
            'median_price' => 10000,
        ], $overrides));
    }

    /** @return list<string> */
    private function deals(): array
    {
        $props = $this->get('/be-nl/daily')->assertOk()->viewData('page')['props'];

        return array_column($props['deals'], 'title');
    }

    #[Test]
    public function a_cheap_thing_with_a_huge_percentage_is_not_a_deal(): void
    {
        $this->group(['title' => 'Phone case', 'min_price' => 470, 'median_price' => 2584]);
        $this->group(['title' => 'Coffee machine', 'min_price' => 3999, 'median_price' => 8495]);

        // 81% off a phone case outranks 52% off a coffee machine on percentage
        // and is the worse thing to show a reader.
        $this->assertSame(['Coffee machine'], $this->deals());
    }

    #[Test]
    public function a_large_percentage_off_a_small_saving_is_not_a_deal(): void
    {
        // Over the price floor, but €6 saved. The percentage is doing all the
        // work and there is no money behind it.
        $this->group(['title' => 'Barely', 'min_price' => 2400, 'median_price' => 3000]);
        $this->group(['title' => 'Properly', 'min_price' => 4000, 'median_price' => 6000]);

        $this->assertSame(['Properly'], $this->deals());
    }

    #[Test]
    public function a_discount_only_one_shop_agrees_with_is_not_shown(): void
    {
        // A median drawn from one shop is that shop's opinion, and a discount
        // against it is that shop's marketing.
        $this->group(['title' => 'Sole seller', 'merchant_count' => 1]);
        $this->group(['title' => 'Corroborated', 'merchant_count' => 3]);

        $this->assertSame(['Corroborated'], $this->deals());
    }

    #[Test]
    public function something_nobody_would_give_as_a_gift_is_not_shown(): void
    {
        // The column sits beside gift writing on a gift site.
        $this->group(['title' => 'Printer cartridge', 'giftable' => false]);
        $this->group(['title' => 'Headphones']);

        $this->assertSame(['Headphones'], $this->deals());
    }

    #[Test]
    public function a_price_nobody_has_re_checked_lately_is_not_shown(): void
    {
        $stale = $this->group(['title' => 'Stale']);
        $stale->forceFill(['updated_at' => now()->subMonth()])->saveQuietly();

        $this->group(['title' => 'Fresh']);

        $this->assertSame(['Fresh'], $this->deals());
    }

    #[Test]
    public function one_product_per_brand(): void
    {
        // Six covers from one maker is one fact repeated six times.
        foreach ([9000, 8000, 7000] as $i => $price) {
            $this->group([
                'title' => "Cover {$i}",
                'brand' => 'Samsung',
                'min_price' => $price,
                'median_price' => 20000,
            ]);
        }

        $this->group(['title' => 'Something else', 'brand' => 'Sony', 'min_price' => 5000, 'median_price' => 9000]);

        $deals = $this->deals();

        $this->assertContains('Something else', $deals);
        $this->assertCount(1, array_filter($deals, fn (string $t) => str_starts_with($t, 'Cover')));
    }

    #[Test]
    public function products_without_a_brand_are_not_collapsed_together(): void
    {
        // An absent brand is not a brand they share.
        $this->group(['title' => 'Nameless one', 'brand' => null]);
        $this->group(['title' => 'Nameless two', 'brand' => null]);

        $this->assertCount(2, $this->deals());
    }

    #[Test]
    public function a_sold_out_find_disappears_from_the_edition(): void
    {
        $edition = DailyPickSet::query()->firstOrFail();

        $here = $this->group(['title' => 'Still here']);
        $gone = $this->group(['title' => 'Sold out']);

        foreach ([$here, $gone] as $i => $group) {
            $edition->picks()->create([
                'group_id' => $group->id,
                'rank' => $i + 1,
                'slug' => 'pick-'.$group->id,
            ]);
        }

        $gone->forceFill(['in_stock' => false])->saveQuietly();

        /*
         * An edition is built once and served all day, and forever after in the
         * archive. Nothing re-checked stock at render, so a pick that sold out
         * at eleven carried on being presented as an ordinary buyable product —
         * price, shop count and a save button — for the rest of its life.
         */
        $props = $this->get('/be-nl/daily')->assertOk()->viewData('page')['props'];

        $this->assertSame(['Still here'], array_column($props['finds'], 'title'));
    }

    #[Test]
    public function a_sold_out_find_disappears_from_the_front_page(): void
    {
        $edition = DailyPickSet::query()->firstOrFail();

        $here = $this->group(['title' => 'Buyable']);
        $gone = $this->group(['title' => 'Sold out']);

        foreach ([$here, $gone] as $i => $group) {
            $edition->picks()->create([
                'group_id' => $group->id,
                'rank' => $i + 1,
                'slug' => 'pick-'.$group->id,
            ]);
        }

        $gone->forceFill(['in_stock' => false])->saveQuietly();

        // The first thing a visitor sees. One fewer card beats one they cannot
        // buy.
        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertSame(['Buyable'], array_column($props['today']['finds'], 'title'));
    }

    #[Test]
    public function the_column_is_capped(): void
    {
        foreach (range(1, 12) as $i) {
            $this->group(['min_price' => 5000 + $i, 'median_price' => 12000]);
        }

        $this->assertCount((int) config('giftcoves.deals.limit'), $this->deals());
    }
}
