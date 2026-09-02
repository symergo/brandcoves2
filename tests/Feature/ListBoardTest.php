<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\ListMessage;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The discussion beside a shared list.
 *
 * Most of this file is about one question: who may read it. A board is free
 * text written by the people doing the buying, and "I've got the scarf, someone
 * take the boots" is claim state in prose — so it hangs off the claim gate, and
 * the row that matters is the owner of a wish list, who must not see it.
 *
 * The rest is the part that is easy to get wrong twice: the endpoint enforces
 * the same rule the page does, because hiding a form stops nobody hand-building
 * the POST.
 */
class ListBoardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_owner_of_a_wish_list_has_no_board(): void
    {
        /*
         * The whole point. Their friends are dividing up the shopping in there,
         * and the owner is the person being surprised — invariant #4, applied
         * to prose that no claim-hiding code path inspects.
         */
        $owner = User::factory()->create();
        $list = $this->list($owner, ListKind::Mine);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('board', null));

        // And through the share link, which is the same page for them.
        $props = $this->props($this->actingAs($owner)->get("/be-nl/l/{$list->share_token}")->assertOk());
        $this->assertNull($props['board']);
    }

    #[Test]
    public function everybody_else_on_that_wish_list_does(): void
    {
        // The people buying need somewhere to coordinate; it is only its owner
        // who must be kept out.
        $list = $this->list(User::factory()->create(), ListKind::Mine);

        $props = $this->props(
            $this->actingAs(User::factory()->create())
                ->get("/be-nl/l/{$list->share_token}")
                ->assertOk(),
        );

        $this->assertNotNull($props['board']);
        $this->assertTrue($props['board']['canPost']);
    }

    #[Test]
    public function the_organiser_of_a_group_gift_is_in_the_conversation(): void
    {
        /*
         * `shouldHideClaimsFrom()` alone answered "hide" here, because
         * `ownerSeesClaimsByDefault()` is true only for `for_someone` — a
         * default older than group lists having a pot. The organiser was being
         * kept out of the conversation they are running.
         */
        $organiser = User::factory()->create();
        $list = $this->list($organiser, ListKind::Group);

        $this->actingAs($organiser)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('board.canPost', true));
    }

    #[Test]
    public function the_owner_of_a_list_about_somebody_else_is_too(): void
    {
        $owner = User::factory()->create();
        $list = $this->list($owner, ListKind::ForSomeone);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('board.canPost', true));
    }

    #[Test]
    public function a_private_list_has_no_board(): void
    {
        // Not because anything would leak — nobody else can reach it — but
        // because a discussion with one participant is a notes field.
        $owner = User::factory()->create();
        $list = $this->list($owner, ListKind::ForSomeone);
        $list->update(['visibility' => ListVisibility::Private]);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('board', null));
    }

    // ── Writing ───────────────────────────────────────────────────────────

    #[Test]
    public function anybody_with_the_link_can_post_and_gets_the_row_back(): void
    {
        $list = $this->list(User::factory()->create(), ListKind::ForSomeone);

        $response = $this->actingAs(User::factory()->create())
            ->postJson("/be-nl/l/{$list->share_token}/messages", [
                'body' => 'Shall we go halves on the coat?',
                'display_name' => 'Bob',
            ])
            ->assertCreated();

        // Answered with the row rather than a redirect: the board appends it
        // and the page does not reload.
        $response->assertJsonPath('message.name', 'Bob');
        $response->assertJsonPath('message.body', 'Shall we go halves on the coat?');

        // Never the identity columns. A name is what the board shows; `user_id`
        // beside a message about what somebody bought is claim state with a
        // person attached.
        $response->assertJsonMissingPath('message.user_id');
        $response->assertJsonMissingPath('message.anon_id');
    }

    #[Test]
    public function the_owner_of_a_wish_list_cannot_post_to_its_board(): void
    {
        // The endpoint asks the same question the page does. Hiding a form
        // stops nobody hand-building the POST.
        $owner = User::factory()->create();
        $list = $this->list($owner, ListKind::Mine);

        $this->actingAs($owner)
            ->postJson("/be-nl/l/{$list->share_token}/messages", [
                'body' => 'What are you all getting me?',
                'display_name' => 'The birthday girl',
            ])
            ->assertForbidden();

        $this->assertSame(0, ListMessage::query()->count());
    }

    #[Test]
    public function a_message_belongs_to_whoever_wrote_it(): void
    {
        $list = $this->list(User::factory()->create(), ListKind::ForSomeone);
        $author = User::factory()->create();

        $this->actingAs($author)->postJson("/be-nl/l/{$list->share_token}/messages", [
            'body' => 'I have the boots.',
            'display_name' => 'Bob',
        ]);

        $message = ListMessage::query()->firstOrFail();

        // A stranger may not delete it…
        $this->actingAs(User::factory()->create())
            ->deleteJson("/be-nl/l/{$list->share_token}/messages/{$message->id}")
            ->assertForbidden();

        // …its author may.
        $this->actingAs($author)
            ->deleteJson("/be-nl/l/{$list->share_token}/messages/{$message->id}")
            ->assertOk();

        $this->assertSame(0, ListMessage::query()->count());
    }

    #[Test]
    public function the_list_owner_can_remove_anybodys_message(): void
    {
        // Deletion is the moderation control — there is no screening, because
        // this is a handful of people who were sent a link by a friend.
        $owner = User::factory()->create();
        $list = $this->list($owner, ListKind::ForSomeone);

        $this->actingAs(User::factory()->create())->postJson("/be-nl/l/{$list->share_token}/messages", [
            'body' => 'Buy my thing instead: cheapshop dot com',
            'display_name' => 'Spammer',
        ]);

        $message = ListMessage::query()->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/be-nl/l/{$list->share_token}/messages/{$message->id}")
            ->assertOk();

        $this->assertSame(0, ListMessage::query()->count());
    }

    #[Test]
    public function a_message_cannot_be_deleted_through_another_lists_token(): void
    {
        $owner = User::factory()->create();
        $mine = $this->list($owner, ListKind::ForSomeone);
        $theirs = $this->list($owner, ListKind::ForSomeone);

        $this->actingAs($owner)->postJson("/be-nl/l/{$mine->share_token}/messages", [
            'body' => 'Ours.',
            'display_name' => 'Owner',
        ]);

        $message = ListMessage::query()->firstOrFail();

        // A 404, not a 403: the row is none of that URL's business either way.
        $this->actingAs($owner)
            ->deleteJson("/be-nl/l/{$theirs->share_token}/messages/{$message->id}")
            ->assertNotFound();

        $this->assertSame(1, ListMessage::query()->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function list(User $owner, ListKind $kind): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::factory()->create([
                'owner_user_id' => $owner->id,
                'name' => 'Dad',
            ])->id,
            'kind' => $kind,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
