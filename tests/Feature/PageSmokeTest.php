<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page a visitor can reach, opened once.
 *
 * The recurring failure in this project has not been wrong logic. It has been
 * pages that render nothing: a component used but not imported, a helper called
 * with the wrong argument, a controller referring to a class an autofix had
 * removed from the imports. Each of those shipped green — every unit under them
 * was tested, and nothing opened the page.
 *
 * This does not check that a page is *correct*. It checks that it is a page.
 * That is a low bar, and it is the exact bar the last several regressions
 * failed to clear.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function publicPages(): array
    {
        $paths = [
            'home' => '',
            'search' => 'search',
            'search with a query' => 'search?q=koptelefoon',
            'brands' => 'brands',
            'guides' => 'guides',
            'daily' => 'daily',
            'surprise' => 'surprise',
            'scan' => 'scan',
            'gift wizard' => 'gift',
            'gift cove' => 'gift-cove',
            'lists' => 'lists',
            'santa' => 'santa',
            'login' => 'login',
            'about' => 'about',
            'privacy' => 'privacy',
            'terms' => 'terms',
        ];

        return array_map(
            fn (string $path) => ['/be-nl'.($path === '' ? '' : '/'.$path)],
            $paths,
        );
    }

    #[Test]
    #[DataProvider('publicPages')]
    public function a_visitor_can_open_it(string $path): void
    {
        $this->assertRenders($this->get($path)->status(), $path);
    }

    #[Test]
    #[DataProvider('publicPages')]
    public function a_signed_in_visitor_can_open_it(string $path): void
    {
        // A different tree: account menu, owner-only controls, list tools. It
        // caught `/login` returning 500 to somebody already signed in, which no
        // signed-out test could have reached.
        $this->assertRenders(
            $this->actingAs(User::factory()->create())->get($path)->status(),
            $path,
        );
    }

    /**
     * The page answered, rather than blowing up on the way out.
     *
     * Deliberately not `assertSuccessful()`. A redirect is an answer — a
     * signed-in visitor opening the login page belongs on the home page — and so
     * is a 404 from a surface whose content has not been generated yet: a fresh
     * environment has no daily edition until `bc:refresh-discovery` runs, and
     * saying "nothing today" is correct. A 5xx is never an answer, and that is
     * the entire failure mode this file exists for.
     */
    private function assertRenders(int $status, string $path): void
    {
        $this->assertLessThan(500, $status, "{$path} returned {$status}.");
    }

    #[Test]
    public function the_pages_that_hang_off_a_list_open_too(): void
    {
        $user = User::factory()->create();

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => 'link',
        ]);

        $forSomeone = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        // Both kinds, because they render different tools — and a gift list
        // going blank the moment it was created is one of the regressions this
        // exists to catch.
        $this->actingAs($user)->get("/be-nl/lists/{$mine->id}")->assertOk();
        $this->actingAs($user)->get("/be-nl/lists/{$forSomeone->id}")->assertOk();

        // And the shared view, which strangers reach from a link.
        $this->get("/be-nl/l/{$mine->share_token}")->assertOk();
    }

    #[Test]
    public function the_lists_page_opens_on_the_create_form_when_asked(): void
    {
        // The Gift Cove links here with an intent. A query string the page does
        // not understand must still be a page.
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/lists?new=for_someone')
            ->assertOk();
    }
}
