<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\Offer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prove the eBay integration end to end, or say exactly where it stops.
 *
 * The same argument as `bc:check-bol`: a live source degrades silently by
 * design, so a rejected credential, a spent daily quota and "eBay genuinely has
 * nothing for this" all arrive at the search page as one empty list. That is
 * right for a visitor and useless for whoever has to fix it.
 *
 * eBay adds a second thing worth proving, which bol does not have. Its
 * market-to-marketplace mapping is a guess this codebase makes on the operator's
 * behalf — see {@see Market::ebayMarketplace()} — and a marketplace the Browse
 * API does not serve fails as an empty list too. This command is what turns that
 * guess into a fact in ten seconds, which is the reason the mapping is config
 * rather than a match arm.
 */
class CheckEbayCommand extends Command
{
    protected $signature = 'bc:check-ebay {--market=nl-nl} {--query=koptelefoon}';

    protected $description = 'Check eBay credentials, the token exchange, the marketplace mapping and a live search.';

    public function handle(): int
    {
        $market = Market::tryFrom((string) $this->option('market'));

        if ($market === null) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $id = (string) config('giftcoves.connectors.ebay.client_id');
        $secret = (string) config('giftcoves.connectors.ebay.client_secret');

        // Lengths, never values. A diagnostic that prints a secret is a
        // diagnostic nobody can paste into a ticket.
        $this->components->twoColumnDetail('Enabled', config('giftcoves.connectors.ebay.enabled') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Client id', $id === '' ? '<fg=red>missing</>' : 'set ('.strlen($id).' chars)');
        $this->components->twoColumnDetail('Client secret', $secret === '' ? '<fg=red>missing</>' : 'set ('.strlen($secret).' chars)');
        $this->components->twoColumnDetail('Marketplace', $market->ebayMarketplace() ?? '<fg=yellow>not mapped — eBay is skipped for this market</>');
        $this->components->twoColumnDetail('Campaign id', $market->ebayCampaignId() ?? '<fg=red>none — clicks would earn nothing</>');
        $this->components->twoColumnDetail('Listing filter', (string) config('giftcoves.connectors.ebay.filter') ?: '<fg=yellow>none — auctions and used goods included</>');

        if ($id === '' || $secret === '') {
            $this->newLine();
            $this->components->error('No credentials configured. Nothing else can be checked.');

            return self::FAILURE;
        }

        /*
         * Clear the cache before measuring anything.
         *
         * A cached token hides a credential that has just been revoked, and a
         * cached search result hides everything downstream of it — including a
         * *previous* broken run's empty result, which is the exact way this
         * class of diagnostic sends you to debug the wrong thing.
         */
        Cache::forget('bc:ebay:token');
        Cache::flush();

        $this->newLine();

        try {
            $token = Http::asForm()->timeout(10)
                ->withBasicAuth($id, $secret)
                ->post('https://api.ebay.com/identity/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'scope' => 'https://api.ebay.com/oauth/api_scope',
                ]);
        } catch (Throwable $e) {
            $this->components->error('Token request threw: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($token->failed()) {
            $this->components->error('Token rejected: HTTP '.$token->status().' '.$token->body());
            $this->components->bulletList([
                'invalid_client means eBay does not recognise this App ID / Cert ID pair.',
                'The commonest cause is a SANDBOX keyset: it authenticates against api.sandbox.ebay.com, not this host. Use the production keyset.',
                'A new production keyset also needs its Browse API access approved before it will mint a token.',
            ]);

            return self::FAILURE;
        }

        $this->components->info('Token exchange OK.');

        if ($market->ebayMarketplace() === null) {
            $this->components->warn('This market has no marketplace mapped, so the connector will never call eBay for it. Set EBAY_MARKETPLACE_'.strtoupper(str_replace('-', '_', $market->value)).' to try one.');

            return self::FAILURE;
        }

        $offers = (new EbayConnector)->search((string) $this->option('query'), $market, 5);

        if ($offers === []) {
            $this->components->warn('Authenticated, but the search returned nothing.');
            $this->components->bulletList([
                'Most likely the marketplace: '.$market->ebayMarketplace().' may not be served by the Browse API, which answers 200 with an empty body rather than an error.',
                'Then the filter: '.((string) config('giftcoves.connectors.ebay.filter')).' excludes auctions and used goods, which is most of eBay for some queries.',
                'Then the quota: Browse is metered per day, and a spent quota answers 429. Check storage/logs/laravel.log.',
            ]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Title', 'Price', 'EAN', 'Image', 'Tracked link'],
            array_map(fn (Offer $o): array => [
                mb_substr($o->title, 0, 40),
                $o->price === null ? '—' : number_format($o->price / 100, 2),
                // Expected to be empty here: search summaries carry no barcode,
                // only the item endpoint does. A dash on every row is correct,
                // and is the reason eBay offers often stay ungrouped.
                $o->ean ?? '—',
                $o->imageUrl !== null ? 'yes' : 'NO',
                /*
                 * The failure that earns nothing while looking perfect.
                 *
                 * Tested on the tracking parameters, NOT on the host: an
                 * untracked `itemWebUrl` and a tracked `itemAffiliateWebUrl`
                 * are the same ebay.xx/itm link, and the only difference is
                 * `campid` / `mkcid` riding along. Checking the domain would
                 * report a green "yes" on every row of a connector earning
                 * nothing, which is precisely the state this column exists to
                 * catch.
                 */
                str_contains($o->affiliateUrl, 'campid=') || str_contains($o->affiliateUrl, 'mkcid=')
                    ? 'yes'
                    : '<fg=red>NO</>',
            ], $offers),
        );

        return self::SUCCESS;
    }
}
