<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Curation\CurationSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search a curator fills a Cove's shortlist from.
 *
 * The property that makes it worth having, and the one worth pinning: it
 * reaches the live merchants, not only what has already been ingested. A bol
 * product nobody has ever seen can be searched for, found, and added to a plan
 * in one request — because SearchService folds a mirrorable live offer into the
 * catalogue on the way past, so by the time the results render it is a real
 * group with a real id.
 */
class CurationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.bol.enabled' => true,
            'giftcoves.connectors.bol.client_id' => 'test-id',
            'giftcoves.connectors.bol.client_secret' => 'test-secret',
            'giftcoves.connectors.bol.partner_site_id' => ['BE' => '25421', 'NL' => '1005548'],
        ]);

        Cache::flush();

        // The rate limiter talks to Redis directly rather than through the
        // cache store, because sharing state across processes is its whole job.
        foreach (['search', 'product'] as $bucket) {
            Redis::del("bc:ratelimit:bol:{$bucket}", "bc:ratelimit:bol:{$bucket}:cooldown");
        }
    }

    #[Test]
    public function a_live_only_product_is_addable_in_the_same_request(): void
    {
        $this->fakeBol();

        $results = app(CurationSearch::class)->search($this->plan(), 'koptelefoon');

        $found = collect($results)->firstWhere('title', 'Sony WH-1000XM5 Koptelefoon');

        $this->assertNotNull($found, 'the live result never reached the curation screen');

        // A group id, not a source and an external id: bol may be mirrored, so
        // the fold made it an ordinary catalogue product with offers behind it.
        $this->assertNotNull($found->groupId);
        $this->assertNull($found->liveSource);
        $this->assertContains('bol', array_map(fn ($s) => $s->value, $found->sources));
    }

    #[Test]
    public function curating_never_writes_to_the_demand_signal(): void
    {
        /*
         * `search_log` decides which buying guides get written and what the
         * related-search chips on public pages say. An editor curating all
         * afternoon would otherwise manufacture demand nobody expressed — and
         * afterwards there is no way to tell the invented rows from the real
         * ones.
         */
        $this->fakeBol();

        app(CurationSearch::class)->search($this->plan(), 'koptelefoon');

        $this->assertSame(0, SearchLog::query()->count());
    }

    #[Test]
    public function a_dead_connector_leaves_the_catalogue_search_working(): void
    {
        // A live source that is down, unconfigured or cooling down after a 429
        // costs a curator some results, never the screen.
        Http::fake(['*' => Http::response([], 500)]);

        $results = app(CurationSearch::class)->search($this->plan(), 'koptelefoon');

        $this->assertSame([], $results);
    }

    #[Test]
    public function an_empty_term_asks_nobody(): void
    {
        // Opening the screen must not fire a request at every merchant.
        Http::fake();

        $this->assertSame([], app(CurationSearch::class)->search($this->plan(), '   '));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_product_already_on_another_plan_is_flagged_rather_than_hidden(): void
    {
        /*
         * The mistake a curator actually makes is not picking a bad product, it
         * is picking a good one twice — and the 90-day repeat memory that
         * catches this for the engine deliberately does not apply to a person's
         * choices, because overriding a score is the whole point of curating.
         *
         * Advisory, never a filter: two Coves a month apart may both want the
         * same kettle, and a screen that refused would be wrong more often than
         * it was right.
         */
        $product = $this->catalogueProduct('Kruidenpers');

        $other = CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->addDays(9)->toDateString(),
            'title' => 'Een andere dag',
            'status' => 'draft',
        ]);
        $other->items()->create(['group_id' => $product->id, 'rank' => 1]);

        Http::fake(['*' => Http::response([], 500)]);

        $results = app(CurationSearch::class)->search($this->plan(), 'kruidenpers');

        $found = collect($results)->firstWhere('groupId', $product->id);

        $this->assertNotNull($found, 'the conflicting product was hidden instead of flagged');
        $this->assertSame(
            'already on '.CarbonImmutable::today()->addDays(9)->format('j M'),
            $found->conflict,
        );
    }

    #[Test]
    public function a_budget_keeps_the_expensive_half_off_the_screen(): void
    {
        // A budget is the commonest constraint a curator works under, and
        // filtering in SQL beats scrolling past everything over it.
        $this->catalogueProduct('Goedkope pers', 2500);
        $this->catalogueProduct('Dure pers', 30000);

        Http::fake(['*' => Http::response([], 500)]);

        $titles = collect(app(CurationSearch::class)->search($this->plan(), 'pers', maxPrice: 10000))
            ->pluck('title');

        $this->assertContains('Goedkope pers', $titles);
        $this->assertNotContains('Dure pers', $titles);
    }

    private function catalogueProduct(string $title, int $price = 4500): ProductGroup
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
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

    private function plan(): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::today()->toDateString(),
            'title' => 'Gecureerd',
            'status' => 'draft',
        ]);
    }

    private function fakeBol(): void
    {
        Http::fake([
            'login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'api.bol.com/*' => Http::response(['results' => [[
                'bolProductId' => '9200000123456',
                'ean' => '4006381333931',
                'title' => 'Sony WH-1000XM5 Koptelefoon',
                'description' => 'Ruisonderdrukking',
                'url' => 'https://www.bol.com/nl/p/sony/9200000123456/',
                'image' => ['url' => 'https://media.bol.com/1.jpg'],
                'gpc' => [['level' => 'CHUNK', 'name' => 'Koptelefoon']],
                'offer' => ['price' => 329.99],
            ]]]),
        ]);
    }
}
