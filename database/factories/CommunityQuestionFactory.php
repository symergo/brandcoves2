<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AskAudience;
use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Models\CommunityQuestion;
use App\Models\User;
use App\Support\ShareCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityQuestion>
 */
class CommunityQuestionFactory extends Factory
{
    protected $model = CommunityQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'market' => Market::BeNl,
            'user_id' => User::factory(),
            'title' => fake()->sentence(8),
            'body' => fake()->paragraph(),
            'budget_max' => null,

            // Pending by default, exactly like a real post. A factory that
            // published by default would let a test pass while the thing it is
            // really asserting — that nothing publishes itself — is broken.
            'status' => ModerationStatus::Pending,
            'published_at' => null,
        ];
    }

    /**
     * On the board.
     *
     * Sets both columns together, because `community_questions_published_is_dated`
     * refuses a row where the status and the date disagree.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ModerationStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => ModerationStatus::Rejected,
            'published_at' => null,
        ]);
    }

    public function inMarket(Market $market): static
    {
        return $this->state(fn () => ['market' => $market]);
    }

    /**
     * Asked of the asker's people only: visible at once, opened by its link
     * code. `community_questions_people_have_a_link` refuses one without a
     * code. Created the way `PeopleQuestions::ask()` creates it, minus the
     * notification.
     */
    public function forPeople(): static
    {
        return $this->state(fn () => [
            'audience' => AskAudience::People,
            'share_token' => ShareCode::make(),
            'status' => ModerationStatus::Published,
            'published_at' => now(),
        ]);
    }
}
