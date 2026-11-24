import { useEffect, useMemo, useState } from 'react'
import { usePage } from '@inertiajs/react'
import LaneScroller from './LaneScroller'
import SaveToList from './SaveToList'
import ToolIcon from './ToolIcon'
import { type GroupCard } from './ProductCard'
import { type AmazonItem } from './AmazonProductCard'
import { type AmazonSearch } from './AmazonSearchCta'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/*
 * The by-store view: shops side by side, one column each. Shared by the search page
 * and the brand page (owner, 2026-11-24: "I don't see a grid/store button?"). Moved
 * out of Search.tsx with its reasons.
 */

/** Where this browser keeps the shops hidden in the by-store view. */
const HIDDEN_SHOPS_KEY = 'bc:search:hidden-shops'

/**
 * That shop's offer for a row in its column (SearchController::laneCard): the
 * row opens the shop, with the shop's own price (owner, 2026-11-07).
 */
export interface LaneOffer {
    /** The shop's own affiliate link, never our redirector (checked on the server). */
    url: string
    price: number | null
    direct: boolean
    beacon: string | null
    offerId: number
    needsPriceTimestamp: boolean
}

export interface Lane {
    shop: string
    logo: string | null
    /** The shop's address, for "Links naar …" in the header; null when unknown. */
    domain: string | null
    items: (GroupCard & { shop: LaneOffer })[]
}

/** A direct link skips the redirector, so the browser reports the click (as on the product page). */
function reportLaneClick(offer: LaneOffer): void {
    if (!offer.direct || !offer.beacon) return

    try {
        navigator.sendBeacon?.(offer.beacon, new Blob([JSON.stringify({ offer: offer.offerId })], { type: 'application/json' }))
    } catch {
        // Analytics must never break an outbound click.
    }
}

/*
 * The by-store view, after the shops answered: the lanes the follow-up computed
 * again, the shops with products found online first, as in the grid. Before that,
 * the lanes the page came with.
 */
export function useLiveLanes(
    storedLanes: Lane[] | null,
    storedResults: { items: GroupCard[] },
    live: { lanes?: Lane[] | null } | null | undefined,
): Lane[] | null {
    return useMemo<Lane[] | null>(() => {
        if (!live?.lanes) {
            return storedLanes
        }
        const shown = new Set(storedResults.items.map((g) => g.id))
        const hasNew = (lane: Lane) => lane.items.some((g) => !shown.has(g.id))

        return [...live.lanes.filter(hasNew), ...live.lanes.filter((lane) => !hasNew(lane))]
    }, [storedLanes, storedResults, live])
}

/*
 * Shops hidden in the by-store view (owner, 2026-10-25), by name. A per-visitor
 * preference, so it lives in this browser and carries over to the next search: a
 * shop you hid once stays out of the way. Read after mounting (storage does not
 * exist on the SSR container) and inside try/catch, since storage can be blocked or
 * missing.
 */
export function useHiddenShops(): { hidden: string[]; hide: (shop: string) => void; show: (shop: string) => void; showAll: () => void } {
    const [hiddenShops, setHiddenShops] = useState<string[]>([])
    useEffect(() => {
        try {
            const stored: unknown = JSON.parse(window.localStorage.getItem(HIDDEN_SHOPS_KEY) ?? '[]')
            if (Array.isArray(stored)) {
                setHiddenShops(stored.filter((name): name is string => typeof name === 'string'))
            }
        } catch {
            // No storage: nothing hidden.
        }
    }, [])
    const keepHiddenShops = (next: string[]) => {
        setHiddenShops(next)
        try {
            window.localStorage.setItem(HIDDEN_SHOPS_KEY, JSON.stringify(next))
        } catch {
            // Hidden for this page only.
        }
    }
    const hideShop = (shop: string) => keepHiddenShops([...hiddenShops.filter((name) => name !== shop), shop])
    const showShop = (shop: string) => keepHiddenShops(hiddenShops.filter((name) => name !== shop))
    const showAllShops = () => keepHiddenShops([])

    return { hidden: hiddenShops, hide: hideShop, show: showShop, showAll: showAllShops }
}

/**
 * Shops side by side, one column each.
 *
 * Stacked, this view answered "what does Krefel have" and then, several screens
 * later, "what does Coolblue have", which is two answers to one question and
 * defeats the point of grouping by shop at all. In columns the comparison is the
 * layout. Horizontal scroll rather than wrapping: a fourth shop belongs beside the
 * third, not underneath the first, and the column width is fixed so the scroll is
 * legible rather than a squeeze.
 */
