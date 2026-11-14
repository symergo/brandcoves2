<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Enums\Market;
use App\Models\Feed;
use App\Models\Merchant;
use Illuminate\Support\Facades\Cache;

/**
 * The tracked address for a link to a shop we are registered with.
 *
 * The owner's rule (2026-11-07): "bol links should be affiliate links - that's
 * a rule for all merchants registered on giftcoves". The feeds already arrive
 * with tracked links; this is for an address somebody typed, such as a shop
 * link in an article. An untracked link to a partner works perfectly and earns
 * nothing, and nothing on the site would ever report it, so every such link
 * goes through here at render.
 *
 * - bol: its own click redirector, `partner.bol.com/click/click`, with the
 *   site id of the country in the link's path (`/be/` or `/nl/`), the same
 *   form `BolConnector` builds for the feed.
 * - Awin advertisers (Coolblue, Dreamland, Fnac, Krefel, Vanden Borre): the
 *   network's deep link, `awin1.com/cread.php`, with the advertiser id from
 *   `merchants.external_id` and the publisher id of the Awin account whose
 *   feed carries that advertiser (Vanden Borre has an account of its own).
 *   A shop is recognised by its domain without the first label when that is
 *   a language or `www` (`nl.fnac.be` covers `fr.fnac.be` too).
 *
 * Amazon is tagged where the link is written (CoveMarkup::shop()), because
 * its tag lives in the product address itself. eBay is not wrapped: its
 * tracked form needs a rotation id per marketplace that this repo has not
 * verified; see docs/features/affiliate-links.md.
 *
 * Anything else, and a registered shop whose ids are missing in this
 * environment, comes back unchanged: a plain link beats a broken tracker.
 */
final class MerchantLinks
{
    /** One lookup of the Awin shops per hour: the list changes when a feed is registered. */
    private const CACHE_KEY = 'bc:merchant-links:awin';

    private const CACHE_TTL = 3600;

    /** The address to send a reader to: tracked when the shop is ours, else as given. */
    public static function wrap(string $url, ?Market $market = null): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || self::isTracked($url)) {
            return $url;
        }

        if ($host === 'bol.com' || str_ends_with($host, '.bol.com')) {
            return self::bol($url, $market);
        }

        try {
            $awin = self::awinShops()[self::shopDomain($host)] ?? null;
        } catch (\Throwable $e) {
            // No database to ask (a unit test, an outage): the plain link.
            report($e);
            $awin = null;
        }

        if ($awin !== null && filled($awin['publisher'])) {
            return 'https://www.awin1.com/cread.php?'.http_build_query([
                'awinmid' => $awin['advertiser'],
                'awinaffid' => $awin['publisher'],
                'ued' => $url,
            ]);
        }

        return $url;
    }

    /** Whether an address already goes through a network's tracker. */
    public static function isTracked(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['partner.bol.com', 'www.awin1.com', 'awin1.com', 'clk.tradedoubler.com'], true);
    }

    /**
     * A stored bol tracker link, carrying the site id configured today.
     *
     * A bol offer is stored with its whole tracker link, site id included, and
     * a stored offer can be weeks old. When the site id changes (2026-11-14:
     * GiftCoves got its own, the links held bstore's) every stored link would
     * keep crediting the old one until its offer happened to be refreshed. So
     * the id is put in when the link is read, by the country of the bol
     * address inside it. Anything that is not a bol tracker link comes back
     * untouched, and so does one for a country without an id.
     */
    public static function withCurrentBolSite(string $url): string
    {
        if (! str_starts_with($url, 'https://partner.bol.com/')) {
            return $url;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $target = is_string($query['url'] ?? null) ? $query['url'] : '';
        $path = (string) parse_url($target, PHP_URL_PATH);
        $country = match (true) {
            str_starts_with($path, '/be/') => 'BE',
            str_starts_with($path, '/nl/') => 'NL',
            default => null,
        };

        $siteId = $country === null ? null : config("giftcoves.connectors.bol.partner_site_id.{$country}");

        if (blank($siteId) || ! isset($query['s']) || (string) $query['s'] === (string) $siteId) {
            return $url;
        }

        $query['s'] = (string) $siteId;

        return 'https://partner.bol.com/click/click?'.http_build_query($query);
    }

    /**
     * bol's click redirector, for the country in the link's own path.
     *
     * By the link rather than by the page: an article on be-fr may point at
     * bol.com/nl/, and a sale there is credited to the NL site.
     */
    private static function bol(string $url, ?Market $market): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $country = match (true) {
            str_starts_with($path, '/be/') => 'BE',
            str_starts_with($path, '/nl/') => 'NL',
            default => $market?->bolCountry(),
        };

        $siteId = $country === null ? null : config("giftcoves.connectors.bol.partner_site_id.{$country}");

        if (blank($siteId)) {
            return $url;
        }

        return 'https://partner.bol.com/click/click?'.http_build_query([
            'p' => '2',
            't' => 'url',
            's' => (string) $siteId,
            'f' => 'TXL',
            'name' => 'giftcoves-'.($market?->value ?? mb_strtolower($country)),
            'url' => $url,
        ]);
    }

    /**
     * The shop part of a host: `www.coolblue.be` and `coolblue.be` alike,
     * `nl.fnac.be` and `fr.fnac.be` alike.
     */
    private static function shopDomain(string $host): string
    {
        return (string) preg_replace('/^(www|nl|fr|en|de|es)\./', '', $host);
    }

    /**
     * Enabled Awin advertisers by shop domain, with the publisher id to use.
     *
     * The account comes from the feed that carries the advertiser, matched on
     * the name both are given when a feed is registered (`bc:awin-feeds`).
     *
     * @return array<string, array{advertiser: string, publisher: string|null}>
     */
    private static function awinShops(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $accounts = Feed::query()
                ->where('source', 'awin')
                ->pluck('account', 'label')
                ->all();

            $shops = [];

            foreach (Merchant::query()->where('source', 'awin')->where('enabled', true)->whereNotNull('domain')->get() as $merchant) {
                $account = $accounts[$merchant->name] ?? 'default';
                // No fallback to the default publisher for another account:
                // a sale credited to the wrong publisher is worse than none.
                $publisher = config("giftcoves.connectors.awin.accounts.{$account}.publisher_id")
                    ?? ($account === 'default' ? config('giftcoves.connectors.awin.publisher_id') : null);

                $shops[self::shopDomain(mb_strtolower((string) $merchant->domain))] = [
                    'advertiser' => (string) $merchant->external_id,
                    'publisher' => blank($publisher) ? null : (string) $publisher,
                ];
            }

            return $shops;
        });
    }
}
