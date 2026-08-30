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
        $aiOn = (bool) config('giftcoves.ai.enabled');

        /*
         * `connectors.amazon`, not `connectors.sources.amazon` — there is no
         * `sources` level.
         *
         * The wrong path resolved to null, so this read as "Amazon is off" on
         * every environment, which quietly downgraded its credentials from
         * required to optional. The bol rows had the same fault and reported
         * MISSING everywhere, including where bol demonstrably works. A config
         * check that is always wrong in the safe direction is worse than none:
         * it gets ignored, or it sends somebody chasing a credential that was
         * never absent.
         */
        $amazonOn = (bool) config('giftcoves.connectors.amazon.enabled');

        // eBay ships enabled but credential-gated, so "on" here means the flag
        // was not turned off — which is what makes its keys required rather
        // than optional and stops them being quietly forgotten.
        $ebayOn = (bool) config('giftcoves.connectors.ebay.enabled');

        $tradedoublerOn = (bool) config('giftcoves.connectors.tradedoubler.enabled');

        $definition = [
            'Application' => [
                ['APP_KEY', config('app.key'), true, 'Sessions and every encrypted cookie depend on it.'],
                ['APP_URL', config('app.url'), true, 'Absolute URLs in mail, sitemaps and social cards.'],
                ['CREDENTIALS_ENCRYPTION_KEY', config('giftcoves.credentials_key'), true, 'Seals connector_settings. Environment-specific: ciphertext does not travel.'],
                ['CLAIM_HASH_SECRET', config('giftcoves.wishlist.claim_hash_secret'), true, 'Permanent — rotating it orphans every wishlist claim.'],
                ['ROBOTS_ALLOW', config('giftcoves.robots_allow') ? 'true' : 'false', false, 'True in production, false everywhere else.'],
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
                ['ANTHROPIC_API_KEY', config('giftcoves.ai.api_key'), $aiOn, $aiOn ? 'Required while AI is on.' : 'Not needed while AI is off.'],
            ],
            'Connectors' => [
                ['AWIN_API_TOKEN', config('giftcoves.connectors.awin.api_token'), true, 'The catalogue comes from here.'],
                ['AWIN_PUBLISHER_ID', config('giftcoves.connectors.awin.publisher_id'), true, null],
                ['BOL_CLIENT_ID', config('giftcoves.connectors.bol.client_id'), true, 'bol is the only supply the en market has.'],
                ['BOL_CLIENT_SECRET', config('giftcoves.connectors.bol.client_secret'), true, null],
                ['EBAY_CLIENT_ID', config('giftcoves.connectors.ebay.client_id'), $ebayOn, $ebayOn ? 'Required while eBay is on. Without it eBay is simply absent from search.' : 'Not needed while eBay is off.'],
                ['EBAY_CLIENT_SECRET', config('giftcoves.connectors.ebay.client_secret'), $ebayOn, null],
                // Not required, and the row most worth reading anyway: without
                // a campaign id eBay links still work and earn nothing. See
                // Market::ebayCampaignId().
                ['EBAY_CAMPAIGN_ID_NL', config('giftcoves.connectors.ebay.campaign_id.EBAY_NL'), false, 'Missing means eBay clicks from be-nl, nl-nl and en are untracked.'],
                ['EBAY_CAMPAIGN_ID_FR', config('giftcoves.connectors.ebay.campaign_id.EBAY_FR'), false, 'Missing means eBay clicks from be-fr are untracked.'],
                // One credential, and it is both the key and the affiliate id:
                // the tracking link comes back already built around it.
                ['TRADEDOUBLER_TOKEN', config('giftcoves.connectors.tradedoubler.token'), $tradedoublerOn, $tradedoublerOn ? 'Required while Tradedoubler is on. It is also what earns the commission.' : 'Not needed while Tradedoubler is off.'],
                ['AMAZON_ACCESS_KEY', config('giftcoves.connectors.amazon.access_key'), $amazonOn, $amazonOn ? 'Required while Amazon is on.' : 'Not needed while Amazon is off.'],
                ['AMAZON_SECRET_KEY', config('giftcoves.connectors.amazon.secret_key'), $amazonOn, null],
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
        $visible = (array) config('giftcoves.connectors.awin.accounts', []);
        /** @var array<string, array{label: string, env: string}> $declared */
        $declared = (array) config('giftcoves.connectors.awin.declared_accounts', []);

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
