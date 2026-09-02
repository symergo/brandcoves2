<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\IngestFeed;
use App\Models\ConnectorSetting;
use App\Models\Feed;
use App\Models\Product;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\SourceSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-market source switch.
 *
 * Two things are worth holding down here and they pull in opposite directions.
 *
 * A switch that does not actually stop the source is the obvious failure, and it
 * has two paths to cover rather than one: live search goes through `supports()`,
 * and feed ingestion does not go near it — the scheduler dispatches straight
 * from `Feed::query()->enabled()`, so `IngestFeed` carries its own check and
 * needs its own test.
 *
 * The less obvious failure is a switch that stops *too much*. Switching a source
 * off must not retract the catalogue it already built: those rows are a
 * catalogue, not a cache, and `bc:withdraw-source` is the thing that removes
 * them. A switch that quietly emptied search would be indistinguishable from
 * data loss.
 */
class SourceSwitchTest extends TestCase
{
    use RefreshDatabase;

    private SourceSwitch $switch;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.ebay.enabled' => true,
            'giftcoves.connectors.ebay.client_id' => 'test-id',
            'giftcoves.connectors.ebay.client_secret' => 'test-secret',
            'giftcoves.connectors.ebay.marketplace' => [
                'be-nl' => 'EBAY_NL',
                'be-fr' => 'EBAY_FR',
                'nl-nl' => 'EBAY_NL',
                'en' => 'EBAY_NL',
                'es' => 'EBAY_ES',
            ],
        ]);

        Cache::flush();

        $this->switch = app(SourceSwitch::class);
    }

    #[Test]
    public function every_source_is_on_until_somebody_says_otherwise(): void
    {
        // A fresh install stores nothing at all. The default has to come from
        // the absence of a row, not from a seeded one, or a new market added to
        // the enum later would arrive switched off.
        $this->assertSame(0, ConnectorSetting::query()->where('key', 'markets')->count());

        foreach (Source::cases() as $source) {
            foreach (Market::cases() as $market) {
                $this->assertTrue(
                    $this->switch->isEnabled($source, $market),
                    "{$source->value} should default to on in {$market->value}",
                );
            }
        }
    }

    #[Test]
    public function switching_a_market_off_leaves_the_others_alone(): void
    {
        $this->switch->set(Source::Ebay, Market::Es, false);

        $this->assertFalse($this->switch->isEnabled(Source::Ebay, Market::Es));
        $this->assertTrue($this->switch->isEnabled(Source::Ebay, Market::NlNl));

        // And it is this source only. One row holds one source's map, so a bug
        // that keyed the map wrongly would show up as bol going dark too.
        $this->assertTrue($this->switch->isEnabled(Source::Bol, Market::Es));
    }

    #[Test]
    public function a_switched_off_market_stops_the_live_connector(): void
    {
        $connector = app(EbayConnector::class);

        // Configured, credentialled and mapped — so supports() is true for both
        // markets before the switch, and the switch is provably the only thing
        // that changes.
        $this->assertTrue($connector->supports(Market::Es));

        $this->switch->set(Source::Ebay, Market::Es, false);

        $this->assertFalse($connector->supports(Market::Es));
        $this->assertTrue($connector->supports(Market::NlNl));

        // search() gates on supports(), so a switched-off market must not even
        // reach the HTTP client. No Http::fake here on purpose: a request would
        // fail the test by trying to leave the machine.
        $this->assertSame([], $connector->search('koptelefoon', Market::Es));
    }

    #[Test]
    public function switching_back_on_removes_the_row_rather_than_storing_true(): void
    {
        $this->switch->set(Source::Ebay, Market::Es, false);
        $this->assertSame(1, ConnectorSetting::query()->where('source', 'ebay')->count());

        $this->switch->set(Source::Ebay, Market::Es, true);

        // Not "stores true": the table holds decisions that differ from the
        // default, so undoing the last one leaves nothing behind. A row saying
        // `true` would be indistinguishable from a deliberate override the day
        // the default changes.
        $this->assertSame(0, ConnectorSetting::query()->where('source', 'ebay')->count());
        $this->assertTrue($this->switch->isEnabled(Source::Ebay, Market::Es));
    }

    #[Test]
    public function ingestion_stops_for_a_switched_off_feed_source(): void
    {
        $feed = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => 'switch-test',
            'market' => Market::BeNl,
            'label' => 'Switchable feed',
            'enabled' => true,
            'column_map' => ['url' => base_path('tests/Fixtures/awin-mixed-barcode-columns.csv')],
        ]);

        $this->switch->set(Source::Awin, Market::BeNl, false);

        IngestFeed::dispatchSync($feed->id);

        // The job never consults supports(), so this is the check that actually
        // holds the scheduler back. Without it a switched-off source keeps
        // downloading on the usual timetable — the one thing switching it off
        // is for.
        $this->assertSame(0, Product::query()->count());
    }

    #[Test]
    public function ingestion_resumes_when_the_source_is_switched_back_on(): void
    {
        $feed = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => 'switch-test',
            'market' => Market::BeNl,
            'label' => 'Switchable feed',
            'enabled' => true,
            'column_map' => ['url' => base_path('tests/Fixtures/awin-mixed-barcode-columns.csv')],
        ]);

        $this->switch->set(Source::Awin, Market::BeNl, false);
        IngestFeed::dispatchSync($feed->id);
        $this->assertSame(0, Product::query()->count());

        $this->switch->set(Source::Awin, Market::BeNl, true);
        IngestFeed::dispatchSync($feed->id);

        // The pair matters more than either half: a guard that stopped the feed
        // permanently — by poisoning the cursor, say — would pass the test above
        // and still be a bug nobody could undo from the panel.
        $this->assertGreaterThan(0, Product::query()->count());
    }

    #[Test]
    public function switching_a_source_off_does_not_retract_what_it_already_stored(): void
    {
        $feed = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => 'switch-test',
            'market' => Market::BeNl,
            'label' => 'Switchable feed',
            'enabled' => true,
            'column_map' => ['url' => base_path('tests/Fixtures/awin-mixed-barcode-columns.csv')],
        ]);

        IngestFeed::dispatchSync($feed->id);
        $ingested = Product::query()->count();
        $this->assertGreaterThan(0, $ingested);

        $this->switch->set(Source::Awin, Market::BeNl, false);

        /*
         * The distinction the whole feature rests on.
         *
         * Off means "stop asking", not "undo". These rows are a catalogue, and
         * a switch that emptied them would be data loss wearing the clothes of a
         * settings change — with no undo, because re-ingesting is a download.
         * `bc:withdraw-source` is the deliberate, dry-run-by-default tool for
         * suppressing them, and the panel says so at the point of the click.
         */
        $this->assertSame($ingested, Product::query()->count());
    }

    #[Test]
    public function an_entry_for_a_market_that_no_longer_exists_is_ignored(): void
    {
        ConnectorSetting::create([
            'source' => Source::Ebay->value,
            'key' => 'markets',
            'encrypted_value' => ['es' => false, 'de-de' => false],
        ]);

        $this->switch->flush();

        $this->assertFalse($this->switch->isEnabled(Source::Ebay, Market::Es));

        // `de-de` addresses nothing. Dropped on read rather than migrated,
        // because a key no page can render is also a key no toggle can clear.
        $this->assertArrayNotHasKey('de-de', $this->switch->matrix()['ebay'] ?? []);
    }

    #[Test]
    public function disabled_markets_are_listed_for_the_panel(): void
    {
        $this->switch->set(Source::Ebay, Market::Es, false);
        $this->switch->set(Source::Ebay, Market::En, false);

        $this->assertSame(
            [Market::En->value, Market::Es->value],
            array_map(fn (Market $m): string => $m->value, $this->switch->disabledMarkets(Source::Ebay)),
        );
    }

    #[Test]
    public function set_all_switches_every_market_at_once(): void
    {
        $this->switch->setAll(Source::Bol, false);

        foreach (Market::cases() as $market) {
            $this->assertFalse($this->switch->isEnabled(Source::Bol, $market));
        }

        $this->switch->setAll(Source::Bol, true);

        $this->assertSame(0, ConnectorSetting::query()->where('source', 'bol')->count());
    }
}
