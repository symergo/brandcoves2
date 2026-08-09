<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    protected $model = WishlistItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'wishlist_id' => Wishlist::factory(),
            'group_id' => ProductGroup::factory(),
            'snapshot_title' => fake()->words(3, true),
            'snapshot_image_url' => 'https://img.test/item.jpg',
            'snapshot_price' => fake()->numberBetween(1000, 40000),
            'priority' => 0,
        ];
    }

    /**
     * Take the snapshot from a real group, the way the controller does.
     *
     * A fixture whose snapshot disagrees with its group hides bugs in exactly
     * the code that compares "what you saved" against "what it costs now".
     */
    public function of(ProductGroup $group): static
    {
        return $this->state(fn () => [
            'group_id' => $group->id,
            'snapshot_title' => $group->title,
            'snapshot_image_url' => $group->image_url,
            'snapshot_price' => $group->min_price,
        ]);
    }

    public function claimedBy(string $hash): static
    {
        return $this->state(fn () => [
            'claimed_by_hash' => $hash,
            'claimed_at' => now(),
        ]);
    }
}
