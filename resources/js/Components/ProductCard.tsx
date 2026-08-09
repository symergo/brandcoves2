import { Link, usePage } from '@inertiajs/react'
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
    minPrice: number | null
    maxPrice: number | null
    offerCount: number
    merchantCount: number
    inStock: boolean
    discountPercent: number | null
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
export default function ProductCard({ group, brandUrl }: { group: GroupCard; brandUrl?: string | null }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const comparable = group.merchantCount > 1

    return (
        <article className="group relative flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30">
            <div className="relative aspect-square overflow-hidden bg-cream">
                {group.image ? (
                    <img
                        src={group.image}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-contain p-4 transition group-hover:scale-[1.02]"
                        // Feed images 404 constantly. Hiding the broken image is
                        // less jarring than a browser's broken-image glyph.
                        onError={(e) => {
                            e.currentTarget.style.visibility = 'hidden'
                        }}
                    />
                ) : null}

                {group.discountPercent !== null && (
                    <span className="absolute top-2 left-2 rounded bg-accent px-2 py-1 text-xs font-medium text-white">
                        {t('product.off', { percent: group.discountPercent })}
                    </span>
                )}

                {!group.inStock && (
                    <span className="absolute top-2 right-2 rounded bg-ink/70 px-2 py-1 text-xs text-white">
                        {t('product.out_of_stock')}
                    </span>
                )}
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
                    <Link href={`/${market.key}/p/${group.id}/${group.slug}`}>
                        {group.title}
                        {/* The stretched link: an empty span covering the card,
                            so the whole thing is a click target while the anchor
                            itself still wraps only the title for a screen
                            reader. */}
                        <span className="absolute inset-0 z-10" aria-hidden />
                    </Link>
                </h3>

                <div className="mt-auto pt-3">
                    {group.minPrice !== null && (
                        <div className="flex items-baseline gap-1.5">
                            {/* "from" only when there is a spread worth reading. */}
                            {comparable && (
                                <span className="text-xs text-ink-soft">{t('product.from')}</span>
                            )}
                            <span className="text-lg font-semibold">
                                {formatPrice(group.minPrice, market)}
                            </span>
                        </div>
                    )}

                    <div className="mt-2 flex items-center justify-between gap-2">
                        {/*
                          Saving from the results grid.

                          Until now the only Save button lived on the product
                          page, so the most common path — search, scan the grid,
                          spot the thing — had no way to keep it without an extra
                          click into a page the shopper did not want.
                        */}
                        <SaveToList groupId={group.id} compact />
                    </div>

                    <div className={`mt-1 text-xs ${comparable ? 'font-medium text-sage' : 'text-ink-soft'}`}>
                        {group.offerCount === 1
                            ? t('product.one_offer')
                            : t('product.offers', { count: n(group.offerCount) })}
                        {' · '}
                        {comparable
                            ? t('product.across_shops', { count: n(group.merchantCount) })
                            : t('product.one_shop')}
                    </div>
                </div>
            </div>
        </article>
    )
}
