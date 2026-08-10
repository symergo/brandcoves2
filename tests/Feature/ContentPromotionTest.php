<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\CopyTemplate;
use App\Models\CovePlan;
use App\Models\Feed;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Content\ContentEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moving editorial between environments without moving people or ids.
 *
 * The whole design turns on one fact: `guide_items.group_id` and friends are
 * ids this environment assigned, and the other environment assigned different
 * ones to the same products. So the tests that matter are not "did the rows
 * arrive" but "did they arrive pointing at the right products, and did anything
 * personal come with them".
 */
class ContentPromotionTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(): ContentEnvelope
    {
        return app(ContentEnvelope::class);
    }

    private function product(string $identity, Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => $market,
            'identity_key' => $identity,
        ]);
    }

    #[Test]
    public function a_guide_survives_a_round_trip(): void
    {
        $product = $this->product('ean:1111111111111');

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $product->id, 'rank' => 1]);

        $exported = $this->envelope()->export(['guides']);

        // The export must not contain the local id anywhere, or an import would
        // have something wrong to fall back on.
        $json = json_encode($exported);
        $this->assertStringContainsString('ean:1111111111111', (string) $json);

        $this->envelope()->import($exported, ['guides'], dryRun: false);

        $this->assertSame(1, Guide::where('slug', 'beste-koptelefoons')->count());
        $this->assertSame($product->id, (int) Guide::where('slug', 'beste-koptelefoons')->first()->items->first()->group_id);
    }

    #[Test]
    public function a_product_reference_is_remapped_to_the_target_id(): void
    {
        /*
         * The bug this whole feature is built to avoid.
         *
         * Two environments assign different ids to the same product. Here the
         * source's guide points at id X; the target holds the same identity at a
         * different id. A verbatim copy would point at whatever the target
         * happens to have at X — a real product, a real price, and not the one
         * anybody chose.
         */
        $source = $this->product('ean:2222222222222');

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'draadloze-oordopjes',
            'title' => 'Draadloze oordopjes',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $source->id, 'rank' => 1]);

        $exported = $this->envelope()->export(['guides']);

        // Rebuild the world so the identity lands on a different id, exactly as
        // a separately ingested environment would.
        Guide::query()->delete();
        ProductGroup::query()->delete();

        $this->product('ean:9999999999999');   // shifts the sequence along
        $this->product('ean:8888888888888');
        $target = $this->product('ean:2222222222222');

        $this->assertNotSame($source->id, $target->id, 'the ids must differ or this proves nothing');

        $this->envelope()->import($exported, ['guides'], dryRun: false);

        $item = Guide::where('slug', 'draadloze-oordopjes')->first()->items->first();
        $this->assertSame($target->id, (int) $item->group_id);
    }

    #[Test]
    public function a_product_the_target_lacks_is_dropped_and_named(): void
    {
        $keep = $this->product('ean:3333333333333');
        $gone = $this->product('ean:4444444444444');

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'koffiemachines',
            'title' => 'Koffiemachines',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $keep->id, 'rank' => 1]);
        $guide->items()->create(['group_id' => $gone->id, 'rank' => 2]);

        $exported = $this->envelope()->export(['guides']);

        Guide::query()->delete();
        $gone->delete();

        $report = $this->envelope()->import($exported, ['guides'], dryRun: false);

        $items = Guide::where('slug', 'koffiemachines')->first()->items;

        $this->assertCount(1, $items, 'the unmatched item must not be invented');
        $this->assertSame($keep->id, (int) $items->first()->group_id);

        // Named, not merely counted — a count tells you something went missing
        // without telling you what to go and look at.
        $this->assertCount(1, $report['guides']['dropped']);
        $this->assertStringContainsString('ean:4444444444444', $report['guides']['dropped'][0]);
    }

    #[Test]
    public function a_dry_run_writes_nothing_but_reports_what_it_would(): void
    {
        $product = $this->product('ean:5555555555555');

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'stofzuigers',
            'title' => 'Stofzuigers',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $product->id, 'rank' => 1]);

        $exported = $this->envelope()->export(['guides']);
        Guide::query()->delete();

        $report = $this->envelope()->import($exported, ['guides'], dryRun: true);

        $this->assertSame(1, $report['guides']['created'], 'the report must describe the real work');
        $this->assertSame(0, Guide::count(), 'and none of it may survive the dry run');
    }

    #[Test]
    public function importing_twice_does_not_duplicate(): void
    {
        // The property most likely to be got wrong, and the one that turns a
        // promotion into two of every Cove.
        $product = $this->product('ean:6666666666666');

        $guide = Guide::create([
            'market' => Market::BeNl,
            'slug' => 'airfryers',
            'title' => 'Airfryers',
            'status' => 'published',
        ]);
        $guide->items()->create(['group_id' => $product->id, 'rank' => 1]);

        $exported = $this->envelope()->export(['guides']);

        $this->envelope()->import($exported, ['guides'], dryRun: false);
        $this->envelope()->import($exported, ['guides'], dryRun: false);

        $this->assertSame(1, Guide::where('slug', 'airfryers')->count());
        $this->assertSame(1, Guide::where('slug', 'airfryers')->first()->items->count());
    }

    #[Test]
    public function a_pinned_plan_keeps_only_the_products_that_exist(): void
    {
        $keep = $this->product('ean:7777777777777');
        $gone = $this->product('ean:1212121212121');

        CovePlan::create([
            'market' => Market::BeNl,
            'drop_date' => '2026-09-01',
            'title' => 'Herfst',
            'pinned_group_ids' => [$keep->id, $gone->id],
            'status' => 'draft',
        ]);

        $exported = $this->envelope()->export(['plans']);

        CovePlan::query()->delete();
        $gone->delete();

        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $plan = CovePlan::where('drop_date', '2026-09-01')->first();

        // A pin is a preference: losing one narrows the plan rather than
        // invalidating it.
        $this->assertSame([$keep->id], $plan->pinned_group_ids);
    }

    #[Test]
    public function nothing_personal_can_be_exported(): void
    {
        /*
         * The assertion that matters most.
         *
         * `bc:scrub` exists because these tables hold real emails and real notes
         * about real people's gifts. An exporter that reached them would move
         * that into a second live system, and the mistake would be invisible —
         * a bigger JSON file is not a symptom anybody notices.
         */
        $user = User::factory()->create(['email' => 'someone@example.test', 'name' => 'Real Person']);

        CopyTemplate::create([
            'surface' => 'brand_intro',
            'slot' => 'lede',
            'language' => 'nl',
            'body' => 'Een merk met karakter.',
            'author_id' => $user->id,
            'enabled' => true,
        ]);

        $json = (string) json_encode($this->envelope()->export(ContentEnvelope::SURFACES));

        $this->assertStringNotContainsString('someone@example.test', $json);
        $this->assertStringNotContainsString('Real Person', $json);
        // author_id is a user id and is meaningless on the far side anyway.
        $this->assertStringNotContainsString('author_id', $json);
    }

    #[Test]
    public function only_allowlisted_surfaces_can_travel(): void
    {
        // An allowlist, so a table added next month is excluded by default
        // rather than included by omission.
        $this->expectException(\InvalidArgumentException::class);

        $this->envelope()->export(['wishlists']);
    }

    #[Test]
    public function feeds_travel_registered_but_not_switched_on(): void
    {
        /*
         * Importing "enabled" would kick off hundreds of megabytes of feed
         * downloads as a side effect of a content promotion. Whether a feed runs
         * is a decision about this environment's bandwidth and spend.
         */
        Feed::create([
            'source' => 'awin',
            'external_feed_id' => '96638',
            'market' => Market::BeNl,
            'label' => 'Coolblue BE',
            'account' => 'default',
            'enabled' => true,
        ]);

        $exported = $this->envelope()->export(['feeds']);
        Feed::query()->delete();

        $this->envelope()->import($exported, ['feeds'], dryRun: false);

        $feed = Feed::where('external_feed_id', '96638')->first();

        $this->assertNotNull($feed);
        $this->assertFalse((bool) $feed->enabled, 'a promoted feed must not start ingesting on its own');
    }
}
