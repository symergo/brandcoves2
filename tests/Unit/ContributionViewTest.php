<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ListKind;
use App\Models\AnonymousIdentity;
use App\Models\GiftPledge;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Wishlist\ContributionView;
use App\Support\Owner;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The privacy table, pinned where it is pure.
 *
 * `ContributionView` is the single place the inversion between a wish list and
 * a group list is expressed, and the inversion is the sort of rule that reads
 * as an inconsistency to somebody tidying up six months from now. Asserting it
 * here — with no HTTP, no database and no session in the way — is what makes
 * the intent legible rather than accidental.
 *
 * | viewer | `mine` | `group` |
 * |---|---|---|
 * | owner | nothing at all | total, share, count, breakdown |
 * | anyone else | total, share, count | total, share, count |
 */
class ContributionViewTest extends TestCase
{
    private function service(): ContributionView
    {
        return new ContributionView;
    }

    /**
     * A list and one item, entirely in memory.
     *
     * Unsaved models: this service reads a loaded relation and never queries,
     * which is the property worth protecting — the moment it needs a database
     * it has stopped being the one place the rule lives and started being a
     * query somebody can bypass.
     *
     * @param  array<int, array{0: string, 1: int, 2: int|null}>  $pledges  [name, cents, userId]
     */
    private function item(array $pledges): WishlistItem
    {
        $item = new WishlistItem(['id' => 1]);
        $item->id = 1;

        $item->setRelation('pledges', new Collection(array_map(
            fn (array $p) => new GiftPledge([
                'display_name' => $p[0],
                'amount' => $p[1],
                'user_id' => $p[2],
                'anon_id' => null,
            ]),
            $pledges,
        )));

        return $item;
    }

    private function listOfKind(ListKind $kind): Wishlist
    {
        $list = new Wishlist(['kind' => $kind]);
        $list->owner_user_id = 1;
        $list->owner_anon_id = null;

        return $list;
    }

    private function owner(int $id): Owner
    {
        $user = new User;
        $user->id = $id;

        return new Owner(user: $user, anonymous: null);
    }

    #[Test]
    public function the_owner_of_a_wish_list_is_told_nothing(): void
    {
        // Invariant #4. Not a zero, not an empty payload — no entry at all.
        $out = $this->service()->forItems(
            $this->listOfKind(ListKind::Mine),
            new Collection([$this->item([['Bob', 2500, 2]])]),
            $this->owner(1),
            isOwner: true,
        );

        $this->assertSame([], $out);
    }

    #[Test]
    public function a_visitor_to_a_wish_list_gets_the_total_and_their_own_share(): void
    {
        $out = $this->service()->forItems(
            $this->listOfKind(ListKind::Mine),
            new Collection([$this->item([['Bob', 2500, 2], ['Cara', 1500, 3]])]),
            $this->owner(3),
            isOwner: false,
        );

        $this->assertSame(4000, $out[1]['total']);
        $this->assertSame(2, $out[1]['count']);
        $this->assertSame(1500, $out[1]['mine']);
        $this->assertArrayNotHasKey('breakdown', $out[1]);
    }

    #[Test]
    public function the_organiser_of_a_group_list_gets_the_breakdown(): void
    {
        // The inversion: the recipient of a group list is a third party who
        // never opens it, so there is no surprise to protect from its owner.
        $out = $this->service()->forItems(
            $this->listOfKind(ListKind::Group),
            new Collection([$this->item([['Bob', 2500, 2], ['Cara', 4000, 3]])]),
            $this->owner(1),
            isOwner: true,
        );

        $this->assertSame(6500, $out[1]['total']);
        $this->assertSame(
            [['name' => 'Cara', 'amount' => 4000], ['name' => 'Bob', 'amount' => 2500]],
            $out[1]['breakdown'],
        );
    }

    #[Test]
    public function a_member_of_a_group_list_does_not_get_the_breakdown(): void
    {
        $out = $this->service()->forItems(
            $this->listOfKind(ListKind::Group),
            new Collection([$this->item([['Bob', 2500, 2], ['Cara', 4000, 3]])]),
            $this->owner(2),
            isOwner: false,
        );

        $this->assertSame(6500, $out[1]['total']);
        $this->assertSame(2500, $out[1]['mine']);
        $this->assertArrayNotHasKey('breakdown', $out[1]);
    }

    #[Test]
    public function a_research_list_produces_nothing_for_anybody(): void
    {
        foreach ([true, false] as $isOwner) {
            $this->assertSame([], $this->service()->forItems(
                $this->listOfKind(ListKind::ForSomeone),
                new Collection([$this->item([['Bob', 2500, 2]])]),
                $this->owner(1),
                $isOwner,
            ));
        }
    }

    #[Test]
    public function an_anonymous_contributor_is_matched_on_their_cookie_identity(): void
    {
        $identity = new AnonymousIdentity;
        $identity->id = 'abc';

        $item = new WishlistItem;
        $item->id = 1;
        $item->setRelation('pledges', new Collection([
            new GiftPledge(['display_name' => 'Someone', 'amount' => 900, 'user_id' => null, 'anon_id' => 'abc']),
        ]));

        $out = $this->service()->forItems(
            $this->listOfKind(ListKind::Mine),
            new Collection([$item]),
            new Owner(user: null, anonymous: $identity),
            isOwner: false,
        );

        $this->assertSame(900, $out[1]['mine']);
    }

    #[Test]
    public function an_item_nobody_can_contribute_to_and_nobody_has_is_omitted(): void
    {
        // Absent, not an empty shape: there is no sentence to write about it.
        $list = $this->listOfKind(ListKind::Mine);

        $out = $this->service()->forItems(
            $list,
            new Collection([$this->item([])]),
            new Owner(user: null, anonymous: null),
            isOwner: false,
        );

        $this->assertSame([], $out);
    }
}
