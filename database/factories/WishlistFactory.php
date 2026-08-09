<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wishlist>
 */
class WishlistFactory extends Factory
{
    protected $model = Wishlist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Exactly one owner, or the CHECK constraint rejects the row.
            'owner_user_id' => User::factory(),
            'owner_anon_id' => null,
            'title' => fake()->words(2, true),
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Private,
            'kind' => ListKind::Mine,
        ];
    }

    public function ownedByAnonymous(string $anonId): static
    {
        return $this->state(fn () => [
            'owner_user_id' => null,
            'owner_anon_id' => $anonId,
        ]);
    }

    /**
     * A list *about* someone. Sets the recipient and the kind together — the
     * whole point of `kind` is that those two cannot disagree.
     */
    public function forSomeone(Recipient|string|null $recipient = null): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ListKind::ForSomeone,
            'recipient_id' => $recipient instanceof Recipient
                ? $recipient->id
                : ($recipient ?? Recipient::factory()->create([
                    'owner_user_id' => $attributes['owner_user_id'] ?? null,
                    'owner_anon_id' => $attributes['owner_anon_id'] ?? null,
                ])->id),
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['visibility' => ListVisibility::Link]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['visibility' => ListVisibility::Public]);
    }
}
