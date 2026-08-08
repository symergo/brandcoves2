<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Discover\ModeEngine;
use App\Services\Discovery\CatalogueAge;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Newness, measured against the catalogue rather than the calendar.
 *
 * Found on staging: 38,924 of 38,924 groups had a `first_seen_at` inside thirty
 * days, because they arrived in one import. Every product scored maximum
 * novelty — Trends became a random sample and the Deals page explained 22 of
 * its 24 results as "New here" instead of saying anything about price.
 */
class CatalogueAgeTest extends TestCase
{
    use RefreshDatabase;

    private function group(CarbonImmutable $firstSeen, Market $market = Market::BeNl): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => 'Product',
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4999,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);

        $group->forceFill(['first_seen_at' => $firstSeen])->save();

        return $group->fresh();
    }

    private function age(): CatalogueAge
    {
        Cache::flush();

        return app(CatalogueAge::class);
    }

    #[Test]
    public function a_bulk_import_carries_no_novelty(): void
    {
        $importDay = CarbonImmutable::now()->subDays(5);

        for ($i = 0; $i < 30; $i++) {
            $this->group($importDay);
        }

        $bulk = ProductGroup::query()->first();

        // Five days old by the calendar, and worth nothing: the timestamp
        // records when we onboarded an advertiser, not when the product
        // appeared in the world.
        $this->assertSame(0.0, $this->age()->novelty(Market::BeNl, $bulk->first_seen_at));
    }

    #[Test]
    public function something_that_arrived_after_the_bulk_is_genuinely_new(): void
    {
        $importDay = CarbonImmutable::now()->subDays(20);

        for ($i = 0; $i < 30; $i++) {
            $this->group($importDay);
        }

        $arrival = $this->group(CarbonImmutable::now()->subDay());

        $this->assertGreaterThan(0.8, $this->age()->novelty(Market::BeNl, $arrival->first_seen_at));
    }

    #[Test]
    public function a_catalogue_that_grew_steadily_has_no_cutoff(): void
    {
        // One product a day for a month: no day dominates, so every date in it
        // is meaningful and suppressing novelty would throw away a real signal.
        for ($i = 0; $i < 30; $i++) {
            $this->group(CarbonImmutable::now()->subDays($i));
        }

        $this->assertNull($this->age()->bulkImportedThrough(Market::BeNl));
    }

    #[Test]
    public function a_busy_day_is_not_treated_as_an_import(): void
    {
        // A tenth of the catalogue arriving at once is a busy Tuesday, not an
        // onboarding. The threshold is a fifth.
        for ($i = 0; $i < 27; $i++) {
            $this->group(CarbonImmutable::now()->subDays($i % 27 + 1));
        }
        for ($i = 0; $i < 3; $i++) {
            $this->group(CarbonImmutable::now()->subDays(2));
        }

        $this->assertNull($this->age()->bulkImportedThrough(Market::BeNl));
    }

    #[Test]
    public function the_cutoff_is_per_market(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->group(CarbonImmutable::now()->subDays(5), Market::BeNl);
        }

        // Markets are onboarded separately, so one market's import must not
        // silence another's genuinely new arrivals.
        $this->assertNotNull($this->age()->bulkImportedThrough(Market::BeNl));
        $this->assertNull($this->age()->bulkImportedThrough(Market::Es));
    }

    #[Test]
    public function trends_stays_empty_rather_than_showing_a_bulk_import(): void
    {
        $importDay = CarbonImmutable::now()->subDays(3);

        for ($i = 0; $i < 30; $i++) {
            $this->group($importDay);
        }

        Cache::flush();

        /*
         * "Nothing is new yet" is the honest answer on a young catalogue, and a
         * short page is how to say it. Returning forty thousand products that
         * all score zero novelty would be a page of old stock labelled new.
         */
        $items = app(ModeEngine::class)
            ->discover('trends', new DiscoveryRequest(
                market: Market::BeNl,
                limit: 8,
            ), seed: 1)->items;

        $this->assertSame([], $items);
    }
}
