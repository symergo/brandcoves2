import { useTranslations } from '../useTranslations'

export interface AmazonSearch {
    /** The storefront the visitor lands on, e.g. `www.amazon.nl`. */
    host: string
    /** Already tagged. Built by `App\Services\Search\AmazonSearchLink`. */
    url: string
    /** The storefront's own favicon, served by it. */
    icon: string
    /**
     * False when there was no term to search — the URL is the storefront's
     * front page, and the label must not quote words that do not exist.
     */
    hasTerm: boolean
}

/**
 * "Search this on Amazon too" — the one shop we do not carry, offered on
 * purpose.
 *
 * ## Why a competitor sits on our own pages
 *
 * Because the shopper opens that tab anyway. A page of four offers has not
 * answered "am I sure this is the best price", and Amazon is the check they run
 * next — from their own address bar, retyping the term, on a visit we neither
 * see nor earn from. Put the link here and the same departure carries the query
 * they already have and the tag that attributes it.
 *
 * ## Why it is drawn as loudly as our own buttons
 *
 * Because a quiet version does not get the click, and the click is the entire
 * point. Drawn as a bordered note in the rail it read as a footnote about
 * Amazon rather than a way to go there, and the shopper still left through
 * their address bar — the outcome the link exists to replace. So it gets the
 * accent fill, the shop's own mark and an arrow: the three things on this site
 * that say "this is a thing you press".
 *
 * The cost is real and accepted: it competes with our own primary actions on
 * the same screen. It is placed where that competition is least damaging —
 * below the filters, below the offer table — rather than toned down.
 *
 * ## What makes it disappear
 *
 * `link` is null. That is the server's decision, never this component's: no
 * Associates tag for the market (`en` and `es` today), nothing to search on, or
 * — on a product page — a group whose identity is a folded title rather than a
 * barcode. See `AmazonSearchLink`.
 *
 * This never assembles a URL of its own. A URL built in the browser would be
 * missing the tag, and an untagged Amazon link is the failure that looks
 * exactly like a working one: the page loads, the visitor buys, the commission
 * goes nowhere, and nothing in the rendered page shows it.
 */
export default function AmazonSearchCta({
    link,
    label,
    detail = null,
}: {
    link: AmazonSearch | null
    /** Already translated — the two pages ask the question differently. */
    label: string
    /** What is being searched, when that is not obvious from the label. */
    detail?: string | null
}) {
    const { t } = useTranslations()

    if (link === null) {
        return null
    }

    return (
        <a
            href={link.url}
            // The same rel as every other outbound affiliate link on the site:
            // `sponsored` because the tag makes it paid, `noopener` because it
            // is a third party in a new tab, and `nofollow` so a page carrying
            // one of these on every result does not read as link-selling.
            rel="sponsored noopener nofollow"
            target="_blank"
            className="group flex items-center gap-3 rounded-card bg-accent p-4 text-white shadow-sm transition hover:bg-accent-dark"
        >
            {/*
              The storefront's own favicon, on a white tile so a dark mark stays
              legible against the accent fill. `alt=""` because the label beside
              it already names the destination — a screen reader announcing
              "amazon.nl logo" before "Search on Amazon" says it twice.

              Hidden on error rather than given a placeholder box: the URL is
              the favicon convention, not a guarantee, and an empty 24px gap is
              better than a broken-image glyph in the middle of a button.
            */}
            <img
                src={link.icon}
                alt=""
                width={24}
                height={24}
                loading="lazy"
                className="h-6 w-6 shrink-0 rounded bg-white p-0.5"
                onError={(e) => {
                    e.currentTarget.style.display = 'none'
                }}
            />

            <span className="min-w-0 flex-1">
                <span className="block font-semibold leading-snug">{label}</span>
                {/*
                  The storefront, named before the click rather than discovered
                  after it. A Belgian visitor sent to amazon.com.be and a Dutch
                  one sent to amazon.nl are both getting the right shop, and
                  neither can tell that from the word "Amazon" alone.
                */}
                <span className="mt-1 block text-xs text-white/80">
                    {detail !== null && <span>{detail} · </span>}
                    {t('search.amazon_search_host', { host: link.host })}
                </span>
            </span>

            <span aria-hidden className="shrink-0 text-lg transition group-hover:translate-x-0.5">
                →
            </span>
        </a>
    )
}
