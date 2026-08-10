<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentEnvelope;
use Illuminate\Console\Command;

/**
 * Apply an envelope written by `bc:export-content` to this environment.
 *
 * **Dry run is the default.** Writing takes `--write`, spelled out, because the
 * interesting question is never "did it import" but "what could this environment
 * not match" — production's catalogue is smaller than staging's, so some picks
 * have no counterpart and are dropped. That list is the reason to run the
 * command at all, and defaulting to a write would hide it behind a fait
 * accompli.
 *
 * Reads stdin when given `--in=-`, so an export can be piped across without
 * ever landing on a disk:
 *
 *     docker exec <staging> php artisan bc:export-content \
 *       | docker exec -i <prod> php artisan bc:import-content --in=-
 */
class ImportContentCommand extends Command
{
    protected $signature = 'bc:import-content
        {--in= : Envelope path, or - for stdin}
        {--surfaces= : Comma separated. Default: every surface in the envelope.}
        {--write : Actually write. Without this nothing is committed.}';

    protected $description = 'Import an editorial content envelope, resolving products by identity';

    public function handle(ContentEnvelope $envelope): int
    {
        $raw = $this->read();

        if ($raw === null) {
            return self::FAILURE;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $this->components->error('Not valid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $surfaces = $this->surfaces($decoded);
        $dryRun = ! $this->option('write');

        try {
            $report = $envelope->import($decoded, $surfaces, $dryRun);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($report, $dryRun);

        return self::SUCCESS;
    }

    /** @param array<string, array{created:int, updated:int, dropped:list<string>}> $report */
    private function render(array $report, bool $dryRun): void
    {
        $this->newLine();
        $this->table(
            ['Surface', 'Created', 'Updated', 'Dropped'],
            array_map(
                fn (string $surface, array $r) => [
                    $surface,
                    $r['created'],
                    $r['updated'],
                    count($r['dropped']) === 0 ? '—' : (string) count($r['dropped']),
                ],
                array_keys($report),
                array_values($report),
            ),
        );

        $dropped = array_merge(...array_values(array_map(
            fn (array $r) => $r['dropped'],
            $report,
        ))) ?: [];

        if ($dropped !== []) {
            $this->newLine();
            $this->components->warn(count($dropped).' reference(s) had no product in this environment and were dropped:');

            // Named, not counted. "14 dropped" is a number; the identity keys
            // are something you can go and look up.
            foreach (array_slice($dropped, 0, 40) as $line) {
                $this->line("  <fg=yellow>·</> {$line}");
            }

            if (count($dropped) > 40) {
                $this->line('  … and '.(count($dropped) - 40).' more.');
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Dry run — nothing was written. Re-run with --write to apply exactly this.');

            return;
        }

        $this->components->info('Applied.');
    }

    private function read(): ?string
    {
        $in = $this->option('in');

        if ($in === null || $in === '') {
            $this->components->error('Give --in=<path>, or --in=- to read stdin.');

            return null;
        }

        if ($in === '-') {
            $raw = file_get_contents('php://stdin');

            if ($raw === false || trim($raw) === '') {
                $this->components->error('Nothing arrived on stdin.');

                return null;
            }

            return $raw;
        }

        if (! is_readable((string) $in)) {
            $this->components->error("Cannot read {$in}.");

            return null;
        }

        return (string) file_get_contents((string) $in);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return list<string>
     */
    private function surfaces(array $envelope): array
    {
        $raw = (string) ($this->option('surfaces') ?? '');

        if (trim($raw) !== '') {
            return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
        }

        return array_values(array_keys((array) ($envelope['surfaces'] ?? [])));
    }
}
