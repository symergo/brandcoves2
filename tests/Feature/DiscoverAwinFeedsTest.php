<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Source;
use App\Filament\Pages\DiscoverAwinFeeds;
use App\Models\Feed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Picking Awin feeds from a list, instead of typing names at a modal.
 *
 * The screen this replaced asked for advertiser names in a text box. Getting the
 * spelling wrong returned "nothing matched", which is indistinguishable from
 * "we are not joined to them" — so the only reliable way to find out what was on
 * offer was an SSH session.
 *
 * What has to hold now:
 *
 *  1. **The whole list is there**, including feeds no market can use and feeds
 *     already registered, because "why is this shop not on the site" is the
 *     question the screen exists to answer.
 *  2. **Search narrows it**, and survives Awin's spelling — "Vanden Borre BE"
 *     has to be findable by typing "vandenborre".
 *  3. **A feed lands in the market its region and language say**, never one a
 *     person picked. A Belgian feed carries Belgian prices, stock and delivery.
 *  4. **Awin is asked once**, not once per keystroke.
 */
class DiscoverAwinFeedsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('bc:awin-available-feeds');

        config()->set('giftcoves.connectors.awin.accounts', [
            'primary' => ['label' => 'Primary account', 'api_token' => 'token-one'],
        ]);
    }

    /** Awin's datafeed list is a CSV, and its column names are its own. */
    private function fakeAwin(array $rows): void
    {
        $header = 'Advertiser ID,Advertiser Name,Primary Region,Language,Membership Status,'
            .'Feed ID,Feed Name,Vertical,Currency,Last Imported,No of products';

        // Quoted, because Awin quotes: "4,000" products in a comma-separated
        // file is exactly the kind of thing a hand-rolled fixture gets wrong and
        // then proves the wrong behaviour with.
        $quote = fn (string $v): string => '"'.str_replace('"', '""', $v).'"';

        $lines = array_map(fn (array $r) => implode(',', array_map($quote, [
            $r['advertiserId'] ?? '1',
            $r['advertiser'],
            $r['region'],
            $r['language'],
            $r['status'] ?? 'active',
            $r['feedId'],
            $r['feedName'] ?? 'All products',
            $r['vertical'] ?? 'Electronics',
            $r['currency'] ?? 'EUR',
            $r['lastImported'] ?? '2026-08-30 04:12:00',
            $r['products'],
        ])), $rows);

        Http::fake([
            'productdata.awin.com/*' => Http::response(implode("\n", [$header, ...$lines])),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function sampleFeeds(): void
    {
        $this->fakeAwin([
            ['advertiser' => 'Vanden Borre BE', 'region' => 'BE', 'language' => 'dutch', 'feedId' => '111', 'products' => '4000'],
            ['advertiser' => 'Coolblue NL', 'region' => 'NL', 'language' => 'dutch', 'feedId' => '222', 'products' => '9000'],
            ['advertiser' => 'Boulanger FR', 'region' => 'FR', 'language' => 'french', 'feedId' => '333', 'products' => '7000'],
            ['advertiser' => 'Tiny Shop BE', 'region' => 'BE', 'language' => 'dutch', 'feedId' => '444', 'products' => '12'],
            ['advertiser' => 'Not Joined BE', 'region' => 'BE', 'language' => 'dutch', 'feedId' => '555', 'products' => '5000', 'status' => 'pending'],
        ]);
    }

    private function rows($component): array
    {
        return array_values(array_map(
            fn ($record) => $record['advertiser'],
            $component->instance()->getTableRecords()->all(),
        ));
    }

    #[Test]
    public function it_lists_what_awin_offers(): void
    {
        $this->sampleFeeds();

        $component = Livewire::actingAs($this->admin())->test(DiscoverAwinFeeds::class);

        $component->assertOk();

        $listed = $this->rows($component);

        $this->assertContains('Vanden Borre BE', $listed);
        $this->assertContains('Coolblue NL', $listed);

        // Not joined to us, so it is not ours to register — Awin lists every
        // advertiser on the network, not only the ones we are approved for.
        $this->assertNotContains('Not Joined BE', $listed);

        // Filtered out by the "at least 100 products" default, which is on
        // because a feed of twelve is not worth an hourly download.
        $this->assertNotContains('Tiny Shop BE', $listed);

        // And a French-region feed no market of ours can use is hidden by the
        // other default, rather than offered and then refused on registering.
        $this->assertNotContains('Boulanger FR', $listed);
    }

    #[Test]
    public function the_unserviceable_feeds_are_there_when_you_ask_for_them(): void
    {
        $this->sampleFeeds();

        $component = Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->set('tableFilters.serviceable.isActive', false)
            ->set('tableFilters.substantial.isActive', false);

        $listed = $this->rows($component);

        // "Why is this shop not on the site" is the question the screen exists
        // to answer, and for these two the answer is on the row.
        $this->assertContains('Boulanger FR', $listed);
        $this->assertContains('Tiny Shop BE', $listed);
    }

    /**
     * The row has to answer "should I register this one?" without leaving it.
     *
     * Size alone misleads: a huge feed from a shop that stopped publishing is a
     * large stale download, and a retailer's six category feeds all look
     * plausible until you can see which is which.
     */
    #[Test]
    public function a_row_carries_what_you_need_to_choose(): void
    {
        $this->fakeAwin([[
            'advertiser' => 'Vanden Borre BE',
            'advertiserId' => '4242',
            'region' => 'BE',
            'language' => 'dutch',
            'feedId' => '111',
            'feedName' => 'Full catalogue',
            'vertical' => 'Electronics',
            'currency' => 'EUR',
            'lastImported' => '2026-08-30 04:12:00',
            'products' => '4,000',
        ]]);

        $row = Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->instance()
            ->getTableRecords()
            ->first();

        $this->assertSame('Full catalogue', $row['feedName']);
        $this->assertSame('Electronics', $row['vertical']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('2026-08-30 04:12:00', $row['lastImported']);
        $this->assertSame('4242', $row['advertiserId']);
        $this->assertSame('111', $row['feed_id']);

        // "4,000" is four thousand, not four. Awin uses a comma on one account
        // and a full stop on another, and either one read naively turns a
        // substantial feed into one the minimum-products filter discards.
        $this->assertSame(4000, $row['products']);
    }

    #[Test]
    public function the_search_reaches_the_feed_name_and_the_sector(): void
    {
        $this->fakeAwin([
            ['advertiser' => 'Vanden Borre BE', 'region' => 'BE', 'language' => 'dutch', 'feedId' => '111', 'products' => '4000', 'feedName' => 'Garden furniture', 'vertical' => 'Home'],
            ['advertiser' => 'Coolblue NL', 'region' => 'NL', 'language' => 'dutch', 'feedId' => '222', 'products' => '9000', 'feedName' => 'All products', 'vertical' => 'Electronics'],
        ]);

        $component = Livewire::actingAs($this->admin())->test(DiscoverAwinFeeds::class);

        $component->set('tableSearch', 'garden');
        $this->assertSame(['Vanden Borre BE'], $this->rows($component));

        $component->set('tableSearch', 'electronics');
        $this->assertSame(['Coolblue NL'], $this->rows($component));

        // And the feed id, which is all somebody arriving from the feeds table
        // or a support thread has.
        $component->set('tableSearch', '222');
        $this->assertSame(['Coolblue NL'], $this->rows($component));
    }

    #[Test]
    public function searching_narrows_the_list(): void
    {
        $this->sampleFeeds();

        $component = Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->set('tableSearch', 'coolblue');

        $this->assertSame(['Coolblue NL'], $this->rows($component));
    }

    /**
     * Awin writes "Vanden Borre BE"; a person types "vandenborre".
     *
     * The exact spelling changes without warning, so matching on a stripped,
     * lowercased form is what makes the search feel like it works — the same
     * folding the allowlist has always done.
     */
    #[Test]
    public function the_search_survives_awins_spelling(): void
    {
        $this->sampleFeeds();

        $component = Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->set('tableSearch', 'vandenborre');

        $this->assertSame(['Vanden Borre BE'], $this->rows($component));
    }

    #[Test]
    public function a_feed_is_registered_into_the_market_its_region_and_language_say(): void
    {
        $this->sampleFeeds();

        Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->callTableBulkAction('register', ['primary:111', 'primary:222']);

        $belgian = Feed::query()->where('external_feed_id', '111')->firstOrFail();
        $dutch = Feed::query()->where('external_feed_id', '222')->firstOrFail();

        // The whole point. A Belgian-Dutch feed carries Belgian prices, stock
        // and delivery; serving it to nl-nl is the error market-scoped product
        // identity exists to prevent.
        $this->assertSame('be-nl', $belgian->market->value);
        $this->assertSame('nl-nl', $dutch->market->value);

        $this->assertSame('Vanden Borre BE', $belgian->label);
        // Which credentials to download it with. Without this the connector
        // uses the primary key and gets a 401.
        $this->assertSame('primary', $belgian->account);

        // Registered off: thirty feeds switched on at once is thirty concurrent
        // multi-hundred-megabyte downloads on the next scheduled run.
        $this->assertFalse($belgian->enabled);
    }

    #[Test]
    public function it_can_register_and_switch_on_in_one_go(): void
    {
        $this->sampleFeeds();

        Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->callTableBulkAction('registerAndEnable', ['primary:111']);

        $this->assertTrue(Feed::query()->where('external_feed_id', '111')->firstOrFail()->enabled);
    }

    #[Test]
    public function a_feed_no_market_can_use_is_not_registered(): void
    {
        $this->sampleFeeds();

        Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->set('tableFilters.serviceable.isActive', false)
            ->callTableBulkAction('register', ['primary:333']);

        // There is no honest market for a French-region feed here, so it is
        // refused rather than put somewhere plausible.
        $this->assertSame(0, Feed::query()->where('external_feed_id', '333')->count());
    }

    #[Test]
    public function an_already_registered_feed_says_so(): void
    {
        $this->sampleFeeds();

        Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => '111',
            'market' => 'be-nl',
            'label' => 'Vanden Borre BE',
            'enabled' => true,
        ]);

        $component = Livewire::actingAs($this->admin())->test(DiscoverAwinFeeds::class);

        $statuses = collect($component->instance()->getTableRecords()->all())
            ->mapWithKeys(fn ($r) => [$r['advertiser'] => $r['status']]);

        $this->assertSame('running', $statuses['Vanden Borre BE']);
        $this->assertSame('new', $statuses['Coolblue NL']);
    }

    /**
     * One request per account, however much searching happens.
     *
     * The table re-evaluates its data source on every search, sort and page, and
     * `available()` is an HTTP call per configured account. Uncached, typing a
     * shop name would be a request per keystroke.
     */
    #[Test]
    public function awin_is_asked_once_however_much_you_search(): void
    {
        $this->sampleFeeds();

        $component = Livewire::actingAs($this->admin())->test(DiscoverAwinFeeds::class);

        foreach (['coolb', 'coolbl', 'coolblue'] as $term) {
            $component->set('tableSearch', $term);
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function refreshing_asks_again(): void
    {
        $this->sampleFeeds();

        Livewire::actingAs($this->admin())
            ->test(DiscoverAwinFeeds::class)
            ->callAction('refresh');

        Http::assertSentCount(2);
    }

    /**
     * One unreachable account must not hide the others' feeds.
     *
     * A revoked key is a configuration problem, not a reason to leave every
     * other account's advertisers unregisterable — and the page says which
     * account did not answer, because otherwise the list is simply shorter than
     * it should be with nothing explaining why.
     */
    #[Test]
    public function a_dead_account_is_reported_rather_than_fatal(): void
    {
        config()->set('giftcoves.connectors.awin.accounts', [
            'primary' => ['label' => 'Primary account', 'api_token' => 'good'],
            'second' => ['label' => 'Second account', 'api_token' => 'revoked'],
        ]);

        Http::fake([
            'productdata.awin.com/datafeed/list/apikey/good/*' => Http::response(implode("\n", [
                'Advertiser ID,Advertiser Name,Primary Region,Language,Membership Status,Feed ID,No of products',
                '1,Vanden Borre BE,BE,dutch,active,111,4000',
            ])),
            'productdata.awin.com/*' => Http::response('nope', 401),
        ]);

        $component = Livewire::actingAs($this->admin())->test(DiscoverAwinFeeds::class);

        $component->assertOk();

        $this->assertContains('Vanden Borre BE', $this->rows($component));
        $this->assertNotEmpty($component->instance()->warnings);
        $this->assertStringContainsString('Second account', implode(' ', $component->instance()->warnings));
    }
}
