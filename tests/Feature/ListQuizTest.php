<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\ListQuiz;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\QuizBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "How well do you know them?"
 *
 * The load-bearing assertion is the distractor one. A quiz whose wrong answers
 * are obviously wrong passes every other test here and is worthless — nobody
 * shares a score they could not have failed to get.
 */
class ListQuizTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_quiz_cannot_be_made_from_a_list_about_somebody_else(): void
    {
        /*
         * A quiz asks "how well do you know **me**", and it publishes what is
         * on the list to whoever holds the link. Over a list about a third
         * person that is somebody's private research turned into a game about
         * them, and the sharing switch the endpoint checks was never consent
         * for it.
         *
         * This could not happen until 2026-08-29: `mine` was the only claimable
         * kind, so the visibility check stood in for the kind by coincidence.
         * Widening `allowsClaiming()` separated them, and a gate that worked by
         * coincidence stops working silently.
         *
         * Posted directly rather than checked on the page — hiding the tab
         * stops nobody hand-building the request.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/quiz")
            ->assertForbidden();

        $this->assertSame(0, ListQuiz::query()->count());
    }

    private function builder(): QuizBuilder
    {
        return new QuizBuilder;
    }

    /** Deterministic, so a passing assertion is a proof and not a coin toss. */
    private function ordered(): callable
    {
        return static fn (array $items): array => $items;
    }

    private function group(string $title, string $category, int $price, ?string $brand = null): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => $title,
            'category' => $category,
            'brand' => $brand,
            'min_price' => $price,
        ]);
    }

    /** @param list<ProductGroup> $groups */
    private function listOf(array $groups): Wishlist
    {
        $list = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
        ]);

        foreach ($groups as $group) {
            WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);
        }

        return $list->fresh(['items.group']);
    }

    #[Test]
    public function every_distractor_is_plausible(): void
    {
        $answers = [];
        $pool = [];

        for ($i = 0; $i < 6; $i++) {
            $answers[] = $this->group("Koffiemolen model {$i}", 'Keuken', 4000 + $i * 10);
        }

        // Same category and price band: hard.
        for ($i = 0; $i < 8; $i++) {
            $pool[] = $this->group("Koffiekan variant {$i}", 'Keuken', 4000 + $i * 10);
        }

        // Nothing like the answers at all: must never be chosen.
        $obvious = $this->group('Kettingzaag 2000W benzine', 'Tuin', 39900);
        $pool[] = $obvious;

        $rounds = $this->builder()->build(
            $this->listOf($answers),
            ProductGroup::query()->whereIn('id', array_map(fn ($g) => $g->id, $pool))->get(),
            $this->ordered(),
        );

        $this->assertNotEmpty($rounds);

        foreach ($rounds as $round) {
            $ids = array_column($round['options'], 'id');

            $this->assertCount(4, $ids);
            $this->assertContains($round['answer'], $ids);

            // A chainsaw among the coffee grinders hands over the answer.
            $this->assertNotContains($obvious->id, $ids);
        }
    }

    #[Test]
    public function nothing_else_from_the_list_is_offered_as_a_wrong_answer(): void
    {
        $answers = [];

        for ($i = 0; $i < 6; $i++) {
            $answers[] = $this->group("Koffiemolen model {$i}", 'Keuken', 4000);
        }

        $pool = [];

        for ($i = 0; $i < 8; $i++) {
            $pool[] = $this->group("Koffiekan variant {$i}", 'Keuken', 4000);
        }

        $list = $this->listOf($answers);
        $onTheList = $list->items->pluck('group_id')->all();

        $rounds = $this->builder()->build(
            $list,
            ProductGroup::query()->whereIn('id', array_map(fn ($g) => $g->id, $pool))->get(),
            $this->ordered(),
        );

        foreach ($rounds as $round) {
            foreach ($round['options'] as $option) {
                if ($option['id'] === $round['answer']) {
                    continue;
                }

                // Otherwise two options are both correct and the round is
                // unwinnable — which reads to the player as a bug in the scoring.
                $this->assertNotContains($option['id'], $onTheList);
            }
        }
    }

    #[Test]
    public function a_short_list_produces_no_quiz_at_all(): void
    {
        $answers = [
            $this->group('Koffiemolen', 'Keuken', 4000),
            $this->group('Koffiekan', 'Keuken', 4000),
        ];

        $rounds = $this->builder()->build(
            $this->listOf($answers),
            ProductGroup::query()->get(),
            $this->ordered(),
        );

        // A two-question quiz teaches people the thing is not worth opening,
        // which is the one irreversible thing a shareable artefact can do.
        $this->assertSame([], $rounds);
    }

    #[Test]
    public function a_round_is_dropped_rather_than_padded_when_there_are_too_few_candidates(): void
    {
        $answers = [];

        for ($i = 0; $i < 6; $i++) {
            $answers[] = $this->group("Koffiemolen model {$i}", 'Keuken', 4000);
        }

        // Only one plausible distractor exists in the whole catalogue.
        $this->group('Koffiekan enkel', 'Keuken', 4000);

        $rounds = $this->builder()->build(
            $this->listOf($answers),
            ProductGroup::query()->where('category', 'Keuken')->where('title', 'Koffiekan enkel')->get(),
            $this->ordered(),
        );

        // Better no round than a round with two options.
        $this->assertSame([], $rounds);
    }

    #[Test]
    public function an_amazon_item_is_never_the_answer(): void
    {
        $answers = [];

        for ($i = 0; $i < 6; $i++) {
            $answers[] = $this->group("Koffiemolen model {$i}", 'Keuken', 4000);
        }

        $list = $this->listOf($answers);

        // Amazon rows carry no stored title, image or price (invariant #6), so
        // there is nothing to render as an option.
        WishlistItem::create([
            'wishlist_id' => $list->id,
            'source' => 'amazon',
            'external_id' => 'B00TEST',
            'snapshot_title' => 'Amazon',
        ]);

        $pool = [];

        for ($i = 0; $i < 8; $i++) {
            $pool[] = $this->group("Koffiekan variant {$i}", 'Keuken', 4000);
        }

        $rounds = $this->builder()->build(
            $list->fresh(['items.group']),
            ProductGroup::query()->whereIn('id', array_map(fn ($g) => $g->id, $pool))->get(),
            $this->ordered(),
        );

        foreach ($rounds as $round) {
            $this->assertNotNull(ProductGroup::query()->find($round['answer']));
        }
    }
}
