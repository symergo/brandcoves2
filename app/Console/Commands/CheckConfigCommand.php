<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\ConfigReport;
use Illuminate\Console\Command;

/**
 * Did the config actually arrive in this environment?
 *
 * The counterpart to `tests/Unit/ConfigContractTest.php` — named rather than
 * linked, because application code must not import from the test namespace,
 * which is autoloaded in development only. That test runs on a laptop and
 * proves a setting *can* reach a container; this command runs inside the
 * container and proves it *did*. Both are needed — the plumbing being right
 * says nothing about whether anyone filled in the value at the other end.
 *
 * The rules live in {@see ConfigReport}, shared with the admin screen so the
 * terminal and the browser cannot disagree about what is configured. This is
 * only the rendering.
 */
class CheckConfigCommand extends Command
{
    protected $signature = 'bc:check-config';

    protected $description = 'Report which settings reached this environment. Prints lengths, never values.';

    public function handle(ConfigReport $report): int
    {
        foreach ($report->groups() as $heading => $rows) {
            $this->newLine();
            $this->components->info($heading);

            foreach ($rows as $row) {
                $this->components->twoColumnDetail(
                    $row['key'].($row['note'] === null ? '' : " <fg=gray>— {$row['note']}</>"),
                    $this->status($row),
                );
            }
        }

        $this->newLine();
        $this->components->info('Awin accounts visible to this environment');

        foreach ($report->awinAccounts() as $account) {
            $this->components->twoColumnDetail(
                $account['key'].' <fg=gray>— '.$account['label'].'</>',
                $account['visible']
                    ? '<fg=green>visible</>'
                    : "<fg=yellow>absent — set {$account['env']}</>",
            );
        }

        $failures = $report->failures();

        $this->newLine();

        if ($failures === []) {
            $this->components->info('Every setting required in this environment is present.');

            return self::SUCCESS;
        }

        /*
         * Only a deployed environment fails on this.
         *
         * A laptop legitimately has no Resend key and no bol credentials — mail
         * goes to Mailpit and bol is not being worked on. Exiting non-zero there
         * would make the command fail on every developer machine every time,
         * which is how a diagnostic becomes something people pipe to /dev/null.
         * It still lists what is missing; it just does not pretend a laptop is
         * broken.
         */
        if (! $report->isDeployed()) {
            $this->components->warn(
                'Missing, and required in production: '.implode(', ', $failures)
                .' — not failing, because this is the '.app()->environment().' environment.'
            );

            return self::SUCCESS;
        }

        $this->components->error('Missing and required here: '.implode(', ', $failures));

        return self::FAILURE;
    }

    /** @param array{set: bool, required: bool, display: string} $row */
    private function status(array $row): string
    {
        if ($row['set']) {
            return in_array($row['display'], ['true', 'false'], true)
                ? "<fg=green>{$row['display']}</>"
                : '<fg=green>set</> <fg=gray>('.str_replace(['set (', ')'], '', $row['display']).')</>';
        }

        return $row['required'] ? '<fg=red>MISSING</>' : '<fg=yellow>unset (optional)</>';
    }
}
