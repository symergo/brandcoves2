<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\CoveKind;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Feed;
use App\Models\GuideTopic;
use App\Models\PageBlock;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Moves editorial work between environments. Never people, never the catalogue.
 *
 * ## Why this exists at all
 *
 * The catalogue regenerates from feeds, so it is never copied. Editorial does
 * not regenerate: a guide is AI-written, and asking production to write its own
 * would spend the budget twice *and* produce different words, leaving two
 * environments that disagree about what the same guide says. Promotion is the
 * only way they stay one site.
 *
 * ## The constraint that shapes everything here
 *
 * Editorial rows point at products by **environment-local integer id** —
 * `daily_picks.group_id`, `guide_items.group_id`, `cove_plans.pinned_group_ids`.
 * Each environment assigns those ids from its own ingestion, so they do not
 * line up. Copying rows verbatim would not fail; it would point a hand-picked
 * Cove at whatever product happens to hold that id on the far side. **A wrong
 * product is far worse than a missing one**, because nothing ever surfaces it —
 * the page renders, the price is real, and the pick is simply not the one
 * anybody chose.
 *
 * So every product reference is rewritten as `(market, identity_key)` on the way
 * out and resolved back on the way in. That pair is unique by invariant 2, and
 * it is the only handle both environments agree on.
 *
 * A reference the target cannot resolve is **dropped and named** — never
 * guessed at, never left dangling. `guide_items.group_id` is `NOT NULL`, which
 * makes dropping the only option there anyway; the rest follow the same rule so
 * the behaviour is one rule rather than a table of exceptions.
 *
 * ## Allowlist, not denylist
 *
 * {@see self::SURFACES} names what may travel. A denylist of personal tables
 * would silently include every table added afterwards, and the cost of being
 * wrong is exporting real people's gift lists into a second live system.
 * Columns are filtered the same way, which is how `created_by` and `author_id`
 * stay behind.
 */
class ContentEnvelope
{
    /**
     * Bumped when the envelope shape changes in a way an old import cannot read.
     *
     * Version 2 retired the guides surface: guides are Coves now, and they
     * travel in editions with every other kind. A v1 envelope is still
     * readable — its guides rows are folded into editions on the way in, so an
     * export taken before the change still imports. See importLegacyGuides().
     *
     * Version 3 retired the `copy` surface, when page copy stopped being slots in
     * a code registry and became blocks an editor arranges. A v2 envelope's copy
     * rows are **dropped and named** rather than converted: every environment is
     * seeded identically by the release migration, so importing them would recreate
     * the same sentences under a different identity and print the region twice.
     *
     * The comparison below was `!==` until version 3, which made every one of these
     * paragraphs a lie — a v1 envelope was rejected three frames before
     * `importLegacyGuides()` could ever run, so that method has been unreachable
     * since the day it was written. The question this guard is asking is "can this
     * build read that file", and it is a file from a **newer** build that it cannot.
     */
    public const VERSION = 3;

    /**
     * What may travel, in dependency order.
     *
     * Order matters on import: an edition must exist before guide_topics and
     * another edition's
eatured_cove_id can point at it.
     */
    public const SURFACES = ['feeds', 'blocks', 'editions', 'topics', 'plans'];

    /**
     * Surfaces an older envelope may carry that this build no longer writes.
     *
     * Accepted on import so an export taken before the change still loads, and
     * handled by an arm that says what it did with them rather than silently
     * skipping — a dropped row nobody is told about is the failure mode this
     * whole class is arranged to avoid.
     */
    private const RETIRED = ['copy', 'guides'];

