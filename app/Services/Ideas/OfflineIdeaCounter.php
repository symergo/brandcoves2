<?php

declare(strict_types=1);

namespace App\Services\Ideas;

use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use App\Enums\Source;
use App\Models\OfflineIdea;
use Illuminate\Support\Facades\DB;

/**
 * Counts what people typed onto their lists by hand, and proposes an idea
 * once enough different people wrote the same thing.
 *
 * The owner's request (2026-09-26): "use the gifts created by others as
 * suggestions for others." An offline item ("a cooking workshop", "a spa day")
 * is exactly what the catalogue cannot suggest, so the people who thought of
 * it are the only source. See docs/features/offline-ideas.md.
 *
 * ## Why this is careful
 *
 * A typed title can name a person or say something private ("tickets for
 * Anna's concert"). So:
 *
 * - **Only titles at least N different people wrote count**
 *   (`giftcoves.offline_ideas.min_owners`, 5, the same bar as the list
 *   signals). Different owners, not lists or items: one person typing it on
 *   ten lists is one.
 * - **Nothing is shown from here.** This only proposes. A person reads each
 *   idea and writes the wording before it can appear (the review screen,
 *   `App\Filament\Pages\OfflineIdeaReview`).
 * - **What is kept is the fold, not the text**: the key (`IdeaKey`), how many
 *   people, and one spelling for the reviewer, which is emptied once decided.
 * - **Only items with no link and no product.** An item with a link was named
 *   after the shop until its page was read, and would teach us shop names; an
 *   item joined to a product is a product. Photos are never read.
 * - **Only accepted items**: a visitor's suggestion the owner did not take is
 *   not the owner's.
 */
class OfflineIdeaCounter
{
    /** Items read per round trip. */
    private const CHUNK = 2000;

    /**
     * @return array{items: int, proposed: int, refreshed: int, dropped: int}
     */
    public function run(): array
    {
        $minOwners = (int) config('giftcoves.offline_ideas.min_owners', 5);

        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS offline_title_counts (market text, key text, owner text, spelling text) ON COMMIT PRESERVE ROWS');
        DB::statement('TRUNCATE offline_title_counts');

        $items = $this->fold();

        $earned = DB::select(
            <<<'SQL'
                SELECT c.market, c.key, c.owners, s.spelling
                FROM (
                    SELECT market, key, count(DISTINCT owner) AS owners
                    FROM offline_title_counts
                    GROUP BY market, key
                    HAVING count(DISTINCT owner) >= ?
                ) c
                JOIN (
                    -- The spelling the most people used, for the reviewer.
                    SELECT DISTINCT ON (market, key) market, key, spelling
                    FROM (
                        SELECT market, key, spelling, count(DISTINCT owner) AS n
                        FROM offline_title_counts
                        GROUP BY market, key, spelling
                    ) per_spelling
                    ORDER BY market, key, n DESC, spelling
                ) s ON s.market = c.market AND s.key = c.key
            SQL,
            [$minOwners],
        );

        DB::statement('DROP TABLE IF EXISTS offline_title_counts');

        return ['items' => $items, ...DB::transaction(fn () => $this->write($earned))];
    }

    /** Fold every offline item into (market, key, owner, spelling). */
    private function fold(): int
    {
        $last = 0;
        $seen = 0;

        do {
            $rows = DB::select(
                <<<'SQL'
                    SELECT wi.id, w.market, wi.snapshot_title,
                           COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text) AS owner
                    FROM wishlist_items wi
                    JOIN wishlists w ON w.id = wi.wishlist_id
                    WHERE wi.id > ?
                      AND wi.source = ?
                      AND wi.group_id IS NULL
                      AND wi.snapshot_url IS NULL
                      AND wi.accepted_at IS NOT NULL
                    ORDER BY wi.id
                    LIMIT ?
                SQL,
                [$last, Source::Manual->value, self::CHUNK],
            );

            $insert = [];

            foreach ($rows as $row) {
                $last = (int) $row->id;
                $market = Market::tryFrom((string) $row->market);

                if ($market === null || $row->owner === null) {
                    continue;
                }

                $key = IdeaKey::of((string) $row->snapshot_title, $market->language());

                if ($key === null) {
                    continue;
                }

                $insert[] = [
                    'market' => $market->value,
                    'key' => $key,
                    'owner' => $row->owner,
                    'spelling' => mb_substr(trim((string) $row->snapshot_title), 0, 160),
                ];
            }

            if ($insert !== []) {
                DB::table('offline_title_counts')->insert($insert);
            }

            $seen += count($rows);
        } while (count($rows) === self::CHUNK);

        return $seen;
    }

    /**
     * @param  list<object{market: string, key: string, owners: int, spelling: string}>  $earned
     * @return array{proposed: int, refreshed: int, dropped: int}
     */
    private function write(array $earned): array
    {
        $existing = OfflineIdea::query()->get()->keyBy(fn (OfflineIdea $i) => $i->market->value.'|'.$i->key);

        /*
         * An approved idea's own wording coming back. Every "Add to my list"
         * from an idea puts its wording on somebody's list, and a reviewer who
         * reworded "kookworkshop" as "Een workshop koken" would otherwise see
         * `workshopkoken` proposed again once five people had added it.
         */
        $wordings = [];

        foreach ($existing as $idea) {
            if ($idea->title !== null) {
                $folded = IdeaKey::of($idea->title, $idea->market->language());

                if ($folded !== null && $folded !== $idea->key) {
                    $wordings[$idea->market->value.'|'.$folded] = true;
                }
            }
        }

        $now = now();
        $proposed = 0;
        $refreshed = 0;
        $kept = [];

        foreach ($earned as $row) {
            $id = $row->market.'|'.$row->key;

            if (isset($wordings[$id])) {
                continue;
            }

            $kept[$id] = true;
            $idea = $existing->get($id);

            if ($idea === null) {
                OfflineIdea::query()->create([
                    'market' => $row->market,
                    'key' => $row->key,
                    'sample_title' => $row->spelling,
                    'status' => OfflineIdeaStatus::Pending,
                    'owners' => (int) $row->owners,
                    'last_seen_at' => $now,
                ]);
                $proposed++;

                continue;
            }

            $idea->update([
                'owners' => (int) $row->owners,
                'last_seen_at' => $now,
                // Only a waiting idea keeps a spelling; a decided one has none.
                ...($idea->status === OfflineIdeaStatus::Pending ? ['sample_title' => $row->spelling] : []),
            ]);
            $refreshed++;
        }

        /*
         * A waiting idea fewer people now write (items deleted, lists gone)
         * leaves the queue: it no longer passes the bar that made it safe to
         * show a reviewer. A decided one stays: its wording is the reviewer's
         * own, and a rejection must keep the idea from coming back.
         */
        $dropped = 0;

        foreach ($existing as $id => $idea) {
            if ($idea->status === OfflineIdeaStatus::Pending && ! isset($kept[$id])) {
                $idea->delete();
                $dropped++;
            }
        }

        return ['proposed' => $proposed, 'refreshed' => $refreshed, 'dropped' => $dropped];
    }
}
