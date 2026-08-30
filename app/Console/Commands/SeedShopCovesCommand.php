<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    public function handle(ConnectorRegistry $registry): int
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

            foreach ($this->shopsIn($market, $registry) as $shop) {
                $text = $content[$shop->domain ?? ''][$market->language()] ?? null;

                if ($text === null) {
                    // No text in this market's language. Silently absent beats
                    // a Dutch Cove on a French page.
                    $skipped++;

                    continue;
                }

                /*
                 * Dots become separators, not nothing. `Str::slug('bol.com')`
                 * is "bolcom" — the dot is stripped rather than replaced —
                 * which reads as a typo in a URL and in a `[[guide:...]]`
                 * token. Replacing it first gives `bol-com`, `coolblue-be`,
                 * `shop-action-com`.
                 */
                $slug = Str::slug(str_replace('.', '-', (string) $shop->domain));

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

                DailyPickSet::query()->updateOrCreate(
                    [
                        'market' => $market->value,
                        'kind' => CoveKind::Shop->value,
                        'slug' => $slug,
                    ],
                    [
                        'theme_title' => $text['title'],
                        'theme_slug' => $slug,
                        'theme_blurb' => $text['blurb'],
                        'body' => $text['body'],
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

    /**
     * The shops a market carries.
     *
     * Deliberately the same question `ShopsController` asks, so a Cove and its
     * directory entry appear together. Duplicated as a query rather than shared
     * through a service because the two callers want different columns and this
     * one runs once per market in a console command — the day a third caller
     * needs it is the day it earns a home of its own.
     *
     * @return Collection<int, Merchant>
     */
    private function shopsIn(Market $market, ConnectorRegistry $registry): Collection
    {
        $live = $registry->liveSourcesFor($market);

        return Merchant::query()
            ->where('enabled', true)
            ->whereNotNull('domain')
            ->where(function (Builder $q) use ($market, $live): void {
                $q->whereHas('products', fn (Builder $p) => $p
                    ->where('market', $market->value)
                    ->where('status', ProductStatus::Active->value));

                if ($live !== []) {
                    $q->orWhereIn('source', array_map(fn ($s) => $s->value, $live));
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'domain', 'source']);
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
