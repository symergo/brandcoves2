<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Feed;
use App\Models\PageBlock;
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

    /**
     * A guide, which is an edition since the fold.
     *
     * It travels in the editions surface with every other kind of Cove — the
     * guides surface only exists on the way *in*, for a v1 envelope exported
     * before the change.
     */
    private function guide(ProductGroup $product, string $slug = 'beste-koptelefoons'): DailyPickSet
    {
        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Guide,
            'slug' => $slug,
            'theme_title' => 'Beste koptelefoons',
            'theme_slug' => $slug,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $guide->picks()->create([
            'group_id' => $product->id,
            'rank' => 1,
            'slug' => $product->slug.'-'.$product->id,
        ]);

        return $guide;
    }

    #[Test]
    public function a_guide_survives_a_round_trip(): void
    {
        $product = $this->product('ean:1111111111111');

        $this->guide($product);

        $exported = $this->envelope()->export(['editions']);

        // The export must not contain the local id anywhere, or an import would
        // have something wrong to fall back on.
        $json = json_encode($exported);
        $this->assertStringContainsString('ean:1111111111111', (string) $json);

        $this->envelope()->import($exported, ['editions'], dryRun: false);

        $this->assertSame(1, DailyPickSet::where('slug', 'beste-koptelefoons')->count());
        $this->assertSame(
            $product->id,
            (int) DailyPickSet::where('slug', 'beste-koptelefoons')->first()->picks->first()->group_id,
        );
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

        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Guide,
            'theme_slug' => 'draadloze-oordopjes',
            'slug' => 'draadloze-oordopjes',
            'theme_title' => 'Draadloze oordopjes',
            'status' => 'published',
        ]);
        $guide->picks()->create(['group_id' => $source->id, 'rank' => 1, 'slug' => 'p1']);

        $exported = $this->envelope()->export(['editions']);

        // Rebuild the world so the identity lands on a different id, exactly as
        // a separately ingested environment would.
        DailyPickSet::query()->delete();
        ProductGroup::query()->delete();

        $this->product('ean:9999999999999');   // shifts the sequence along
        $this->product('ean:8888888888888');
        $target = $this->product('ean:2222222222222');

        $this->assertNotSame($source->id, $target->id, 'the ids must differ or this proves nothing');

        $this->envelope()->import($exported, ['editions'], dryRun: false);

        $item = DailyPickSet::where('slug', 'draadloze-oordopjes')->first()->picks->first();
        $this->assertSame($target->id, (int) $item->group_id);
    }

    #[Test]
    public function a_product_the_target_lacks_is_dropped_and_named(): void
    {
        $keep = $this->product('ean:3333333333333');
        $gone = $this->product('ean:4444444444444');

        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Guide,
            'theme_slug' => 'koffiemachines',
            'slug' => 'koffiemachines',
            'theme_title' => 'Koffiemachines',
            'status' => 'published',
        ]);
        $guide->picks()->create(['group_id' => $keep->id, 'rank' => 1, 'slug' => 'p1']);
        $guide->picks()->create(['group_id' => $gone->id, 'rank' => 2, 'slug' => 'p2']);

        $exported = $this->envelope()->export(['editions']);

        DailyPickSet::query()->delete();
        $gone->delete();

        $report = $this->envelope()->import($exported, ['editions'], dryRun: false);

        $items = DailyPickSet::where('slug', 'koffiemachines')->first()->picks;

        $this->assertCount(1, $items, 'the unmatched item must not be invented');
        $this->assertSame($keep->id, (int) $items->first()->group_id);

        // Named, not merely counted — a count tells you something went missing
        // without telling you what to go and look at.
        $this->assertCount(1, $report['editions']['dropped']);
        $this->assertStringContainsString('ean:4444444444444', $report['editions']['dropped'][0]);
    }

    #[Test]
    public function a_dry_run_writes_nothing_but_reports_what_it_would(): void
    {
        $product = $this->product('ean:5555555555555');

        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Guide,
            'slug' => 'stofzuigers',
            'theme_slug' => 'stofzuigers',
            'theme_title' => 'Stofzuigers',
            'published_at' => now(),
        ]);
        $guide->picks()->create(['group_id' => $product->id, 'rank' => 1, 'slug' => 'p1']);

        $exported = $this->envelope()->export(['editions']);
        DailyPickSet::query()->delete();

        $report = $this->envelope()->import($exported, ['editions'], dryRun: true);

        $this->assertSame(1, $report['editions']['created'], 'the report must describe the real work');
        $this->assertSame(0, DailyPickSet::count(), 'and none of it may survive the dry run');
    }

    #[Test]
    public function importing_twice_does_not_duplicate(): void
    {
        // The property most likely to be got wrong, and the one that turns a
        // promotion into two of every Cove.
        $product = $this->product('ean:6666666666666');

        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Guide,
            'theme_slug' => 'airfryers',
            'slug' => 'airfryers',
            'theme_title' => 'Airfryers',
            'status' => 'published',
        ]);
        $guide->picks()->create(['group_id' => $product->id, 'rank' => 1, 'slug' => 'p1']);

        $exported = $this->envelope()->export(['editions']);

        $this->envelope()->import($exported, ['editions'], dryRun: false);
        $this->envelope()->import($exported, ['editions'], dryRun: false);

        $this->assertSame(1, DailyPickSet::where('slug', 'airfryers')->count());
        $this->assertSame(1, DailyPickSet::where('slug', 'airfryers')->first()->picks->count());
    }

    #[Test]
    public function a_curated_plan_keeps_only_the_products_that_exist(): void
    {
        $keep = $this->product('ean:7777777777777');
        $gone = $this->product('ean:1212121212121');

        $plan = CovePlan::create([
            'market' => Market::BeNl,
            'drop_date' => '2026-09-01',
            'title' => 'Herfst',
            'status' => 'draft',
        ]);

        $plan->items()->create(['group_id' => $keep->id, 'rank' => 1, 'note' => 'lead with this']);
        $plan->items()->create(['group_id' => $gone->id, 'rank' => 2]);

        $exported = $this->envelope()->export(['plans']);

        CovePlan::query()->delete();
        $gone->delete();

        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $imported = CovePlan::where('drop_date', '2026-09-01')->first();

        // A curated product is a preference: losing one narrows the plan rather
        // than invalidating it.
        $this->assertSame([$keep->id], $imported->items->pluck('group_id')->all());

        // And the reason it was chosen travels with it. Without the note, the
        // far environment has the shortlist and not the brief, and the article
        // it builds is about the right products for no stated reason.
        $this->assertSame('lead with this', $imported->items->first()->note);
    }

    #[Test]
    public function a_persona_survives_a_second_import_as_one_row(): void
    {
        /*
         * A persona has no drop date, and `where('drop_date', null)` compiles
         * to `drop_date = NULL`, which matches nothing. Keyed on the date, every
         * import would therefore create another copy of every persona — and
         * nothing about a growing gift-ideas page looks like a bug.
         */
        $product = $this->product('ean:5555555555555');

        $plan = CovePlan::create([
            'market' => Market::BeNl,
            'kind' => 'persona',
            'slug' => 'de-kruidenliefhebber',
            'title' => 'De kruidenliefhebber',
            'status' => 'draft',
        ]);

        $plan->items()->create(['group_id' => $product->id, 'rank' => 1]);

        $exported = $this->envelope()->export(['plans']);

        $this->envelope()->import($exported, ['plans'], dryRun: false);
        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $this->assertSame(1, CovePlan::where('slug', 'de-kruidenliefhebber')->count());
        $this->assertSame(1, CovePlan::where('slug', 'de-kruidenliefhebber')->first()->items()->count());
    }

    #[Test]
    public function two_dateless_plans_in_one_market_do_not_collapse_onto_each_other(): void
    {
        /*
         * The regression for the bug the advice Coves exposed.
         *
         * The importer keyed anything that was not a persona on
         * `['market' => …, 'drop_date' => null]`. Laravel renders that null as
         * `drop_date IS NULL`, which does not match nothing — it matches *every
         * dateless row in the market*. So the second guide imported would find
         * the first, overwrite it, and report an update: one plan silently
         * replaced, one never created, and a growing archive quietly losing
         * pages.
         *
         * Five of the six kinds are dateless since the guide fold, so this
         * applied to guides, seasonals, advice articles and Shop Coves alike —
         * everything except the persona the original key happened to name.
         */
        $plans = collect(['guide', 'advice', 'seasonal'])->map(fn (string $kind, int $i) => CovePlan::create([
            'market' => Market::BeNl,
            'kind' => $kind,
            'slug' => "dateless-{$kind}",
            'title' => "A {$kind}",
            'status' => 'draft',
        ]));

        $exported = $this->envelope()->export(['plans']);

        // Wipe and re-import: the far environment starts without them.
        CovePlan::query()->delete();

        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $this->assertSame(3, CovePlan::query()->count(), 'each dateless plan should arrive as its own row');

        foreach ($plans as $original) {
            $landed = CovePlan::query()->where('slug', $original->slug)->first();

            $this->assertNotNull($landed, "{$original->slug} was overwritten by another plan");
            $this->assertSame($original->kind->value, $landed->kind->value);
            $this->assertSame($original->title, $landed->title);
        }

        // And a second run still updates in place rather than duplicating.
        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $this->assertSame(3, CovePlan::query()->count());
    }

    #[Test]
    public function importing_a_guide_does_not_overwrite_an_unrelated_plan_already_there(): void
    {
        /*
         * The same bug from the direction it was actually met: an environment
         * that already has editorial — as production does, and as any
         * environment that has run the advice-Cove seeding does — receiving an
         * envelope. The pre-existing plan must survive untouched.
         */
        $resident = CovePlan::create([
            'market' => Market::BeNl,
            'kind' => 'advice',
            'slug' => 'je-rechten-bij-een-online-aankoop',
            'title' => 'Je rechten bij een online aankoop',
            'status' => 'used',
        ]);

        $incoming = CovePlan::create([
            'market' => Market::BeNl,
            'kind' => 'guide',
            'slug' => 'beste-koptelefoons',
            'title' => 'De beste koptelefoons',
            'status' => 'approved',
        ]);

        $exported = $this->envelope()->export(['plans']);

        $incoming->delete();

        $this->envelope()->import($exported, ['plans'], dryRun: false);

        $resident->refresh();

        $this->assertSame('advice', $resident->kind->value);
        $this->assertSame('Je rechten bij een online aankoop', $resident->title);
        $this->assertSame('je-rechten-bij-een-online-aankoop', $resident->slug);

        $this->assertSame(1, CovePlan::query()->where('slug', 'beste-koptelefoons')->count());
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

        PageBlock::create([
            'page' => 'brand',
            'region' => 'above_grid',
            'language' => 'nl',
            'kind' => PageBlock::PARAGRAPH,
            'position' => 1,
            'conditions' => [],
            'enabled' => true,
            'author_id' => $user->id,
        ])->variants()->create([
            'body' => 'Een merk met karakter.',
            'weight' => 1,
            'enabled' => true,
            'author_id' => $user->id,
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