export default function StoreLanes({
    lanes,
    hidden: hiddenShops,
    hide: hideShop,
    showAll: showAllShops,
    amazon,
    amazonSearch,
}: {
    lanes: Lane[]
    hidden: string[]
    hide: (shop: string) => void
    showAll: () => void
    amazon?: AmazonItem[] | null
    amazonSearch: AmazonSearch | null
}) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const amazonHost = amazonSearch ? amazonSearch.host.replace(/^www\./, '') : ''

    return (
        <>
        {/*
          Hidden columns, and the way back (owner, 2026-10-25:
          hide a shop on its column instead of the chip row
          that used to sit above them).
        */}
        {lanes.some((lane) => hiddenShops.includes(lane.shop)) && (
            <p className="mb-3 text-sm text-ink-soft">
                {(() => {
                    const count = lanes.filter((lane) => hiddenShops.includes(lane.shop)).length
                    return count === 1 ? t('search.hidden_shops_one') : t('search.hidden_shops', { count })
                })()}
                {' · '}
                <button type="button" onClick={() => showAllShops()} className="text-accent-dark underline hover:text-ink">
                    {t('search.show_all_shops')}
                </button>
            </p>
        )}
        <LaneScroller>
            {/*
              Amazon's column (owner, 2026-11-23: "add also amazon
              column to the by store view"), first, as on the
              product page. Drawn like the shops' columns: the
              storefront as the name, Amazon's mark, a row per
              product. Where the others show a price, each row says
              "Bekijk op Amazon": Amazon's price may not be shown
              without its own API. No hide button and no save
              button: Amazon's products are not in our catalogue.
            */}
            {amazon && amazon.length > 0 && amazonSearch && (
                <section
                    className="w-60 shrink-0 snap-start overflow-hidden rounded-card border border-line bg-card sm:w-72 xl:w-80"
                    aria-label={amazonHost}
                >
                    <div className="flex items-center gap-2 border-b border-line bg-cream py-1.5 pr-1.5 pl-3">
                        <h2 className="flex min-w-0 flex-1 items-center gap-2 py-1 font-medium">
                            <img
                                src={amazonSearch.icon}
                                alt=""
                                loading="lazy"
                                width={20}
                                height={20}
                                className="h-5 w-5 shrink-0 rounded bg-white object-contain"
                                onError={(e) => {
                                    e.currentTarget.hidden = true
                                }}
                            />
                            <span className="min-w-0">
                                <span className="block truncate">{amazonHost}</span>
                                <span className="block truncate text-xs font-normal text-ink-soft">
                                    {t('search.links_to', { shop: amazonHost })}
                                </span>
                            </span>
                        </h2>
                    </div>
                    <ul className="divide-y divide-line">
                        {amazon.map((item) => (
                            <li key={item.asin} className="relative">
                                <a
                                    href={item.url}
                                    target="_blank"
                                    rel="sponsored noopener nofollow"
                                    className="group flex gap-3 p-3 transition hover:bg-cream"
                                >
                                    {item.image && (
                                        <img src={item.image} alt="" className="h-14 w-14 shrink-0 object-contain" loading="lazy" />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-sm text-ink-soft">{item.title}</span>
                                        <span className="mt-1.5 flex items-center">
                                            <span
                                                aria-hidden
                                                className="mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-white text-ink transition group-hover:border-accent group-hover:bg-accent group-hover:text-white"
                                            >
                                                <ToolIcon name="shop" className="h-4 w-4" />
                                            </span>
                                            <span className="text-sm font-semibold text-ink">{t('product.check_on_amazon')}</span>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {lanes.filter((lane) => !hiddenShops.includes(lane.shop)).map(({ shop, logo, domain, items }) => (
                /*
                  Each shop is a card, not a stretch of column.

                  Stacked as bare bordered rows under a hairline
                  heading, nothing said where one shop ended and
                  the next began except the gap between them —
                  on a strip that scrolls sideways, the reader
                  loses which column they are in. A single
                  surface with the shop's name banded across the
                  top holds the column together as one object,
                  which is what it is.
                */
                <section
                    key={shop}
                    className="w-60 shrink-0 snap-start overflow-hidden rounded-card border border-line bg-card sm:w-72 xl:w-80"
                    aria-label={shop}
                >
                    {/*
                      The shop's mark and its name, and nothing
                      after them.

                      The header used to carry the number of
                      products in the lane, which was never the
                      number a shopper would read it as: the
                      lane is capped at store_lane_cap, so a
                      shop with four hundred matches and one
                      with exactly eight both said "8". A count
                      that is really a description of the cap is
                      worse than no count.

                      The logo took its place because this is
                      the one view a shopper scans by shop
                      rather than by product, and a mark is
                      recognised across a horizontal scroll
                      faster than a truncated name — which is
                      what several of these are at 224px.

                      Hidden `onError` rather than checked
                      first: the URL is usually a favicon
                      guessed from the merchant's domain, so
                      whether it exists is something only the
                      browser finds out. `alt=""` because the
                      name is right beside it — a described logo
                      would have a screen reader say the shop
                      twice.
                    */}
                    <div className="flex items-center gap-2 border-b border-line bg-cream py-1.5 pr-1.5 pl-3">
                    <h2 className="flex min-w-0 flex-1 items-center gap-2 py-1 font-medium">
                        {logo && (
                            <img
                                src={logo}
                                alt=""
                                loading="lazy"
                                width={20}
                                height={20}
                                className="h-5 w-5 shrink-0 rounded object-contain"
                                onError={(e) => {
                                    e.currentTarget.hidden = true
                                }}
                            />
                        )}
                        <span className="min-w-0">
                            <span className="block truncate">{shop}</span>
                            {/*
                              Where every row in this column goes,
                              said once (owner, 2026-11-07: "it
                              should be clear that a link goes to
                              the store", then "don't mention the
                              store each time").
                            */}
                            {domain && (
                                <span className="block truncate text-xs font-normal text-ink-soft">
                                    {t('search.links_to', { shop: domain })}
                                </span>
                            )}
                        </span>
                    </h2>
                    {/* Hide this shop's column; the line above the columns brings it back. */}
                    <button
                        type="button"
                        onClick={() => hideShop(shop)}
                        aria-label={t('search.hide_shop', { shop })}
                        title={t('search.hide_shop', { shop })}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/50 hover:text-ink"
                    >
                        <ToolIcon name="close" className="h-3.5 w-3.5" />
                    </button>
                    </div>

                    {/*
                      Rows divided by a hairline rather than
                      boxed individually: inside a card that
                      already has an edge, a border per row is
                      three nested outlines in 224px. The image
                      gives up most of its height because in a
                      column it is the title and the price that
                      get compared.
                    */}
                    <ul className="divide-y divide-line">
                        {items.map((g) => (
                            <li key={g.id} className="relative">
                                {/*
                                  The grid view saves because it
                                  is a ProductCard; this one is a
                                  bespoke compact row and had no
                                  control at all — so changing
                                  how you look at the same
                                  results quietly took away the
                                  ability to keep one. Outside
                                  the anchor, which owns the
                                  click.
                                */}
                                <div className="absolute top-1.5 right-1.5 z-10">
                                    <SaveToList groupId={g.id} compact />
                                </div>
                                {/*
                                  Straight to this shop (owner,
                                  2026-11-07): the column is that
                                  shop's stock, so the row is its
                                  "Naar de winkel". The same link
                                  and rel as the product page's
                                  button: sponsored, because it
                                  is paid; noopener, a third party
                                  in a new tab; nofollow, so a
                                  page of them does not read as
                                  link-selling.
                                */}
                                <a
                                    href={g.shop.url}
                                    target="_blank"
                                    rel="sponsored noopener nofollow"
                                    onMouseDown={() => reportLaneClick(g.shop)}
                                    className="group flex gap-3 p-3 pr-14 transition hover:bg-cream"
                                >
                                    {g.image && (
                                        <img
                                            src={g.image}
                                            alt=""
                                            className="h-14 w-14 shrink-0 object-contain"
                                            loading="lazy"
                                        />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-sm text-ink-soft">
                                            {g.title}
                                        </span>
                                        {/*
                                          The discount trails the
                                          price as a bare "−20%",
                                          not the grid's badge
                                          over the image: the
                                          lane is 240px wide at least and
                                          the thumbnail 56px, so
                                          there is room for a
                                          suffix and none for
                                          either a badge or a
                                          second line. It was
                                          missing entirely, so the
                                          same result looked
                                          full-price in this view
                                          and reduced in the other
                                          one.

                                          The percentage carries
                                          its own sign rather than
                                          the translated ":percent%
                                          off" string, which does
                                          not fit — so the number
                                          is announced properly to
                                          a screen reader, which
                                          would otherwise read a
                                          price and an unexplained
                                          negative.

                                          The title is the softer
                                          of the two: in a column
                                          of one shop's stock the
                                          price is what is being
                                          compared, so it is the
                                          thing that should carry
                                          the weight.
                                        */}
                                        <span className="mt-1.5 flex items-center">
                                            {/*
                                              To the shop, as a mark rather than
                                              words (owner, 2026-11-07: the arrow
                                              was too plain, the shop is named once
                                              in the header). Before the price, white
                                              with a black bag while it is not
                                              pressed, like the save button beside
                                              it; red when the row is pointed at.
                                            */}
                                            <span
                                                aria-hidden
                                                className="mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-white text-ink transition group-hover:border-accent group-hover:bg-accent group-hover:text-white"
                                            >
                                                <ToolIcon name="shop" className="h-4 w-4" />
                                            </span>
                                            <span className="text-base font-semibold text-ink">
                                                {g.shop.price === null
                                                    ? '-'
                                                    : formatPrice(g.shop.price, market)}
                                            </span>
                                            {/* The discount is the cheapest offer's: shown on that price only. */}
                                            {g.discountPercent !== null && g.shop.price === g.minPrice && (
                                                <span
                                                    className="ml-1.5 text-sm font-medium text-accent"
                                                    aria-label={t('product.off', {
                                                        percent: g.discountPercent,
                                                    })}
                                                >
                                                    −{n(g.discountPercent)}%
                                                </span>
                                            )}
                                        </span>
                                        {g.shop.needsPriceTimestamp && (
                                            <span className="mt-0.5 block text-2xs text-ink-soft">
                                                {t('product.price_as_of')}
                                            </span>
                                        )}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </LaneScroller>
        </>
    )
}
