<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductGroup>
 */
class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'market' => Market::BeNl,
            // Unique per row: `(market, identity_key)` is the identity
            // constraint, and a factory that collides on it fails in a way that
            // looks like a bug in the code under test.
            'identity_key' => 'ean:'.fake()->unique()->numerify('#############'),
            'identity_kind' => IdentityKind::Ean,
            'title' => Str::title($title),
            'slug' => Str::slug($title).'-'.Str::random(5),
            'brand' => fake()->company(),
            'image_url' => 'https://img.test/'.Str::random(8).'.jpg',
            'category' => 'audio',
            // Cents, per invariant #7.
            'min_price' => fake()->numberBetween(1000, 40000),
            'merchant_count' => 1,
            'offer_count' => 1,
            'in_stock' => true,
            // Default to giftable: nearly every test that needs a group needs a
            // *presentable* one, and the retrieval predicate filters on this.
            'giftable' => true,
            'first_seen_at' => now(),
        ];
    }

    public function forMarket(Market $market): static
    {
        return $this->state(fn () => ['market' => $market]);
    }

    public function notGiftable(string $reason = 'consumable'): static
    {
        return $this->state(fn () => ['giftable' => false, 'giftable_reason' => $reason]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['in_stock' => false]);
    }

    public function priced(int $cents): static
    {
        return $this->state(fn () => ['min_price' => $cents]);
    }

    public function surprising(float $score): static
    {
        return $this->state(fn () => ['surprise_score' => $score]);
    }
}
