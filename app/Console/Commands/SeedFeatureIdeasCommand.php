<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FeatureIdea;
use App\Services\Contribute\FeatureIdeaSeeder;
use Illuminate\Console\Command;

/**
 * Put the shipped feature ideas on the contribute page's board.
 *
 * Run it after editing resources/content/feature-ideas.php, and once on a
 * fresh database (a deployed one got them from the migration
 * `2026_09_28_000910_the_feature_ideas_move_in`). Never overwrites an idea
 * edited in the admin unless `--replace`, which asks first. See
 * App\Services\Contribute\FeatureIdeaSeeder and docs/features/contribute.md.
 */
class SeedFeatureIdeasCommand extends Command
{
    protected $signature = 'bc:seed-feature-ideas
        {--replace : Overwrite ideas that were edited in the admin.}
        {--dry-run : Report what would change and write nothing.}';

    protected $description = 'Publish the shipped feature ideas on the contribute page';

    public function handle(FeatureIdeaSeeder $seeder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        if ($replace && ! $dryRun) {
            $edited = FeatureIdea::query()
                ->whereNotNull('seed_key')
                ->where('source', '!=', FeatureIdea::SOURCE_SEED)
                ->count();

            if ($edited > 0 && ! $this->confirm("--replace will overwrite {$edited} idea(s) edited in the admin. Continue?", false)) {
                return self::FAILURE;
            }
        }

        $report = $seeder->run($dryRun, $replace);

        foreach ($report['written'] as $key) {
            $this->line("  <info>write</info> {$key}");
        }

        foreach ($report['kept'] as $key) {
            $this->line("  <comment>kept</comment>  {$key} (edited in the admin)");
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d written, %d kept.',
            $dryRun ? 'Dry run: ' : '',
            count($report['written']),
            count($report['kept']),
        ));

        return self::SUCCESS;
    }
}
