<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Wishlist\DefaultList;
use App\Services\Wishlist\ItemSaver;
use App\Services\Wishlist\PendingSave;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The save somebody pressed before they had an account.
 *
 * Keeping a list requires an account, and that is not what changed. What
 * changed is that the product in the visitor's hand at the moment they are
 * asked to sign in is no longer thrown away: the save control navigated to the
 * login page client-side, before any request reached the server, so Laravel
 * never recorded an intended URL — and signing in landed them on My Lists with
 * an empty list and no memory of what they had been looking at.
 */
class PendingSaveTest extends TestCase
{
    use RefreshDatabase;

    private function group(): ProductGroup
    {
        return ProductGroup::factory()->create(['market' => Market::BeNl]);
    }

    /** Sign in, without the events a real login fires being mocked away. */
    private function signIn(User $user): void
    {
        Auth::login($user);

        event(new Login('web', $user, false));
    }

    #[Test]
    public function a_pending_save_is_applied_when_the_visitor_signs_in(): void
    {
        $group = $this->group();

        $this->postJson('/be-nl/save-intent', [
            'group_id' => $group->id,
            'return_to' => "/be-nl/p/{$group->id}/thing",
        ])->assertOk();

        $user = User::factory()->create();
        $this->signIn($user);

        $item = WishlistItem::query()->where('group_id', $group->id)->first();

        $this->assertNotNull($item, 'the save the visitor pressed was lost');
        $this->assertSame($user->id, $item->wishlist->owner_user_id);

        // Named, because a save that happens silently during a sign-in is
        // indistinguishable from one that did not happen. By content, not by
        // wording: this market reads Dutch.
        $this->assertStringContainsString($item->wishlist->title, (string) session('success'));
    }

    /** So `redirect()->intended()` puts them back on the product, not on /lists. */
    #[Test]
    public function the_product_page_is_remembered_as_the_intended_url(): void
    {
        $group = $this->group();

        $this->postJson('/be-nl/save-intent', [
            'group_id' => $group->id,
            'return_to' => "/be-nl/p/{$group->id}/thing",
        ])->assertOk();

        $this->assertSame("/be-nl/p/{$group->id}/thing", session('url.intended'));
    }

    /**
     * An open redirect wearing a login page as a costume.
     *
     * `url.intended` is where a freshly authenticated person is sent, and the
     * value arrives from the caller. A protocol-relative `//host` is a URL, not
     * a path, and would hand somebody to another site with the trust of having
     * just signed in.
     */
    #[Test]
    public function an_offsite_return_url_is_refused(): void
    {
        foreach (['//evil.example/x', 'https://evil.example/x', '/\evil.example'] as $hostile) {
            session()->forget('url.intended');

            $this->postJson('/be-nl/save-intent', [
                'group_id' => $this->group()->id,
                'return_to' => $hostile,
            ])->assertOk();

            $this->assertNull(session('url.intended'), "accepted {$hostile}");
        }
    }

    #[Test]
    public function a_pending_save_is_used_once_and_not_again(): void
    {
        $group = $this->group();

        $this->postJson('/be-nl/save-intent', ['group_id' => $group->id])->assertOk();

        $first = User::factory()->create();
        $this->signIn($first);

        $this->assertSame(1, WishlistItem::query()->count());

        // A second sign-in in the same session must not replay it onto whoever
        // signs in next — which, on a shared machine, is somebody else.
        $second = User::factory()->create();
        $this->signIn($second);

        $this->assertSame(1, WishlistItem::query()->count());
    }

    /**
     * An hour.
     *
     * A save replayed days later — plausibly on a shared machine, plausibly by
     * somebody else — is not what anybody asked for, and a gift list is the
     * wrong place to be surprised by an item you did not put there.
     */
    #[Test]
    public function a_stale_pending_save_is_discarded(): void
    {
        $group = $this->group();

        $this->postJson('/be-nl/save-intent', ['group_id' => $group->id])->assertOk();

        $this->travel(2)->hours();

        $this->signIn(User::factory()->create());

        $this->assertSame(0, WishlistItem::query()->count());
    }

    /**
     * A group id means one thing in one market — `product_groups` is unique on
     * (market, identity_key), so the same product elsewhere is a different row
     * at a different price.
     */
    #[Test]
    public function an_intent_does_not_cross_markets(): void
    {
        $group = ProductGroup::factory()->create(['market' => Market::NlNl]);

        $this->postJson('/be-nl/save-intent', ['group_id' => $group->id])->assertOk();

        $this->signIn(User::factory()->create());

        $this->assertSame(0, WishlistItem::query()->count());
    }

    /**
     * A hand-written wish is typed on a list page, which is behind `auth`
     * already. Accepting one here would be a free-text channel with no owner.
     */
    #[Test]
    public function a_manual_item_cannot_be_smuggled_through_the_intent(): void
    {
        $this->postJson('/be-nl/save-intent', [
            'source' => 'manual',
            'title' => 'a nice scarf',
        ])->assertStatus(422);
    }

    #[Test]
    public function signing_in_with_nothing_waiting_does_nothing(): void
    {
        $this->signIn(User::factory()->create());

        $this->assertSame(0, WishlistItem::query()->count());
        $this->assertNull(session('success'));
    }

    /** Belt and braces: the service refuses a payload it cannot make sense of. */
    #[Test]
    public function a_corrupt_intent_is_dropped_rather_than_thrown(): void
    {
        session()->put('wishlist.pending_save', ['payload' => 'not an array']);

        $replayed = app(PendingSave::class)->replayFor(
            User::factory()->create(),
            app(ItemSaver::class),
            app(DefaultList::class),
        );

        $this->assertNull($replayed);
        $this->assertNull(session('wishlist.pending_save'));
    }
}
