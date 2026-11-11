<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The front page since 2026-09-26 (docs/features/homepage.md).
 *
 * What these hold in place is the promise the page makes, not its markup:
 * "Create a Cove" works for somebody with no account (owner's decision), and
 * every place the page and the header send people exists.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_a_cove_works_without_an_account(): void
    {
        // The growth loop's first step must not be a sign-in form.
        $this->get('/be-nl/lists?new=mine')->assertOk();
    }

    #[Test]
    public function the_front_page_sends_only_what_it_shows(): void
    {
        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        // No list wizard: it is where "Create a Cove" leads, so the front page
        // does not repeat the next page. Its props (recipients, friends,
        // occasions) cost queries for nothing if they are still sent.
        $this->assertArrayNotHasKey('recipients', $props);
        $this->assertArrayNotHasKey('myLists', $props);
        $this->assertArrayHasKey('today', $props);

        // Owner, 2026-09-29: the two lists of Coves became one card per kind.
        // Neither list's data is fetched any more.
        $this->assertArrayNotHasKey('coves', $props);
        $this->assertArrayNotHasKey('collected', $props);
    }

    #[Test]
    public function how_it_works_explains_the_whole_site_and_keeps_the_form(): void
    {
        /*
         * The header's "How it works" led to the list tools' manual for a few
         * hours on 2026-09-26: lists only, and no form. The owner called that
         * the support page, and it was missing search, what a Cove is, and a
         * way to report anything. It is /help now, which has all three and
         * links the manual as one of its guides.
         */
        $this->get('/nl-nl/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Help')
                ->where('guides', fn ($guides) => collect($guides)->pluck('key')->all() === ['search', 'lists', 'manual']));

        $this->get('/nl-nl/help')->assertSee('Wat is een Cove?')->assertSee('Iets mis, of een vraag?');
    }

    #[Test]
    public function every_place_the_page_and_the_header_send_people_exists(): void
    {
        foreach ([
            '/be-nl/coves',
            '/be-nl/gift',
            '/be-nl/gift-ideas',
            '/be-nl/discover-cove',
            '/be-nl/search',
            '/be-nl/surprise',
            '/be-nl/ask',
            '/be-nl/guides',
            '/be-nl/gift-cove/how-it-works',
            '/be-nl/lists',
            '/be-nl/coves/community',
            '/be-nl/brands',
        ] as $url) {
            $status = $this->get($url)->status();

            $this->assertContains($status, [200, 301, 302], "{$url} answered {$status}.");
        }
    }

    #[Test]
    public function signed_in_the_page_shows_your_own_coves_and_people(): void
    {
        // Owner, 2026-11-07. Only your own lists on this market, and the
        // people My people lists; a visitor gets nothing personal, which is
        // also what lets the anonymous page cache keep serving the page.
        $me = User::factory()->create();
        $other = User::factory()->create();

        Wishlist::factory()->create(['owner_user_id' => $me->id, 'kind' => ListKind::Mine, 'market' => Market::BeNl, 'title' => 'Mijn wensen']);
        Wishlist::factory()->create(['owner_user_id' => $me->id, 'kind' => ListKind::Mine, 'market' => Market::NlNl, 'title' => 'Elders']);
        Wishlist::factory()->create(['owner_user_id' => $other->id, 'kind' => ListKind::Mine, 'market' => Market::BeNl, 'title' => 'Niet van mij']);
        Recipient::query()->create(['owner_user_id' => $me->id, 'name' => 'Lien', 'relationship' => 'sibling']);

        $mine = $this->actingAs($me)->get('/be-nl')->assertOk()->viewData('page')['props']['mine'];

        $this->assertSame(['Mijn wensen'], array_column($mine['coves'], 'title'));
        $this->assertSame(['Lien'], array_column($mine['people'], 'name'));

        auth()->logout();
        $this->assertNull($this->get('/be-nl')->assertOk()->viewData('page')['props']['mine']);
    }
}
