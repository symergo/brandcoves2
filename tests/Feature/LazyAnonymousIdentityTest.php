<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\ListItemVote;
use App\Models\ListOpen;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shared list, Find a gift and /for make no anonymous identity on a read
 * (owner, 2026-09-27). Most views of them are link previews and people who
 * look and leave; the identity is made by the first write, and what the page
 * offers a guest does not change. See docs/features/speed.md, "Anonymous page
 * cache".
 */
class LazyAnonymousIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    private function groupList(): Wishlist
    {
        $organiser = User::factory()->create();

        return Wishlist::factory()->create([
            'owner_user_id' => $organiser->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $organiser->id, 'name' => 'Dad'])->id,
            'kind' => ListKind::Group,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
    }

    #[Test]
    public function a_guest_reading_a_shared_list_gets_no_identity_and_is_still_offered_everything(): void
    {
        $list = $this->groupList();

        $this->withHeader('User-Agent', self::BROWSER)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertCookieMissing(TrackAnonymousIdentity::COOKIE)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSuggest', true)
                ->where('canVote', true)
                ->where('canContribute', true)
                ->where('board.canPost', true)
                // Claims still need an account, exactly as before.
                ->where('canClaim', false)
                ->etc());

        $this->assertSame(0, AnonymousIdentity::query()->count());
        $this->assertSame([$list->id], session('list_opens_pending'));
    }

    #[Test]
    public function the_first_suggestion_makes_one_identity_and_keeps_the_list_findable(): void
    {
        // A wish list, where a guest's suggestion waits for the owner.
        $list = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->withHeader('User-Agent', self::BROWSER)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSuggest', true)
                // Claiming asks a guest to sign in, exactly as before.
                ->where('canClaim', false)
                ->where('claimNeedsAccount', true)
                ->etc());

        $this->assertSame(0, AnonymousIdentity::query()->count());

        // What the GET left in the session, carried to the POST as a browser would.
        $this->withHeader('User-Agent', self::BROWSER)
            ->withSession(['list_opens_pending' => [$list->id]])
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id])
            ->assertRedirect()
            ->assertCookie(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(1, AnonymousIdentity::query()->count());
        $identity = AnonymousIdentity::query()->sole();

        $this->assertSame(1, $list->suggestions()->count(), 'suggestion');
        $this->assertSame(1, ListOpen::query()->where('wishlist_id', $list->id)->where('anon_id', $identity->id)->count(), 'list open');
    }

    #[Test]
    public function the_first_vote_makes_one_identity(): void
    {
        $list = $this->groupList();
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->withHeader('User-Agent', self::BROWSER)
            ->post("/be-nl/l/{$list->share_token}/vote/{$item->id}")
            ->assertRedirect()
            ->assertCookie(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(1, AnonymousIdentity::query()->count());
        $this->assertSame(1, ListItemVote::query()->count());
    }

    #[Test]
    public function a_visitor_who_has_an_identity_is_still_recognised_on_the_page(): void
    {
        // An anonymous owner opening their own share link must be seen as the
        // owner, or they would be shown what was claimed (invariant 4).
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);
        $list = Wishlist::factory()->create([
            'owner_user_id' => null,
            'owner_anon_id' => $identity->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $this->withHeader('User-Agent', self::BROWSER)
            ->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isOwner', true)
                ->where('hideClaims', true)
                ->where('canSuggest', false)
                ->etc());

        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function a_crawler_is_offered_nothing_that_needs_an_identity(): void
    {
        $list = $this->groupList();

        $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0')
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSuggest', false)
                ->where('canVote', false)
                ->etc());

        $this->assertSame(0, AnonymousIdentity::query()->count());
        $this->assertNull(session('list_opens_pending'));
    }

    #[Test]
    public function find_a_gift_makes_no_identity_until_the_first_answer(): void
    {
        $this->withHeader('User-Agent', self::BROWSER)
            ->get('/be-nl/gift')
            ->assertOk()
            ->assertCookieMissing(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(0, AnonymousIdentity::query()->count());

        $this->withHeader('User-Agent', self::BROWSER)
            ->post('/be-nl/gift', ['interests' => ['cooking'], 'relationship' => 'father'])
            ->assertOk()
            ->assertCookie(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function the_for_page_makes_no_identity_until_they_describe_themselves(): void
    {
        $recipient = Recipient::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Mum',
        ]);

        foreach (["/be-nl/for/{$recipient->share_token}", "/be-nl/for/{$recipient->share_token}/suggest?q=koptelefoon"] as $url) {
            $this->withHeader('User-Agent', self::BROWSER)
                ->get($url)
                ->assertOk()
                ->assertCookieMissing(TrackAnonymousIdentity::COOKIE)
                ->assertInertia(fn (AssertableInertia $page) => $page->where('canSignInToClaim', true)->etc());
        }

        $this->assertSame(0, AnonymousIdentity::query()->count());

        $this->withHeader('User-Agent', self::BROWSER)
            ->post("/be-nl/for/{$recipient->share_token}", ['interests' => ['cooking']])
            ->assertRedirect()
            ->assertCookie(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function only_reads_of_the_named_pages_are_lazy(): void
    {
        // A route name that stopped existing would make the page quietly
        // create identities again.
        foreach (TrackAnonymousIdentity::LAZY_ROUTES as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} is not a route");
            $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$name} must be a read");
        }
    }
}
