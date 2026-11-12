import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Badge from './Badge'
import ImagePlaceholder from './ImagePlaceholder'
import { hasResized, pictureAttributes, type ShopSizes } from '../imageUrl'
import SaveToList from './SaveToList'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

export interface GroupCard {
    id: number
    title: string
    slug: string
    brand: string | null
    image: string | null
    /** Signed address of our resized WebP copies; null keeps `image` as it is. */
    imageToken?: string | null
    /** The shop's own picture per width, where its server takes a size (ShopImageSizes). */
    imageSizes?: ShopSizes
    minPrice: number | null
    maxPrice: number | null
    offerCount: number
    merchantCount: number
    inStock: boolean
    discountPercent: number | null
    /**
     * What other people keep, worded on the server: "On 14 people's lists ·
     * In 3 Coves". Search results only, and null below the privacy threshold
     * (ProductSignals::forResults). Never a claim, never who.
     */
    kept?: string | null
    /**
     * The cheapest shop's own link (CheapestOffer, owner 2026-11-07): the whole
     * card opens it, "Bekijk alle aanbiedingen" under it our product page.
     * Null or absent: the card opens the product page, as before.
     */
    buy?: { url: string; shop: string; domain: string | null; beacon: string; offerId: number } | null
}

/** A direct link skips our redirector, so the browser reports the click. */
function reportBuy(buy: NonNullable<GroupCard['buy']>): void {
    try {
        navigator.sendBeacon?.(buy.beacon, new Blob([JSON.stringify({ offer: buy.offerId })], { type: 'application/json' }))
    } catch {
        // Analytics must never break an outbound click.
    }
}

/**
 * The offer-comparison card.
 *
 * The whole schema exists so this can say "from €X · 3 offers across 2 shops"
 * on a single row of one query. One card per physical product — never one per
 * offer, which is what makes a comparison site different from a search engine
 * pointed at a feed.
 *
 * ## Two links, one card
 *
 * The card used to be a single wrapping `<Link>`, which is the simplest thing
 * that works right up until part of the card needs to point somewhere else. The
 * brand does: a brand page is the most valuable internal link a results grid can
 * offer, and it is not the same destination as the product.
 *
 * Nesting an anchor inside an anchor is invalid HTML and browsers resolve it by
 * discarding one of them, unpredictably. So the structure is the stretched-link
 * pattern instead: the product link carries an `absolute inset-0` overlay that
 * makes the whole card clickable, and the brand link sits above it on the
 * z-axis. Both are real anchors, both are crawlable, neither is inside the other.
 *
 * `brandUrl` is resolved server-side and is null for brands with no page —
 * slugifying in the browser would link confidently to a 404, from every card.
 */
/**
 * The card's width in the grids that draw it (two columns on a phone, three
 * from `sm`, four or five on a desktop), for the browser to pick a copy from
 * the srcset. Roughly right is enough: it only chooses between 160, 320 and 480.
 */
const CARD_SIZES = '(min-width: 1024px) 240px, (min-width: 640px) 33vw, 50vw'

