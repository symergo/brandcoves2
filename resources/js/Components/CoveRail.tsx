import { Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import type { CoveBand } from './MoreCoves'
import { useTranslations } from '../useTranslations'

/** More products from one of the categories the Cove's own picks are in. */
export interface ProductBand {
    /** The feed's own word for the shelf, in the market's language. */
    category: string
    /** The search that browses the whole of it. */
    url: string
    products: {
        id: number
        title: string
        image: string | null
        price: Cents | null
        merchantCount: number
        url: string
    }[]
}

/**
 * One prop for everything a Cove page carries around itself.
 *
 * `coves` is not rendered here — MoreCoves puts it under the article, which is
 * where reaching the end of one asks for another. It travels in the same prop
 * because it comes from the same service and the same request, and splitting it
 * into two props would make the two halves look unrelated.
 */
export interface Rail {
    coves: CoveBand | null
    products: ProductBand[]
    /**
     * The parts of the series this Cove belongs to, current one included.
     *
     * Null on all but a seasonal Cove published as a series, and null there too
     * until a second part is live — see App\Services\Cove\CoveRail::series().
     * Rendered by CoveSeries at the top of the article rather than in the rail,
     * because "which part am I reading" is something you need before you start
     * rather than somewhere to go afterwards.
     */
    series: SeriesPart[] | null
}

/** One page of a series: a season split into a part per subject. */
export interface SeriesPart {
    title: string
    url: string
    current: boolean
}

/**
 * Where this page sits in its series, and the way to the rest of it.
 *
 * A season is published as several pages — "Kamperen, deel 2" — and a numbered
 * title with nothing to number against is a promise the page does not keep.
 *
 * The current part is text rather than a link. A link to the page you are on
 * looks like the way forward and is the way nowhere, and screen readers get the
 * same fact from aria-current.
 */
export function CoveSeries({ parts }: { parts: SeriesPart[] | null }) {
    const { t } = useTranslations()

    if (!parts || parts.length < 2) {
        return null
    }

    return (
        <nav
            aria-label={t('guides.series_heading')}
            className="mt-4 rounded-lg border border-line bg-card p-3 text-sm"
        >
            <h2 className="text-xs font-medium uppercase tracking-wide text-ink-soft">
                {t('guides.series_heading')}
            </h2>

            <ol className="mt-2 space-y-1">
                {parts.map((part) => (
                    <li key={part.url}>
                        {part.current ? (
                            <span aria-current="page" className="font-medium">
                                {part.title}
                            </span>
                        ) : (
                            <Link href={part.url} className="underline">
                                {part.title}
                            </Link>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    )
}

/**
 * The rail every Cove page carries: the Gift Cove, then more of what the page
 * is about.
 *
 * 1. **The Gift Cove.** The one part of the site a reader here has no way of
 *    having found — the nav names it and nothing explains it. Three lines and a
 *    link do more than a nav entry ever did.
 * 2. **More products like the ones on the page**, from the categories its own
 *    picks are in. Beside the reading rather than under it: this is something
 *    to glance at while you read, not a conclusion to it.
 *
 * Shared by the Daily edition, the personas and the articles rather than copied
 * into each: the three pages had drifted once already, and a rail that says
 * something different depending on which kind you are reading is a rail nobody
 * can reason about. See App\Services\Cove\CoveRail for what fills it.
 */
export default function CoveRail({ rail }: { rail: Rail }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    return (
        <>
            <section className="rounded-lg border border-accent/40 bg-accent/5 p-4">
                <h2 className="font-medium">{t('gift_cove.title')}</h2>
                <p className="mt-1 text-sm text-ink-soft">{t('gift_cove.rail_hint')}</p>

                <ul className="mt-3 space-y-1.5 text-sm">
                    {['wishlist', 'giftlist', 'santa', 'quiz'].map((tool) => (
                        <li key={tool} className="flex gap-2">
                            <span aria-hidden className="text-accent">
                                ·
                            </span>
                            <span>{t(`gift_cove.${tool}_title`)}</span>
                        </li>
                    ))}
                </ul>

                <Link
                    href={`/${market.key}/gift-cove`}
                    className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                >
                    {t('gift_cove.rail_cta')}
                </Link>
            </section>

            {/*
              More of what the Cove is about.

              One box for the categories rather than one box each: two bordered
              cards down a narrow column read as two unrelated widgets, and
              under a single heading they read as the one thing they are.

              The category name is the sub-heading and the link, because it is
              already the phrase the search box wants — it is the word the feed
              used for the shelf, in the language the page is being read in.
            */}
            {rail.products.length > 0 && (
                <section className="rounded-lg border border-line bg-card p-4">
                    <h2 className="text-sm font-medium text-ink-soft">
                        {t('coves.rail_products')}
                    </h2>

                    <div className="mt-4 space-y-5">
                        {rail.products.map((band) => (
                            <div key={band.category}>
                                <h3 className="text-sm font-medium">
                                    <Link href={band.url} className="hover:underline">
                                        {band.category}
                                    </Link>
                                </h3>

                                <ul className="mt-2 divide-y divide-line">
                                    {band.products.map((product) => (
                                        <li key={product.id} className="py-2.5 first:pt-0 last:pb-0">
                                            <Link
                                                href={product.url}
                                                className="group flex items-center gap-3"
                                            >
                                                {product.image && (
                                                    <img
                                                        src={product.image}
                                                        alt=""
                                                        loading="lazy"
                                                        className="h-12 w-12 shrink-0 object-contain"
                                                    />
                                                )}
                                                <span className="min-w-0 flex-1">
                                                    <span className="line-clamp-2 text-sm group-hover:underline">
                                                        {product.title}
                                                    </span>
                                                    <span className="mt-1 flex items-baseline gap-2">
                                                        {product.price !== null && (
                                                            <span className="text-sm font-semibold">
                                                                {formatPrice(product.price, market)}
                                                            </span>
                                                        )}
                                                        {/*
                                                          The reason this site
                                                          exists, stated where
                                                          the click is decided.
                                                        */}
                                                        {product.merchantCount > 1 && (
                                                            <span className="text-xs text-ink-soft">
                                                                {t('guides.shops', {
                                                                    count: n(product.merchantCount),
                                                                })}
                                                            </span>
                                                        )}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </>
    )
}
