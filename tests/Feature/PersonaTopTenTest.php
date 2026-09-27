<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\Source;
use App\Jobs\RefreshPersonaTopLists;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\PersonaTopList;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Cove\EditionBuilder;
use App\Services\Gift\PersonaTopTen;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * A persona's top 10 of the week (docs/features/persona-top-ten.md): the
 * engine's pool for the persona, ranked by charts and by wish lists from the
 * privacy floor up, stored per week as ids only, and shown at the end of the
 * persona page.
 */
class PersonaTopTenTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday: the list is for the Monday before it.
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00'));
    }

    #[Test]
    public function the_list_is_ranked_by_wish_lists_and_charts_and_leaves_out_the_curated_shelf(): void
    {
        $this->forbidAi();
        $curated = $this->persona();

        $pool = collect(range(1, 8))->map(fn (int $i) => $this->giftable("Koksmes {$i}", 2000 + $i, ['interest:cooking'], 'Keuken'));

        $wished = $pool[5];
        $charted = $pool[6];
        $underFloor = $pool[7];

        $this->wish($wished, 5);
        $this->chart($charted, 1);
        // Two lists is under the floor: it must not lift the product at all.
        $this->wish($underFloor, 2);

        $result = (new RefreshPersonaTopLists(Market::BeNl))->handle(app(PersonaTopTen::class));

        $this->assertSame(['personas' => 1, 'lists' => 1], $result);

        $row = PersonaTopList::query()->sole();
        $this->assertSame('2026-09-28', $row->week->toDateString());
        $this->assertSame([$wished->id, $charted->id], array_slice($row->group_ids, 0, 2));
        $this->assertSame([], array_intersect($curated->pluck('id')->all(), $row->group_ids));

        $props = $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->viewData('page')['props'];

        $this->assertSame('2026-09-28', $props['topTen']['week']);
        $this->assertSame([1, 2], array_slice(array_column($props['topTen']['items'], 'rank'), 0, 2));
        $this->assertSame($wished->id, $props['topTen']['items'][0]['groupId']);
    }

    #[Test]
    public function too_few_products_make_no_list(): void
    {
        $this->forbidAi();
        $this->persona();

        foreach (range(1, 5) as $i) {
            $this->giftable("Koksmes {$i}", 2000, ['interest:cooking'], 'Keuken');
        }

        (new RefreshPersonaTopLists(Market::BeNl))->handle(app(PersonaTopTen::class));

        $this->assertSame(0, PersonaTopList::query()->count());
        $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->assertInertia(fn ($page) => $page->where('topTen', null));
    }

    #[Test]
    public function one_brand_takes_at_most_two_places(): void
    {
        $this->forbidAi();
        $this->persona();

        foreach (range(1, 5) as $i) {
            $knife = $this->giftable("Merkmes {$i}", 2000, ['interest:cooking'], 'Keuken');
            $knife->update(['brand' => 'Zwilling']);
            $this->chart($knife, $i);
        }
        foreach (range(1, 6) as $i) {
            $this->giftable("Pan {$i}", 2000, ['interest:cooking'], 'Keuken');
        }

        (new RefreshPersonaTopLists(Market::BeNl))->handle(app(PersonaTopTen::class));

        $brands = ProductGroup::query()->whereIn('id', PersonaTopList::query()->sole()->group_ids)->pluck('brand');

        $this->assertSame(2, $brands->filter(fn ($b) => $b === 'Zwilling')->count());
    }

    #[Test]
    public function a_product_gone_since_monday_drops_out_and_an_old_list_is_not_shown(): void
    {
        $this->forbidAi();
        $this->persona();

        $pool = collect(range(1, 8))->map(fn (int $i) => $this->giftable("Koksmes {$i}", 2000, ['interest:cooking'], 'Keuken'));

        (new RefreshPersonaTopLists(Market::BeNl))->handle(app(PersonaTopTen::class));

        $pool[0]->update(['in_stock' => false]);

        $items = $this->get('/be-nl/gift-ideas/de-thuiskok')->viewData('page')['props']['topTen']['items'];
        $this->assertNotContains($pool[0]->id, array_column($items, 'groupId'));
        $this->assertSame(range(1, count($items)), array_column($items, 'rank'));

        // Three weeks without a refresh: the job stopped, and "this week"
        // over that list would be untrue.
        $this->travel(21)->days();

        $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->assertInertia(fn ($page) => $page->where('topTen', null));
    }

    /** A published cooking persona with three hand-picked products. */
    private function persona()
    {
        $curated = collect(range(1, 3))->map(fn (int $i) => $this->giftable("Kookboek {$i}", 2000, ['interest:cooking'], 'Keuken'));

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-thuiskok',
            'title' => 'De thuiskok',
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Voor wie het liefst in de keuken staat.',
            'pick_mode' => PickMode::Locked->value,
            'brief' => ['interests' => ['cooking']],
        ]);

        foreach ($curated as $rank => $group) {
            $plan->items()->create(['group_id' => $group->id, 'rank' => $rank + 1]);
        }

        $this->assertInstanceOf(DailyPickSet::class, app(EditionBuilder::class)->buildPersona($plan));

        return $curated;
    }

    private function wish(ProductGroup $group, int $lists): void
    {
        for ($i = 0; $i < $lists; $i++) {
            $anon = (string) Str::uuid();
            DB::table('anonymous_identities')->insert(['id' => $anon, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            $list = Wishlist::create(['owner_anon_id' => $anon, 'title' => "Lijst {$i}", 'market' => Market::BeNl->value]);
            WishlistItem::create(['wishlist_id' => $list->id, 'group_id' => $group->id, 'snapshot_title' => $group->title]);
        }
    }

    private function chart(ProductGroup $group, int $rank): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'group_id' => $group->id,
            'rank' => $rank,
            'captured_on' => now()->toDateString(),
            'captured_at' => now(),
        ]);
    }
}
