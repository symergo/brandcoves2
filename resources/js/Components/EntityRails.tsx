import { Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

/** One card in a rail. The shape every other product surface here emits. */
export interface RailProduct {
    id: number
    title: string
    brand: string | null
    image: string | null
    category: string | null
    price: Cents | null
    merchantCount: number
    inStock: boolean
    discountPercent: number | null
    url: string
}

/**
 * The three rails under an entity Cove, keyed by what orders them.
 *
 * Null on every page that is not about a shop or a brand, and a rail with
 * nothing in it is simply absent — an empty shelf under a heading promising
 * products reads as broken rather than as empty.
 */
export interface EntityRailSet {
    discounts: RailProduct[]
    popular: RailProduct[]
    wishlisted: RailProduct[]
}

/**
 * Products under a piece about a shop or a brand.
 *
 * An entity Cove carries no shortlist: its prose is about ranges and categories
 * rather than about individual products, because a page's prose and its products
 * move at different speeds. A frozen "biggest discounts" list is wrong within
 * days; a live one cannot be named in prose written last month. So the writing
 * talks about ranges, which do not move, and these rails talk about products,
 * which do.
 *
 * ## The captions are load-bearing
 *
 * Each rail makes a different claim and the caption is what the claim rests on:
 *
 * - **discounts** is measured against our own 30-day median, so a reader can
 *   check it against the card underneath;
 * - **popular** comes from a retailer's chart, and naming it is the deliberate
 *   exception recorded in `docs/features/popularity-charts.md`;
 * - **wishlisted** is the honest one — it is what *our* visitors put on a list,
 *   aggregated over at least three distinct lists so it can never be read back
 *   as one person wanting one thing.
 */
export default function EntityRails({ rails }: { rails: EntityRailSet | null }) {
    const { t } = useTranslations()

    if (!rails) return null

    const sections = [
        { key: 'discounts', products: rails.discounts },
        { key: 'popular', products: rails.popular },
        { key: 'wishlisted', products: rails.wishlisted },
    ].filter((section) => section.products.length > 0)

    if (sections.length === 0) return null

    return (
        <div className="mt-12 space-y-10">
            {sections.map((section) => (
                <section key={section.key}>
                    <h2 className="text-lg font-semibold text-stone-900 dark:text-stone-100">
                        {t(`entity_rails.${section.key}.title`)}
                    </h2>
                    <p className="mt-1 text-sm text-stone-600 dark:text-stone-400">
                        {t(`entity_rails.${section.key}.blurb`)}
                    </p>

                    {/*
                      A scrolling row rather than a grid. These are a shelf beside
                      the writing, not the page's own results - and a grid of
                      eight would out-weigh the article they sit under.
                    */}
                    <ul className="mt-4 flex snap-x gap-4 overflow-x-auto pb-2">
                        {section.products.map((product) => (
                            <li key={product.id} className="w-40 shrink-0 snap-start">
                                <RailCard product={product} />
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    )
}

function RailCard({ product }: { product: RailProduct }) {
    const { market } = usePage<SharedProps>().props

    return (
        <Link href={product.url} className="group block">
            <div className="aspect-square overflow-hidden rounded-lg bg-stone-100 dark:bg-stone-800">
                {product.image && (
                    <img
                        src={product.image}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-contain transition group-hover:scale-105"
                    />
                )}
            </div>

            <p className="mt-2 line-clamp-2 text-sm text-stone-800 dark:text-stone-200">{product.title}</p>

            {product.price !== null && (
                <p className="mt-1 text-sm font-medium text-stone-900 dark:text-stone-100">
                    {formatPrice(product.price, market)}
                    {/*
                      The badge is the claim the discount rail makes, so it is
                      shown wherever the number exists rather than only on that
                      rail: a product that is down 30% is down 30% whichever
                      shelf it turned up on.
                    */}
                    {product.discountPercent !== null && (
                        <span className="ml-2 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                            -{product.discountPercent}%
                        </span>
                    )}
                </p>
            )}
        </Link>
    )
}
