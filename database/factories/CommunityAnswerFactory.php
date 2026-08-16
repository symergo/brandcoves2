<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModerationStatus;
use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityAnswer>
 */
class CommunityAnswerFactory extends Factory
{
    protected $model = CommunityAnswer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'question_id' => CommunityQuestion::factory()->published(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'status' => ModerationStatus::Pending,
            'published_at' => null,
        ];
    }

    /** Both columns together — the CHECK constraint refuses them apart. */
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
}
