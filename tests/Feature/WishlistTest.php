<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClaimVisibility;
use App\Enums\CollaboratorRole;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\ListOpen;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
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

    /**
     * A list about a third person, with one item on it.
     *
     * Takes the kind and the visibility because the three questions this file
     * now asks — may you claim, who sees it, under what name — all turn on
     * those two and nothing else.
     *
     * @return array{Wishlist, WishlistItem}
     */
    private function giftListForSomeone(
        ListKind $kind = ListKind::ForSomeone,
        ListVisibility $visibility = ListVisibility::Link,
    ): array {
        // A distinct owner per call: this helper is used inside a loop over the
        // three kinds, and `user()` defaults to one address.
        $owner = $this->user('owner-'.bin2hex(random_bytes(4)).'@example.test');

        $recipient = Recipient::create([
            'owner_user_id' => $owner->id,
            'name' => 'Mum',
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            // A `mine` list is about its owner, and a group list is refused by
            // `wishlists_group_has_recipient` without one.
            'recipient_id' => $kind === ListKind::Mine ? null : $recipient->id,
            'title' => 'Gifts for Mum',
            'market' => Market::BeNl,
            'kind' => $kind,
            'visibility' => $visibility,
        ]);

        $item = WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $this->group()->id,
            'snapshot_title' => 'Sony WH-1000XM5',
            'snapshot_price' => 32999,
        ]);

        return [$list, $item];
    }

    #[Test]
    public function opening_a_shared_list_puts_it_under_shared_lists(): void
    {
        /*
         * Phase 2's open half, and what forced it: sharing is a link now, so
         * `ListAccess::scope()` can no longer lean on invitations to populate
         * Shared Lists. Without this, following a link recorded nothing and the
         * list vanished the moment the message did — which was already true for
         * anybody sent a link rather than an invitation, i.e. most people.
         */
        [$list] = $this->giftListForSomeone();
        $reader = $this->user('reader-'.bin2hex(random_bytes(4)).'@example.test');

        // Asserted on this list's id, not on a count: opening `/lists` mints
        // the default "My wishlist" for anybody signed in, so the interesting
        // number is never zero and a count would be measuring that instead.
        $before = $this->actingAs($reader)->get('/be-nl/lists')->assertOk()
            ->viewData('page')['props']['lists'];

        $this->assertNotContains($list->id, array_column($before, 'id'));

        $this->actingAs($reader)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $after = $this->actingAs($reader)->get('/be-nl/lists')->assertOk()
            ->viewData('page')['props']['lists'];

        $this->assertContains($list->id, array_column($after, 'id'));
    }

    #[Test]
    public function opening_it_again_does_not_add_a_second_row(): void
    {
        // The write is an upsert on one unique index. A reader refreshing must
        // not accumulate rows — and the index is `NULLS NOT DISTINCT` across
        // all three columns precisely because Postgres cannot infer
        // `ON CONFLICT` from a partial one, which 500'd every shared list.
        [$list] = $this->giftListForSomeone();
        $reader = $this->user('reader-'.bin2hex(random_bytes(4)).'@example.test');

        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($reader)->get("/be-nl/l/{$list->share_token}")->assertOk();
        }

        $this->assertSame(1, ListOpen::query()->count());
    }

    #[Test]
    public function the_owner_opening_their_own_link_does_not_bookmark_it(): void
    {
        // Otherwise their own list turns up in their own Shared Lists.
        [$list] = $this->giftListForSomeone();

        $this->actingAs($list->owner)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertSame(0, ListOpen::query()->count());
    }

    #[Test]
    public function a_note_on_a_list_survives_the_next_setting_pressed(): void
    {
        /*
         * `description` was validated `['nullable', 'string', 'max:2000']` with
         * no `sometimes`, so a missing key validated as null and `update()`
         * wrote the null. Every setting on this endpoint sends one key — so the
         * first switch pressed after writing a note erased it, silently, on the
         * same request that saved something else.
         *
         * Invisible until the note could be written at all, which is why it
         * survived: nothing in the UI set `description` before 2026-09-01.
         */
        [$list] = $this->giftListForSomeone();

        $this->actingAs($list->owner)
            ->patch("/be-nl/lists/{$list->id}", ['description' => 'Bring it Friday.'])
            ->assertRedirect();

        $this->actingAs($list->owner)
            ->patch("/be-nl/lists/{$list->id}", ['claim_visibility' => 'named'])
            ->assertRedirect();

        $this->assertSame('Bring it Friday.', $list->fresh()->description);
    }

    #[Test]
    public function the_owner_decides_whether_the_link_can_add(): void
    {
        /*
         * It used to follow from the kind. Whether you want additions turns on
         * how well you know the people holding the link, and the kind cannot
         * tell a family gift list from a wish list sent to forty colleagues.
         *
         * Off is the approval queue, not a refusal — `items()` filters on
         * `accepted_at`, so a pending row is on nobody's page until the owner
         * accepts it.
         */
        // The helper seeds one item, so the counts below are deltas from one.
        [$list] = $this->giftListForSomeone();
        $seeded = $list->items()->count();

        $this->assertTrue($list->linkCanAdd(), 'A gift list takes additions by default.');

        $list->update(['link_can_add' => false]);

        $this->actingAs($this->user('helper-'.bin2hex(random_bytes(4)).'@example.test'))
            ->post("/be-nl/l/{$list->share_token}/suggest", [
                'group_id' => $this->group()->id,
            ])
            ->assertRedirect();

        $this->assertSame($seeded, $list->items()->count(), 'It waits.');
        $this->assertSame($seeded + 1, $list->allItems()->count(), 'But it exists.');
    }

    #[Test]
    public function saving_a_product_without_an_account_is_refused(): void
    {
        $group = $this->group();

        /*
         * Keeping a list requires an account.
         *
         * The reverse of how this started, and deliberately: a list belonging
         * to a cookie cannot be reached from a second device, does not survive
         * clearing the browser, and has no address a reminder could be sent to.
         * It looked like a feature and behaved like a draft.
         */
        $this->get('/be-nl');
        $this->post('/be-nl/list-items', ['group_id' => $group->id])
            ->assertRedirect('/be-nl/login');

        $this->assertSame(0, Wishlist::query()->count());
        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function signing_in_makes_the_same_save_work(): void
    {
        $group = $this->group();

        $this->actingAs($this->user())
            ->post('/be-nl/list-items', ['group_id' => $group->id])
            ->assertRedirect();

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
    public function a_claimer_can_say_they_have_bought_it(): void
    {
        [$list, $item] = $this->sharedGiftList();

        // A signed-in visitor, so the same identity carries across all three
        // requests. An anonymous one is issued a fresh cookie per test request,
        // which would make this measure the harness rather than the feature.
        $claimer = User::factory()->create();

        $this->actingAs($claimer)
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertRedirect();

        $this->actingAs($claimer)
            ->post("/be-nl/l/{$list->share_token}/sent/{$item->id}")
            ->assertSessionHas('success');

        /*
         * Claiming used to be a dead end in the interface: you said you would
         * get it and then had nowhere to say you had. Both the endpoint and the
         * flag existed — only the button was missing — so the progress strip
         * could never finish.
         */
        $this->actingAs($claimer)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page->where('items.0.sent', true));
    }

    #[Test]
    public function the_progress_strip_counts_for_visitors_and_is_absent_for_the_owner(): void
    {
        [$list, $item] = $this->sharedGiftList();

        WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $item->claim(WishlistItem::identityHash('anon:someone'));

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page
                ->where('progress.claimed', 1)
                ->where('progress.total', 2));

        /*
         * Absent for the owner, not zero. A count is claim state: the moment a
         * zero stops being zero they have learnt something, which is the whole
         * thing a gift list exists to prevent.
         */
        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page->where('progress', null));
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
                ->missing('items.0.claimed'));
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
                ->missing('items.0.claimed'));
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
    public function a_shared_list_for_someone_else_is_claimable(): void
    {
        /*
         * This test used to be `a_list_for_someone_else_is_never_claimable`,
         * and reversing it is the point rather than an accident.
         *
         * The old rule read "sharing private research with a co-giver is
         * coordination, not a registry" — correct about what the list is, and
         * it drew the wrong conclusion. Coordination is *exactly* what claiming
         * does: it stops two siblings buying the same thing. The kind was doing
         * two jobs, and refusing to claim was the wrong half.
         *
         * What has NOT changed is the rule underneath it. Gating claiming on
         * visibility alone was the original bug, and it stays fixed: a `group`
         * list is shared and still not claimable, because there is one present
         * and nothing to divide.
         */
        [$list, $item] = $this->giftListForSomeone();

        $this->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->claimed_by_hash);
    }

    #[Test]
    public function a_group_list_is_shared_and_still_not_claimable(): void
    {
        // One present, bought by everybody. There is nothing to claim, and
        // saying "I will get this" about a candidate the group has not chosen
        // is not a thing anybody means. Pledges are the mechanism here.
        [$list, $item] = $this->giftListForSomeone(ListKind::Group);

        $this->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertForbidden();

        $this->assertNull($item->fresh()->claimed_by_hash);
    }

    // --- Claiming needs somebody to coordinate with --------------------------

    #[Test]
    public function a_private_list_of_any_kind_offers_no_claiming(): void
    {
        /*
         * Most lists are private, of every kind: a gift list about somebody is
         * usually solo research, and the default wish list every account gets
         * is simply where a bookmark lands. Claiming exists to stop two people
         * buying the same thing, so it is noise until there is a second person
         * — and a claim-privacy setting about an audience of one is worse than
         * noise, because it implies readers who do not exist.
         *
         * Asserted on the model rather than through the endpoint on purpose.
         * `findShared()` 404s a private list, so the route cannot reach this
         * question at all; the page reads `allowsClaiming()` to decide what
         * controls to draw, and that is what has to be false.
         */
        foreach ([ListKind::Mine, ListKind::ForSomeone, ListKind::Group] as $kind) {
            [$list] = $this->giftListForSomeone($kind, ListVisibility::Private);

            $this->assertFalse(
                $list->allowsClaiming(),
                "A private {$kind->value} list should offer no claiming.",
            );
        }
    }

    #[Test]
    public function sharing_a_gift_list_turns_claiming_on(): void
    {
        [$list] = $this->giftListForSomeone(ListKind::ForSomeone, ListVisibility::Private);

        $this->assertFalse($list->allowsClaiming());

        $list->update(['visibility' => ListVisibility::Link]);

        $this->assertTrue($list->fresh()->allowsClaiming());
    }

    #[Test]
    public function inviting_a_co_giver_turns_claiming_on_without_sharing_a_link(): void
    {
        /*
         * The other half of "is anybody else on this list". An invited
         * collaborator is a second person just as surely as a pasted link is,
         * and the owner's own page has to offer the controls to them.
         */
        [$list] = $this->giftListForSomeone(ListKind::ForSomeone, ListVisibility::Private);

        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => $this->user('cogiver@example.test')->id,
            'role' => CollaboratorRole::Editor,
        ]);

        $this->assertTrue($list->fresh()->allowsClaiming());
    }

    // --- Who sees a claim, and under whose name -----------------------------

    #[Test]
    public function the_owner_of_a_gift_list_sees_claim_state(): void
    {
        /*
         * The inversion, and the reason the whole feature works.
         *
         * On a `mine` list the owner IS the person being surprised, so
         * invariant #4 hides everything from them. On a list about somebody
         * else the recipient is a third party who never opens the page — there
         * is no surprise to protect *from the owner*, and the owner is the
         * person organising the buying. Hiding it from them leaves the one
         * person co-ordinating as the only one who cannot see what is covered.
         *
         * Exactly the inversion `ListKind::ownerSeesContributions()` already
         * makes for money on a group list.
         */
        [$list, $item] = $this->giftListForSomeone();

        $item->claim(WishlistItem::identityHash('anon:a-sibling'));

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', true)
                ->where('items.0.claimed', true));
    }

    #[Test]
    public function a_wish_list_owner_still_never_sees_claim_state(): void
    {
        /*
         * Invariant #4, asserted beside the inversion rather than only in its
         * own test far away. These two are one decision, and the way it breaks
         * is somebody widening the gift-list branch by one kind.
         */
        [$list, $item] = $this->giftListForSomeone(ListKind::Mine);

        $item->claim(WishlistItem::identityHash('anon:a-friend'));

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', true)
                ->missing('items.0.claimed'));
    }

    #[Test]
    public function a_wish_list_owner_can_ask_to_see_claims_and_only_then_does(): void
    {
        /*
         * Invariant #4 became a **default** on 2026-08-29 rather than an
         * absolute: hidden unless the owner has explicitly asked otherwise.
         *
         * The half that matters is the assertion above this one — nothing
         * *infers* it. Not sharing, not inviting somebody, not putting an
         * occasion on the list. Only this press, stored as a boolean that
         * starts null, which is why `ownerSeesClaims()` distinguishes "never
         * asked" from "said no".
         */
        [$list, $item] = $this->giftListForSomeone(ListKind::Mine);

        $item->claim(WishlistItem::identityHash('anon:a-friend'));

        $this->assertFalse($list->ownerSeesClaims(), 'A wish list hides by default.');

        $list->update(['owner_sees_claims' => true]);

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isOwner', true)
                ->where('items.0.claimed', true));
    }

    #[Test]
    public function what_the_owner_sees_and_what_the_others_see_are_two_settings(): void
    {
        /*
         * They were one three-valued enum, in which "hide claims from me" sat
         * among options otherwise about *names* — so the third meant something
         * different per kind of list, and this combination could not be
         * expressed at all: **show me the claims, and let the others see each
         * other's names.**
         */
        [$list, $item] = $this->giftListForSomeone(ListKind::Mine);

        $list->update([
            'owner_sees_claims' => true,
            'claim_visibility' => ClaimVisibility::Named,
        ]);

        $item->claim(WishlistItem::identityHash('anon:anna'), 'Anna');

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.claimed', true)
                ->where('items.0.claimedBy', 'Anna'));
    }

    #[Test]
    public function an_owner_who_asks_to_be_kept_out_is_kept_out(): void
    {
        /*
         * The opt-out exists for one real case: a list about "the family" that
         * the owner might end up receiving from. They give up the coordinating
         * view deliberately, and everybody else keeps it.
         */
        [$list, $item] = $this->giftListForSomeone();
        $list->update(['owner_sees_claims' => false]);

        $item->claim(WishlistItem::identityHash('anon:a-sibling'));

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('items.0.claimed'));

        // And a visitor still coordinates normally: the setting is about the
        // owner alone, not about turning the mechanism off.
        auth()->logout();

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.claimed', true));
    }

    #[Test]
    public function a_claim_carries_a_name_only_when_the_list_asks_for_one(): void
    {
        /*
         * A name shown to other people is a consent decision, so it is stored
         * only when the list was in `named` mode at the moment of the claim —
         * never backfilled by a later change of setting, because nobody
         * consented to it then.
         */
        [$anon, $anonItem] = $this->giftListForSomeone();

        $this->post("/be-nl/l/{$anon->share_token}/claim/{$anonItem->id}", [
            'display_name' => 'Anna',
        ])->assertRedirect();

        $this->assertNull(
            $anonItem->fresh()->claimed_by_name,
            'An anonymous list must discard a name even when one is posted.',
        );

        [$named, $namedItem] = $this->giftListForSomeone();
        $named->update(['claim_visibility' => ClaimVisibility::Named]);

        $this->post("/be-nl/l/{$named->share_token}/claim/{$namedItem->id}", [
            'display_name' => 'Anna',
        ])->assertRedirect();

        $this->assertSame('Anna', $namedItem->fresh()->claimed_by_name);
    }

    #[Test]
    public function switching_a_list_to_named_does_not_name_the_claims_already_on_it(): void
    {
        [$list, $item] = $this->giftListForSomeone();

        $item->claim(WishlistItem::identityHash('anon:a-sibling'));

        $list->update(['claim_visibility' => ClaimVisibility::Named]);

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.claimed', true)
                ->where('items.0.claimedBy', null));
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
    /**
     * A visitor keeping something for themselves is not a claim.
     *
     * `Lists/Shared` now carries a save control, which means the group id is in
     * a payload strangers read. It discloses nothing new — `url` in the same
     * payload has always been `p/{group_id}/{slug}` — and the act it enables
     * reads the *viewer's* lists and writes to the viewer's list. This pins
     * both halves: the owner's view is unchanged, and the visitor's save leaves
     * no trace on the list they found it on.
     */
    #[Test]
    public function a_visitor_saving_from_a_shared_list_tells_the_owner_nothing(): void
    {
        [$list, $item] = $this->sharedGiftList();
        $owner = $list->owner;

        $visitor = User::factory()->create();

        $this->actingAs($visitor)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.groupId', $item->group_id));

        $this->actingAs($visitor)
            ->postJson('/be-nl/list-items', ['group_id' => $item->group_id])
            ->assertOk();

        // Their copy is their own, on a list of theirs.
        $theirs = WishlistItem::query()
            ->where('group_id', $item->group_id)
            ->whereKeyNot($item->id)
            ->firstOrFail();

        $this->assertSame($visitor->id, $theirs->wishlist->owner_user_id);

        // The owner's list is untouched, and still says nothing about claims.
        $this->assertSame(1, $list->items()->count());

        $payload = $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->viewData('page')['props']['items'][0];

        $this->assertArrayNotHasKey('claimed', $payload);
        $this->assertNull($list->items()->first()->claimed_by_hash);
    }

    /** Nothing about the group id reveals who is reading the list. */
    #[Test]
    public function the_owner_still_gets_no_progress_after_the_save_control_landed(): void
    {
        [$list] = $this->sharedGiftList();

        $this->actingAs($list->owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page->where('progress', null));
    }

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
