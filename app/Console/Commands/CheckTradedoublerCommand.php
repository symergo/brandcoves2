<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Connectors\Offer;
use App\Services\Connectors\Tradedoubler\TradedoublerConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prove the Tradedoubler integration, and — with `--raw` — prove its SHAPE.
 *
 * The `--raw` half is why this command is more than a copy of `bc:check-bol`.
 * Every other connector here was written against a live response; this one was
 * not, because the outbound probe was blocked in the environment where it was
 * written. So its field mapping is Tradedoubler's documented shape and nothing
 * more, and a wrong field name in a connector fails silently — an empty list is
 * indistinguishable from "the network has nothing for this query", which is how
 * the Awin barcode-column bug survived for weeks.
 *
 * `--raw` prints the envelope's real keys and one real product, so the mapping
 * can be checked against the thing itself in one command rather than inferred
 * from an empty result.
 */
class CheckTradedoublerCommand extends Command
{
    protected $signature = 'bc:check-tradedoubler
        {--market=nl-nl}
        {--query=koptelefoon}
        {--raw : Print the response envelope keys and one raw product, to verify the field mapping}';

    protected $description = 'Check the Tradedoubler token, the market scoping and a live search. --raw proves the payload shape.';

    public function handle(): int
    {
        $market = Market::tryFrom((string) $this->option('market'));

        if ($market === null) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $token = (string) config('giftcoves.connectors.tradedoubler.token');
        $scope = $market->tradedoublerQuery();

        /*
         * Lengths, never values — and here that rule has teeth.
         *
         * This token is passed as a QUERY PARAMETER, so it ends up in URLs.
         * Printing a request URL in this command, or logging one in the
         * connector, would put the credential in a terminal buffer and a ticket
         * — and this credential is also what earns the commission, so anybody
         * holding it can attribute their own traffic to this account.
         */
        $this->components->twoColumnDetail('Enabled', config('giftcoves.connectors.tradedoubler.enabled') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Token', $token === '' ? '<fg=red>missing</>' : 'set ('.strlen($token).' chars)');
        $this->components->twoColumnDetail(
            'Market scoping',
            $scope === null
                ? '<fg=yellow>none — this market is skipped entirely</>'
                : http_build_query($scope, '', ', '),
        );

        if ($token === '') {
            $this->newLine();
            $this->components->error('No token configured. Nothing else can be checked.');

            return self::FAILURE;
        }

        if ($scope === null) {
            $this->newLine();
            $this->components->error('No scoping for this market, so the connector will never call Tradedoubler for it. Set giftcoves.connectors.tradedoubler.query.'.$market->value.'.');

            return self::FAILURE;
        }

        // A cached token would hide a revoked credential and a cached search
        // would hide everything downstream of it — including an earlier broken
        // run's empty result, which is how this class of diagnostic sends you
        // to debug the wrong thing.
        Cache::flush();

        $this->newLine();

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/json'])
                ->get('https://api.tradedoubler.com/1.0/products.json', [
                    'token' => $token,
                    'q' => (string) $this->option('query'),
                    'limit' => 5,
                    ...$scope,
                ]);
        } catch (Throwable $e) {
            $this->components->error('Request threw: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->components->error('Rejected: HTTP '.$response->status());
            $this->components->bulletList([
                '401 or 403 means the token is wrong or revoked. There is no token exchange here — the value in TRADEDOUBLER_TOKEN is sent as-is.',
                'The Open Product API token comes from the publisher interface, and is not the same credential as a report-API client id and secret.',
                'If you were given a client_id alongside it, this is probably the wrong API and the OAuth one is meant.',
            ]);

            return self::FAILURE;
        }

        $body = (array) $response->json();

        $this->components->twoColumnDetail('HTTP', (string) $response->status());
        $this->components->twoColumnDetail('Envelope keys', implode(', ', array_keys($body)) ?: '<fg=red>empty body</>');

        if ($this->option('raw')) {
            $this->rawShape($body);
        }

        $offers = (new TradedoublerConnector)->search((string) $this->option('query'), $market, 10);

        if ($offers === []) {
            $this->newLine();
            $this->components->warn('The API answered, but the connector parsed no offers out of it.');
            $this->components->bulletList([
                'That gap IS the thing this command exists to find: the request works and the field mapping does not.',
                'Re-run with --raw and compare the keys against TradedoublerConnector::normalise().',
                'Check storage/logs/laravel.log for "unrecognised envelope", which names the keys that were actually returned.',
            ]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Shop', 'Title', 'Price', 'EAN', 'Image', 'Tracked link'],
            array_map(fn (Offer $o): array => [
                // The whole point of this source: several different shops for
                // one product. All one name here means the advertiser fields
                // are not being read.
                $o->merchantName ?? '<fg=red>none</>',
                mb_substr($o->title, 0, 32),
                $o->price === null ? '—' : number_format($o->price / 100, 2),
                // The other half of the point: without a barcode these offers
                // cannot join the group that makes them a comparison.
                $o->ean ?? '<fg=yellow>—</>',
                $o->imageUrl !== null ? 'yes' : 'NO',
                str_contains($o->affiliateUrl, 'tradedoubler.com') ? 'yes' : '<fg=red>NO</>',
            ], $offers),
        );

        $shops = count(array_unique(array_map(fn (Offer $o): string => (string) $o->merchantName, $offers)));
        $withEan = count(array_filter($offers, fn (Offer $o): bool => $o->ean !== null));

        $this->newLine();
        $this->components->twoColumnDetail('Distinct shops', (string) $shops.' of '.count($offers).' offers');
        $this->components->twoColumnDetail('Offers with a barcode', $withEan.' of '.count($offers));

        // Both numbers are the integration's actual value. One shop and no
        // barcodes is a working request that buys nothing: the offers cannot be
        // compared against each other and cannot join anyone else's group.
        if ($shops < 2) {
            $this->components->warn('Only one shop in these results. Fine for a niche query; suspicious for a mainstream one — check that programName is being read.');
        }

        if ($withEan === 0) {
            $this->components->warn('No barcodes. These offers will not group with the rest of the catalogue — check identifiers.ean in --raw output.');
        }

        return self::SUCCESS;
    }

    /**
     * One real product, flattened enough to read.
     *
     * Deliberately not a full var_dump: a Tradedoubler product with a dozen
     * advertisers is hundreds of lines, and the fields that matter are few.
     *
     * @param  array<string, mixed>  $body
     */
    private function rawShape(array $body): void
    {
        $products = $body['products'] ?? $body['product'] ?? $body['results'] ?? null;

        if (! is_array($products) || $products === []) {
            $this->newLine();
            $this->components->warn('No product rows under any expected key. The envelope keys above are what the connector has to be taught.');

            return;
        }

        $product = $products[0];

        if (! is_array($product)) {
            return;
        }

        $this->newLine();
        $this->components->info('First product, keys and shapes:');

        foreach ($product as $key => $value) {
            $this->components->twoColumnDetail(
                (string) $key,
                match (true) {
                    is_array($value) && array_is_list($value) => 'list('.count($value).')'.($value !== [] && is_array($value[0]) ? ' of {'.implode(', ', array_keys($value[0])).'}' : ''),
                    is_array($value) => '{'.implode(', ', array_keys($value)).'}',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => mb_substr((string) $value, 0, 70),
                },
            );
        }

        $offers = $product['offers'] ?? $product['offer'] ?? null;
        $offer = is_array($offers) ? ($offers[0] ?? $offers) : null;

        if (! is_array($offer)) {
            return;
        }

        $this->newLine();
        $this->components->info('First offer on it — this is where price, shop and link come from:');

        foreach ($offer as $key => $value) {
            $this->components->twoColumnDetail(
                (string) $key,
                match (true) {
                    is_array($value) && array_is_list($value) => 'list('.count($value).')'.($value !== [] && is_array($value[0]) ? ' of {'.implode(', ', array_keys($value[0])).'}' : ''),
                    is_array($value) => '{'.implode(', ', array_keys($value)).'}',
                    is_bool($value) => $value ? 'true' : 'false',
                    // Truncated hard: productUrl is a tracking link and long,
                    // and it is the one field here that carries the token's
                    // affiliate id.
                    default => mb_substr((string) $value, 0, 70),
                },
            );
        }
    }
}
