<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Interest;
use App\Enums\Vibe;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipient>
 */
class RecipientFactory extends Factory
{
    protected $model = Recipient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Exactly one owner, or the CHECK constraint rejects the row.
            'owner_user_id' => User::factory(),
            'owner_anon_id' => null,
            'name' => fake()->firstName(),
            'relationship' => 'mother',
            'interests' => [],
            'values' => [],
            'avoid' => [],
        ];
    }

    /** @param list<Interest|string> $interests */
    public function into(array $interests, ?Vibe $vibe = null): static
    {
        return $this->state(fn () => [
            'interests' => array_map(
                fn (Interest|string $i) => $i instanceof Interest ? $i->value : $i,
                $interests,
            ),
            'vibe' => $vibe?->value,
        ]);
    }

    public function ownedByAnonymous(string $anonId): static
    {
        return $this->state(fn () => [
            'owner_user_id' => null,
            'owner_anon_id' => $anonId,
        ]);
    }

    /** Budget in cents, per invariant #7. */
    public function budget(?int $min, ?int $max): static
    {
        return $this->state(fn () => ['budget_min' => $min, 'budget_max' => $max]);
    }
}