export default function ProductCard({ group, brandUrl }: { group: GroupCard; brandUrl?: string | null }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [broken, setBroken] = useState(false)
    // First failure: a resized address (proxy or the shop's own size), so fall
    // back to the shop's plain URL. Second: that too, so the placeholder.
    const [proxyFailed, setProxyFailed] = useState(false)

    const buy = group.buy ?? null
    const productUrl = `/${market.key}/p/${group.id}/${group.slug}`

    return (
        <article className="group relative flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30">
            <div className="relative aspect-square overflow-hidden bg-card">
                {group.image && !broken ? (
                    <img
                        {...pictureAttributes(group.image, group.imageToken, proxyFailed, 320, CARD_SIZES, [160, 320, 480], group.imageSizes)}
                        alt=""
                        loading="lazy"
                        /*
                          Edge to edge. It was `object-contain` inside 16px of
                          padding, so a feed photo — most arrive on white — sat
                          as a small object in a cream frame, and on a phone,
                          where a card is 170px wide, the frame was a third of
                          it. Cover fills the square; the crop is the photo's
                          own margin, which is what a product shot has to spare.
                        */
                        className="h-full w-full object-cover transition group-hover:scale-[1.02]"
                        // Feed images 404 constantly. A placeholder is less
                        // jarring than a browser's broken-image glyph — and
                        // than the blank square this used to leave, which read
                        // as a card that failed to load rather than a product
                        // with no picture.
                        onError={() => (hasResized(group.imageToken, group.imageSizes) && !proxyFailed ? setProxyFailed(true) : setBroken(true))}
                    />
                ) : (
                    <ImagePlaceholder />
                )}

                {/* The number alone. "16% off" as words is a sentence on a
                    170px card; the sign and the percent say the same thing
                    at a glance and in every language. */}
                {group.discountPercent !== null && (
                    <Badge tone="discount" className="absolute top-2 left-2 tabular-nums">
                        −{n(group.discountPercent)}%
                    </Badge>
                )}

                {/* Bottom left since 2026-10-05: the save button took the top right. */}
                {!group.inStock && (
                    <span className="absolute bottom-2 left-2 rounded bg-ink/70 px-2 py-1 text-xs text-white">
                        {t('product.out_of_stock')}
                    </span>
                )}

                {/*
                  Saving from the results grid.

                  The only Save button used to live on the product page, so the
                  commonest path — search, scan the grid, spot the thing — had no
                  way to keep it without a detour into a page the shopper did not
                  want. Top right of the image since 2026-10-05 (owner), where a
                  save or a favourite sits on most shops' cards; the discount
                  badge keeps the top left and the stock badge moved to the
                  bottom left. Never over the product title.
                */}
                <div className="absolute top-2 right-2">
                    <SaveToList groupId={group.id} compact />
                </div>
            </div>

            <div className="flex flex-1 flex-col p-4">
                {group.brand && (
                    <div className="text-xs tracking-wide text-ink-soft uppercase">
                        {brandUrl ? (
                            // z-20 puts it above the product overlay; without it
                            // the overlay swallows the click and the brand link
                            // is decoration.
                            <Link href={brandUrl} className="relative z-20 hover:text-accent hover:underline">
                                {group.brand}
                            </Link>
                        ) : (
                            group.brand
                        )}
                    </div>
                )}

                <h3 className="mt-1 line-clamp-2 text-sm font-medium">
                    {/* The stretched link: an empty span covering the card,
                        so the whole thing is a click target while the anchor
                        itself still wraps only the title for a screen reader.
                        Since 2026-11-07 (owner) it opens the cheapest shop when
                        there is one, as a plain anchor to the shop's own
                        affiliate link: sponsored, a third party in a new tab,
                        nofollow, and never our redirector. */}
                    {buy ? (
                        <a
                            href={buy.url}
                            target="_blank"
                            rel="sponsored noopener nofollow"
                            onMouseDown={() => reportBuy(buy)}
                            aria-label={`${group.title}, ${t('product.buy_at', { shop: buy.shop })}`}
                        >
                            {group.title}
                            <span className="absolute inset-0 z-10" aria-hidden />
                        </a>
                    ) : (
                        <Link href={productUrl}>
                            {group.title}
                            <span className="absolute inset-0 z-10" aria-hidden />
                        </Link>
                    )}
                </h3>

                <div className="mt-auto pt-3">
                    {/*
                      The price and the shop it is at (owner, 2026-11-07: "show the
                      merchant of the lowest offer after the price", "remove
                      vanaf", "remove x aanbiedingen bij x winkels"). It does not
                      change on hover ("don't change on hover"): the card's link
                      says "Koop bij …" to a screen reader, and the shop's name
                      here says where a press goes.
                    */}
                    {group.minPrice !== null && (
                        <div className="flex items-baseline gap-2">
                            <span className="shrink-0 text-lg font-semibold">{formatPrice(group.minPrice, market)}</span>
                            {buy && <span className="min-w-0 truncate text-sm text-ink-soft">{buy.shop}</span>}
                        </div>
                    )}

                    {group.kept && <div className="mt-1 text-xs text-ink-soft">{group.kept}</div>}
                </div>
            </div>

            {/*
              Our product page, a link of its own under the card (never inside
              the card's link), above its stretched area, and tall enough to
              tap without reaching the shop by mistake. "Alle aanbiedingen"
              only when there is more than one; one offer is "the product".
            */}
            {buy && (
                <Link
                    href={productUrl}
                    // Grey, not red (site review, 2026-11-12): twenty red lines made the grid shout.
                    className="relative z-20 flex min-h-11 items-center justify-between gap-2 border-t border-line px-4 text-sm text-ink-soft transition hover:bg-cream hover:text-ink"
                >
                    {group.offerCount > 1
                        ? t('product.all_offers_count', { count: n(group.offerCount) })
                        : t('product.view_product')}
                    <span aria-hidden>→</span>
                </Link>
            )}
        </article>
    )
}
