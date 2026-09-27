<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Models\IdentityAlias;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Ingestion\ProductGrouper;
use App\Services\Search\SearchGenerations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Make two products one: the loser's offers, list items, alerts and Cove
 * places move to the winner, for good.
 *
 * ## Why the merge lives in identity
 *
 * The grouper re-derives every offer's product from its identity key twice a
 * day (and live search does it per request). Moving `products.group_id` alone
 * would be undone within twelve hours. So the first thing written is an alias,
 * loser key → winner key, which the grouper follows from then on; everything
 * after it is making the rest of the database agree now rather than later.
 *
 * ## What happens, in one transaction
 *
 *  1. The alias. Aliases that pointed at the loser are rewritten to the winner
 *     so a chain never forms: the grouper follows one hop only.
 *  2. Every foreign key to the loser moves to the winner. Four tables have a
 *     unique constraint that would collide (a list, a Cove plan, an alert or a
 *     community answer holding both products): the winner's row is kept and
 *     the loser's deleted, and on lists and Cove plans the deleted row's note
 *     is appended to the kept one and a claim carried over, so nothing a
 *     person wrote or did is lost.
 *  3. The two JSON id arrays (`cove_plans.pinned_group_ids`,
 *     `search_alerts.seen_group_ids`).
 *  4. `merged_into_id` on the loser, which is kept rather than deleted: its
 *     URL answers 301 to the winner and `[[product:N]]` tokens in published
 *     prose still resolve (see ProductController and CoveMarkup).
 *  5. The aggregates of both, so the winner shows its new offers on the next
 *     page load and the loser drops out of every listing.
 *
 * Only ever within one market: identity is scoped to the market (invariant 2),
 * and a merge across markets would let a foreign price pose as the cheapest.
 */
final class GroupMerger
{
    public function __construct(private readonly ProductGrouper $grouper) {}

    public function merge(ProductGroup $loser, ProductGroup $winner, ?User $by = null, ?string $reason = null): void
    {
        $this->guard($loser, $winner);

        DB::transaction(function () use ($loser, $winner, $by, $reason): void {
            $market = $loser->market->value;
            $l = $loser->id;
            $w = $winner->id;

            // 1. Identity.
            IdentityAlias::query()
                ->where('market', $market)
                ->where('to_key', $loser->identity_key)
                ->update(['to_key' => $winner->identity_key, 'updated_at' => now()]);

            // An alias FROM the winner's key would now point at itself. It
            // cannot exist for a live winner, but a stale one must not survive
            // to trip the not-self check or loop.
            IdentityAlias::query()->where('market', $market)->whereColumn('from_key', 'to_key')->delete();

            IdentityAlias::query()->updateOrCreate(
                ['market' => $market, 'from_key' => $loser->identity_key],
                ['to_key' => $winner->identity_key, 'reason' => $reason, 'created_by' => $by?->id],
            );

            // 2. Every row that points at a product.
            DB::update('UPDATE products SET group_id = ? WHERE group_id = ?', [$w, $l]);

            $this->mergeListItems($l, $w);
            $this->mergePlanItems($l, $w);

            // A gift somebody noted they gave (gift-history.md). No unique
            // constraint on the product, so it just moves.
            DB::update('UPDATE recipient_gifts SET group_id = ? WHERE group_id = ?', [$w, $l]);

            foreach (['price_alerts', 'restock_alerts'] as $table) {
                // Unique on (group_id, user_id) for signed-in alerts only; a
                // guest's alert (by email) has no constraint and just moves.
                DB::delete(
                    "DELETE FROM {$table} l WHERE l.group_id = ? AND l.user_id IS NOT NULL
                       AND EXISTS (SELECT 1 FROM {$table} k WHERE k.group_id = ? AND k.user_id = l.user_id)",
                    [$l, $w],
                );
                DB::update("UPDATE {$table} SET group_id = ? WHERE group_id = ?", [$w, $l]);
            }

            DB::delete(
                'DELETE FROM community_answer_picks l WHERE l.group_id = ?
                   AND EXISTS (SELECT 1 FROM community_answer_picks k WHERE k.group_id = ? AND k.answer_id = l.answer_id)',
                [$l, $w],
            );
            DB::update('UPDATE community_answer_picks SET group_id = ? WHERE group_id = ?', [$w, $l]);

            /*
             * Thumbs on Find a gift's ideas (find-a-gift.md). One per person
             * and product, and one per voter and product: where both products
             * had one, the winner's is kept. Moved rather than left on the
             * loser, or a product turned down for somebody would come back
             * under the winner's id.
             */
            foreach (['recipient_feedback' => 'recipient_id', 'gift_votes' => 'voter_hash'] as $table => $who) {
                DB::delete(
                    "DELETE FROM {$table} l WHERE l.group_id = ?
                       AND EXISTS (SELECT 1 FROM {$table} k WHERE k.group_id = ? AND k.{$who} = l.{$who})",
                    [$l, $w],
                );
                DB::update("UPDATE {$table} SET group_id = ? WHERE group_id = ?", [$w, $l]);
            }

            /*
             * A published Cove holding both keeps its loser card rather than
             * showing the same product twice. That card shows the loser's
             * (now empty) offers, which is how a vanished product already
             * looks on an old Cove; deleting a pick would change a page an
             * editor approved.
             */
            DB::update(
                'UPDATE daily_picks l SET group_id = ? WHERE l.group_id = ?
                   AND NOT EXISTS (SELECT 1 FROM daily_picks k WHERE k.set_id = l.set_id AND k.group_id = ?)',
                [$w, $l, $w],
            );
            DB::update('UPDATE daily_pick_sets SET challenge_group_id = ? WHERE challenge_group_id = ?', [$w, $l]);
            DB::update('UPDATE popular_ranks SET group_id = ? WHERE group_id = ?', [$w, $l]);

            // "People who want this also want…" is rebuilt from the lists every
            // night (CountListSignals), and the lists have just moved to the
            // winner. Repointing would collide with the winner's own links
            // and could link the winner to itself; the next count restores it.
            DB::delete('DELETE FROM product_links WHERE group_a = ? OR group_b = ?', [$l, $l]);

            // 3. Ids inside JSON.
            $this->rewriteJsonIds('cove_plans', 'pinned_group_ids', $l, $w);
            $this->rewriteJsonIds('search_alerts', 'seen_group_ids', $l, $w);

            // 4. The loser stays, pointing at the winner, and so does anything
            // merged into it before: one hop, always.
            DB::update('UPDATE product_groups SET merged_into_id = ? WHERE merged_into_id = ?', [$w, $l]);
            DB::update('UPDATE product_groups SET merged_into_id = ?, updated_at = now() WHERE id = ?', [$w, $l]);

            // What an editor wrote about the loser is kept on the winner when
            // the winner has none of its own: a hand-written title, gift tags.
            $this->carryEditorial($loser, $winner);

            $this->settleCandidates($l, $w, $market, $by);

            // 5. Aggregates, for both: the winner gains offers, the loser is
            // zeroed and drops out of every listing that wants stock and price.
            $this->grouper->recomputeGroups($loser->market, [$w, $l]);
        });

        // One card where there were two: cached searches of the market would
        // still list the loser's id (and drop it at render, a card short).
        SearchGenerations::bumpMarket($loser->market);

        $loser->refresh();
        $winner->refresh();
    }

