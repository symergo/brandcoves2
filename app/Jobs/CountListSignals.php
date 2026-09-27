<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Services\Gift\CrowdPicks;
use App\Services\Gift\GiftTags;
use App\Services\Search\GiftIntentParser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;

/**
 * What people's lists teach the catalogue, counted once a night.
 *
 * Roadmap step 4, engine F and G (docs/strategy.md; docs/features/list-signals.md).
 * The owner's idea: a list made with an intent says "these things fit that",
 * and summed over many lists that is a better answer than any tag an editor
 * can write. Two outputs:
 *
 * 1. **Crowd tags** (`product_groups.crowd_tags`). Each list's intent is
 *    deduced from everything the list says about itself, wish lists and gift
 *    lists alike: who it is for (the recipient's relationship, age band and
 *    interests), its occasion, its title and description (read by the same
 *    GiftIntentParser as the search box), and the editors' interest tags that
 *    several products on it share, since products on one list share its
 *    intent. A product earns a tag once enough different people's lists
 *    carrying that tag hold it.
 * 2. **Product links** (`product_links`): products that sit on the same lists,
 *    counted in different people. "Often on the same lists as this."
 * 3. **Crowd picks** (`crowd_picks`): what people shopping for a kind of
 *    person keep on their lists, per single fact ("a father") and per pair
 *    ("a father who likes cooking"). A tag says a product suits a father;
 *    only a pair counted on the same lists says it suits a father who cooks.
 *    See docs/features/crowd-picks.md.
 *
 * ## The rules that keep it safe
 *
 * - People, not lists: every count is of distinct owners, so one person with
 *   ten lists is one, and nobody can move a tag alone.
 * - A threshold (`giftcoves.list_signals.min_owners`) before anything counts.
 * - Counts only: neither output holds a list, an owner or a word anybody
 *   wrote. Private lists count too (the owner's decision, 2026-09-26); the
 *   privacy page says so.
 * - Claims are never read (invariant 4). Only accepted items count.
 * - Crowd tags never touch `gift_tags`: people's lists never change what an
 *   editor wrote, and the editors' own tags are the only product tags read
 *   back into a list's intent, so the crowd cannot feed on itself.
 * - Links stay inside one market (invariant 2).
 */
