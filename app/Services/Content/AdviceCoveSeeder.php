<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Console\Commands\SeedShopCovesCommand;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Services\Editorial\HouseStyle;
use Illuminate\Support\Facades\DB;

/**
 * Publish the shipped Advice Coves from `resources/content/advice-coves.php`.
 *
 * The sibling of {@see SeedShopCovesCommand}, and it
 * exists for the same reason: prose that ships in the repository is invisible
 * until something puts it in the database, and every other Cove is a row a
 * person can open and rewrite.
 *
 * It is a **service** rather than a migration body because it is the part of
 * this change that can lose something, and a migration body cannot be tested —
 * by the time a test has Coves to overwrite, the migration has already run. The
 * same reasoning as {@see GuideFold}, which is called from its migration the
 * same way.
 *
 * ## Idempotent, and it never overwrites a person
 *
 * Matched on `(market, slug)` — the site's one slug namespace per market,
 * which spans every kind. A row whose `editorial_source` is still `seed` is
 * refreshed from the file. Anything else was touched by a person or a builder
 * and is left exactly as it is, and `$replace` is the only thing that overrides
 * that.
 *
 * So the honest description of running this twice is: the second run is a
 * no-op, unless the file changed, in which case it is a content update that
 * skips everything anybody has since edited.
 *
 * ## Why `published_at` is stamped once
 *
 * It orders the shelf. Refreshing it on a re-run would reshuffle the whole
 * advice section every time the file is touched, and would re-date pages that
 * have been live for months to the top of every "newest first" listing. The
 * same instability `SeedShopCovesCommand` documents avoiding.
 *
 * ## Why every seeded Cove gets a plan
 *
 * Because "every published Cove has a plan" is the rule the planner rests on —
 * a Cove with none cannot be opened and re-curated, which would make these the
 * one kind of page an editor could not work on. {@see CovePlan::recordFor()}
 * mints it as `used`, never `approved`: a record of what was published, not an
 * instruction the next build obeys.
 */
class AdviceCoveSeeder
{
    /**
     * How a seeded row identifies itself.
     *
     * The whole safety property of re-running rests on this marker: without one
     * there is no way to tell "we wrote this and may rewrite it" from "somebody
     * improved this and must not lose it". Deliberately the same string the
     * Shop Coves use — the question it answers is "did a person touch this",
     * which is not per-kind, and the kind column already separates the two sets.
     */
    public const SOURCE = 'seed';

    /**
     * @return array{written: list<string>, kept: list<string>, skipped: list<string>}
     */
    public function run(bool $dryRun = false, bool $replace = false, ?Market $only = null): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $content */
        $content = require resource_path('content/advice-coves.php');

        $written = [];
        $kept = [];
        $skipped = [];

        foreach ($content as $topic => $markets) {
            foreach ($markets as $marketValue => $article) {
                $market = Market::tryFrom((string) $marketValue);

                if ($market === null) {
                    // A key that is not a market at all. Named rather than
                    // ignored: it means the file has a typo, and a silently
                    // absent article is exactly the failure nothing surfaces.
                    $skipped[] = "{$topic}/{$marketValue} — not a market";

                    continue;
                }

                if ($only !== null && $market !== $only) {
                    continue;
                }

                $slug = (string) $article['slug'];

                /*
                 * Not scoped to the kind, on purpose.
                 *
                 * The unique index on (market, slug) covers every kind, so a
                 * persona already sitting on this slug is a collision this
                 * would otherwise discover as a database error mid-write. It is
                 * also not ours to overwrite.
                 */
                $existing = DailyPickSet::query()
                    ->where('market', $market->value)
                    ->where('slug', $slug)
                    ->first();

                if ($existing !== null && $existing->kind !== CoveKind::Advice) {
                    $skipped[] = "{$market->value}/{$slug} — slug taken by a {$existing->kind->value} Cove";

                    continue;
                }

                if ($existing !== null && $existing->editorial_source !== self::SOURCE && ! $replace) {
                    $kept[] = "{$market->value}/{$slug} — edited since seeding";

                    continue;
                }

                $written[] = sprintf(
                    '%s %s/%s',
                    $existing === null ? 'new' : 'update',
                    $market->value,
                    $slug,
                );

                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($market, $slug, $article, $existing): void {
                    $cove = DailyPickSet::query()->updateOrCreate(
                        [
                            'market' => $market->value,
                            'slug' => $slug,
                        ],
                        [
                            'kind' => CoveKind::Advice->value,
                            /*
                             * House style, applied on the way in like every
                             * other writer's output. These articles were
                             * written by a model too; that the prose happens to
                             * live in a file this repo ships does not make it a
                             * different kind of text. See
                             * {@see \App\Services\Editorial\HouseStyle} for
                             * why `theme_blurb` and `body` keep their `**` and
                             * the title does not.
                             */
                            'theme_title' => HouseStyle::plain($article['title']),
                            /*
                             * The slug, not a rotation key. `theme_slug` is the
                             * Daily's internal bookkeeping and is English in
                             * every market; an advice article has no rotation
                             * to book-keep, so the address is the honest value.
                             */
                            'theme_slug' => $slug,
                            'theme_blurb' => HouseStyle::prose($article['blurb']),
                            'body' => HouseStyle::prose($article['body']),
                            'faq' => isset($article['faq'])
                                ? array_map(fn (array $pair): array => [
                                    'q' => HouseStyle::plain($pair['q']),
                                    'a' => HouseStyle::prose($pair['a']),
                                ], $article['faq'])
                                : null,
                            'meta_description' => HouseStyle::plain($article['meta_description'] ?? null),
                            'editorial_source' => self::SOURCE,
                            'status' => PublishStatus::Published->value,
                            // Stamped once — see the class note.
                            'published_at' => $existing?->published_at ?? now(),
                            /*
                             * Never a date. The CHECK constraint on
                             * `daily_pick_sets` allows one only for a Daily,
                             * and a dateless kind that acquired one would be
                             * picked up and published as that morning's column.
                             */
                            'drop_date' => null,
                        ],
                    );

                    CovePlan::recordFor($cove);
                });
            }
        }

        return ['written' => $written, 'kept' => $kept, 'skipped' => $skipped];
    }
}
