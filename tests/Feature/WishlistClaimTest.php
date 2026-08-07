<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two rules a gift list exists to protect. Both are easy to break with an
 * innocent-looking refactor, which is exactly why they are pinned by tests.
 */
class WishlistClaimTest extends TestCase
{
    use RefreshDatabase;

    private function item(): WishlistItem
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Birthday',
            'market' => Market::BeNl,
            'is_gift_list' => true,
        ]);

        return WishlistItem::create([
            'wishlist_id' => $list->id,
            'snapshot_title' => 'A very good present',
        ]);
    }

    #[Test]
    public function only_one_of_two_simultaneous_claims_succeeds(): void
    {
        $item = $this->item();

        // Two people open a shared gift list and both tap "I'll get this". This
        // is the expected case, not an edge case — a read-then-write would let
        // both succeed and the recipient gets two of the same thing.
        $first = (clone $item)->claim(WishlistItem::identityHash('alice'));
        $second = (clone $item)->claim(WishlistItem::identityHash('bob'));

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->assertSame(
            WishlistItem::identityHash('alice'),
            $item->fresh()->claimed_by_hash,
        );
    }

    #[Test]
    public function the_claim_hash_is_never_serialised(): void
    {
        $item = $this->item();
        $item->claim(WishlistItem::identityHash('alice'));

        // The whole value of a gift list is that the owner does not know what
        // has been bought. Even a one-way hash reveals *that* something was.
        $this->assertArrayNotHasKey('claimed_by_hash', $item->fresh()->toArray());
    }

    #[Test]
    public function only_the_claimer_can_release_a_claim(): void
    {
        $item = $this->item();
        $item->claim(WishlistItem::identityHash('alice'));

        $this->assertFalse($item->fresh()->release(WishlistItem::identityHash('bob')));
        $this->assertTrue($item->fresh()->release(WishlistItem::identityHash('alice')));
        $this->assertNull($item->fresh()->claimed_by_hash);
    }

    #[Test]
    public function the_claim_hash_is_not_reversible_to_the_identity(): void
    {
        $hash = WishlistItem::identityHash('user:42');

        $this->assertNotSame('user:42', $hash);
        $this->assertSame(64, strlen($hash));
        $this->assertNotSame(WishlistItem::identityHash('user:43'), $hash);
    }
}
