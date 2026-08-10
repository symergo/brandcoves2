<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\CopyTemplate;
use App\Models\CovePlan;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Feed;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\GuideTopic;
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
    /** Bumped when the envelope shape changes in a way an old import cannot read. */
    public const VERSION = 1;

    /**
     * What may travel, in dependency order.
     *
     * Order matters on import: guides must exist before `guide_topics` and
     * `daily_pick_sets` can point at them.
     */
    public const SURFACES = ['feeds', 'copy', 'guides', 'topics', 'editions', 'plans'];

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
        'copy' => ['id', 'author_id', 'created_at', 'updated_at'],
        'guides' => ['id', 'created_at', 'updated_at'],
        'topics' => ['id', 'guide_id', 'created_at', 'updated_at', 'last_attempt_at', 'attempts'],
        'editions' => ['id', 'guide_id', 'challenge_group_id', 'created_at', 'updated_at'],
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
                'copy' => $this->exportCopy(),
                'guides' => $this->exportGuides(),
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

        if ($version !== self::VERSION) {
            throw new \RuntimeException(
                "Envelope is version {$version}, this build reads ".self::VERSION.'.'
            );
        }

        $payload = (array) ($envelope['surfaces'] ?? []);
        $report = [];

        DB::beginTransaction();

        try {
            foreach ($this->ordered($surfaces) as $surface) {
                if (! array_key_exists($surface, $payload)) {
                    continue;
                }

                $rows = (array) $payload[$surface];

                $report[$surface] = match ($surface) {
                    'feeds' => $this->importFeeds($rows),
                    'copy' => $this->importCopy($rows),
                    'guides' => $this->importGuides($rows),
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

    /** @return list<array<string, mixed>> */
    private function exportCopy(): array
    {
        return CopyTemplate::query()->orderBy('id')->get()
            ->map(fn (CopyTemplate $row) => $this->strip($row->getAttributes(), 'copy'))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function exportGuides(): array
    {
        return Guide::query()->with('items')->orderBy('id')->get()
            ->map(function (Guide $guide): array {
                $row = $this->strip($guide->getAttributes(), 'guides');

                $row['items'] = $guide->items
                    ->map(function (GuideItem $item): ?array {
                        $ref = $this->identify($item->group_id === null ? null : (int) $item->group_id);

                        // Unresolvable here means the source lost the product
                        // after the guide was written. Exporting it would just
                        // move the problem.
                        if ($ref === null) {
                            return null;
                        }

                        $attributes = $item->getAttributes();
                        unset($attributes['id'], $attributes['guide_id'], $attributes['group_id'], $attributes['created_at'], $attributes['updated_at']);
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
    private function exportTopics(): array
    {
        return GuideTopic::query()->orderBy('id')->get()
            ->map(function (GuideTopic $topic): array {
                $row = $this->strip($topic->getAttributes(), 'topics');

                // Guides travel by slug, the only handle both sides share.
                $row['guide_slug'] = $topic->guide_id === null
                    ? null
                    : Guide::query()->whereKey($topic->guide_id)->value('slug');

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

                $row['guide_slug'] = $set->guide_id === null
                    ? null
                    : Guide::query()->whereKey($set->guide_id)->value('slug');

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

                $pinned = $plan->pinned_group_ids;
                $pinned = is_string($pinned) ? (array) json_decode($pinned, true) : (array) $pinned;

                $row['pinned_products'] = array_values(array_filter(array_map(
                    fn ($id) => $this->identify((int) $id),
                    $pinned,
                )));

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
    private function importCopy(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $this->upsert(
                CopyTemplate::query(),
                ['surface' => $row['surface'], 'slot' => $row['slot'], 'language' => $row['language'], 'body' => $row['body']],
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
    private function importGuides(array $rows): array
    {
        $report = $this->report();

        foreach ($rows as $row) {
            $items = (array) ($row['items'] ?? []);
            unset($row['items']);

            $guide = $this->upsert(
                Guide::query(),
                ['market' => $row['market'], 'slug' => $row['slug']],
                $row,
                $report,
            );

            // Replaced wholesale rather than merged: rank is a property of the
            // list, so a half-updated list would silently reorder itself.
            $guide->items()->delete();

            foreach ($items as $item) {
                $groupId = $this->resolve($item['product'] ?? null);

                if ($groupId === null) {
                    $report['dropped'][] = "guide {$row['slug']}: "
                        .($item['product']['identity_key'] ?? 'unknown product');

                    continue;
                }

                unset($item['product']);
                $item['group_id'] = $groupId;
                $guide->items()->create($item);
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

            $row['guide_id'] = $slug === null
                ? null
                : Guide::query()->where('market', $row['market'])->where('slug', $slug)->value('id');

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

            $row['guide_id'] = $slug === null
                ? null
                : Guide::query()->where('market', $row['market'])->where('slug', $slug)->value('id');

            // The guess-the-price round simply does not run without its product,
            // which is a better outcome than a round about the wrong one.
            $row['challenge_group_id'] = $this->resolve($challenge);

            $set = $this->upsert(
                DailyPickSet::query(),
                ['market' => $row['market'], 'drop_date' => $row['drop_date']],
                $row,
                $report,
            );

            $set->picks()->delete();

            foreach ($picks as $pick) {
                $groupId = $this->resolve($pick['product'] ?? null);

                if ($groupId === null && blank($pick['amazon_asin'] ?? null)) {
                    $report['dropped'][] = "edition {$row['market']} {$row['drop_date']}: "
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
            $pinned = (array) ($row['pinned_products'] ?? []);
            unset($row['pinned_products']);

            $resolved = [];

            foreach ($pinned as $ref) {
                $id = $this->resolve($ref);

                if ($id === null) {
                    $report['dropped'][] = "plan {$row['market']} {$row['drop_date']}: "
                        .($ref['identity_key'] ?? 'unknown product');

                    continue;
                }

                $resolved[] = $id;
            }

            // A pin is a preference, not a requirement — an empty list means the
            // builder chooses freely, which is what it does for an unplanned day.
            $row['pinned_group_ids'] = $resolved;

            $this->upsert(
                CovePlan::query(),
                ['market' => $row['market'], 'drop_date' => $row['drop_date']],
                $row,
                $report,
            );
        }

        return $report;
    }

    // --- Plumbing ------------------------------------------------------------

    /**
     * Idempotent by natural key, so re-running updates rather than duplicating.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     * @param  array{created:int, updated:int, dropped:list<string>}  $report
     */
    private function upsert($query, array $key, array $values, array &$report): mixed
    {
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
    private function ordered(array $surfaces): array
    {
        $unknown = array_diff($surfaces, self::SURFACES);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Not a promotable surface: '.implode(', ', $unknown)
                .'. Known: '.implode(', ', self::SURFACES).'.'
            );
        }

        return array_values(array_filter(
            self::SURFACES,
            fn (string $surface) => in_array($surface, $surfaces, true),
        ));
    }
}
