<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use Carbon\CarbonImmutable;

/**
 * An Amazon sales event shown under the home page's hero while it runs
 * (owner, 2026-10-06: "zet de Amazon Prime Days in de kijker onder de hero,
 * met affiliate links", for the Dutch and Belgian markets, French included).
 *
 * Configured, not coded: each event in `giftcoves.amazon_events` has a window
 * in Belgian time and, per market, the event page's path and Amazon's own
 * banner. The link uses the market's storefront and Associates tag from
 * `giftcoves.amazon_search`, so a market without a tag shows nothing rather
 * than an untracked link, as `AmazonSearchLink` does.
 *
 * Invariant 6 holds: this is a link and Amazon's own creative, loaded from
 * Amazon. Nothing about an Amazon product is stored or shown.
 *
 * The path is the event page itself, never `/deals`: Amazon forwards `/deals`
 * to the event page during the event, and that forward drops the `tag`.
 */
final class AmazonEvent
{
    /** @return array{key: string, url: string, image: string, title: string, body: string, cta: string}|null */
    public static function current(Market $market, ?CarbonImmutable $now = null): ?array
    {
        $store = config('giftcoves.amazon_search.markets.'.$market->value);

        if (! is_array($store) || ($store['tag'] ?? '') === '') {
            return null;
        }

        $now ??= CarbonImmutable::now();

        foreach ((array) config('giftcoves.amazon_events', []) as $key => $event) {
            $from = CarbonImmutable::parse((string) $event['from'], 'Europe/Brussels');
            $until = CarbonImmutable::parse((string) $event['until'], 'Europe/Brussels');
            $here = $event['markets'][$market->value] ?? null;

            if ($here === null || $now->lt($from) || $now->gt($until)) {
                continue;
            }

            $path = (string) $here['path'];
            $url = 'https://'.$store['host'].$path.(str_contains($path, '?') ? '&' : '?').'tag='.rawurlencode((string) $store['tag']);

            return [
                'key' => (string) $key,
                'url' => $url,
                'image' => (string) $here['image'],
                'title' => __("site.home.{$key}.title"),
                'body' => __("site.home.{$key}.body"),
                'cta' => __("site.home.{$key}.cta"),
            ];
        }

        return null;
    }
}
