<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ListKind;
use App\Models\AnonymousIdentity;
use App\Models\GiftPledge;
use App\Models\User;
use App\Models\Wishlist;
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
 * | viewer | `mine`, per item | `group`, the pot |
 * |---|---|---|
 * | owner | nothing at all | total, share, count, breakdown |
 * | anyone else | total, share, count | total, share, count |
 *
 * **Two methods, because they are two facts.** `forItems()` answers "how much
 * towards this one thing on Anna's list"; `forList()` answers "how much towards
 * the present". A group list is one present with a shortlist of candidates
 * under it, so money pledged against a candidate would be a bet on an outcome
 * nobody has chosen — and most of those bets would end up attached to something
 * nobody buys. The privacy rule is identical either way; only what it is about
 * changes.
 */
class ContributionViewTest extends TestCase
{
    private function service(): ContributionView
    {
        return new ContributionView;
    }

    /**
     * A list with money on it, entirely in memory.
     *
     * Unsaved models: this service reads a loaded relation and never queries,
     * which is the property worth protecting — the moment it needs a database
     * it has stopped being the one place the rule lives and started being a
     * query somebody can bypass.
     *
     * @param  array<int, array{0: string, 1: int, 2: int|null}>  $pledges
     */
    private function listWithPot(ListKind $kind, array $pledges): Wishlist
    {
        $list = $this->listOfKind($kind);

        $list->setRelation('pledges', new Collection(array_map(
            fn (array $p) => new GiftPledge([
                'display_name' => $p[0],
                'amount' => $p[1],
                'user_id' => $p[2],
                'anon_id' => null,
            ]),
            $pledges,
        )));

        return $list;
    }

    private function listOfKind(ListKind $kind): Wishlist
    {
        $list = new Wishlist(['kind' => $kind]);
        $list->owner_user_id = 1;
        $list->owner_anon_id = null;

        return $list;
    }

    private function anonymousOwner(string $id): Owner
    {
        $identity = new AnonymousIdentity;
        $identity->id = $id;

        return new Owner(user: null, anonymous: $identity);
    }

    private function owner(int $id): Owner
    {
        $user = new User;
        $user->id = $id;

        return new Owner(user: $user, anonymous: null);
    }

    #[Test]
    public function the_organiser_gets_the_breakdown(): void
    {
        // The inversion: the recipient of a group list is a third party who
        // never opens it, so there is no surprise to protect from its owner.
        $out = $this->service()->forList(
            $this->listWithPot(ListKind::Group, [['Bob', 2500, 2], ['Cara', 4000, 3]]),
            $this->owner(1),
            isOwner: true,
        );

        $this->assertSame(6500, $out['total']);
        $this->assertSame(
            [['name' => 'Cara', 'amount' => 4000], ['name' => 'Bob', 'amount' => 2500]],
            $out['breakdown'],
        );
    }

    #[Test]
    public function a_member_gets_the_total_and_their_own_share_and_no_more(): void
    {
        $out = $this->service()->forList(
            $this->listWithPot(ListKind::Group, [['Bob', 2500, 2], ['Cara', 4000, 3]]),
            $this->owner(2),
            isOwner: false,
        );

        $this->assertSame(6500, $out['total']);
        $this->assertSame(2, $out['count']);
        $this->assertSame(2500, $out['mine']);

        // Absent, not empty. A visible ladder of who put in what is social
        // pressure on whoever put in least.
        $this->assertArrayNotHasKey('breakdown', $out);
    }

    #[Test]
    public function somebody_who_has_not_put_in_has_a_null_share_rather_than_a_zero(): void
    {
        // Distinct facts: a zero is a promise of nothing, null is the absence
        // of a promise, and only the second is true of somebody who has not
        // joined the pool.
        $out = $this->service()->forList(
            $this->listWithPot(ListKind::Group, [['Bob', 2500, 2]]),
            $this->owner(9),
            isOwner: false,
        );

        $this->assertNull($out['mine']);
    }

    #[Test]
    public function an_anonymous_contributor_is_matched_on_their_cookie_identity(): void
    {
        /*
         * Most members of an office group never sign up — the join link is the
         * whole design — so matching on `user_id` alone would tell every one of
         * them they had put in nothing.
         */
        $list = $this->listOfKind(ListKind::Group);

        $list->setRelation('pledges', new Collection([
            new GiftPledge([
                'display_name' => 'Someone',
                'amount' => 1500,
                'user_id' => null,
                'anon_id' => 'anon-1',
            ]),
        ]));

        $out = $this->service()->forList($list, $this->anonymousOwner('anon-1'), isOwner: false);

        $this->assertSame(1500, $out['mine']);
    }

    #[Test]
    public function no_other_kind_of_list_has_a_pot_at_all(): void
    {
        /*
         * A wish list pooled per item until 2026-08-30, which rendered as an
         * "I'm in" under every card beside the claim button that is the real
         * action there. On a wish list you claim a thing; going in together on
         * one *is* a group gift. So there is no money on any other kind, and
         * null rather than an empty shape means the page draws no pot rather
         * than an empty one.
         */
        foreach ([ListKind::Mine, ListKind::ForSomeone] as $kind) {
            foreach ([true, false] as $isOwner) {
                $this->assertNull(
                    $this->service()->forList(
                        $this->listWithPot($kind, [['Bob', 2500, 2]]),
                        $this->owner(1),
                        $isOwner,
                    ),
                    "A {$kind->value} list should have no pot.",
                );
            }
        }
    }
}
