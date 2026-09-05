<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Services\Editorial\HouseStyle;
use App\Services\Shops\ShopDirectory;
use Illuminate\Console\Command;

/**
 * Publish the shipped Shop Coves into the markets that have those shops.
 *
 * The counterpart to the page-template seeding migration, and it exists for the
 * same reason: text
 * that ships in the repository is invisible to the editor until something puts
 * it in the database, and every other Cove kind is a database row that a person
 * can open and rewrite. A Shop Cove read straight out of a PHP file would be the
 * one kind of Cove nobody could edit.
 *
 * ## Idempotent, and it never overwrites a person
 *
 * Matched on (market, slug). A row whose `editorial_source` is still `seed` is
 * refreshed from the file; anything else was touched by a person or a builder
 * and is left exactly as it is. `--replace` overrides that, and asks first.
 *
 * ## Which markets get which shop
 *
 * The same question `/shops` asks — does this shop have active offers in this
 * market, or is it a live source serving it — so the Cove and the directory
 * entry appear and disappear together. A shop with no text for the market's
 * language is skipped rather than published in the wrong one.
 */
class SeedShopCovesCommand extends Command
{
    protected $signature = 'bc:seed-shop-coves
        {--market= : One market. Omit for all published ones.}
        {--replace : Overwrite Coves that were edited after seeding.}
        {--dry-run : Report what would change and write nothing.}';

    protected $description = 'Publish the shipped Shop Coves for the shops each market carries';

    /**
     * How a seeded row identifies itself.
     *
     * The whole safety property of re-running this rests on it: without a
     * marker there is no way to tell "we wrote this and can rewrite it" from
     * "somebody improved this and must not lose it".
     */
    private const SOURCE = 'seed';

    public function __construct(private readonly ShopDirectory $shops)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $only = $this->option('market');

        if ($only !== null && Market::tryFrom($only) === null) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        /** @var array<string, array<string, array{title: string, blurb: string, body: string}>> $content */
        $content = require resource_path('content/shop-coves.php');

        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        if ($replace && ! $dryRun && ! $this->confirmReplacement()) {
            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        $kept = 0;

        foreach (Market::published() as $market) {
            if ($only !== null && $market->value !== $only) {
                continue;
            }

            foreach ($this->shops->in($market) as $shop) {
                $text = $content[$shop->domain ?? ''][$market->language()] ?? null;

                if ($text === null) {
                    // No text in this market's language. Silently absent beats
                    // a Dutch Cove on a French page.
                    $skipped++;

                    continue;
                }

                // One rule, in one place: see ShopDirectory::slugFor().
                $slug = ShopDirectory::slugFor($shop);

                $existing = DailyPickSet::query()
                    ->where('market', $market->value)
                    ->where('kind', CoveKind::Shop->value)
                    ->where('slug', $slug)
                    ->first();

                if ($existing !== null && $existing->editorial_source !== self::SOURCE && ! $replace) {
                    $this->line("  <comment>kept</comment>    {$market->value}/{$slug} — edited since seeding");
                    $kept++;

                    continue;
                }

                $this->line(sprintf(
                    '  %s %s/%s',
                    $existing === null ? '<info>new</info>    ' : '<info>update</info> ',
                    $market->value,
                    $slug,
                ));

                $written++;

                if ($dryRun) {
                    continue;
                }

                $cove = DailyPickSet::query()->updateOrCreate(
                    [
                        'market' => $market->value,
                        'kind' => CoveKind::Shop->value,
                        'slug' => $slug,
                    ],
                    [
                        // House style on the way in, the same as every other
                        // writer's output. See App\Services\Editorial\HouseStyle:
                        // a Shop Cove renders through GuideController, so its
                        // blurb is the intro paragraph and keeps its emphasis.
                        'theme_title' => HouseStyle::plain($text['title']),
                        'theme_slug' => $slug,
                        'theme_blurb' => HouseStyle::prose($text['blurb']),
                        'body' => HouseStyle::prose($text['body']),
                        'editorial_source' => self::SOURCE,
                        'status' => PublishStatus::Published->value,
                        /*
                         * Stamped once. `published_at` orders the shelf, and
                         * refreshing it on every re-run would reshuffle the
                         * whole section every time this command is called —
                         * which is exactly the instability `GiftIdeasController`
                         * documents avoiding for personas.
                         */
                        'published_at' => $existing?->published_at ?? now(),
                    ],
                );

                /*
                 * Give it a plan, so the planner describes it like everything else.
                 *
                 * `AdviceCoveSeeder` already does this and this one did not, so
                 * the shipped Shop Coves were the only published pages on the
                 * site with no `cove_plans` row: invisible to the planner,
                 * impossible to re-curate, and unbuildable even after
                 * `writesBody()` gave the kind a build path — because nothing
                 * existed to build *from*.
                 *
                 * `recordFor()` mints it as `used`, never `approved`, which is
                 * what keeps it a record of what happened rather than an
                 * instruction the next rebuild obeys.
                 */
                CovePlan::recordFor($cove);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d written, %d kept, %d without text for their market.',
            $dryRun ? 'Dry run: ' : '',
            $written,
            $kept,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function confirmReplacement(): bool
    {
        $edited = DailyPickSet::query()
            ->where('kind', CoveKind::Shop->value)
            ->where('editorial_source', '!=', self::SOURCE)
            ->count();

        if ($edited === 0) {
            return true;
        }

        return $this->confirm(
            "--replace will overwrite {$edited} Shop Cove(s) that were edited after seeding. Continue?",
            false,
        );
    }
}
