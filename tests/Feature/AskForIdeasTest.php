<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Ask for ideas", chosen in the wizard for a list about somebody else
 * (owner's request, 2026-09-26). Both routes already worked; the wizard now
 * asks at the moment the list is made, and the new list's page shows the
 * links to send, once.
 */
class AskForIdeasTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function asking_the_person_and_others_shows_both_links_once(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'For Emma',
            'new_recipient' => 'Emma',
            'ask' => ['recipient', 'others'],
        ]);

        $list = Wishlist::query()->where('title', 'For Emma')->firstOrFail();

        $response->assertRedirect("/be-nl/lists/{$list->id}")
            ->assertSessionHas('ask_for_ideas', ['recipient', 'others']);

        $this->assertSame(ListKind::ForSomeone, $list->kind);
        // Asking other people needs a link to send them.
        $this->assertSame('link', $list->visibility->value);

        $this->actingAs($owner)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('flash.askForIdeas', ['recipient', 'others'])
                ->whereNot('target.askUrl', null)
                ->whereNot('list.shareUrl', null));

        // Once: the next visit is an ordinary one.
        $this->actingAs($owner)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page->where('flash.askForIdeas', null));
    }

    #[Test]
    public function asking_only_the_person_leaves_the_list_private(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'For Emma',
            'new_recipient' => 'Emma',
            'ask' => ['recipient'],
        ])->assertSessionHas('ask_for_ideas', ['recipient']);

        $this->assertSame('private', Wishlist::query()->where('title', 'For Emma')->firstOrFail()->visibility->value);
    }

    #[Test]
    public function your_own_wish_list_has_nobody_to_ask_on_your_behalf(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'Things I want',
            'ask' => ['recipient', 'others'],
        ])->assertSessionMissing('ask_for_ideas');
    }

    #[Test]
    public function anything_else_in_ask_is_refused(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'For Emma',
            'new_recipient' => 'Emma',
            'ask' => ['everyone'],
        ])->assertSessionHasErrors('ask.0');
    }
}
