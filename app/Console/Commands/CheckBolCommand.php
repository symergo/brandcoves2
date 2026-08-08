<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Connectors\Bol\BolConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prove the bol integration end to end, or say exactly where it stops.
 *
 * bol is a live source that degrades silently by design — a dead token, a
 * rejected credential and "bol genuinely has no results for this" all arrive at
 * the search page as the same empty list. That is right for a visitor and
 * useless for whoever has to fix it.
 *
 * This walks the same path the connector does and reports each step, so the
 * answer to "is bol working" is one command rather than an afternoon.
 */
class CheckBolCommand extends Command
{
    protected $signature = 'bc:check-bol {--market=be-nl} {--query=koptelefoon}';

    protected $description = 'Check bol credentials, the token exchange and a live search.';

    public function handle(): int
    {
        $market = Market::tryFrom((string) $this->option('market'));

        if ($market === null) {
            $this->error('Unknown market. Valid: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $id = (string) config('brandcoves.connectors.bol.client_id');
        $secret = (string) config('brandcoves.connectors.bol.client_secret');

        // Lengths, never values. A diagnostic that prints a secret is a
        // diagnostic nobody can paste into a ticket.
        $this->components->twoColumnDetail('Enabled', config('brandcoves.connectors.bol.enabled') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Client id', $id === '' ? '<fg=red>missing</>' : 'set ('.strlen($id).' chars)');
        $this->components->twoColumnDetail('Client secret', $secret === '' ? '<fg=red>missing</>' : 'set ('.strlen($secret).' chars)');
        $this->components->twoColumnDetail('Country', $market->bolCountry() ?? '<fg=yellow>not served by bol</>');
        $this->components->twoColumnDetail('Partner site id', $market->bolPartnerSiteId() ?? '<fg=red>none — clicks would earn nothing</>');

        if ($id === '' || $secret === '') {
            $this->newLine();
            $this->components->error('No credentials configured. Nothing else can be checked.');

            return self::FAILURE;
        }

        /*
         * Clear the cache before measuring anything.
         *
         * A cached token hides a credential that has just been revoked, and a
         * cached search result hides everything downstream of it. This cost me
         * an hour: the connector was fixed and working, and the diagnostic kept
         * reporting nothing because a *previous* broken run had left an empty
         * result in the cache. A diagnostic that can be fooled by cache is
         * worse than no diagnostic — it sends you to debug the wrong thing.
         */
        Cache::forget('bc:bol:token');
        Cache::flush();

        $this->newLine();

        try {
            $token = Http::asForm()->timeout(10)
                ->withBasicAuth($id, $secret)
                ->post('https://login.bol.com/token', ['grant_type' => 'client_credentials']);
        } catch (Throwable $e) {
            $this->components->error('Token request threw: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($token->failed()) {
            $this->components->error('Token rejected: HTTP '.$token->status().' '.$token->body());
            $this->components->bulletList([
                'invalid_client means bol does not recognise this id/secret pair — renew it in the bol Partner portal.',
                'The client id is a UUID; the secret is issued alongside it and is not recoverable, only reissued.',
            ]);

            return self::FAILURE;
        }

        $this->components->info('Token exchange OK.');

        $offers = (new BolConnector)->search((string) $this->option('query'), $market, 5);

        if ($offers === []) {
            $this->components->warn('Authenticated, but the search returned nothing. Check the log for a rate-limit or a shape mismatch.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Title', 'Price', 'EAN', 'Image', 'Tracked link'],
            array_map(fn ($o) => [
                mb_substr($o->title, 0, 40),
                $o->price === null ? '—' : number_format($o->price / 100, 2),
                $o->ean ?? '—',
                $o->imageUrl ? 'yes' : 'NO',
                // The failure that earns nothing while looking perfect.
                str_starts_with($o->affiliateUrl, 'https://partner.bol.com/') ? 'yes' : 'NO',
            ], $offers),
        );

        return self::SUCCESS;
    }
}
