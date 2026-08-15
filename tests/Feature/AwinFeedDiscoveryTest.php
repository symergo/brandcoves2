<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use App\Services\Catalogue\AwinFeedDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Finding Awin feeds and registering them against the right market.
 *
 * Two properties matter. A Belgian-Dutch feed must never be registered against
 * the Dutch market — that puts Belgian prices, stock and delivery in front of
 * Dutch shoppers, the same class of error market-scoped identity exists to
 * prevent. And re-running discovery must never switch off a feed that is
 * already running.
 */
class AwinFeedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function awinReturns(string ...$rows): void
    {
        $header = 'Advertiser Name,Feed ID,Membership Status,Primary Region,Language,No of products';

        Http::fake([
            'productdata.awin.com/*' => Http::response(implode("\n", [$header, ...$rows])),
        ]);

        config()->set('giftcoves.connectors.awin.accounts', [
            'default' => ['label' => 'GiftCoves', 'api_token' => 'tok', 'publisher_id' => '1'],
        ]);
    }

    #[Test]
    public function a_feed_is_matched_on_both_region_and_language(): void
    {
        $this->awinReturns(
            'Coolblue BE,111,active,BE,dutch,17100',
            'Coolblue BE,222,active,BE,french,16837',
            'Coolblue NL,333,active,NL,dutch,16329',
        );

        $discovery = app(AwinFeedDiscovery::class);
        $perMarket = $discovery->perMarket($discovery->available(), 100, null, true);

        /*
         * Region alone would put the French Belgian feed in `be-nl`, and
         * language alone would put the Dutch Belgian feed in `nl-nl`. Awin
         * reports language as an English word and region as a country code, and
         * both have to match.
         */
        $this->assertSame(['111'], array_column($perMarket['be-nl'], 'id'));
        $this->assertSame(['222'], array_column($perMarket['be-fr'], 'id'));
        $this->assertSame(['333'], array_column($perMarket['nl-nl'], 'id'));
    }

    #[Test]
    public function only_names_the_advertisers_it_is_given(): void
    {
        $this->awinReturns(
            'Coolblue BE,111,active,BE,dutch,17100',
            'Vanden Borre BE,222,active,BE,dutch,13535',
            'DreamLand BE,333,active,BE,dutch,31063',
        );

        $discovery = app(AwinFeedDiscovery::class);

        // "Add Vanden Borre and DreamLand" should add those and leave Coolblue
        // out of it — the allowlist wants all three.
        $perMarket = $discovery->perMarket(
            $discovery->available(),
            100,
            null,
            false,
            ['vandenborre', 'dreamland'],
        );

        $this->assertEqualsCanonicalizing(
            ['Vanden Borre BE', 'DreamLand BE'],
            array_column($perMarket['be-nl'], 'advertiser'),
        );
    }

    #[Test]
    public function the_advertiser_match_survives_a_spelling_change(): void
    {
        // Awin writes "Vanden Borre BE" today and could write "VandenBorre"
        // tomorrow. An allowlist that stops matching empties the catalogue.
        $this->awinReturns('VANDENBORRE  Belgium,222,active,BE,dutch,13535');

        $discovery = app(AwinFeedDiscovery::class);
        $perMarket = $discovery->perMarket($discovery->available(), 100, null, false, ['Vanden Borre']);

        $this->assertCount(1, $perMarket['be-nl']);
    }

    #[Test]
    public function rediscovery_does_not_switch_off_a_running_feed(): void
    {
        $feed = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => '111',
            'market' => Market::BeNl,
            'label' => 'Coolblue BE',
            'enabled' => true,
        ]);

        /*
         * `enabled` used to be part of the `updateOrCreate` payload, so a plain
         * re-run — the obvious thing to do, and now a button in the admin —
         * silently switched off every feed already running. The catalogue would
         * empty itself over the following days with nothing on screen to say why.
         */
        app(AwinFeedDiscovery::class)->register('be-nl', [[
            'id' => '111',
            'account' => 'default',
            'advertiser' => 'Coolblue BE',
            'products' => 17100,
        ]], enable: false);

        $this->assertTrue($feed->fresh()->enabled);
    }

    #[Test]
    public function a_newly_registered_feed_is_off_until_asked_for(): void
    {
        $discovery = app(AwinFeedDiscovery::class);

        $discovery->register('be-nl', [[
            'id' => '222', 'account' => 'default', 'advertiser' => 'Vanden Borre BE', 'products' => 13535,
        ]], enable: false);

        // Thirty feeds switched on at once is thirty concurrent
        // multi-hundred-megabyte downloads on the next scheduled run.
        $this->assertFalse(Feed::query()->where('external_feed_id', '222')->firstOrFail()->enabled);

        $result = $discovery->register('be-nl', [[
            'id' => '222', 'account' => 'default', 'advertiser' => 'Vanden Borre BE', 'products' => 13535,
        ]], enable: true);

        $this->assertTrue(Feed::query()->where('external_feed_id', '222')->firstOrFail()->enabled);
        $this->assertSame(1, $result['enabled']);
    }

    #[Test]
    public function the_feed_remembers_which_account_reached_it(): void
    {
        app(AwinFeedDiscovery::class)->register('be-nl', [[
            'id' => '222', 'account' => 'vandenborre', 'advertiser' => 'Vanden Borre BE', 'products' => 13535,
        ]], enable: true);

        // An advertiser is only reachable through the publisher account joined
        // to them. Without this the connector downloads with the primary key
        // and gets a 401.
        $this->assertSame(
            'vandenborre',
            Feed::query()->where('external_feed_id', '222')->firstOrFail()->account,
        );
    }

    #[Test]
    public function one_feed_per_advertiser_largest_first(): void
    {
        $this->awinReturns(
            'Krefel BE,111,active,BE,dutch,4000',
            'Krefel BE,222,active,BE,dutch,9092',
            'Coolblue BE,333,active,BE,dutch,17100',
        );

        $discovery = app(AwinFeedDiscovery::class);
        $perMarket = $discovery->perMarket($discovery->available(), 100, null, true);

        /*
         * Retailers publish many category feeds, so ranking by size alone
         * returns six slices of one shop. Offer comparison needs the same
         * product at *different* merchants: breadth beats depth.
         */
        $this->assertSame(['333', '222'], array_column($perMarket['be-nl'], 'id'));
    }

    #[Test]
    public function a_feed_below_the_floor_is_skipped(): void
    {
        $this->awinReturns('Action BE-NL,111,active,BE,dutch,12');

        $discovery = app(AwinFeedDiscovery::class);

        $this->assertSame([], $discovery->perMarket($discovery->available(), 100, null, true)['be-nl']);
    }

    #[Test]
    public function an_advertiser_you_are_not_joined_to_is_ignored(): void
    {
        // The list includes every advertiser on the network, not only the ones
        // this account may actually download.
        $this->awinReturns('Some Shop,111,pending,BE,dutch,9000');

        $this->assertSame([], app(AwinFeedDiscovery::class)->available());
    }

    #[Test]
    public function one_unreachable_account_does_not_stop_the_others(): void
    {
        $header = 'Advertiser Name,Feed ID,Membership Status,Primary Region,Language,No of products';

        Http::fake([
            'productdata.awin.com/datafeed/list/apikey/bad/*' => Http::response('nope', 403),
            'productdata.awin.com/*' => Http::response($header."\nCoolblue BE,111,active,BE,dutch,17100"),
        ]);

        config()->set('giftcoves.connectors.awin.accounts', [
            'broken' => ['label' => 'Broken', 'api_token' => 'bad', 'publisher_id' => '1'],
            'default' => ['label' => 'GiftCoves', 'api_token' => 'good', 'publisher_id' => '2'],
        ]);

        $discovery = app(AwinFeedDiscovery::class);
        $available = $discovery->available();

        // A revoked key is a configuration problem, not a reason to leave the
        // rest of the catalogue unregistered.
        $this->assertCount(1, $available);
        $this->assertCount(1, $discovery->warnings);
    }
}
