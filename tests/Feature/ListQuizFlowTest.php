<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\ListQuiz;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The quiz, from the button on a list to somebody playing it.
 *
 * `ListQuizTest` covers the builder in isolation. This covers the journey,
 * which is where a feature that is individually correct at every step still
 * fails to work.
 */
class ListQuizFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Wishlist} */
    private function listWithEnoughItems(): array
    {
        $user = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        for ($i = 0; $i < 6; $i++) {
            WishlistItem::factory()
                ->of(ProductGroup::factory()->create([
                    'market' => Market::BeNl,
                    'title' => "Koffiemolen model {$i}",
                    'category' => 'Keuken',
                    'min_price' => 4000,
                ]))
                ->create(['wishlist_id' => $list->id]);
        }

        // A pool of plausible wrong answers.
        for ($i = 0; $i < 10; $i++) {
            ProductGroup::factory()->create([
                'market' => Market::BeNl,
                'title' => "Koffiekan variant {$i}",
                'category' => 'Keuken',
                'min_price' => 4000,
            ]);
        }

        return [$user, $list];
    }

    #[Test]
    public function the_owner_can_create_a_quiz_and_the_link_opens(): void
    {
        [$user, $list] = $this->listWithEnoughItems();

        $this->actingAs($user)
            ->post("/be-nl/lists/{$list->id}/quiz")
            ->assertRedirect();

        $quiz = ListQuiz::query()->where('wishlist_id', $list->id)->firstOrFail();

        // The journey's whole point: the link somebody is handed has to open.
        $this->get("/be-nl/q/{$quiz->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Quiz/Play')
                ->has('quiz.rounds'));
    }

    #[Test]
    public function the_list_page_offers_the_quiz_link_after_creating_it(): void
    {
        [$user, $list] = $this->listWithEnoughItems();

        $this->actingAs($user)->post("/be-nl/lists/{$list->id}/quiz");

        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->whereNot('quizUrl', null));
    }

    #[Test]
    public function a_stranger_can_play_without_signing_in(): void
    {
        [$user, $list] = $this->listWithEnoughItems();
        $this->actingAs($user)->post("/be-nl/lists/{$list->id}/quiz");

        $quiz = ListQuiz::query()->where('wishlist_id', $list->id)->firstOrFail();

        auth()->logout();

        // Asking for a signup before the first guess loses the player, and the
        // share artefact is worthless if nobody ever gets a score.
        $this->get("/be-nl/q/{$quiz->share_token}")->assertOk();

        $this->post("/be-nl/q/{$quiz->share_token}", [
            'answers' => array_map(fn ($r) => $r['options'][0]['id'], $quiz->rounds),
        ])->assertRedirect();

        $this->assertSame(1, $quiz->attempts()->count());
    }

    #[Test]
    public function the_answer_is_never_in_the_payload(): void
    {
        [$user, $list] = $this->listWithEnoughItems();
        $this->actingAs($user)->post("/be-nl/lists/{$list->id}/quiz");

        $quiz = ListQuiz::query()->where('wishlist_id', $list->id)->firstOrFail();

        auth()->logout();

        $response = $this->get("/be-nl/q/{$quiz->share_token}")->assertOk();
        $payload = json_encode($response->viewData('page')['props']['quiz']);

        foreach ($quiz->answers() as $answer) {
            $this->assertStringNotContainsString('"answer":'.$answer, (string) $payload);
        }
    }

    #[Test]
    public function un_sharing_the_list_takes_the_quiz_with_it(): void
    {
        [$user, $list] = $this->listWithEnoughItems();
        $this->actingAs($user)->post("/be-nl/lists/{$list->id}/quiz");

        $quiz = ListQuiz::query()->where('wishlist_id', $list->id)->firstOrFail();

        $list->update(['visibility' => ListVisibility::Private]);

        // Turning sharing off has to actually turn everything off. A quiz shows
        // what is on the list.
        auth()->logout();
        $this->get("/be-nl/q/{$quiz->share_token}")->assertNotFound();
    }

    #[Test]
    public function a_list_that_is_too_short_says_so_rather_than_half_working(): void
    {
        $user = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        WishlistItem::factory()
            ->of(ProductGroup::factory()->create(['market' => Market::BeNl]))
            ->create(['wishlist_id' => $list->id]);

        $this->actingAs($user)
            ->post("/be-nl/lists/{$list->id}/quiz")
            ->assertSessionHas('error');

        $this->assertSame(0, ListQuiz::query()->count());
    }
}
