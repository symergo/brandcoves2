<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
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
 * The other end of a recipient.
 *
 * The load-bearing rule: this page is read by the one person the surprise is
 * being kept from, so it must show their own list and nothing the giver did.
 */
class RecipientProfileTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(?User $owner = null): Recipient
    {
        return Recipient::factory()->create([
            'owner_user_id' => ($owner ?? User::factory()->create())->id,
            'name' => 'Mum',
        ]);
    }

    #[Test]
    public function the_self_describe_page_never_reveals_the_givers_list(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->recipient($owner);

        // The giver's private research about this exact person.
        $research = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'title' => 'Gifts for Mum',
        ]);

        WishlistItem::factory()->create([
            'wishlist_id' => $research->id,
            'snapshot_title' => 'The surprise',
        ]);

        $response = $this->get("/be-nl/for/{$recipient->share_token}")->assertOk();

        $payload = json_encode($response->viewData('page')['props']);

        // Not the title, not the item, not a count of them. A "1 thing has been
        // picked for you" is the same leak as naming it.
        $this->assertStringNotContainsString('The surprise', $payload);
        $this->assertStringNotContainsString('Gifts for Mum', $payload);
        $this->assertArrayNotHasKey('giverList', $response->viewData('page')['props']);
    }

    #[Test]
    public function the_page_does_not_prefill_what_the_giver_guessed(): void
    {
        $recipient = $this->recipient();
        $recipient->describeTaste(['interests' => ['gardening']], TasteSource::Suggested);

        $this->get("/be-nl/for/{$recipient->share_token}")
            ->assertOk()
            // Seeing "we heard you like gardening" reveals what they have been
            // told about you, and anchors the answer to someone else's idea.
            ->assertInertia(fn ($page) => $page->where('person.interests', []));
    }

    #[Test]
    public function their_own_answer_outranks_the_givers_guess(): void
    {
        $recipient = $this->recipient();
        $recipient->describeTaste(['interests' => ['gardening']], TasteSource::Suggested);

        $this->post("/be-nl/for/{$recipient->share_token}", ['interests' => ['cooking']])
            ->assertRedirect();

        $recipient->refresh();
        $this->assertSame(['cooking'], (array) $recipient->interests);
        $this->assertSame(TasteSource::Self, $recipient->taste_source);
    }

    #[Test]
    public function a_guess_never_overwrites_what_they_said_themselves(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->recipient($owner);

        $recipient->describeTaste(['interests' => ['cooking']], TasteSource::Self);

        $this->actingAs($owner)
            ->patch("/be-nl/recipients/{$recipient->id}", ['interests' => ['gaming']])
            ->assertRedirect();

        /*
         * The destructive direction is always the wrong one. Once they have
         * said what they like, the owner's older opinion is simply worse
         * evidence — and silently replacing theirs with it is the outcome
         * nobody would pick deliberately.
         */
        $this->assertSame(['cooking'], (array) $recipient->fresh()->interests);
    }

    #[Test]
    public function the_owner_can_still_change_their_own_context_afterwards(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->recipient($owner);
        $recipient->describeTaste(['interests' => ['cooking']], TasteSource::Self);

        $this->actingAs($owner)
            ->patch("/be-nl/recipients/{$recipient->id}", ['occasion' => 'birthday', 'notes' => 'likes blue'])
            ->assertRedirect();

        // Relationship, occasion, budget and notes describe *my* situation, not
        // theirs. Locking those behind their answer would be the wrong lesson.
        $this->assertSame('birthday', $recipient->fresh()->occasion);
        $this->assertSame('likes blue', $recipient->fresh()->notes);
    }

    #[Test]
    public function claiming_the_link_binds_the_person_to_their_account(): void
    {
        $recipient = $this->recipient();
        $person = User::factory()->create();

        $this->actingAs($person)
            ->post("/be-nl/for/{$recipient->share_token}/claim")
            ->assertRedirect();

        $recipient->refresh();
        $this->assertSame($person->id, $recipient->user_id);
        $this->assertSame(RecipientStatus::Linked, $recipient->status);
        $this->assertTrue($recipient->isLinked());
    }

    #[Test]
    public function the_owner_cannot_claim_their_own_stub(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->recipient($owner);

        // Otherwise they become the recipient of their own gift research.
        $this->actingAs($owner)
            ->post("/be-nl/for/{$recipient->share_token}/claim")
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_token_is_not_found(): void
    {
        $this->get('/be-nl/for/'.Str::uuid())->assertNotFound();
    }

    #[Test]
    public function a_linked_recipients_shared_list_reaches_the_giver(): void
    {
        $owner = User::factory()->create();
        $person = User::factory()->create();

        $recipient = Recipient::factory()->create([
            'owner_user_id' => $owner->id,
            'user_id' => $person->id,
            'status' => RecipientStatus::Linked,
            'name' => 'Mum',
        ]);

        $theirs = Wishlist::factory()->create([
            'owner_user_id' => $person->id,
            'kind' => ListKind::Mine,
            'visibility' => ListVisibility::Link,
        ]);

        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        WishlistItem::factory()->of($group)->create(['wishlist_id' => $theirs->id]);

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
        ]);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$mine->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('target.isLinked', true)
                ->has('asked', 1));
    }

    #[Test]
    public function an_unlinked_recipient_shows_only_my_own_finds(): void
    {
        $owner = User::factory()->create();
        $recipient = $this->recipient($owner);

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
        ]);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$mine->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('target.isLinked', false)
                ->has('asked', 0));
    }

    #[Test]
    public function a_private_list_of_theirs_is_not_pulled_in(): void
    {
        $owner = User::factory()->create();
        $person = User::factory()->create();

        $recipient = Recipient::factory()->create([
            'owner_user_id' => $owner->id,
            'user_id' => $person->id,
            'status' => RecipientStatus::Linked,
        ]);

        // Being linked is permission to be *found*, not permission to read
        // everything they own.
        $private = Wishlist::factory()->create([
            'owner_user_id' => $person->id,
            'kind' => ListKind::Mine,
            'visibility' => ListVisibility::Private,
        ]);

        WishlistItem::factory()->create(['wishlist_id' => $private->id]);

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
        ]);

        $this->actingAs($owner)
            ->get("/be-nl/lists/{$mine->id}")
            ->assertInertia(fn ($page) => $page->has('asked', 0));
    }
}
