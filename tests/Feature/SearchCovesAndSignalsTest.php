<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search page since 2026-09-26: the Coves a term matches above the
 * products, what other people keep under each card, and the props the Filters
 * button and its chips are drawn from. (The line above the results that
 * counted lists for the term went on 2026-10-25.)
 * See docs/features/search.md.
 *
 * The privacy rules held here: counts of different people, never lists;
 * nothing below `list_signals.min_owners`; claims never read (invariant 4);
 * a Community Cove found by its public title only.
 */
class SearchCovesAndSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['giftcoves.list_signals.min_owners' => 3]);
    }

    #[Test]
    public function the_coves_row_above_the_products_is_gone(): void
    {
        // Owner, 2026-11-07: "remove the coves hierover section".
        $this->cove('koffie-voor-thuis', 'Koffie voor thuis', CoveKind::Guide);

        $props = $this->props(['q' => 'koffie']);

        $this->assertArrayNotHasKey('coves', $props);
        $this->assertArrayNotHasKey('covesUrl', $props);
    }

    #[Test]
    public function each_card_says_how_many_coves_it_is_in(): void
    {
        $popular = $this->product('Koffiemolen handmatig');
        $rare = $this->product('Koffiemolen elektrisch');

        // Three people, one of them with two lists: three, which is the threshold.
        $many = User::factory()->create();
        $this->save($popular, $this->listOf($many));
        $this->save($popular, $this->listOf($many));
        $this->save($popular, $this->listOf(User::factory()->create()));
        $claimed = $this->save($popular, $this->listOf(User::factory()->create()));

        // Two people and one pending suggestion: under it.
        $this->save($rare, $this->listOf(User::factory()->create()));
        $this->save($rare, $this->listOf(User::factory()->create()));
        $this->save($rare, $this->listOf(User::factory()->create()), accepted: false);

        $this->pick($rare, $this->cove('koffie-gids', 'Een gids', CoveKind::Guide));

        $cards = collect($this->props(['q' => 'koffiemolen'])['results']['items'])->keyBy('id');

        // "Op de lijst van … mensen" left the card on 2026-11-07 (owner); the Coves stay.
        $this->assertNull($cards[$popular->id]['kept']);
        $this->assertSame('In 1 Cove', $cards[$rare->id]['kept']);

        // A claim changes nothing: being bought is not being wanted.
        Cache::flush();
        $claimed->forceFill(['claimed_by_hash' => str_repeat('b', 64), 'claimed_by_name' => 'Bob', 'claimed_at' => now()])->save();
        $cards = collect($this->props(['q' => 'koffiemolen'])['results']['items'])->keyBy('id');
        $this->assertNull($cards[$popular->id]['kept']);
    }

    #[Test]
    public function the_line_counting_lists_for_the_term_is_gone(): void
    {
        // Removed 2026-10-25 for what it cost on every new term. See
        // docs/features/search.md, "What other people keep".
        $this->assertArrayNotHasKey('keptSummary', $this->props(['q' => 'koffiemolen']));
    }

    #[Test]
    public function the_filter_button_and_its_chips_are_drawn_from_the_filters_and_the_facets(): void
    {
        $this->product('Koffiemolen handmatig', ['brand' => 'Hario']);

        $props = $this->props(['q' => 'koffiemolen', 'brand' => ['Hario'], 'max' => 50, 'discounted' => '1', 'for' => 'father']);

        $this->assertSame(['Hario'], $props['filters']['brand']);
        $this->assertEquals(50, $props['filters']['max']);
        $this->assertSame('1', $props['filters']['discounted']);
        $this->assertCount(1, $props['tagFilters']);
        $this->assertStringNotContainsString('for=father', $props['tagFilters'][0]['without']);
        $this->assertArrayHasKey('brands', $props['facets']);
        $this->assertArrayHasKey('merchants', $props['facets']);
    }

    /** @return array<string, mixed> */
    private function props(array $params): array
    {
        return $this->get('/be-nl/search?'.http_build_query($params))->assertOk()->viewData('page')['props'];
    }

    private function product(string $title, array $extra = []): ProductGroup
    {
        return ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => $title, ...$extra]);
    }

    private function cove(
        string $slug,
        string $title,
        CoveKind $kind,
        ?string $blurb = null,
        bool $published = true,
        Market $market = Market::BeNl,
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => $market,
            'kind' => $kind,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => $blurb,
            'drop_date' => $kind === CoveKind::Daily ? now()->toDateString() : null,
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subHour() : null,
        ]);
    }

    private function pick(ProductGroup $group, DailyPickSet $cove): void
    {
        DailyPick::query()->create(['set_id' => $cove->id, 'group_id' => $group->id, 'rank' => 1, 'slug' => $group->slug]);
    }

    private function communityCove(
        string $publicTitle,
        string $slug,
        int $products = 3,
        Market $market = Market::BeNl,
        string $privateTitle = 'Mijn lijstje',
    ): Wishlist {
        $list = Wishlist::create([
            'owner_user_id' => User::factory()->create()->id,
            'title' => $privateTitle,
            'market' => $market,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);

        foreach (range(1, $products) as $i) {
            $this->save(ProductGroup::factory()->create(['market' => $market]), $list);
        }

        $list->forceFill(['public_title' => $publicTitle, 'public_slug' => $slug, 'published_at' => now()->subDay()])->save();

        return $list;
    }

    private function save(ProductGroup $group, Wishlist $list, bool $accepted = true): WishlistItem
    {
        return WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'snapshot_title' => $group->title,
            'accepted_at' => $accepted ? now() : null,
        ]);
    }

    private function listOf(User $user): Wishlist
    {
        return Wishlist::create([
            'owner_user_id' => $user->id,
            'title' => 'Mine',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);
    }
}