    private function guard(ProductGroup $loser, ProductGroup $winner): void
    {
        if ($loser->id === $winner->id) {
            throw new InvalidArgumentException('A product cannot be merged into itself.');
        }

        if ($loser->market !== $winner->market) {
            throw new InvalidArgumentException('Products in different markets are never merged: their prices are not comparable.');
        }

        if ($loser->merged_into_id !== null) {
            throw new InvalidArgumentException("Product {$loser->id} was already merged into {$loser->merged_into_id}.");
        }

        if ($winner->merged_into_id !== null) {
            throw new InvalidArgumentException("Product {$winner->id} was merged into {$winner->merged_into_id}; merge into that one instead.");
        }
    }

    /**
     * Lists holding both products keep one item.
     *
     * The winner's item is the one kept, because it is the one the unique
     * index already has. The loser's note is appended (a person wrote it), and
     * its claim moves across when the kept item has none, so a gift someone
     * already bought is not offered again.
     */
    private function mergeListItems(int $l, int $w): void
    {
        $pairs = DB::select(
            'SELECT l.id AS loser_item, k.id AS kept_item
               FROM wishlist_items l
               JOIN wishlist_items k ON k.wishlist_id = l.wishlist_id AND k.group_id = ?
              WHERE l.group_id = ?',
            [$w, $l],
        );

        foreach ($pairs as $pair) {
            $lost = DB::table('wishlist_items')->where('id', $pair->loser_item)->first();
            $kept = DB::table('wishlist_items')->where('id', $pair->kept_item)->first();

            $update = ['note' => $this->joinNotes($kept->note, $lost->note), 'updated_at' => now()];

            if ($kept->claimed_by_hash === null && $lost->claimed_by_hash !== null) {
                $update += [
                    'claimed_by_hash' => $lost->claimed_by_hash,
                    'claimed_by_name' => $lost->claimed_by_name,
                    'claimed_at' => $lost->claimed_at,
                    'marked_sent_at' => $kept->marked_sent_at ?? $lost->marked_sent_at,
                ];
            }

            // Priority: the higher of the two, so a "must have" is not demoted.
            if ($lost->priority !== null && ($kept->priority === null || $lost->priority > $kept->priority)) {
                $update['priority'] = $lost->priority;
            }

            DB::table('wishlist_items')->where('id', $kept->id)->update($update);
            DB::table('wishlist_items')->where('id', $lost->id)->delete();
        }

        DB::update('UPDATE wishlist_items SET group_id = ? WHERE group_id = ?', [$w, $l]);
    }

