<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentEnvelope;
use Illuminate\Console\Command;

/**
 * Write this environment's editorial work to a JSON envelope, for another one.
 *
 * The rules — what may travel, and how product references survive the trip —
 * live in {@see ContentEnvelope}. This is the console face of it.
 *
 * Output goes to stdout by default so it can be piped straight into the other
 * environment without ever touching a laptop:
 *
 *     docker exec <staging> php artisan bc:export-content > coves.json
 *
 * Progress therefore has to go to stderr, or it would land in the middle of the
 * JSON and the import would reject the file.
 */
class ExportContentCommand extends Command
{
    protected $signature = 'bc:export-content
        {--surfaces= : Comma separated. Default: everything promotable.}
        {--out= : Write here instead of stdout}';

    protected $description = 'Export editorial content (feeds, coves, guides, copy) as a portable envelope';

    public function handle(ContentEnvelope $envelope): int
    {
        $surfaces = $this->surfaces();

        try {
            $payload = $envelope->export($surfaces);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->components->error('Could not encode the envelope: '.json_last_error_msg());

            return self::FAILURE;
        }

        $out = $this->option('out');

        if ($out === null) {
            $this->output->writeln($json, $this->output::OUTPUT_RAW);
        } else {
            file_put_contents((string) $out, $json);
        }

        // stderr, always: stdout may be halfway through a pipe into the other
        // environment's importer, and a progress line landing in the middle of
        // the JSON would make the envelope unparseable at the far end.
        $stderr = $this->output->getErrorStyle();

        foreach ((array) $payload['surfaces'] as $surface => $rows) {
            $stderr->writeln(sprintf('  %-10s %d', $surface, count((array) $rows)));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function surfaces(): array
    {
        $raw = (string) ($this->option('surfaces') ?? '');

        if (trim($raw) === '') {
            return ContentEnvelope::SURFACES;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }
}
