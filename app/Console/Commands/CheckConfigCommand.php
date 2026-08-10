<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 * Two rules, both learned the hard way.
 *
 * **Lengths and presence, never values**, exactly as {@see CheckBolCommand}
 * does. A diagnostic that prints a secret is a diagnostic nobody can paste into
 * a ticket or a chat window.
 *
 * **Read `config()`, never `env()`.** Under `config:cache` — which any
 * production environment may switch on at any time — `env()` outside a config
 * file returns null, so a checker built on `env()` would cheerfully report that
 * every setting is missing on precisely the environment it exists to reassure
 * you about. `config()` is what the application itself reads, which makes it
 * the only honest thing to measure.
 *
 * Optional settings are reported, never failed on. A missing Amazon key with
 * Amazon switched off is a fact, not a fault; failing on it would train people
 * to ignore the command, and a diagnostic nobody trusts is worse than none.
 */
class CheckConfigCommand extends Command
{
    protected $signature = 'bc:check-config';

    protected $description = 'Report which settings reached this environment. Prints lengths, never values.';

    public function handle(): int
    {
        $aiOn = (bool) config('brandcoves.ai.enabled');
        $amazonOn = (bool) config('brandcoves.connectors.sources.amazon.enabled');

        $groups = [
            'Application' => [
                ['APP_KEY', config('app.key'), true, 'Sessions and every encrypted cookie depend on it.'],
                ['APP_URL', config('app.url'), true, 'Absolute URLs in mail, sitemaps and social cards.'],
                ['CREDENTIALS_ENCRYPTION_KEY', config('brandcoves.credentials_key'), true, 'Seals connector_settings. Environment-specific: ciphertext does not travel.'],
                ['CLAIM_HASH_SECRET', config('brandcoves.wishlist.claim_hash_secret'), true, 'Permanent — rotating it orphans every wishlist claim.'],
                ['ROBOTS_ALLOW', config('brandcoves.robots_allow') ? 'true' : 'false', false, 'Must be true in production, false everywhere else.'],
            ],
            'Database' => [
                ['DB_DATABASE', config('database.connections.pgsql.database'), true, null],
                ['DB_USERNAME', config('database.connections.pgsql.username'), true, null],
                ['DB_PASSWORD', config('database.connections.pgsql.password'), true, null],
            ],
            'Mail' => [
                ['RESEND_API_KEY', config('services.resend.key'), true, 'No key means no magic link, so nobody can sign in.'],
                ['MAIL_FROM_ADDRESS', config('mail.from.address'), true, null],
            ],
            'Sign-in' => [
                ['GOOGLE_CLIENT_ID', config('services.google.client_id'), false, 'Optional — the button hides itself when unset.'],
                ['GOOGLE_CLIENT_SECRET', config('services.google.client_secret'), false, null],
            ],
            'AI' => [
                ['AI_ENABLED', $aiOn ? 'true' : 'false', false, 'With AI off the whole site still works, by invariant.'],
                ['ANTHROPIC_API_KEY', config('brandcoves.ai.api_key'), $aiOn, $aiOn ? 'Required while AI_ENABLED is true.' : 'Not needed while AI is off.'],
            ],
            'Connectors' => [
                ['AWIN_API_TOKEN', config('brandcoves.connectors.awin.api_token'), true, 'The catalogue comes from here.'],
                ['AWIN_PUBLISHER_ID', config('brandcoves.connectors.awin.publisher_id'), true, null],
                ['BOL_CLIENT_ID', config('brandcoves.connectors.sources.bol.client_id'), true, 'bol is the only supply the en market has.'],
                ['BOL_CLIENT_SECRET', config('brandcoves.connectors.sources.bol.client_secret'), true, null],
                ['AMAZON_ACCESS_KEY', config('brandcoves.connectors.sources.amazon.access_key'), $amazonOn, $amazonOn ? 'Required while AMAZON_ENABLED is true.' : 'Not needed while Amazon is off.'],
                ['AMAZON_SECRET_KEY', config('brandcoves.connectors.sources.amazon.secret_key'), $amazonOn, null],
            ],
        ];

        $failures = [];

        foreach ($groups as $heading => $rows) {
            $this->newLine();
            $this->components->info($heading);

            foreach ($rows as [$key, $value, $required, $note]) {
                $set = filled($value);

                if (! $set && $required) {
                    $failures[] = $key;
                }

                $this->components->twoColumnDetail(
                    $key.($note === null ? '' : " <fg=gray>— {$note}</>"),
                    $this->describe($value, $set, (bool) $required),
                );
            }
        }

        $this->newLine();
        $this->awinAccounts();

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
         * It still lists what is missing, because that is useful; it just does
         * not pretend a laptop is broken.
         */
        if (! app()->environment('production')) {
            $this->components->warn(
                'Missing, and required in production: '.implode(', ', $failures)
                .' — not failing, because this is the '.app()->environment().' environment.'
            );

            return self::SUCCESS;
        }

        $this->components->error('Missing and required here: '.implode(', ', $failures));

        return self::FAILURE;
    }

    /**
     * The check that started all of this.
     *
     * A second Awin publisher account was declared in config and never passed
     * through the compose file, so `array_filter` on a filled token dropped it
     * without a word: two accounts on a laptop, one in production, and a
     * catalogue quietly missing a publisher's merchants. Counting the accounts
     * the application can actually see is the shortest way to notice.
     */
    private function awinAccounts(): void
    {
        /** @var array<string, array{label?: string}> $accounts */
        $accounts = config('brandcoves.connectors.awin.accounts', []);

        $this->components->info('Awin accounts visible to this environment');

        if ($accounts === []) {
            $this->components->twoColumnDetail('none', '<fg=red>no account has a token — nothing will ingest</>');

            return;
        }

        /** @var array<string, array{label: string, env: string}> $declared */
        $declared = (array) config('brandcoves.connectors.awin.declared_accounts', []);

        // Named rather than counted: "1 of 2" says something is missing, but not
        // which credential to go and find.
        foreach ($declared as $key => $meta) {
            $this->components->twoColumnDetail(
                $key.' <fg=gray>— '.$meta['label'].'</>',
                array_key_exists($key, $accounts)
                    ? '<fg=green>visible</>'
                    : "<fg=yellow>absent — set {$meta['env']}</>",
            );
        }

        foreach (array_diff(array_keys($accounts), array_keys($declared)) as $undeclared) {
            $this->components->twoColumnDetail(
                (string) $undeclared,
                '<fg=yellow>has a token but is not declared</>',
            );
        }
    }

    private function describe(mixed $value, bool $set, bool $required): string
    {
        if (! $set) {
            return $required ? '<fg=red>MISSING</>' : '<fg=yellow>unset (optional)</>';
        }

        // Booleans and short flags are worth showing outright; anything that
        // could be a secret is reduced to a length.
        $string = (string) $value;

        if (in_array($string, ['true', 'false'], true)) {
            return "<fg=green>{$string}</>";
        }

        return '<fg=green>set</> <fg=gray>('.strlen($string).' chars)</>';
    }
}