    /**
     * Columns stripped on the way out, per surface.
     *
     * Three kinds, and each is deliberate:
     *
     * - **People.** `created_by` and `author_id` are user ids. They are also
     *   meaningless on the far side, where that user does not exist.
     * - **Audience.** `mindblown_count` and `meh_count` are reactions from
     *   staging's visitors. Carrying them would put invented engagement in
     *   front of real users.
     * - **Runtime state.** A feed's `last_run_at` and `last_error` describe the
     *   environment that ran it, not the feed.
     */
    private const DROP = [
        'feeds' => ['id', 'merchant_id', 'last_run_at', 'last_row_count', 'last_error', 'created_at', 'updated_at'],
        'blocks' => ['id', 'author_id', 'created_at', 'updated_at'],
        'topics' => ['id', 'guide_id', 'edition_id', 'plan_id', 'created_at', 'updated_at', 'last_attempt_at', 'attempts'],
        'editions' => ['id', 'guide_id', 'featured_cove_id', 'folded_from_guide_id', 'challenge_group_id', 'created_at', 'updated_at'],
        'plans' => ['id', 'edition_id', 'created_by', 'created_at', 'updated_at'],
    ];

    /**
     * Export the named surfaces, with every product id rewritten as an identity.
     *
     * @param  list<string>  $surfaces
     * @return array<string, mixed>
     */
    public function export(array $surfaces): array
    {
        $surfaces = $this->ordered($surfaces);
        $out = [];

        foreach ($surfaces as $surface) {
            $out[$surface] = match ($surface) {
                'feeds' => $this->exportFeeds(),
                'blocks' => $this->exportBlocks(),
                'topics' => $this->exportTopics(),
                'editions' => $this->exportEditions(),
                'plans' => $this->exportPlans(),
            };
        }

        return [
            'version' => self::VERSION,
            'surfaces' => $out,
        ];
    }

