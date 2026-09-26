<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\AnonymousIdentity;
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
 * The product page's people and Coves (roadmap step 3). The rules held here
 * are the privacy ones: counts of people, never lists; nothing below the
 * threshold; claims never read.
 */
class ProductSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function saved_by_counts_people_and_stays_silent_below_the_threshold(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'min_price' => 4500]);

        // Four people, one of them with three lists holding it: four, not six.
        $many = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->save($group, $this->listOf($many));
        }
        foreach (range(1, 3) as $i) {
            $this->save($group, $this->listOf(User::factory()->create()));
        }

        $this->assertNull($this->signals($group)['savedBy'], 'Four people is under the threshold of five.');

        // An anonymous visitor's list is a real person's list.
        Cache::flush();
        $this->save($group, $this->anonymousList());

        $this->assertSame(5, $this->signals($group)['savedBy']);
    }

    #[Test]
    public function an_unaccepted_suggestion_does_not_count_and_a_claim_changes_nothing(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        foreach (range(1, 5) as $i) {
            $item = $this->save($group, $this->listOf(User::factory()->create()));
        }

        // Claimed or not, it is still wanted: the count must not move, or it
        // would tell somebody that something was bought.
        $item->forceFill(['claimed_by_hash' => 'x', 'claimed_at' => now()])->save();
        $this->assertSame(5, $this->signals($group)['savedBy']);

        Cache::flush();
        $this->save($group, $this->listOf(User::factory()->create()), accepted: false);
        $this->assertSame(5, $this->signals($group)['savedBy'], 'A pending suggestion is not a save.');
    }

    #[Test]
    public function found_in_counts_published_coves_in_this_market_only(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->pick($group, $this->cove('kerstcadeaus'));
        $this->pick($group, $this->cove('voor-koks'));
        $this->pick($group, $this->cove('nog-niet', published: false));
        $this->pick($group, $this->cove('in-nederland', market: Market::NlNl));

        $signals = $this->signals($group);

        $this->assertSame(2, $signals['coveCount']);
        $this->assertEqualsCanonicalizing(
            ['/be-nl/gift-ideas/kerstcadeaus', '/be-nl/gift-ideas/voor-koks'],
            array_column($signals['coves'], 'url'),
        );
    }

    #[Test]
    public function the_price_band_is_the_smallest_one_it_fits_under(): void
    {
        $this->assertSame(50, $this->signals(ProductGroup::factory()->create(['min_price' => 4999]))['band']['euros']);
        $this->assertSame(100, $this->signals(ProductGroup::factory()->create(['min_price' => 8999]))['band']['euros']);
        $this->assertNull($this->signals(ProductGroup::factory()->create(['min_price' => 45000]))['band']);
    }

    #[Test]
    public function the_product_page_carries_the_signals(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('signals.savedBy')->has('signals.coves')->has('signals.band'));
    }

    /** @return array<string, mixed> */
    private function signals(ProductGroup $group): array
    {
        return $this->get("/be-nl/p/{$group->id}/{$group->slug}")->viewData('page')['props']['signals'];
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

    private function anonymousList(): Wishlist
    {
        return Wishlist::create([
            'owner_anon_id' => AnonymousIdentity::factory()->create()->id,
            'title' => 'Mine',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);
    }

    private function cove(string $slug, bool $published = true, Market $market = Market::BeNl): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => $market,
            'kind' => CoveKind::Persona,
            'slug' => $slug,
            'theme_title' => ucfirst($slug),
            'theme_slug' => $slug,
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subHour() : null,
        ]);
    }

    private function pick(ProductGroup $group, DailyPickSet $cove): void
    {
        DailyPick::query()->create([
            'set_id' => $cove->id,
            'group_id' => $group->id,
            'rank' => 1,
            'slug' => $group->slug,
        ]);
    }
}
