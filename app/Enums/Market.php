<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every market the site serves. Single source of truth.
 *
 * Deliberately called "market", not "locale": Laravel already has an app locale
 * for framework strings, and conflating the two is a footgun. `be-nl` and
 * `nl-nl` are both Dutch but they are different catalogues with different
 * merchants, prices, tax and delivery.
 *
 * Product identity is scoped to this value for exactly that reason — an offer
 * from one market is never a valid "cheapest price" for another.
 */
enum Market: string
{
    case BeNl = 'be-nl';
    case BeFr = 'be-fr';
    case En = 'en';
    case Es = 'es';
    case NlNl = 'nl-nl';

    /**
     * Where a visitor lands when nothing tells us anything better.
     *
     * The international market, not Belgium. This is only ever reached after
     * `fromAcceptLanguage()` has failed to match a language at all — so the one
     * thing we know about this visitor is that they read neither Dutch, French
     * nor Spanish. Sending them to `be-nl` answers that by guessing Dutch,
     * which is the single worst guess available given what we just learned.
     *
     * It also changes what an unrecognised header costs: landing on `en` is a
     * language they can probably read, one flag click from anywhere else.
     * Landing on `be-nl` is a page they cannot read at all.
     *
     * Since 2026-08-30 that lands them somewhere with no live source configured
     * — {@see self::bolCountry()}. Still the right default: an empty market in
     * a language you read beats a stocked one you do not, and the flag click
     * out of it works either way.
     */
    public static function default(): self
    {
        return self::En;
    }

    /**
     * Whether this market is offered to the public.
     *
     * A market is a promise of somewhere to buy, and `es` cannot keep it: Awin
     * reports no advertiser coverage for Spain, and bol does not operate there
     * either (see {@see self::bolCountry()}), so it has no supply at all rather
     * than a thin catalogue. Shipping it anyway would put an empty shop in the
     * switcher and five market sitemaps in front of a crawler, one of which
     * leads to nothing.
     *
     * Unpublished is *unadvertised*, not removed. `/es/` still routes, so the
     * copy bank, guides and Cove plans can be prepared before it opens and the
     * whole thing reverses by flipping this one arm. What it does not do is
     * appear in the switcher, the sitemap, the hreflang set, or language
     * negotiation.
     */
    public function isPublished(): bool
    {
        return match ($this) {
            self::Es => false,
            default => true,
        };
    }