    /** Cove plans holding both keep the winner's item, with both notes. */
    private function mergePlanItems(int $l, int $w): void
    {
        $pairs = DB::select(
            'SELECT l.id AS loser_item, l.note AS loser_note, k.id AS kept_item, k.note AS kept_note
               FROM cove_plan_items l
               JOIN cove_plan_items k ON k.plan_id = l.plan_id AND k.group_id = ?
              WHERE l.group_id = ?',
            [$w, $l],
        );

        foreach ($pairs as $pair) {
            DB::table('cove_plan_items')->where('id', $pair->kept_item)
                ->update(['note' => $this->joinNotes($pair->kept_note, $pair->loser_note), 'updated_at' => now()]);
            DB::table('cove_plan_items')->where('id', $pair->loser_item)->delete();
        }

        DB::update('UPDATE cove_plan_items SET group_id = ? WHERE group_id = ?', [$w, $l]);
    }

    private function joinNotes(?string $kept, ?string $lost): ?string
    {
        $kept = trim((string) $kept);
        $lost = trim((string) $lost);

        if ($lost === '' || $lost === $kept) {
            return $kept === '' ? null : $kept;
        }

        return $kept === '' ? $lost : $kept."\n\n".$lost;
    }

    /**
     * Replace the loser's id with the winner's in a JSON array of ids, keeping
     * the order (a pinned list is ordered) and dropping the duplicate if the
     * winner was already there. Found with `@>`, for both an int and a string
     * element, because the `array` cast has stored both over time.
     */
    private function rewriteJsonIds(string $table, string $column, int $l, int $w): void
    {
        $rows = DB::table($table)
            ->whereRaw("{$column} @> ?::jsonb", [json_encode([$l])])
            ->orWhereRaw("{$column} @> ?::jsonb", [json_encode([(string) $l])])
            ->get(['id', $column]);

        foreach ($rows as $row) {
            $ids = [];

            foreach ((array) json_decode((string) $row->{$column}, true) as $id) {
                $id = (int) $id === $l ? $w : (int) $id;

                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            DB::table($table)->where('id', $row->id)->update([$column => json_encode($ids)]);
        }
    }

    private function carryEditorial(ProductGroup $loser, ProductGroup $winner): void
    {
        $update = [];

        if ($winner->display_title === null && $loser->display_title !== null) {
            $update['display_title'] = $loser->display_title;
        }

        $tags = array_values(array_unique([...$winner->giftTags(), ...$loser->giftTags()]));
        if ($tags !== $winner->giftTags()) {
            $update['gift_tags'] = json_encode($tags);
        }

        if ($update !== []) {
            DB::table('product_groups')->where('id', $winner->id)->update($update);
        }
    }

    /**
     * Keep the review queue honest after a merge.
     *
     * - The pair itself, if a rule had proposed it, is marked merged.
     * - Other pending pairs with the loser are dropped: the loser no longer
     *   exists as a product, and the next candidate run proposes the same
     *   pairs against the winner if they still hold.
     * - A pair someone REJECTED with the loser is carried to the winner as a
     *   rejection, so "not the same" is not asked again under a new id.
     *   Recorded as `manual`, so it does not count in any rule's precision.
     */
    private function settleCandidates(int $l, int $w, string $market, ?User $by): void
    {
        DB::table('match_candidates')
            ->where('group_a', min($l, $w))->where('group_b', max($l, $w))
            ->update([
                'status' => MatchStatus::Merged->value,
                'decided_by' => $by?->id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('match_candidates')
            ->where('status', MatchStatus::Pending->value)
            ->where(fn ($q) => $q->where('group_a', $l)->orWhere('group_b', $l))
            ->delete();

        $rejected = DB::table('match_candidates')
            ->where('status', MatchStatus::Rejected->value)
            ->where(fn ($q) => $q->where('group_a', $l)->orWhere('group_b', $l))
            ->get(['group_a', 'group_b', 'decided_by']);

        foreach ($rejected as $row) {
            $other = $row->group_a === $l ? $row->group_b : $row->group_a;

            if ($other === $w) {
                continue;
            }

            DB::table('match_candidates')->insertOrIgnore([
                'market' => $market,
                'group_a' => min($w, $other),
                'group_b' => max($w, $other),
                'rule' => MatchRule::Manual->value,
                'status' => MatchStatus::Rejected->value,
                'decided_by' => $row->decided_by,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
