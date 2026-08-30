<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Content\AdviceCoveSeeder;
use Illuminate\Console\Command;

/**
 * Re-apply `resources/content/advice-coves.php` to this environment.
 *
 * The migration lands these articles once, on the way in. This is what you run
 * afterwards: correct a sentence in the file, run this, and only the rows still
 * carrying `editorial_source = seed` are refreshed. Anything a person has
 * edited in the panel is reported as kept and left alone.
 *
 * Dry run is not the default here, unlike `bc:import-content`. That command
 * resolves products against a catalogue that differs per environment, so what
 * it *cannot* match is the reason to run it; this one writes text that is the
 * same everywhere and has no such question to answer. `--dry-run` is still
 * there for the case where you want to see which rows would be overwritten
 * before overwriting them.
 */
class SeedAdviceCovesCommand extends Command
{
    protected $signature = 'bc:seed-advice-coves
        {--market= : One market. Omit for all of them.}
        {--replace : Overwrite Coves that were edited after seeding.}
        {--dry-run : Report what would change and write nothing.}';

    protected $description = 'Publish the shipped advice articles as Coves, without overwriting edits';

    public function handle(AdviceCoveSeeder $seeder): int
    {
        $only = null;

        if ($this->option('market') !== null) {
            $only = Market::tryFrom((string) $this->option('market'));

            if ($only === null) {
                $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        if ($replace && ! $dryRun && ! $this->confirmReplacement()) {
            return self::FAILURE;
        }

        $report = $seeder->run($dryRun, $replace, $only);

        foreach ($report['written'] as $line) {
            $this->line("  <info>{$line}</info>");
        }

        // Named rather than counted. "3 kept" is a number; the slugs are
        // something you can go and look at before deciding to use --replace.
        foreach ($report['kept'] as $line) {
            $this->line("  <comment>kept</comment>    {$line}");
        }

        foreach ($report['skipped'] as $line) {
            $this->line("  <fg=yellow>skipped</> {$line}");
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d written, %d kept, %d skipped.',
            $dryRun ? 'Dry run: ' : '',
            count($report['written']),
            count($report['kept']),
            count($report['skipped']),
        ));

        return self::SUCCESS;
    }

    private function confirmReplacement(): bool
    {
        return $this->confirm(
            '--replace overwrites Coves that a person edited after seeding. There is no undo. Continue?',
            false,
        );
    }
}
