<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Feed;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Connectors\SourceSwitch;
use App\Services\Ops\MarketSupply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What supplies each market.
 *
 * The tests worth having here are about the distinctions the page exists to
 * draw. "No supply" has at least five different causes — nobody registered a
 * feed, somebody disabled one, it is running and failing, a credential never
 * arrived, the source was never integrated — and every one of them looks
 * identical on the site. Collapsing any two of them back together is the
 * regression this file is guarding against.
 */
class MarketSupplyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Config, not env.
     *
     * phpunit.xml blanks the bol and Awin credentials but not eBay's or
     * Tradedoubler's, so on a machine with a populated `.env` those two are
     * genuinely configured during the run. A test asserting "not serving" would
     * then pass on CI and fail on a laptop, which is worse than either.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('giftcoves.connectors.ebay.client_id', null);
        config()->set('giftcoves.connectors.ebay.client_secret', null);
        config()->set('giftcoves.connectors.tradedoubler.token', null);
        config()->set('giftcoves.connectors.bol.client_id', null);
        config()->set('giftcoves.connectors.bol.client_secret', null);
    }

    /** @return array<string, mixed> */
    private function cell(Market $market, Source $source): array
    {
        foreach (app(MarketSupply::class)->rows() as $row) {
            if ($row['market'] !== $market) {
                continue;
            }

            foreach ($row['cells'] as $cell) {
                if ($cell['source'] === $source) {
                    return $cell;
                }
            }
        }

        $this->fail("No cell for {$source->value} in {$market->value}");
    }

    private function feed(Market $market, bool $enabled, ?string $error = null, ?string $ranAt = null): Feed
    {
        return Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => (string) random_int(1000, 999999),
            'market' => $market,
            'label' => 'Test advertiser',
            'enabled' => $enabled,
            'last_error' => $error,
            'last_run_at' => $ranAt,
        ]);
    }

    #[Test]
    public function a_non_admin_cannot_reach_it(): void
    {
        // Nothing here is a secret — presence and counts only — but it reports
        // which credentials an environment is missing, which is a map for
        // somebody probing it. Same gate as the rest of /admin.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/market-supply')
            ->assertForbidden();

        $this->get('/admin/market-supply')->assertForbidden();
    }

    #[Test]
    public function it_renders_every_market_including_the_unpublished_one(): void
    {
        $this->feed(Market::BeNl, enabled: true, ranAt: now()->subHour()->toDateTimeString());

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/market-supply')
            ->assertOk();

        // `es` is closed *because* it has no supply, so it is the row most worth
        // showing — hiding unpublished markets would hide the work of opening
        // one.
        foreach (Market::values() as $market) {
            $response->assertSee($market);
        }
    }

    #[Test]
    public function a_source_switched_off_in_the_panel_says_so_in_its_own_words(): void
    {
        $this->feed(Market::BeNl, enabled: true, ranAt: now()->subHour()->toDateTimeString());

        app(SourceSwitch::class)->set(Source::Awin, Market::BeNl, false);

        $cell = $this->cell(Market::BeNl, Source::Awin);

        /*
         * The distinction the whole switch depends on staying legible.
         *
         * "Switched off in the panel" and "switched off in config" are different
         * facts with different fixes, and the second sends somebody to an
         * environment variable that is set perfectly well. A feed cell reporting
         * "1 enabled" here would be worse still: the rows *are* enabled, and
         * nothing is being downloaded.
         */
        $this->assertSame('off', $cell['status']);
        $this->assertSame('switched off in the panel', $cell['headline']);

        // The catalogue is not retracted, and the page has to say which tool
        // does that — otherwise the obvious next assumption is that switching
        // off emptied search, and it did not.
        $this->assertStringContainsString(
            'bc:withdraw-source',
            implode(' ', $cell['notes']),
        );
    }

    #[Test]
    public function switching_a_live_source_off_is_not_reported_as_a_missing_credential(): void
    {
        app(SourceSwitch::class)->set(Source::Ebay, Market::BeNl, false);

        $cell = $this->cell(Market::BeNl, Source::Ebay);

        $this->assertSame('off', $cell['status']);

        $notes = implode(' ', $cell['notes']);

        // blockers() short-circuits on the switch, so none of the environment
        // advice fires. A cell telling somebody EBAY_CLIENT_ID is missing when
        // it is present and correct is how a five-minute fix becomes an hour.
        $this->assertStringContainsString('panel', $notes);
        $this->assertStringNotContainsString('EBAY_CLIENT_ID', $notes);
        $this->assertStringNotContainsString('EBAY_MARKETPLACE', $notes);
    }

    #[Test]
    public function a_feed_source_tells_absent_disabled_and_failing_apart(): void
    {
        /*
         * Every fixture before the first assertion, deliberately.
         *
         * The row counts are cached for a minute, so reading the report and
         * then writing a feed reads the report's own snapshot rather than the
         * new row. That is correct for a screen a person refreshes and a trap
         * for a test that interleaves — the four states have to coexist here
         * anyway, which is closer to what the page actually renders.
         */

        // Registered and switched off — a different problem with the same
        // symptom, and the one a rediscovery run has caused before.
        $this->feed(Market::BeFr, enabled: false);

        // On, and failing every run.
        $this->feed(Market::NlNl, enabled: true, error: 'HTTP 401', ranAt: now()->toDateTimeString());

        // On and never run: queued work, not a fault.
        $this->feed(Market::BeNl, enabled: true);

        // Nothing registered at all for Spain.
        $this->assertSame('absent', $this->cell(Market::Es, Source::Awin)['status']);

        $beFr = $this->cell(Market::BeFr, Source::Awin);
        $this->assertSame('off', $beFr['status']);
        $this->assertStringContainsString('none enabled', $beFr['headline']);

        $this->assertSame('failing', $this->cell(Market::NlNl, Source::Awin)['status']);
        $this->assertSame('pending', $this->cell(Market::BeNl, Source::Awin)['status']);
    }

    #[Test]
    public function a_partly_failing_feed_set_still_reads_as_serving_and_says_so(): void
    {
        // The failure that hides: the catalogue keeps filling from the healthy
        // feeds, the site looks fine, and one merchant has quietly gone.
        $this->feed(Market::BeNl, enabled: true, ranAt: now()->subHour()->toDateTimeString());
        $this->feed(Market::BeNl, enabled: true, error: 'timeout', ranAt: now()->subHour()->toDateTimeString());

        $cell = $this->cell(Market::BeNl, Source::Awin);

        $this->assertSame('ok', $cell['status']);
        $this->assertContains('1 of 2 failing', $cell['notes']);
    }

    #[Test]
    public function an_unconfigured_live_source_names_the_variable_that_fixes_it(): void
    {
        $notes = implode(' ', $this->cell(Market::BeNl, Source::Ebay)['notes']);

        // "Not serving" on its own is the dead end the old discovery modal's
        // "nothing matched" was. The reason has to carry the fix.
        $this->assertSame('off', $this->cell(Market::BeNl, Source::Ebay)['status']);
        $this->assertStringContainsString('EBAY_CLIENT_ID', $notes);
    }

    #[Test]
    public function a_market_the_source_does_not_operate_in_is_not_reported_as_broken(): void
    {
        config()->set('giftcoves.connectors.bol.client_id', 'id');
        config()->set('giftcoves.connectors.bol.client_secret', 'secret');

        // Credentials present and bol still absent from Spain, because bol does
        // not trade there. Nothing to fix, and the cell must say that rather
        // than sending somebody after a credential.
        $notes = implode(' ', $this->cell(Market::Es, Source::Bol)['notes']);

        $this->assertStringContainsString('does not operate', $notes);
        $this->assertStringNotContainsString('BOL_CLIENT_ID', $notes);
    }

    #[Test]
    public function a_source_with_no_connector_reports_that_credentials_will_not_help(): void
    {
        // Amazon has config, compliance rules and a place in the Source enum,
        // and no connector class at all. Supplying AMAZON_ACCESS_KEY would
        // switch on nothing, and the config's own comment claims otherwise.
        config()->set('giftcoves.connectors.amazon.enabled', true);
        config()->set('giftcoves.connectors.amazon.access_key', 'key');
        config()->set('giftcoves.connectors.amazon.secret_key', 'secret');

        $cell = $this->cell(Market::BeNl, Source::Amazon);

        $this->assertSame('absent', $cell['status']);
        $this->assertSame('not integrated', $cell['headline']);
    }

    #[Test]
    public function a_serving_source_that_earns_nothing_says_so(): void
    {
        config()->set('giftcoves.connectors.ebay.enabled', true);
        config()->set('giftcoves.connectors.ebay.client_id', 'id');
        config()->set('giftcoves.connectors.ebay.client_secret', 'secret');
        config()->set('giftcoves.connectors.ebay.campaign_id', []);

        $cell = $this->cell(Market::BeNl, Source::Ebay);

        // Serving, and paying nobody. The link resolves, the visitor buys and
        // the commission goes nowhere — invisible everywhere else in the panel,
        // which is the entire reason it is on the face of the cell.
        $this->assertSame('ok', $cell['status']);
        $this->assertStringContainsString('earn nothing', (string) $cell['earning']);
    }

    #[Test]
    public function the_catalogue_count_only_counts_what_a_visitor_could_reach(): void
    {
        /*
         * The bug this pins: counting every `product_groups` row reported 51
         * products for `en` on the day all of its offers were withdrawn — a
         * market with nothing findable in it, described here as stocked.
         */
        $group = ProductGroup::factory()->create(['market' => Market::En->value]);

        foreach ([ProductStatus::Excluded, ProductStatus::Stale] as $status) {
            Product::create([
                'source' => Source::Bol->value,
                'external_id' => 'withdrawn-'.$status->value,
                'market' => Market::En->value,
                'group_id' => $group->id,
                'title' => 'Withdrawn offer',
                'price' => 4900,
                'affiliate_url' => 'https://example.test/withdrawn',
                'availability' => Availability::InStock->value,
                'status' => $status->value,
            ]);
        }

        $row = collect(app(MarketSupply::class)->rows())->firstWhere('market', Market::En);

        $this->assertSame(0, $row['groups']);
        $this->assertSame(0, $row['offers']);
    }

    #[Test]
    public function a_market_nothing_serves_is_reported_as_dark(): void
    {
        $supply = app(MarketSupply::class);

        // No feeds, no credentials: every market is dark.
        $this->assertSame(Market::values(), $supply->darkMarkets());

        $this->feed(Market::BeNl, enabled: true, ranAt: now()->toDateTimeString());
        $supply->forget();

        $this->assertNotContains(Market::BeNl->value, $supply->darkMarkets());
    }
}