    /**
     * Resolve an envelope against this environment's catalogue and apply it.
     *
     * Everything is resolved and counted before anything is written, so a dry
     * run and a real run report identically — the only difference is whether
     * the transaction commits.
     *
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $surfaces
     * @return array<string, array{created:int, updated:int, dropped:list<string>}>
     */
    public function import(array $envelope, array $surfaces, bool $dryRun = true): array
    {
        $version = (int) ($envelope['version'] ?? 0);

        if ($version < 1) {
            throw new \RuntimeException('This file is not a content envelope.');
        }

        if ($version > self::VERSION) {
            throw new \RuntimeException(
                "Envelope is version {$version}, exported by a newer build than this one, which reads "
                .self::VERSION.'. Deploy first, then import.'
            );
        }

        $payload = (array) ($envelope['surfaces'] ?? []);
        $report = [];

        DB::beginTransaction();

        try {
            foreach ($this->ordered($surfaces, forImport: true) as $surface) {
                if (! array_key_exists($surface, $payload)) {
                    continue;
                }

                $rows = (array) $payload[$surface];

                $report[$surface] = match ($surface) {
                    'feeds' => $this->importFeeds($rows),
                    'blocks' => $this->importBlocks($rows),
                    // A surface this build no longer writes. Counted and named
                    // rather than skipped, so the report says what happened.
                    'copy' => $this->dropRetired($rows, 'copy templates: page copy is blocks now, and this environment already has them'),
                    // A v1 envelope. Its guides become editions on the way in,
                    // so an export taken before the fold still imports.
                    'guides' => $this->importLegacyGuides($rows),
                    'topics' => $this->importTopics($rows),
                    'editions' => $this->importEditions($rows),
                    'plans' => $this->importPlans($rows),
                };
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // The dry run does the entire job and then throws it away. Resolving for
        // real is the only way the counts can be trusted, and a report built
        // from a cheaper simulation would drift from what the write actually
        // does — which is the one thing a dry run must never do.
        $dryRun ? DB::rollBack() : DB::commit();

        return $report;
    }

    // --- Product identity ----------------------------------------------------

    /**
     * `(market, identity_key)` for a local group id, or null if it has gone.
     *
     * @return array{market:string, identity_key:string}|null
     */
    private function identify(?int $groupId): ?array
    {
        if ($groupId === null) {
            return null;
        }

        $row = ProductGroup::query()->whereKey($groupId)->first(['market', 'identity_key']);

        if ($row === null) {
            return null;
        }

        return [
            'market' => is_string($row->market) ? $row->market : $row->market->value,
            'identity_key' => (string) $row->identity_key,
        ];
    }

    /** The local id for an exported identity, or null when this environment lacks the product. */
    private function resolve(mixed $ref): ?int
    {
        if (! is_array($ref) || ! isset($ref['market'], $ref['identity_key'])) {
            return null;
        }

        return ProductGroup::query()
            ->where('market', $ref['market'])
            ->where('identity_key', $ref['identity_key'])
            ->value('id');
    }

    // --- Export --------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function exportFeeds(): array
    {
        return Feed::query()->orderBy('id')->get()
            ->map(fn (Feed $feed) => $this->strip($feed->getAttributes(), 'feeds'))
            ->all();
    }

    /**
     * The page templates, with their phrasings nested inside them.
     *
     * Nested rather than a second flat surface, because a variant has no
     * identity independent of its block: `(page, region, language, position)`
     * looks like a natural key for one and is not, since position is exactly
     * what an edit changes. The same shape `plans` uses for its items.
     *
     * @return list<array<string, mixed>>
     */
    private function exportBlocks(): array
    {
        return PageBlock::query()
            ->with(['variants' => fn ($q) => $q->orderBy('id')])
            ->orderBy('page')->orderBy('region')->orderBy('language')->orderBy('position')
            ->get()
            ->map(function (PageBlock $block): array {
                $row = $this->strip($block->getAttributes(), 'blocks');
                $row['conditions'] = $block->conditions ?? [];
                $row['variants'] = $block->variants
                    ->map(fn ($variant) => [
                        'body' => $variant->body,
                        'weight' => $variant->weight,
                        'enabled' => $variant->enabled,
                        'note' => $variant->note,
                    ])
                    ->all();

                return $row;
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportTopics(): array
    {
        return GuideTopic::query()->orderBy('id')->get()
            ->map(function (GuideTopic $topic): array {
                $row = $this->strip($topic->getAttributes(), 'topics');

                // Coves travel by slug, the only handle both sides share.
                $row['guide_slug'] = $topic->edition_id === null
                    ? null
                    : DailyPickSet::query()->whereKey($topic->edition_id)->value('slug');

                return $row;
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportEditions(): array
    {
        return DailyPickSet::query()->with('picks')->orderBy('id')->get()
            ->map(function (DailyPickSet $set): array {
                $row = $this->strip($set->getAttributes(), 'editions');

                $row['guide_slug'] = $set->featured_cove_id === null
                    ? null
                    : DailyPickSet::query()->whereKey($set->featured_cove_id)->value('slug');

                $row['challenge_product'] = $this->identify(
                    $set->challenge_group_id === null ? null : (int) $set->challenge_group_id
                );

                $row['picks'] = $set->picks
                    ->map(function (DailyPick $pick): ?array {
                        $ref = $this->identify($pick->group_id === null ? null : (int) $pick->group_id);

                        // An Amazon pick is stored as a decision, not a row in
                        // product_groups, so it travels on its ASIN alone.
                        if ($ref === null && blank($pick->amazon_asin)) {
                            return null;
                        }

                        $attributes = $pick->getAttributes();
                        unset(
                            $attributes['id'], $attributes['set_id'], $attributes['group_id'],
                            $attributes['created_at'], $attributes['updated_at'],
                            $attributes['mindblown_count'], $attributes['meh_count'],
                        );
                        $attributes['product'] = $ref;

                        return $attributes;
                    })
                    ->filter()
                    ->values()
                    ->all();

                return $row;
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportPlans(): array
    {
        return CovePlan::query()->orderBy('id')->get()
            ->map(function (CovePlan $plan): array {
                $row = $this->strip($plan->getAttributes(), 'plans');

                /*
                 * The curated shortlist, as portable references.
                 *
                 * Without this, `bc:export-content` would move a plan's title,
                 * blurb and prose between environments and silently leave its
                 * products behind — and the plan would look complete on the
                 * far side right up until it built a page nobody chose.
                 */
                $row['items'] = $plan->items()->get()
                    ->map(function (CovePlanItem $item): ?array {
                        $ref = $this->identify($item->group_id === null ? null : (int) $item->group_id);

                        // A pick from a source we may not mirror has no group to
                        // identify; it travels on its own id, exactly as an
                        // Amazon pick does in an edition.
                        if ($ref === null && blank($item->external_id)) {
                            return null;
                        }

                        return [
                            'product' => $ref,
                            'source' => $item->source?->value,
                            'external_id' => $item->external_id,
                            'rank' => $item->rank,
                            'note' => $item->note,
                            // The sentence under the card, distinct from `note`
                            // above, which is the reason it was chosen. Both
                            // travel: leaving `copy` behind would promote an
                            // authored article whose cards are blank on the far
                            // side, which is exactly the state it looks fine in.
                            'copy' => $item->copy,
                            'verdict' => $item->verdict,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return $row;
            })
            ->all();
    }

    // --- Import --------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importFeeds(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            /*
             * Registration travels; the switch does not.
             *
             * Whether a feed runs is a decision about *this* environment's
             * bandwidth and spend, so importing "on" would start hundreds of
             * megabytes of downloads as a side effect of a content promotion.
             *
             * Dropping the column is not enough, and a test caught it: the
             * `feeds.enabled` column defaults to **true**, so an unset value
             * arrives switched on. It has to be stated.
             */
            unset($row['enabled']);

            $key = [
                'source' => $row['source'],
                'external_feed_id' => $row['external_feed_id'],
                'market' => $row['market'],
            ];

            $existing = Feed::query()->where($key)->first();

            if ($existing !== null) {
                // Left exactly as this environment set it — the local operator's
                // decision outranks the exporting one's.
                $existing->fill($row)->save();
                $report['updated']++;

                continue;
            }

            Feed::query()->create([...$row, 'enabled' => false]);
            $report['created']++;
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importBlocks(array $rows): array
    {
        $report = $this->report();

        /*
         * Replace per (page, region, language), never merge.
         *
         * An import means "make this environment match the envelope". A merge
         * leaves behind blocks the author deleted on the far side, and no second
         * run would ever remove them — the same reasoning `importPlans()` gives
         * for its items, and it matters more here because position is meaningful:
         * merged blocks would interleave two orderings into one nonsense.
         *
         * A partial envelope carrying only Dutch replaces only Dutch, which is
         * the behaviour somebody sending one would expect.
         */
        $scopes = [];

        foreach ($rows as $row) {
            $scopes["{$row['page']}|{$row['region']}|{$row['language']}"] = true;
        }

        foreach (array_keys($scopes) as $scope) {
            [$page, $region, $language] = explode('|', $scope);

            // Deleted through the model, so the variants cascade and the page
            // cache is flushed by the model hook.
            PageBlock::query()
                ->where('page', $page)
                ->where('region', $region)
                ->where('language', $language)
                ->get()
                ->each(fn (PageBlock $block) => $block->delete());
        }

        foreach ($rows as $row) {
            $variants = $row['variants'] ?? [];
            unset($row['variants']);

            $block = PageBlock::query()->create($row);
            $report['created']++;

            foreach ($variants as $variant) {
                $block->variants()->create($variant);
            }
        }

        return $report;
    }

    /**
     * A surface this build no longer writes.
     *
     * Named in the report rather than silently ignored. Dropping loudly is what
     * this class already commits to for a product reference it cannot resolve,
     * and the reason is the same: a row that quietly did not arrive is a bug
     * nobody finds until somebody notices a page reads wrong.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function dropRetired(array $rows, string $why): array
    {
        $report = $this->report();

        if ($rows !== []) {
            $report['dropped'][] = count($rows).' '.$why;
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importLegacyGuides(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $items = (array) ($row['items'] ?? []);

            /*
             * A v1 guide, translated into the edition it would be today.
             *
             * Field for field, the same mapping GuideFold applies to a local
             * table — an envelope exported before the fold describes exactly
             * the rows that migration moved, so reading it any other way would
             * mean two answers to one question.
             */
            $edition = $this->upsert(
                DailyPickSet::query(),
                [
                    'market' => $row['market'],
                    'kind' => ($row['kind'] ?? 'buying') === 'advice'
                        ? CoveKind::Advice->value
                        : CoveKind::Guide->value,
                    'slug' => $row['slug'],
                ],
                [
                    'theme_title' => $row['title'] ?? $row['slug'],
                    'theme_slug' => $row['slug'],
                    'theme_source' => 'imported',
                    'theme_blurb' => $row['intro'] ?? null,
                    'body' => $row['body_md'] ?? null,
                    'faq' => $row['faq'] ?? null,
                    'meta_description' => $row['meta_description'] ?? null,
                    'focus_keyphrase' => $row['focus_keyphrase'] ?? null,
                    'source_queries' => $row['source_queries'] ?? [],
                    'source_volume' => $row['source_volume'] ?? 0,
                    'status' => $row['status'] ?? 'draft',
                    'published_at' => $row['published_at'] ?? null,
                    'last_checked_at' => $row['last_checked_at'] ?? null,
                ],
                $report,
            );

            // Replaced wholesale rather than merged: rank is a property of the
            // list, so a half-updated list would silently reorder itself.
            $edition->picks()->delete();

            foreach ($items as $rank => $item) {
                $groupId = $this->resolve($item['product'] ?? null);

                if ($groupId === null) {
                    $report['dropped'][] = "guide {$row['slug']}: "
                        .($item['product']['identity_key'] ?? 'unknown product');

                    continue;
                }

                $edition->picks()->create([
                    'group_id' => $groupId,
                    'rank' => $item['rank'] ?? $rank + 1,
                    'slug' => Str::slug((string) ($item['product']['identity_key'] ?? 'item')).'-'.$groupId,
                    'blurb' => $item['editorial_copy'] ?? null,
                    'verdict' => $item['verdict'] ?? null,
                    'unavailable' => (bool) ($item['unavailable'] ?? false),
                ]);
            }
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importTopics(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $slug = $row['guide_slug'] ?? null;
            unset($row['guide_slug']);

            // The Cove this topic produced, matched by the only handle both
            // sides share.
            $row['edition_id'] = $slug === null
                ? null
                : DailyPickSet::query()->where('market', $row['market'])->where('slug', $slug)->value('id');

            $this->upsert(
                GuideTopic::query(),
                ['market' => $row['market'], 'topic' => $row['topic']],
                $row,
                $report,
            );
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importEditions(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $picks = (array) ($row['picks'] ?? []);
            $slug = $row['guide_slug'] ?? null;
            $challenge = $row['challenge_product'] ?? null;
            unset($row['picks'], $row['guide_slug'], $row['challenge_product']);

            // The Cove this one points its readers at — a self-reference since
            // the fold, resolved by slug like everything else.
            $row['featured_cove_id'] = $slug === null
                ? null
                : DailyPickSet::query()->where('market', $row['market'])->where('slug', $slug)->value('id');

            // The guess-the-price round simply does not run without its product,
            // which is a better outcome than a round about the wrong one.
            $row['challenge_group_id'] = $this->resolve($challenge);

            $set = $this->upsert(
                DailyPickSet::query(),
                $this->naturalKey($row),
                $row,
                $report,
            );

            $set->picks()->delete();

            foreach ($picks as $pick) {
                $groupId = $this->resolve($pick['product'] ?? null);

                if ($groupId === null && blank($pick['amazon_asin'] ?? null)) {
                    $report['dropped'][] = 'edition '.$row['market'].' '.($row['drop_date'] ?? $row['slug'] ?? '?').': '
                        .($pick['product']['identity_key'] ?? 'unknown product');

                    continue;
                }

                unset($pick['product']);
                $pick['group_id'] = $groupId;
                $set->picks()->create($pick);
            }
        }

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created:int, updated:int, dropped:list<string>}
     */
    private function importPlans(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $items = (array) ($row['items'] ?? []);
            unset($row['items']);

            // The legacy field. An envelope written before the shortlist became
            // a table still carries pins, and they mean exactly the same thing.
            $legacy = (array) ($row['pinned_products'] ?? []);
            unset($row['pinned_products']);

            $label = 'plan '.$row['market'].' '.($row['drop_date'] ?? ($row['slug'] ?? '?'));
            $resolved = [];
            $rank = 0;

            foreach ([...$items, ...array_map(fn ($ref) => ['product' => $ref], $legacy)] as $item) {
                $rank++;

                $id = $this->resolve($item['product'] ?? null);

                if ($id === null && blank($item['external_id'] ?? null)) {
                    // Dropped rather than failed: the far environment may simply
                    // not stock this product, and losing one item off a
                    // shortlist is a smaller loss than refusing the whole plan.
                    $report['dropped'][] = $label.': '
                        .($item['product']['identity_key'] ?? 'unknown product');

                    continue;
                }

                $resolved[] = [
                    'group_id' => $id,
                    'source' => $item['source'] ?? null,
                    'external_id' => $item['external_id'] ?? null,
                    'rank' => $item['rank'] ?? $rank,
                    'note' => $item['note'] ?? null,
                    // Absent from an envelope written before the column existed,
                    // which is the ordinary case for a while yet — and null is
                    // the right answer there, because those plans were written
                    // by the builder and it supplies the copy at build time.
                    'copy' => $item['copy'] ?? null,
                    'verdict' => $item['verdict'] ?? null,
                ];
            }

            $plan = $this->upsert(CovePlan::query(), $this->naturalKey($row), $row, $report);

            if ($plan instanceof CovePlan) {
                /*
                 * Replace, never merge.
                 *
                 * An import is "make this environment match the envelope". A
                 * merge would leave behind items the author deleted on the far
                 * side, and no second run would ever remove them.
                 */
                $plan->items()->delete();

                foreach ($resolved as $item) {
                    $plan->items()->create($item);
                }
            }
        }

        return $report;
    }

    // --- Plumbing ------------------------------------------------------------

    /**
     * What identifies this Cove — plan or edition — in the target environment.
     *
     * **A Daily is addressed by its date; every other kind by its slug.** That
     * is the rule `App\Enums\CoveKind::isDated()` states, and asking the enum is
     * the whole point of this method: the previous version asked
     * `kind === 'persona'` instead, which was a correct reading of the world
     * when `daily` and `persona` were the only two kinds and became silently
     * wrong the moment the guide fold added four more.
     *
     * The failure it caused is worth writing down, because nothing about it
     * looked like a bug. A `guide`, `seasonal`, `advice` or `shop` row took the
     * date branch and was matched on `['market' => …, 'drop_date' => null]` —
     * and Laravel turns a null value there into `drop_date IS NULL`, which does
     * not match nothing, it matches **every dateless row in that market**. So an
     * imported guide plan would find some unrelated advice plan, fill it with
     * the guide's attributes and save it: one plan silently overwritten, one
     * plan never created, and a report saying "updated". Where the overwritten
     * row's new slug was already taken by a third plan it surfaced instead as a
     * unique violation on `cove_plans_market_slug_idx`, which is how it was
     * found at all.
     *
     * Keyed on `(market, slug)` without the kind, deliberately. The slug
     * namespace is one per market **across** kinds — that is what the partial
     * unique indexes on both tables enforce — so adding `kind` to the key would
     * let an import miss a row that exists and then create a duplicate the
     * database refuses.
     *
     * Null means "this row cannot be matched, always create it": a plan is held
     * to the dating rule but not to the slug rule, so a dateless plan that has
     * not been named yet has no natural key. Matching those on `slug IS NULL`
     * would collapse every unnamed plan in a market onto the first one.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function naturalKey(array $row): ?array
    {
        $kind = CoveKind::tryFrom((string) ($row['kind'] ?? CoveKind::Daily->value));

        if ($kind?->isDated() ?? true) {
            /*
             * An unknown kind is treated as a Daily, which is what the export
             * side's default has always been.
             *
             * A dated row with no date is refused a key rather than given one
             * containing a null — that null is the whole bug described above,
             * and it would match a dateless row and overwrite it. Falling
             * through to a create means the CHECK constraint rejects the row
             * loudly, which is the correct outcome for a malformed envelope.
             */
            /*
             * The kind is part of the dated key, and it has to be.
             *
             * `(market, drop_date)` stopped identifying one row when seasonal
             * plans gained a due date: a Daily and a part of a season can share
             * a Tuesday, which is why the unique index behind this is now
             * partial on `kind = 'daily'`. Without the kind here, importing a
             * Daily could find a seasonal part sitting on that date, fill it
             * with the Daily's attributes and save it — the same silent
             * overwrite described above, arrived at from the other side.
             *
             * Safe on the slug branch's terms too: unlike the slug namespace,
             * which is deliberately shared across kinds, a date is only ever
             * claimed by one kind at a time.
             */
            return blank($row['drop_date'] ?? null)
                ? null
                : [
                    'market' => $row['market'],
                    'kind' => $kind?->value ?? CoveKind::Daily->value,
                    'drop_date' => $row['drop_date'],
                ];
        }

        return blank($row['slug'] ?? null)
            ? null
            : ['market' => $row['market'], 'slug' => $row['slug']];
    }

    /**
     * Idempotent by natural key, so re-running updates rather than duplicating.
     *
     * A null key means the row has nothing to be matched on and is always
     * created — see {@see naturalKey()}.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>|null  $key
     * @param  array<string, mixed>  $values
     * @param  array{created:int, updated:int, dropped:list<string>}  $report
     */
    private function upsert($query, ?array $key, array $values, array &$report): mixed
    {
        if ($key === null) {
            $report['created']++;

            return $query->create($values);
        }

        $existing = (clone $query)->where($key)->first();

        if ($existing !== null) {
            $existing->fill($values)->save();
            $report['updated']++;

            return $existing;
        }

        $report['created']++;

        return $query->create($values);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function strip(array $attributes, string $surface): array
    {
        foreach (self::DROP[$surface] as $column) {
            unset($attributes[$column]);
        }

        return $attributes;
    }

    /** @return array{created:int, updated:int, dropped:list<string>} */
    private function report(): array
    {
        return ['created' => 0, 'updated' => 0, 'dropped' => []];
    }

    /**
     * @param  list<string>  $surfaces
     * @return list<string>
     */
    private function ordered(array $surfaces, bool $forImport = false): array
    {
        /*
         * Import accepts a surface this build no longer writes; export does not
         * offer one.
         *
         * The asymmetry is the whole point of `RETIRED`. An envelope taken from
         * an older build has to load — that is what a version number is for —
         * and the arm that handles it says in the report what it did with those
         * rows. Exporting one would mean writing a shape nothing reads.
         */
        $known = $forImport ? [...self::SURFACES, ...self::RETIRED] : self::SURFACES;

        $unknown = array_diff($surfaces, $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Not a promotable surface: '.implode(', ', $unknown)
                .'. Known: '.implode(', ', $known).'.'
            );
        }

        return array_values(array_filter(
            $known,
            fn (string $surface) => in_array($surface, $surfaces, true),
        ));
    }
}