    /**
     * Markets a visitor may be shown or sent to.
     *
     * Use this for anything public-facing. Admin and console keep {@see
     * self::cases()}: an editor still needs to build the market that has not
     * opened yet.
     *
     * @return list<self>
     */
    public static function published(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $market): bool => $market->isPublished(),
        ));
    }

    /** Short label for the market switcher. */
    public function label(): string
    {
        return match ($this) {
            self::BeNl => 'BE/NL',
            self::BeFr => 'BE/FR',
            self::En => 'EU/EN',
            self::Es => 'ES/ES',
            self::NlNl => 'NL/NL',
        };
    }

    /** Name in its own language. */
    public function nativeName(): string
    {
        return match ($this) {
            self::BeNl => 'België (Nederlands)',
            self::BeFr => 'Belgique (Français)',
            self::En => 'Europe (English)',
            self::Es => 'España (Español)',
            self::NlNl => 'Nederland (Nederlands)',
        };
    }

    /**
     * The country or region whose catalogue this market sells from.
     *
     * The axis the switcher's flags are on. A market value already encodes this
     * — `be-nl` is Belgium in Dutch — but the two halves have to be separable
     * before a visitor can be offered one of them at a time, and `explode('-')`
     * would call the English market's country "en".
     *
     * `EU` is not a country and is not pretending to be one. The English market
     * has no single home: it exists so that somebody who reads neither Dutch nor
     * French can still use the site.
     *
     * It used to buy from the Belgian catalogue, because that is where the
     * supply is. It no longer does — see {@see self::bolCountry()} for why
     * borrowing a neighbour's catalogue meant borrowing its language too.
     */
    public function country(): string
    {
        return match ($this) {
            self::BeNl, self::BeFr => 'BE',
            self::NlNl => 'NL',
            self::En => 'INT',
            self::Es => 'ES',
        };
    }

    /**
     * Published countries, in the order the switcher shows them.
     *
     * Explicit rather than derived from `cases()`, because the order that
     * matters here is editorial: the home market first, then the neighbour, then
     * the catch-all — and EU last is the point, since it is where a visitor ends
     * up when neither of the real countries fits.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        $published = array_map(fn (self $m): string => $m->country(), self::published());

        return array_values(array_filter(
            ['BE', 'NL', 'INT', 'ES'],
            fn (string $country): bool => in_array($country, $published, true),
        ));
    }

    /**
     * This market's language, named in that language.
     *
     * Always in its own language, never translated: a visitor looking for their
     * language scans for the word they would write, and "Dutch" is invisible to
     * someone who only reads Dutch.
     */
    public function languageName(): string
    {
        return match ($this->language()) {
            'nl' => 'Nederlands',
            'fr' => 'Français',
            'es' => 'Español',
            default => 'English',
        };
    }

    /** ISO 639-1 — which translation file to load. */
    public function language(): string
    {
        return match ($this) {
            self::BeNl, self::NlNl => 'nl',
            self::BeFr => 'fr',
            self::En => 'en',
            self::Es => 'es',
        };
    }

    /**
     * The URL segment the Daily Cove lives under, in this market's language.
     *
     * `/nl-nl/cadeau-van-de-dag/...`, not `/nl-nl/daily/...`. A path segment is
     * read by both a person deciding whether to click and a search engine
     * deciding what the page is about, and "daily" was doing neither job in four
     * of the five markets.
     *
     * The phrase rather than the product's own name, deliberately. The site
     * calls this The Daily Cove — `site.daily.title` — but nobody searches for
     * "cove"; they search "cadeautips". The brand keeps the page, the heading
     * and the newsletter; the URL is the one place where being findable beats
     * being on-message.
     *
     * Short, and that cost something. `cadeau-van-de-dag` reads better and says
     * "daily" outright, but seventeen characters in every archived URL is a lot
     * to spend saying what the slug and the page already say. `cadeautips` is a
     * phrase people actually type.
     *
     * `gift-ideas` is not available for English: it is already the persona
     * shelf.
     *
     * **These strings are permanent.** They are the address of every archived
     * edition, and the archive is the SEO asset the whole daily column is for.
     * Changing one later means another redirect layer on top of the `/daily`
     * one that already exists — see the Daily Cove block in routes/web.php.
     */
    public function coveSegment(): string
    {
        return self::COVE_SEGMENT;
    }

    /**
     * One word for every market: `tips`.
     *
     * The segment was localised per market for about two hours on 2026-09-03 —
     * `cadeautips`, `idees-cadeaux`, `gift-tips`, `ideas-regalo` — before being
     * collapsed to a single English word. Reverted deliberately and early: those
     * URLs were a few hours old and barely crawled, and the same change in a
     * month would have meant abandoning a real archive.
     *
     * `tips` reads in all four languages this site is written in — Dutch and
     * French both use it, and it is short enough not to dominate the path. The
     * gratuity sense exists in English and is not reachable from here: the
     * segment always sits under a market and above an edition slug.
     *
     * {@see self::HISTORICAL_SEGMENTS} for the words that still resolve.
     */
    private const COVE_SEGMENT = 'tips';

    /**
     * Segments this section has used before, which must keep resolving.
     *
     * The archive is the SEO asset the daily column exists to build, so every
     * address a page has ever had is kept. These 301 to the current one rather
     * than 404ing — see DailyCoveController.
     *
     * Note this list is deliberately not per-market. `/es/cadeautips/...` was
     * never a valid address, but it costs nothing to redirect it to the Spanish
     * page and it is one fewer rule to hold.
     *
     * @var list<string>
     */
    public const HISTORICAL_SEGMENTS = [
        'cadeautips',
        'idees-cadeaux',
        'gift-tips',
        'ideas-regalo',
    ];

    /**
     * Every segment the routes must match: the current one and the retired ones.
     *
     * The routes are declared once under a `{market}` prefix, so the segment
     * cannot be a literal. The pattern admits all of these and the controller
     * permanently redirects anything that is not the current word — which is how
     * the archive keeps every address it has ever had without a second set of
     * routes per retired spelling.
     *
     * @return list<string>
     */
    public static function coveSegments(): array
    {
        return [self::COVE_SEGMENT, ...self::HISTORICAL_SEGMENTS];
    }

    /**
     * The path of a Daily Cove page in this market.
     *
     * One place builds this. Before the segment was localised it was spelled
     * `"/{$market->value}/daily/{$slug}"` as a raw string in the sitemap, the
     * hreflang alternates, the digest mailer and two controllers — and a rule
     * that lives in five string literals is a rule that will be right in four
     * of them after the next change.
     */
    public function covePath(string $path = ''): string
    {
        return '/'.$this->value.'/'.$this->coveSegment()
            .($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** BCP 47 tag for hreflang, <html lang> and Intl formatting. */
    public function hrefLang(): string
    {
        return match ($this) {
            self::BeNl => 'nl-BE',
            self::BeFr => 'fr-BE',
            self::En => 'en',
            self::Es => 'es-ES',
            self::NlNl => 'nl-NL',
        };
    }

    public function currency(): string
    {
        return 'EUR';
    }

    /**
     * bol.com country code. Null means bol has no catalogue for this market.
     *
     * ## `en` is null by choice, not by geography
     *
     * It read `BE` until 2026-08-30, on the reasoning in {@see self::country()}:
     * the English market has no home of its own, so it bought from the Belgian
     * catalogue because that is where the supply is.
     *
     * What that delivered was an English market whose every product title was
     * **Dutch**. bol has no English catalogue, so {@see
     * self::bolAcceptLanguage()} asked it for `nl` deliberately, and the stored
     * titles came back reading "Strex OBD2 Scanner - Auto Uitlezen en Storing
     * Verwijderen - Nederlandse Taal" under English page furniture. That is not
     * a thinner catalogue, it is an unreadable one, and no display-time filter
     * repairs a title that is wrong in the database.
     *
     * So this is a decision about what `en` *is*, not about how much supply it
     * has: a market that cannot describe what it sells in its own language is
     * not serving that language. `docs/showcase-mode.md` holds the rejected
     * alternative — keep the Dutch catalogue and translate it into a `title_en`
     * column — which was priced at 3,400 rows and not taken.
     *
     * **This removes `en`'s only configured source.** Awin has no advertisers
     * registered for it, and eBay and Tradedoubler are both credential-blocked
     * (see `docs/TODO.md`), so `en` has no live supply until one of those
     * lands. It is still {@see self::default()} — where every unrecognised
     * `Accept-Language` arrives.
     */
    public function bolCountry(): ?string
    {
        return match ($this) {
            self::BeNl, self::BeFr => 'BE',
            self::NlNl => 'NL',
            // bol does not operate in Spain; this market is Awin-only for now.
            self::Es => null,
            // English or nothing — see above.
            self::En => null,
        };
    }

    /**
     * The bol Partner site id that earns on a click from this market.
     *
     * Follows the country, not the language: Belgium and the Netherlands are
     * separate partner accounts with separate ids, so `be-fr` and `be-nl` share
     * one and `nl-nl` has its own. A market bol does not serve has none.
     */
    public function bolPartnerSiteId(): ?string
    {
        $country = $this->bolCountry();

        if ($country === null) {
            return null;
        }

        $id = config("giftcoves.connectors.bol.partner_site_id.{$country}");

        return blank($id) ? null : (string) $id;
    }

    /**
     * Accept-Language sent to bol.
     *
     * Null wherever {@see self::bolCountry()} is null, and it has to stay that
     * way: a language here for a market bol is not asked about is a standing
     * invitation to re-enable the wrong thing by reading this arm as evidence
     * the market was supported.
     *
     * `en` returned `nl` until 2026-08-30 — bol has no English catalogue, and
     * Dutch names were judged better than no results. They were not; that is
     * why `en` no longer asks bol anything.
     */
    public function bolAcceptLanguage(): ?string
    {
        return match ($this) {
            self::BeNl => 'nl-BE',
            self::BeFr => 'fr-BE',
            self::NlNl => 'nl-NL',
            self::En, self::Es => null,
        };
    }

    /**
     * eBay marketplace id, for the `X-EBAY-C-MARKETPLACE-ID` header.
     *
     * Null means "do not query eBay for this market", never "use the default":
     * a request sent to the wrong marketplace still succeeds, and returns
     * priced, buyable, completely irrelevant results.
     *
     * ## Belgium is served from the neighbours, and that is a decision
     *
     * eBay publishes `EBAY_BENL` and `EBAY_BEFR` as marketplace ids, but the
     * Browse API's marketplace support is narrower than the id list and the
     * Belgian sites have long redirected at the storefront. A marketplace the
     * Browse API does not serve fails the way every mistake in this connector
     * fails — an empty array, indistinguishable from "eBay has nothing" — so
     * the default is the neighbouring marketplace that is certainly served and
     * certainly prices in euro: Dutch Belgium reads `ebay.nl`, French Belgium
     * `ebay.fr`. Both ship to Belgium; neither invents a currency.
     *
     * Every value is overridable per market (`EBAY_MARKETPLACE_BE_NL` and
     * friends), so pointing `be-nl` at `EBAY_BENL` after proving it with
     * `bc:check-ebay --market=be-nl` is an env change, not a deploy of this file.
     *
     * `en` follows bol's precedent: there is no English euro marketplace, so it
     * reads the Dutch one rather than `EBAY_GB`, whose prices are sterling. See
     * EbayConnector::normalise() for why a non-euro price is dropped outright.
     */
    public function ebayMarketplace(): ?string
    {
        $configured = config("giftcoves.connectors.ebay.marketplace.{$this->value}");

        return blank($configured) ? null : (string) $configured;
    }

    /**
     * The eBay Partner Network campaign id that earns on a click from here.
     *
     * Follows the marketplace rather than the market, because EPN campaigns are
     * created per marketplace and `be-nl`, `nl-nl` and `en` all read `EBAY_NL`
     * — three markets, one campaign, one row of config.
     *
     * Null is survivable and silent, which is the danger: without a campaign id
     * eBay simply omits `itemAffiliateWebUrl` from the response and the
     * connector falls back to the plain item URL. The link works, the visitor
     * buys, and the commission goes to nobody. Same failure as bol's site id,
     * and `bc:check-ebay` reports it in red for the same reason.
     */
    public function ebayCampaignId(): ?string
    {
        $marketplace = $this->ebayMarketplace();

        if ($marketplace === null) {
            return null;
        }

        $id = config("giftcoves.connectors.ebay.campaign_id.{$marketplace}");

        return blank($id) ? null : (string) $id;
    }

    /**
     * Query parameters that scope a Tradedoubler search to this market.
     *
     * Null means "do not ask Tradedoubler about this market", never "use the
     * default" — the same rule bol and eBay follow, and here it matters more
     * than for either of them. Tradedoubler is a NETWORK spanning every European
     * market at once, and an unrecognised filter parameter is *ignored* rather
     * than rejected. So the failure mode is not an error and not an empty list:
     * it is a Belgian visitor being shown German offers, in German, priced for
     * delivery from Germany, with nothing anywhere reporting a problem.
     *
     * Returned as an array of parameters rather than a single code because the
     * right scoping is not yet known and will change. `language` is the opening
     * bid; program-id scoping is the real answer once the operator knows which
     * advertisers they are joined to, exactly as `connectors.awin.advertisers`
     * is for Awin. Config carries the whole array so that move is an env change
     * rather than a signature change here.
     *
     * {@see App\Services\Connectors\Tradedoubler\TradedoublerConnector} does
     * NOT rely on this alone: it drops any offer whose currency is not this
     * market's, which is the guard that holds even when the scoping is wrong.
     *
     * @return array<string, scalar>|null
     */
    public function tradedoublerQuery(): ?array
    {
        $configured = config("giftcoves.connectors.tradedoubler.query.{$this->value}");

        if (! is_array($configured) || $configured === []) {
            return null;
        }

        return array_filter($configured, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Every language this application speaks, each once.
     *
     * Five markets, four languages — `be-nl` and `nl-nl` share one. Anything
     * that has to be produced in *all* of them (a stored string translated
     * after the fact, a language-independent comparison) iterates this rather
     * than `cases()`, which would do the Dutch work twice.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_values(array_unique(
            array_map(fn (self $m): string => $m->language(), self::cases()),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /**
     * Best-effort market for an incoming Accept-Language header.
     *
     * Deliberately conservative: a wrong guess shows the wrong currency and the
     * wrong merchants, so anything unrecognised falls back to the default market
     * rather than being approximated.
     */
    public static function fromAcceptLanguage(?string $header): self
    {
        if ($header === null || trim($header) === '') {
            return self::default();
        }

        $tags = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0] ?? ''));
            if ($tag === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $quality = (float) substr(trim($param), 2);
                }
            }
            $tags[] = ['tag' => $tag, 'q' => $quality];
        }

        usort($tags, fn (array $a, array $b) => $b['q'] <=> $a['q']);

        // Resolve each tag fully — exact match, then language — before moving to
        // the next one. Doing all the exact matches first looks equivalent but
        // is not: "fr,en;q=0.5" would then match `en` exactly and return the
        // English market, ignoring that the visitor asked for French first.
        // Published only. Sending a Spanish speaker to a market with nothing in
        // it is a worse answer than the default, which at least has a catalogue.
        foreach ($tags as $t) {
            // Exact tag wins: nl-BE is a better answer than "some Dutch market".
            foreach (self::published() as $market) {
                if (strtolower($market->hrefLang()) === $t['tag']) {
                    return $market;
                }
            }

            $language = explode('-', $t['tag'])[0];
            foreach (self::published() as $market) {
                if ($market->language() === $language) {
                    return $market;
                }
            }
        }

        return self::default();
    }
}
