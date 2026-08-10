<?php

declare(strict_types=1);

namespace App\Services\Ops;

/**
 * What this environment's configuration actually looks like, as data.
 *
 * Extracted so the console command and the admin screen cannot disagree. Two
 * implementations of "is the config right" would drift, and the one that drifts
 * is always the one somebody is reading at the time.
 *
 * **Presence and lengths, never values.** A report that carries a secret cannot
 * be pasted into a ticket, and this one is rendered into an HTML page as well as
 * a terminal.
 *
 * **Reads `config()`, never `env()`.** Under `config:cache` — which any
 * environment may switch on — `env()` outside a config file returns null, so a
 * report built on it would claim everything was missing on precisely the
 * environment it exists to reassure you about. `config()` is what the
 * application itself reads, which makes it the only honest thing to measure.
 */
class ConfigReport
{
    /**
     * Grouped settings, each row saying whether it arrived and whether it had to.
     *
     * @return array<string, list<array{key: string, set: bool, required: bool, display: string, note: ?string}>>
     */
    public function groups(): array
    {
        $aiOn = (bool) config('brandcoves.ai.enabled');
        $amazonOn = (bool) config('brandcoves.connectors.sources.amazon.enabled');

        $definition = [
            'Application' => [
                ['APP_KEY', config('app.key'), true, 'Sessions and every encrypted cookie depend on it.'],
                ['APP_URL', config('app.url'), true, 'Absolute URLs in mail, sitemaps and social cards.'],
                ['CREDENTIALS_ENCRYPTION_KEY', config('brandcoves.credentials_key'), true, 'Seals connector_settings. Environment-specific: ciphertext does not travel.'],
                ['CLAIM_HASH_SECRET', config('brandcoves.wishlist.claim_hash_secret'), true, 'Permanent — rotating it orphans every wishlist claim.'],
                ['ROBOTS_ALLOW', config('brandcoves.robots_allow') ? 'true' : 'false', false, 'True in production, false everywhere else.'],
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
                ['ANTHROPIC_API_KEY', config('brandcoves.ai.api_key'), $aiOn, $aiOn ? 'Required while AI is on.' : 'Not needed while AI is off.'],
            ],
            'Connectors' => [
                ['AWIN_API_TOKEN', config('brandcoves.connectors.awin.api_token'), true, 'The catalogue comes from here.'],
                ['AWIN_PUBLISHER_ID', config('brandcoves.connectors.awin.publisher_id'), true, null],
                ['BOL_CLIENT_ID', config('brandcoves.connectors.sources.bol.client_id'), true, 'bol is the only supply the en market has.'],
                ['BOL_CLIENT_SECRET', config('brandcoves.connectors.sources.bol.client_secret'), true, null],
                ['AMAZON_ACCESS_KEY', config('brandcoves.connectors.sources.amazon.access_key'), $amazonOn, $amazonOn ? 'Required while Amazon is on.' : 'Not needed while Amazon is off.'],
                ['AMAZON_SECRET_KEY', config('brandcoves.connectors.sources.amazon.secret_key'), $amazonOn, null],
            ],
        ];

        $groups = [];

        foreach ($definition as $heading => $rows) {
            foreach ($rows as [$key, $value, $required, $note]) {
                $set = filled($value);

                $groups[$heading][] = [
                    'key' => $key,
                    'set' => $set,
                    'required' => (bool) $required,
                    'display' => $this->describe($value, $set),
                    'note' => $note,
                ];
            }
        }

        return $groups;
    }

    /**
     * Settings that are missing and required here.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $missing = [];

        foreach ($this->groups() as $rows) {
            foreach ($rows as $row) {
                if ($row['required'] && ! $row['set']) {
                    $missing[] = $row['key'];
                }
            }
        }

        return $missing;
    }

    /**
     * Every Awin account this build declares, and whether it can be reached.
     *
     * The check that started all of this. An account with no token is dropped
     * from `connectors.awin.accounts` by design, so "declared but absent" and
     * "never existed" look identical unless something holds the full list. It
     * cost a publisher's worth of catalogue: `AWIN_VDB_API_TOKEN` was set in
     * `.env` and never passed through the compose file, so a laptop ingested
     * from two accounts and production from one, silently.
     *
     * @return list<array{key: string, label: string, env: string, visible: bool}>
     */
    public function awinAccounts(): array
    {
        /** @var array<string, array{label?: string}> $visible */
        $visible = (array) config('brandcoves.connectors.awin.accounts', []);
        /** @var array<string, array{label: string, env: string}> $declared */
        $declared = (array) config('brandcoves.connectors.awin.declared_accounts', []);

        $rows = [];

        foreach ($declared as $key => $meta) {
            $rows[] = [
                'key' => (string) $key,
                'label' => $meta['label'],
                'env' => $meta['env'],
                'visible' => array_key_exists($key, $visible),
            ];
        }

        // Configured but never declared — possible after a rename, and worth
        // saying rather than quietly including.
        foreach (array_diff(array_keys($visible), array_keys($declared)) as $key) {
            $rows[] = [
                'key' => (string) $key,
                'label' => $visible[$key]['label'] ?? (string) $key,
                'env' => 'not declared',
                'visible' => true,
            ];
        }

        return $rows;
    }

    /** Whether a laptop is allowed to be missing things a deployed environment is not. */
    public function isDeployed(): bool
    {
        return app()->environment('production');
    }

    private function describe(mixed $value, bool $set): string
    {
        if (! $set) {
            return 'unset';
        }

        $string = (string) $value;

        // Flags are worth showing outright; anything that could be a secret is
        // reduced to a length.
        if (in_array($string, ['true', 'false'], true)) {
            return $string;
        }

        return 'set ('.strlen($string).' chars)';
    }
}
