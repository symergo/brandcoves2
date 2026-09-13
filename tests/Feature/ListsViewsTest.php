<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The lists page's three views, by whom the lists are for (2026-09-13).
 *
 * "My wish lists" is my own lists of what I want and nothing else. "For
 * others" is everything about giving: my lists about a person, and the wish
 * lists and gift lists others shared with me. "Group lists" is one present
 * bought together, mine and the ones I was let into.
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

    /** @return list<string> */
    private function titles(User $user, string $view = ''): array
    {
        return array_column(
            $this->actingAs($user)->get('/be-nl/lists'.$view)->assertOk()->viewData('page')['props']['lists'],
            'title',
        );
    }

    #[Test]
    public function each_view_holds_the_lists_it_is_named_for(): void
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

        $mine = $this->titles($me);
        $this->assertContains('My wishes', $mine);
        foreach (['Ideas for Dad', 'A bike for Sam', 'What Anna wants', 'Ideas for Anna and mum', 'A trip for Anna'] as $title) {
            $this->assertNotContains($title, $mine, "$title does not belong under my wish lists");
        }

        $others = $this->titles($me, '?view=shared');
        foreach (['Ideas for Dad', 'What Anna wants', 'Ideas for Anna and mum'] as $title) {
            $this->assertContains($title, $others, "$title belongs under for others");
        }
        $this->assertNotContains('My wishes', $others);
        $this->assertNotContains('A trip for Anna', $others, 'a group list shared with me belongs under group lists');

        $group = $this->titles($me, '?view=group');
        $this->assertContains('A bike for Sam', $group);
        $this->assertContains('A trip for Anna', $group);
        $this->assertNotContains('My wishes', $group);
    }
}
