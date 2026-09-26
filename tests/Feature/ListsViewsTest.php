<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\Recipient;
use App\Models\SavedCove;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * My Coves: one page, four sections (2026-09-26).
 *
 * Wish lists (my own lists of what I want), For others (my lists about a
 * person, and the wish lists and gift lists others shared with me), Give
 * together (group lists, mine and the ones I was let into) and Saved (Coves I
 * bookmarked). From 2026-09-13 these were separate `?view=` views and the
 * plain page showed only the first, so somebody with lists of two kinds saw
 * half of them and thought the rest had gone. `?view=` now only says which
 * section to scroll to.
 */
class ListsViewsTest extends TestCase
{
    use RefreshDatabase;

    private function list(User $owner, ListKind $kind, string $title): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => $kind,
            'market' => Market::BeNl,
            'title' => $title,
            'visibility' => ListVisibility::Link,
            // A list about somebody names them; the schema insists for a group.
            'recipient_id' => $kind === ListKind::Mine
                ? null
                : Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/be-nl/lists'.$query)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, list<string>> section => titles */
    private function sections(array $props): array
    {
        $sections = [];

        foreach ($props['lists'] as $row) {
            $sections[$row['section']][] = $row['title'];
        }

        return $sections;
    }

    /** A user with lists of every kind, three shared with them, and a saved Cove. */
    private function everything(): User
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();

        $this->list($me, ListKind::Mine, 'My wishes');
        $this->list($me, ListKind::ForSomeone, 'Ideas for Dad');
        $this->list($me, ListKind::Group, 'A bike for Sam');

        // Opened by me, which is what puts a shared list within my reach.
        foreach ([
            $this->list($friend, ListKind::Mine, 'What Anna wants'),
            $this->list($friend, ListKind::ForSomeone, 'Ideas for Anna and mum'),
            $this->list($friend, ListKind::Group, 'A trip for Anna'),
        ] as $shared) {
            $this->actingAs($me)->get("/be-nl/l/{$shared->share_token}")->assertOk();
        }

        $cove = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => '2026-08-08',
            'theme_title' => 'Rond de tafel',
            'theme_blurb' => 'Voor avonden zonder scherm.',
            'theme_slug' => 'theme-board-games',
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);
        SavedCove::create(['user_id' => $me->id, 'set_id' => $cove->id]);

        return $me;
    }

    #[Test]
    public function the_plain_page_holds_every_section_and_each_list_once(): void
    {
        $props = $this->props($this->everything());
        $sections = $this->sections($props);

        // The default list is created on the first visit, so it is here too.
        $this->assertContains('My wishes', $sections['mine']);
        $this->assertEqualsCanonicalizing(['Ideas for Dad', 'Ideas for Anna and mum', 'What Anna wants'], $sections['shared']);
        $this->assertEqualsCanonicalizing(['A bike for Sam', 'A trip for Anna'], $sections['group']);

        // Every row in exactly one section.
        $ids = array_column($props['lists'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));

        $this->assertCount(1, $props['savedCoves']);
        $this->assertSame('Rond de tafel', $props['savedCoves'][0]['title']);
        $this->assertNull($props['view']);
    }

    #[Test]
    public function for_others_lists_my_own_gift_lists_first(): void
    {
        $sections = $this->sections($this->props($this->everything()));

        $this->assertSame('Ideas for Dad', $sections['shared'][0]);
    }

    #[Test]
    public function a_section_with_nothing_in_it_sends_nothing(): void
    {
        // Only the default list: no rows for the other sections, and no
        // saved Coves, so the page draws one section and no empty headings.
        $props = $this->props(User::factory()->create());

        $this->assertSame(['mine'], array_keys($this->sections($props)));
        $this->assertSame([], $props['savedCoves']);
    }

    #[Test]
    public function every_view_link_still_lands_on_the_whole_page(): void
    {
        $me = $this->everything();
        $whole = array_column($this->props($me)['lists'], 'id');

        foreach (['mine', 'shared', 'group', 'saved'] as $view) {
            $props = $this->props($me, "?view={$view}");

            $this->assertSame($view, $props['view'], "?view={$view} names the section to scroll to");
            $this->assertEqualsCanonicalizing($whole, array_column($props['lists'], 'id'), "?view={$view} hides nothing");
            $this->assertCount(1, $props['savedCoves']);
        }

        // An unknown value scrolls nowhere rather than failing.
        $this->assertNull($this->props($me, '?view=nonsense')['view']);
    }

    #[Test]
    public function the_page_leaks_no_claim_on_my_own_wish_list(): void
    {
        // Invariant 4: a wish list hides what was bought from its owner by
        // default. Putting every section on one page must not change that.
        $me = User::factory()->create();
        $list = $this->list($me, ListKind::Mine, 'My wishes');
        $hash = hash('sha256', 'the-buyer');
        WishlistItem::factory()->claimedBy($hash)->create(['wishlist_id' => $list->id]);

        $response = $this->actingAs($me)->get('/be-nl/lists')->assertOk();

        $this->assertStringNotContainsString($hash, $response->getContent());

        $row = collect($response->viewData('page')['props']['lists'])->firstWhere('id', $list->id);
        $this->assertSame('mine', $row['section']);
        $this->assertFalse($row['ownerSeesClaims']);
        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('claimed', $key, "the index row carries {$key}");
        }
    }
}
