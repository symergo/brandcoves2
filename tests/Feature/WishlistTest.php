<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lists, sharing and claiming.
 *
 * The rule the whole feature protects: a gift list exists so the owner does not
 * learn what has been bought. Everything else here is convenience; that is
 * correctness.
 */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $title = 'Sony WH-1000XM5'): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(4)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'sony-wh-1000xm5',
            'image_url' => 'https://img.test/1.jpg',
            'min_price' => 32999,
            'merchant_count' => 2,
            'in_stock' => true,
        ]);
    }

    private function user(string $email = 'owner@example.test'): User
    {
        return User::create(['email' => $email]);
    }

    #[Test]
    public function saving_a_product_without_an_account_creates_a_list(): void
    {
        $group = $this->group();

        // The common path: someone presses Save having never made a list and
        // having never signed up. Asking them to do either first loses them.
        $this->get('/be-nl');
        $this->post('/be-nl/list-items', ['group_id' => $group->id])->assertRedirect();

        $this->assertSame(1, Wishlist::query()->count());
        $item = WishlistItem::query()->firstOrFail();

        // A snapshot, not just a reference: the feed can drop or rename this
        // product tomorrow and the list must still show what was chosen.
        $this->assertSame('Sony WH-1000XM5', $item->snapshot_title);
        $this->assertSame(32999, $item->snapshot_price);
    }

    #[Test]
    public function saving_the_same_product_twice_does_not_duplicate_it(): void
    {
        $group = $this->group();

        // Signed in rather than anonymous: Laravel's test client does not carry
        // cookies between requests, so an anonymous visitor would get a fresh
        // identity per call and this would test nothing. The dedupe itself is
        // identity-independent.
        $this->actingAs($this->user());

        $this->post('/be-nl/list-items', ['group_id' => $group->id]);
        $this->post('/be-nl/list-items', ['group_id' => $group->id]);

        $this->assertSame(1, WishlistItem::query()->count());
        $this->assertSame(1, Wishlist::query()->count());
    }

    #[Test]
    public function you_cannot_see_someone_elses_list(): void
    {
        $other = Wishlist::create([
            'owner_user_id' => $this->user('other@example.test')->id,
            'title' => 'Private',
            'market' => Market::BeNl,
        ]);

        $this->actingAs($this->user())
            ->get("/be-nl/lists/{$other->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_private_list_is_not_reachable_by_its_share_token(): void
    {
        $list = Wishlist::create([
            'owner_user_id' => $this->user()->id,
            'title' => 'Private',
            'market' => Market::BeNl,
        ]);

        // Turning sharing off has to actually turn it off, even for someone who
        // already has the link.
        $this->get("/be-nl/l/{$list->share_token}")->assertNotFound();
    }

    #[Test]
    public function a_shared_list_is_visible_and_claimable(): void
    {
        [$list, $item] = $this->sharedGiftList();

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', false)
                ->where('items.0.claimed', false));

        $this->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")->assertRedirect();

        $this->assertNotNull($item->fresh()->claimed_by_hash);
    }

    #[Test]
    public function the_owner_never_sees_claim_state(): void
    {
        [$list, $item] = $this->sharedGiftList();
        $owner = $list->owner;

        // Someone claims it.
        $item->claim(WishlistItem::identityHash('anon:someone'));

        // The owner's private view carries no claim field at all.
        $private = $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk();

        $itemPayload = $private->viewData('page')['props']['items'][0];
        $this->assertArrayNotHasKey('claimed', $itemPayload);
        $this->assertArrayNotHasKey('claimedByMe', $itemPayload);

        // And the shared view, opened by the owner, nulls it rather than
        // showing it. A single leaked boolean defeats the whole feature.
        $this->actingAs($owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', true)
                ->where('items.0.claimed', null));
    }

    #[Test]
    public function an_anonymous_owner_never_sees_claim_state(): void
    {
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);

        $list = Wishlist::create([
            'owner_anon_id' => $identity->getKey(),
            'title' => 'Birthday',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'link',
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $this->group()->id,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
        ]);

        $item->claim(WishlistItem::identityHash('anon:someone'));

        /*
         * Lists work before signup, so the *common* owner is an anonymous one.
         * A check that only knows how to recognise a signed-in owner tells the
         * person who built the list exactly what has been bought for them —
         * invariant #4 failing in the ordinary case rather than an exotic one.
         */
        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', true)
                ->where('items.0.claimed', null));
    }

    #[Test]
    public function an_anonymous_owner_cannot_claim_on_their_own_list(): void
    {
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);

        $list = Wishlist::create([
            'owner_anon_id' => $identity->getKey(),
            'title' => 'Birthday',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'link',
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $this->group()->id,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
        ]);

        // The response itself would otherwise tell them whether it was taken.
        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertForbidden();
    }

    #[Test]
    public function a_list_for_someone_else_is_never_claimable(): void
    {
        $owner = $this->user();

        $recipient = Recipient::create([
            'owner_user_id' => $owner->id,
            'name' => 'Mum',
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'title' => 'Gifts for Mum',
            'market' => Market::BeNl,
            'kind' => ListKind::ForSomeone,
            'visibility' => 'link',
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $this->group()->id,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
        ]);

        /*
         * Sharing private research with a co-giver is coordination, not a
         * registry. Gating on visibility alone made every shared list
         * claimable, including one whose subject is a person who never asked
         * for any of it.
         */
        $this->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertForbidden();

        $this->assertNull($item->fresh()->claimed_by_hash);
    }

    #[Test]
    public function a_shared_list_names_its_owner_and_not_its_title(): void
    {
        $owner = User::create(['email' => 'ann@example.test', 'name' => 'Ann']);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            // The title an auto-created list gets. It is a label for a list,
            // never a name for a person.
            'title' => 'Saved items',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'link',
        ]);

        /*
         * The payload carried no owner at all, so the copy that names a person
         * fell back to the list title — and a visitor to somebody's own wishlist
         * was told that "Saved items" would not see who claimed what.
         */
        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('list.for', 'Ann'));
    }

    #[Test]
    public function a_list_owned_by_nobody_named_offers_no_name_at_all(): void
    {
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);

        $list = Wishlist::create([
            'owner_anon_id' => $identity->getKey(),
            'title' => 'Saved items',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'link',
        ]);

        // An anonymous owner genuinely has no name. Null, so the UI can say
        // "whoever made this list" rather than invent one.
        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('list.for', null));
    }

    #[Test]
    public function a_list_for_someone_else_names_the_recipient(): void
    {
        $owner = $this->user();

        $recipient = Recipient::create([
            'owner_user_id' => $owner->id,
            'name' => 'Mum',
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'title' => 'Ideas',
            'market' => Market::BeNl,
            'kind' => ListKind::ForSomeone,
            'visibility' => 'link',
        ]);

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page->where('list.for', 'Mum'));
    }

    #[Test]
    public function the_owner_cannot_claim_on_their_own_list(): void
    {
        [$list, $item] = $this->sharedGiftList();

        // Otherwise the response itself tells them whether it was already taken.
        $this->actingAs($list->owner)
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertForbidden();
    }

    #[Test]
    public function only_one_of_two_racing_claims_wins(): void
    {
        [$list, $item] = $this->sharedGiftList();

        $first = $item->fresh()->claim(WishlistItem::identityHash('anon:alice'));
        $second = $item->fresh()->claim(WishlistItem::identityHash('anon:bob'));

        // Two people tapping "I'll get this" at once is the expected case. If
        // both won, the recipient gets two of the same thing.
        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    #[Test]
    public function a_claimer_can_undo_their_own_claim_but_not_another(): void
    {
        [$list, $item] = $this->sharedGiftList();
        $item->claim(WishlistItem::identityHash('anon:alice'));

        $this->assertFalse($item->fresh()->release(WishlistItem::identityHash('anon:bob')));
        $this->assertTrue($item->fresh()->release(WishlistItem::identityHash('anon:alice')));
    }

    #[Test]
    public function claims_cannot_be_undone_after_the_window(): void
    {
        [$list, $item] = $this->sharedGiftList();
        $item->claim(WishlistItem::identityHash('anon:alice'));
        $item->forceFill(['claimed_at' => now()->subDays(3)])->save();

        // Otherwise someone could quietly release a claim weeks later and
        // nobody buys the thing.
        $this->assertFalse($item->fresh()->release(WishlistItem::identityHash('anon:alice')));
    }

    #[Test]
    public function a_share_link_works_from_any_market(): void
    {
        [$list] = $this->sharedGiftList();

        // Deliberate: a share link gets pasted into a message and opened by
        // someone whose browser resolves a different market. It must not 404
        // for them — the list is the same list wherever it is read.
        $this->get("/be-nl/l/{$list->share_token}")->assertOk();
        $this->get("/es/l/{$list->share_token}")->assertOk();
        $this->get("/nl-nl/l/{$list->share_token}")->assertOk();
    }

    #[Test]
    public function an_unknown_share_token_is_not_found(): void
    {
        $this->get('/be-nl/l/'.Str::uuid())->assertNotFound();
    }

    /** @return array{0: Wishlist, 1: WishlistItem} */
    private function sharedGiftList(): array
    {
        $list = Wishlist::create([
            'owner_user_id' => $this->user()->id,
            'title' => 'Birthday',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'link',
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $this->group()->id,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
        ]);

        return [$list, $item];
    }
}
