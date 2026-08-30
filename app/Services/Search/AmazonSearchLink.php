<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;

/**
 * "Search this term on Amazon too", as a tagged URL.
 *
 * The counterpart to {@see AmazonLink}, which reads an Amazon URL somebody
 * pasted in. This one writes the URL we send them back out on.
 *
 * ## Why the search page offers a competitor at all
 *
 * Because the shopper is going to look anyway. A search that found four offers
 * has not answered "is this the best price" — Amazon is the check they run
 * next, and they run it in a new tab whether or not we link to it. The link
 * turns a departure we do not see into one that is attributed, and it keeps the
 * term they typed rather than making them retype it.
 *
 * ## Why this stores nothing
 *
 * Invariant 6 — Amazon may not be mirrored — is about *catalogue* data: title,
 * price, image, availability. A search URL carries none of that. We never
 * request it, never parse a response, and never learn what is on the other end.
 * The whole feature is one anchor.
 *
 * ## Why a missing tag means no link
 *
 * An Amazon Associates tag is issued per marketplace. A `.be` tag on an
 * `amazon.nl` URL is not an error Amazon reports — the page loads, the visitor
 * buys, and the commission goes nowhere. The same is true of no tag at all. So
 * a market that has no tag of its own gets no link, which is a visible absence,
 * rather than a link that silently earns nothing.
 */
final readonly class AmazonSearchLink
{
    private function __construct(
        /** The storefront the visitor lands on, shown to them: `www.amazon.nl`. */
        public string $host,
        public string $url,
        /** False when there was no term — the view drops the quoted words. */
        public bool $hasTerm,
    ) {}

    /**
     * The storefront's own favicon.
     *
     * The same idiom offer rows already use for every shop — see
     * `Merchant::faviconUrl()` — so the hand-off is marked the way the rest of
     * the page marks a shop, and there is no asset of ours to keep in sync.
     *
     * Amazon's own file, served by Amazon, rather than a copy of their logo in
     * our `public/`: a favicon is what a browser fetches from any site it draws
     * a tab for, which keeps this well clear of reproducing a trademark. It is
     * also why the markup hides a broken image instead of reserving space for
     * it — this URL is a convention, not a promise.
     */
    public function iconUrl(): string
    {
        return "https://{$this->host}/favicon.ico";
    }

    /**
     * The hand-off for this market and term, or null if this market has none.
     *
     * An empty term is allowed and is not the same as no link. The search page
     * with nothing typed, and a page that found nothing, are both moments where
     * the shopper's question is wide open — and it was the *link* they wanted,
     * not the term. So a blank term produces the storefront's own home page,
     * still tagged, and the view says "search on Amazon" rather than quoting a
     * term that does not exist. Only a missing tag produces null.
     */
    public static function for(Market $market, string $term = ''): ?self
    {
        $term = trim($term);

        /** @var array{host?: string, tag?: string} $config */
        $config = (array) config("giftcoves.amazon_search.markets.{$market->value}", []);

        $host = trim((string) ($config['host'] ?? ''));
        $tag = trim((string) ($config['tag'] ?? ''));

        if ($host === '' || $tag === '') {
            return null;
        }

        /*
         * `/s` is the search *path*, `k` is the keyword *parameter*. They read
         * as one thing in a URL — `amazon.nl/s?k=koptelefoon` — and they are
         * not: `s` as a query parameter is Amazon's sort key
         * (`s=price-asc-rank`), so `/s?s=koptelefoon` is a sort by a value that
         * does not exist, over no query, and it answers with an empty page. `k`
         * replaced the older `field-keywords`, which still redirects.
         *
         * `tag` is the attribution; nothing else is needed and nothing else is
         * sent. In particular no `ref`, no
         * `linkCode` and no session identifier — the fewer parameters we
         * invent, the fewer break when Amazon changes the shape of that page.
         *
         * http_build_query gives RFC3986 encoding, so a term with a space, an
         * ampersand or an accent arrives intact rather than truncating the
         * query at the first `&`.
         */
        if ($term === '') {
            // The storefront itself, tagged. `/s` with no `k` is a search
            // results page for nothing, which Amazon answers with an empty
            // grid — a worse landing than the shop's own front page.
            $query = http_build_query(['tag' => $tag], '', '&', PHP_QUERY_RFC3986);

            return new self($host, "https://{$host}/?{$query}", hasTerm: false);
        }

        $query = http_build_query(['k' => $term, 'tag' => $tag], '', '&', PHP_QUERY_RFC3986);

        return new self($host, "https://{$host}/s?{$query}", hasTerm: true);
    }

    /** @return array{host: string, url: string, icon: string, hasTerm: bool} */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'url' => $this->url,
            'icon' => $this->iconUrl(),
            'hasTerm' => $this->hasTerm,
        ];
    }
}
