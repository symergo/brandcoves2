<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\Source;
use App\Filament\Resources\CommunityCoves\Pages\ListCommunityCoves;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\SavedCove;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Cove\CommunityCoves;
use App\Services\Gift\TasteBrief;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Community Coves: a list its owner published, for anybody to find, save and
 * copy. See docs/features/community-coves.md.
 *
 * The load-bearing tests are the ones about what does NOT reach the public
 * page: the recipient's name, notes, claims, the share token, the list's own
 * title and description, a hand-written item's link.
 */
class CommunityCoveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /**
     * A gift list about Emma, the owner's father, for his birthday: three
     * products (one claimed by Bob, each with a private note) and one
     * hand-written item with a link and a photo.
     */
    private function list(int $products = 3, string $market = 'be-nl'): Wishlist
    {
        $this->owner ??= User::factory()->create(['name' => 'Ann Peeters', 'email' => 'ann.peeters@example.com']);

        $recipient = Recipient::factory()->create([
            'owner_user_id' => $this->owner->id,
            'name' => 'Emma',
            'relationship' => 'father',
        ]);

        $list = Wishlist::factory()->forSomeone($recipient)->create([
            'owner_user_id' => $this->owner->id,
            'title' => "Emma's birthday",
            'description' => 'Private words about Emma',
            'market' => Market::from($market),
            'visibility' => ListVisibility::Link,
            'event_type' => EventType::Birthday,
        ]);

        foreach (range(1, $products) as $i) {
            $group = ProductGroup::factory()->create([
                'market' => Market::from($market),
                'title' => "Board game {$i}",
                'gift_tags' => ['interest:gaming'],
            ]);

            WishlistItem::factory()->of($group)->create([
                'wishlist_id' => $list->id,
                'note' => 'secret note '.$i,
                'claimed_by_hash' => $i === 1 ? str_repeat('a', 64) : null,
                'claimed_by_name' => $i === 1 ? 'Bob Claimer' : null,
                'claimed_at' => $i === 1 ? now() : null,
            ]);
        }

        WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'group_id' => null,
            'source' => Source::Manual->value,
            'snapshot_title' => 'A day out at the zoo',
            'snapshot_price' => 4500,
            'snapshot_url' => 'https://tiny-shop.test/zoo',
            'snapshot_image_url' => 'https://photos.test/my-living-room.jpg',
            'note' => 'hand-written secret',
            'accepted_at' => now(),
        ]);

        return $list->refresh();
    }

    private function publish(Wishlist $list, string $title = 'Board games for a dad', bool $showOwner = false): Wishlist
    {
        $this->actingAs($this->owner)
            ->post("/{$list->market->value}/lists/{$list->id}/publish", ['title' => $title, 'show_owner' => $showOwner])
            ->assertSessionHasNoErrors();

        Auth::logout();

        return $list->refresh();
    }

    /**
     * This page's own props as JSON, without the shared ones (translations,
     * the market), whose copy would match words like "claimed" on any page.
     */
    private function pageProps($response): string
    {
        $props = $response->viewData('page')['props'];

        return (string) json_encode(
            array_intersect_key($props, array_flip(['cove', 'items', 'save', 'reportUrl', 'indexUrl'])),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    #[Test]
    public function a_list_is_not_public_until_its_owner_publishes_it_and_sharing_does_not_publish(): void
    {
        $list = $this->list();

        // Shared by link, and still nowhere to find it.
        $this->assertNull($list->published_at);
        $this->assertNull($list->public_slug);
        $this->assertSame([], app(CommunityCoves::class)->newest(Market::BeNl, 12));
        $this->get('/be-nl/coves/community')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('coves', []));
    }

    #[Test]
    public function the_public_page_shows_the_products_and_nothing_private(): void
    {
        $list = $this->publish($this->list());

        $response = $this->get("/be-nl/coves/community/{$list->public_slug}")->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Coves/CommunityCove')
            ->where('cove.title', 'Board games for a dad')
            // In the market's language: be-nl reads Dutch.
            ->where('cove.about', ['Voor een papa', 'Verjaardag'])
            ->where('cove.by', null)
            ->has('items', 4));

        $props = $this->pageProps($response);

        foreach (['Emma', 'secret note', 'hand-written secret', 'Bob Claimer', 'claimed', 'Private words', $list->share_token, 'tiny-shop.test', 'my-living-room', 'Ann', 'ann.peeters', $list->id] as $private) {
            $this->assertStringNotContainsString($private, (string) $props, "The public page leaked: {$private}");
        }

        // The hand-written item is there by title and price, not by link.
        $this->assertStringContainsString('A day out at the zoo', (string) $props);
    }

    #[Test]
    public function the_owner_may_show_their_first_name_and_nothing_more(): void
    {
        $list = $this->publish($this->list(), showOwner: true);

        $response = $this->get("/be-nl/coves/community/{$list->public_slug}")->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('cove.by', 'Ann'));

        $props = $this->pageProps($response);
        $this->assertStringNotContainsString('Peeters', $props);
        $this->assertStringNotContainsString('ann.peeters', $props);
    }

    #[Test]
    public function the_owner_is_offered_a_title_that_does_not_name_the_recipient(): void
    {
        $list = $this->list();

        // "Emma's birthday" names her, so the suggestion is written from the
        // relationship instead, in the market's language.
        $this->actingAs($this->owner)->get("/be-nl/lists/{$list->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('publication.published', false)
                ->where('publication.title', 'Cadeau-ideeën voor een papa')
                ->where('publication.firstName', 'Ann'));

        // Nobody else is offered the switch.
        $this->actingAs(User::factory()->create())->get("/be-nl/lists/{$list->id}")->assertNotFound();
    }

    #[Test]
    public function a_title_that_names_the_recipient_or_carries_a_link_is_refused(): void
    {
        $list = $this->list();
        $this->owner->refresh();

        $this->actingAs($this->owner)
            ->post("/be-nl/lists/{$list->id}/publish", ['title' => "Emma's favourites"])
            ->assertSessionHasErrors('title');

        $this->actingAs($this->owner)
            ->post("/be-nl/lists/{$list->id}/publish", ['title' => 'Cheap at www.spam-shop.com'])
            ->assertSessionHasErrors('title');

        $this->assertNull($list->refresh()->published_at);
    }

    #[Test]
    public function a_list_with_too_few_things_cannot_be_published(): void
    {
        // One product and the hand-written item: two, under the minimum of three.
        $list = $this->list(products: 1);

        $this->actingAs($this->owner)
            ->post("/be-nl/lists/{$list->id}/publish", ['title' => 'Two things'])
            ->assertSessionHasErrors('title');

        $this->assertNull($list->refresh()->published_at);
    }

    #[Test]
    public function only_the_owner_may_publish(): void
    {
        $list = $this->list();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/be-nl/lists/{$list->id}/publish", ['title' => 'Mine now'])
            ->assertNotFound();

        $this->assertNull($list->refresh()->published_at);
    }

    #[Test]
    public function unpublishing_removes_it_at_once_and_republishing_keeps_the_address(): void
    {
        $list = $this->publish($this->list());
        $slug = $list->public_slug;

        $this->actingAs($this->owner)->delete("/be-nl/lists/{$list->id}/publish")->assertRedirect();
        Auth::logout();

        $this->get("/be-nl/coves/community/{$slug}")->assertStatus(410);
        $this->assertSame([], app(CommunityCoves::class)->newest(Market::BeNl, 12));

        $list = $this->publish($list->refresh(), 'A new title');
        $this->assertSame($slug, $list->public_slug);
        $this->get("/be-nl/coves/community/{$slug}")->assertOk();
    }

    #[Test]
    public function a_community_cove_lives_in_its_own_market_only(): void
    {
        $list = $this->publish($this->list());

        $this->get("/nl-nl/coves/community/{$list->public_slug}")->assertNotFound();
        $this->assertCount(1, app(CommunityCoves::class)->newest(Market::BeNl, 12));
        $this->assertSame([], app(CommunityCoves::class)->newest(Market::NlNl, 12));

        // And it appears on the all-Coves overview of its own market.
        $this->get('/be-nl/coves')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', fn ($sections) => collect($sections)->contains(fn ($s) => $s['key'] === 'community')));
    }

    #[Test]
    public function an_admin_can_hide_a_community_cove_and_the_owner_cannot_put_it_back(): void
    {
        $list = $this->publish($this->list());

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))
            ->test(ListCommunityCoves::class)
            ->callAction(TestAction::make('hide')->table($list));

        $this->assertNotNull($list->refresh()->public_hidden_at);
        $this->get("/be-nl/coves/community/{$list->public_slug}")->assertStatus(410);
        $this->assertSame([], app(CommunityCoves::class)->newest(Market::BeNl, 12));

        $this->actingAs($this->owner)
            ->post("/be-nl/lists/{$list->id}/publish", ['title' => 'Back again'])
            ->assertSessionHasErrors('title');
    }

    #[Test]
    public function the_gift_finder_suggests_coves_made_for_the_same_kind_of_person(): void
    {
        $list = $this->publish($this->list());
        $coves = app(CommunityCoves::class);

        $forDad = $coves->forBrief(new TasteBrief(market: Market::BeNl, relationship: 'father'));
        $this->assertCount(1, $forDad);
        $this->assertSame('Board games for a dad', $forDad[0]['title']);

        // An interest its products are tagged with is enough on its own.
        $this->assertCount(1, $coves->forBrief(new TasteBrief(market: Market::BeNl, interests: ['gaming'])));

        // Somebody else, with nothing in common, gets nothing.
        $this->assertSame([], $coves->forBrief(new TasteBrief(market: Market::BeNl, relationship: 'colleague', interests: ['coffee'])));

        // Another market's brief never reaches this market's Coves.
        $this->assertSame([], $coves->forBrief(new TasteBrief(market: Market::NlNl, relationship: 'father')));

        // The owner is not shown their own Cove.
        $this->assertSame([], $coves->forBrief(new TasteBrief(market: Market::BeNl, relationship: 'father'), $this->owner));

        // And the Gift Finder's results page carries them.
        $this->post('/be-nl/gift', ['relationship' => 'father', 'interests' => ['gaming']])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('communityCoves', 1));
    }

    #[Test]
    public function somebody_else_can_save_it_and_find_it_in_my_coves(): void
    {
        $list = $this->publish($this->list());
        $reader = User::factory()->create();

        $this->actingAs($reader)->post("/be-nl/coves/community/{$list->public_slug}/save")->assertRedirect();
        $this->actingAs($reader)->post("/be-nl/coves/community/{$list->public_slug}/save")->assertRedirect();

        $this->assertSame(1, SavedCove::query()->where('user_id', $reader->id)->where('wishlist_id', $list->id)->count());

        $this->actingAs($reader)->get('/be-nl/lists?view=saved')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('savedCoves', 1)
                ->where('savedCoves.0.kind', 'community')
                ->where('savedCoves.0.title', 'Board games for a dad'));

        // Unpublished, it drops out of the saved view without losing the bookmark.
        $this->actingAs($this->owner)->delete("/be-nl/lists/{$list->id}/publish");
        $this->actingAs($reader)->get('/be-nl/lists?view=saved')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('savedCoves', 0));
        $this->assertSame(1, SavedCove::query()->where('wishlist_id', $list->id)->count());
    }

    #[Test]
    public function the_owner_saving_their_own_cove_does_nothing(): void
    {
        $list = $this->publish($this->list());

        $this->actingAs($this->owner)->post("/be-nl/coves/community/{$list->public_slug}/save")->assertRedirect();

        $this->assertSame(0, SavedCove::query()->count());
    }

    #[Test]
    public function make_it_my_list_copies_only_what_the_public_sees(): void
    {
        $list = $this->publish($this->list());
        $reader = User::factory()->create();

        $this->actingAs($reader)->post("/be-nl/coves/community/{$list->public_slug}/copy")->assertRedirect();

        $copy = Wishlist::query()->where('owner_user_id', $reader->id)->where('title', 'Board games for a dad')->firstOrFail();
        $items = $copy->items()->get();

        $this->assertCount(4, $items);
        $this->assertTrue($items->every(fn (WishlistItem $item) => $item->note === null));
        $this->assertTrue($items->every(fn (WishlistItem $item) => $item->claimed_by_hash === null));

        $manual = $items->firstWhere('source', Source::Manual);
        $this->assertSame('A day out at the zoo', $manual->snapshot_title);
        $this->assertNull($manual->snapshot_url);
        $this->assertNull($manual->snapshot_image_url);

        // The original is untouched and knows nothing of the copy.
        $this->assertSame(4, $list->items()->count());
    }

    #[Test]
    public function a_guest_save_is_finished_after_sign_in(): void
    {
        $list = $this->publish($this->list());

        $this->postJson('/be-nl/save-intent', ['community_cove' => $list->public_slug, 'cove_action' => 'save'])->assertOk();

        $reader = User::factory()->create();
        Auth::login($reader);
        event(new Login('web', $reader, false));

        $this->assertTrue(SavedCove::query()->where('user_id', $reader->id)->where('wishlist_id', $list->id)->exists());
    }

    #[Test]
    public function a_new_community_cove_is_not_indexed_and_an_established_one_is(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $list = $this->publish($this->list());
        $coves = app(CommunityCoves::class);

        $html = (string) $this->get("/be-nl/coves/community/{$list->public_slug}")->getContent();
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex, follow"/', $html);
        $this->assertFalse($coves->isIndexable($list->loadCount('saves')));

        // Eight things, three savers, a week on the site.
        $more = $this->list(products: 7);
        $more->items()->update(['wishlist_id' => $list->id]);
        foreach (range(1, 3) as $ignored) {
            SavedCove::query()->create(['user_id' => User::factory()->create()->id, 'wishlist_id' => $list->id]);
        }
        $list->forceFill(['published_at' => now()->subDays(8)])->save();

        $fresh = $list->fresh(['items.group'])->loadCount('saves');
        $this->assertTrue($coves->isIndexable($fresh));

        $html = (string) $this->get("/be-nl/coves/community/{$list->public_slug}")->getContent();
        $this->assertDoesNotMatchRegularExpression('/<meta name="robots" content="noindex/', $html);

        $sitemap = (string) $this->get('/sitemap/be-nl/1.xml')->getContent();
        $this->assertStringContainsString("/be-nl/coves/community/{$list->public_slug}", $sitemap);
    }
}