#[Queue('batch')]
class CountListSignals implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    /** Lists read per round trip when their titles are parsed. */
    private const CHUNK = 1000;

    /**
     * An interest the editors tagged on this many products of one list is
     * that list's interest. Two, because one tagged product says something
     * about that product, and two sharing a tag say something about the list.
     */
    private const SHARED_PRODUCT_TAG = 2;

    public function handle(GiftIntentParser $parser): void
    {
        $minOwners = (int) config('giftcoves.list_signals.min_owners', 5);

        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS list_intent (wishlist_id uuid, tag text) ON COMMIT PRESERVE ROWS');
        DB::statement('TRUNCATE list_intent');

        $this->fromRecipientsAndOccasions();
        $this->fromWords($parser);
        $this->fromSharedProductTags();

        DB::transaction(function () use ($minOwners): void {
            $this->writeCrowdTags($minOwners);
            $this->writeLinks($minOwners);
            $this->writeCrowdPicks($minOwners);
        });

        DB::statement('DROP TABLE IF EXISTS list_intent');

        // Tonight's counts replace every cached lookup of last night's.
        CrowdPicks::forgetCached();
    }

    /** Who a list is for, and what for. */
    private function fromRecipientsAndOccasions(): void
    {
        DB::insert(
            <<<'SQL'
                INSERT INTO list_intent (wishlist_id, tag)
                SELECT w.id, 'recipient:' || r.relationship
                FROM wishlists w JOIN recipients r ON r.id = w.recipient_id
                WHERE r.relationship = ANY (?::text[])
                UNION
                SELECT w.id, 'age:' || r.age_band
                FROM wishlists w JOIN recipients r ON r.id = w.recipient_id
                WHERE r.age_band = ANY (?::text[])
                UNION
                SELECT w.id, 'interest:' || i.value
                FROM wishlists w JOIN recipients r ON r.id = w.recipient_id
                CROSS JOIN LATERAL jsonb_array_elements_text(r.interests) AS i(value)
                WHERE i.value = ANY (?::text[])
                UNION
                SELECT w.id, 'occasion:' || w.event_type
                FROM wishlists w
                WHERE w.event_type IS NOT NULL AND w.event_type <> ?
            SQL,
            [
                $this->textArray(RecipientType::values()),
                $this->textArray(GiftTags::AGE_BANDS),
                $this->textArray(array_map(fn (Interest $i) => $i->value, Interest::cases())),
                EventType::Other->value,
            ],
        );
    }

    /**
     * What a list's own title and description say: "Kerst voor papa",
     * "Verjaardag Emma, ze houdt van bakken". Read as a gift context, since a
     * list is one.
     */
    private function fromWords(GiftIntentParser $parser): void
    {
        DB::table('wishlists')
            ->select(['id', 'market', 'title', 'description'])
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($lists) use ($parser): void {
                $rows = [];

                foreach ($lists as $list) {
                    $market = Market::tryFrom((string) $list->market);

                    if ($market === null) {
                        continue;
                    }

                    $parsed = $parser->parse(trim($list->title.'. '.($list->description ?? '')), $market, giftContext: true);

                    $tags = array_map(fn (string $i) => GiftTags::interest($i), $parsed->interests);

                    if ($parsed->relationship !== null) {
                        $tags[] = GiftTags::recipient($parsed->relationship);
                    }

                    if ($parsed->occasion !== null) {
                        $tags[] = GiftTags::occasion($parsed->occasion);
                    }

                    foreach (array_unique($tags) as $tag) {
                        $rows[] = ['wishlist_id' => $list->id, 'tag' => $tag];
                    }
                }

                if ($rows !== []) {
                    DB::table('list_intent')->insert($rows);
                }
            });
    }

    /** An interest several products on one list were tagged with by editors. */
    private function fromSharedProductTags(): void
    {
        DB::insert(
            <<<'SQL'
                INSERT INTO list_intent (wishlist_id, tag)
                SELECT wi.wishlist_id, t.tag
                FROM wishlist_items wi
                JOIN product_groups g ON g.id = wi.group_id
                CROSS JOIN LATERAL jsonb_array_elements_text(g.gift_tags) AS t(tag)
                WHERE wi.accepted_at IS NOT NULL AND t.tag LIKE 'interest:%'
                GROUP BY wi.wishlist_id, t.tag
                HAVING count(DISTINCT wi.group_id) >= ?
            SQL,
            [self::SHARED_PRODUCT_TAG],
        );
    }

    private function writeCrowdTags(int $minOwners): void
    {
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS earned_tags (group_id bigint PRIMARY KEY, tags jsonb) ON COMMIT DROP');
        // Empty even when it survived: inside an outer transaction (a test,
        // or a second run in one connection) the transaction above is only a
        // savepoint, ON COMMIT never fires, and last run's rows would collide.
        DB::statement('TRUNCATE earned_tags');

        DB::insert(
            <<<'SQL'
                INSERT INTO earned_tags (group_id, tags)
                SELECT group_id, jsonb_agg(tag ORDER BY tag)
                FROM (
                    SELECT wi.group_id, li.tag
                    FROM (SELECT DISTINCT wishlist_id, tag FROM list_intent) li
                    JOIN wishlists w ON w.id = li.wishlist_id
                    JOIN wishlist_items wi ON wi.wishlist_id = w.id
                    WHERE wi.group_id IS NOT NULL AND wi.accepted_at IS NOT NULL
                    GROUP BY wi.group_id, li.tag
                    HAVING count(DISTINCT COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text)) >= ?
                ) counted
                GROUP BY group_id
            SQL,
            [$minOwners],
        );

        // Tags a product no longer earns go. Only rows that carry some.
        DB::statement(
            <<<'SQL'
                UPDATE product_groups g SET crowd_tags = '[]'::jsonb
                WHERE g.crowd_tags <> '[]'::jsonb
                  AND NOT EXISTS (SELECT 1 FROM earned_tags e WHERE e.group_id = g.id)
            SQL
        );

        DB::statement(
            <<<'SQL'
                UPDATE product_groups g SET crowd_tags = e.tags
                FROM earned_tags e
                WHERE g.id = e.group_id AND g.crowd_tags IS DISTINCT FROM e.tags
            SQL
        );
    }

    /** Products on the same lists, in different people, within one market. */
    private function writeLinks(int $minOwners): void
    {
        DB::table('product_links')->delete();

        DB::insert(
            <<<'SQL'
                INSERT INTO product_links (group_a, group_b, market, owners)
                SELECT a.group_id, b.group_id, ga.market,
                       count(DISTINCT COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text))
                FROM wishlist_items a
                JOIN wishlist_items b ON b.wishlist_id = a.wishlist_id AND b.group_id > a.group_id
                JOIN wishlists w ON w.id = a.wishlist_id
                JOIN product_groups ga ON ga.id = a.group_id
                JOIN product_groups gb ON gb.id = b.group_id AND gb.market = ga.market
                WHERE a.accepted_at IS NOT NULL AND b.accepted_at IS NOT NULL
                GROUP BY a.group_id, b.group_id, ga.market
                HAVING count(DISTINCT COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text)) >= ?
            SQL,
            [$minOwners],
        );
    }

    /**
     * What people shopping for a kind of person picked.
     *
     * A list's context is every fact of its intent (list_intent, above) and
     * every pair of them, so a list for "papa" with a cooking interest counts
     * for `recipient:father`, `interest:cooking` and
     * `interest:cooking+recipient:father`. Pairs are what crowd tags cannot
     * say: five people's lists for a father and five other people's cooking
     * lists make both tags, and not one of them was shopping for a father who
     * cooks. Pairs are joined in byte order (COLLATE "C"), the order
     * CrowdPicks builds its keys in, because a locale's collation skips
     * punctuation and would sort `age:30-49` differently from PHP.
     *
     * Also read here, and only here: interests the crowd's own tags give two
     * or more products on the list (the way TasteBrief::fromList() reads a
     * list). Safe because this table is never read back into list_intent or
     * crowd_tags, so the crowd still cannot feed on itself.
     *
     * Same market on both sides (invariant 2): a list's context speaks for
     * the shoppers of its own market.
     */
    private function writeCrowdPicks(int $minOwners): void
    {
        DB::table('crowd_picks')->delete();

        DB::insert(
            <<<'SQL'
                INSERT INTO crowd_picks (market, context, group_id, owners)
                WITH intent AS (
                    SELECT DISTINCT wishlist_id, tag FROM list_intent
                    UNION
                    SELECT wi.wishlist_id, t.tag
                    FROM wishlist_items wi
                    JOIN product_groups g ON g.id = wi.group_id
                    CROSS JOIN LATERAL jsonb_array_elements_text(g.crowd_tags) AS t(tag)
                    WHERE wi.accepted_at IS NOT NULL AND t.tag LIKE 'interest:%'
                    GROUP BY wi.wishlist_id, t.tag
                    HAVING count(DISTINCT wi.group_id) >= ?
                ),
                contexts AS (
                    SELECT wishlist_id, tag AS context FROM intent
                    UNION
                    SELECT a.wishlist_id, a.tag || '+' || b.tag
                    FROM intent a
                    JOIN intent b ON b.wishlist_id = a.wishlist_id AND a.tag COLLATE "C" < b.tag COLLATE "C"
                )
                SELECT g.market, c.context, wi.group_id,
                       count(DISTINCT COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text))
                FROM contexts c
                JOIN wishlists w ON w.id = c.wishlist_id
                JOIN wishlist_items wi ON wi.wishlist_id = w.id
                JOIN product_groups g ON g.id = wi.group_id AND g.market = w.market
                WHERE wi.accepted_at IS NOT NULL
                GROUP BY g.market, c.context, wi.group_id
                HAVING count(DISTINCT COALESCE('u' || w.owner_user_id::text, 'a' || w.owner_anon_id::text)) >= ?
            SQL,
            [self::SHARED_PRODUCT_TAG, $minOwners],
        );
    }

    /** @param  list<string>  $values */
    private function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }
}
