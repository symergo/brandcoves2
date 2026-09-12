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
 * - **discounts** is measured against our own previous price, so a reader can
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
                    <h2 className="text-lg font-semibold text-ink">
                        {t(`entity_rails.${section.key}.title`)}
                    </h2>
                    <p className="mt-1 text-sm text-ink-soft">
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

/**
 * One product, as a card or as a row.
 *
 * Two layouts because there are two places: a shelf under an article, where a
 * square picture over a title is right, and a sidebar 18rem wide beside one,
 * where the same card would be a column of stamps. Exported for the second —
 * the entity Cove page draws its own list rather than a scrolling shelf.
 */
export function RailCard({
    product,
    layout = 'card',
}: {
    product: RailProduct
    layout?: 'card' | 'row'
}) {
    const { market } = usePage<SharedProps>().props

    if (layout === 'row') {
        return (
            <Link
                href={product.url}
                /*
                  A card, not a line in a list.

                  The first version was a 56px thumbnail against the page's own
                  cream, with the title wrapping into the price - four of them
                  read as a cramped column rather than as four products. The
                  picture now sits on white inside its own tile, which is what
                  most product photography is shot against, and the row has a
                  border to stand on so the eye can find where one product ends.
                */
                className="group flex gap-3 rounded-lg border border-transparent p-2 transition hover:border-line hover:bg-card"
            >
                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-md border border-line bg-white">
                    {product.image && (
                        <img
                            src={product.image}
                            alt=""
                            loading="lazy"
                            className="h-full w-full object-contain p-1"
                        />
                    )}
                </div>

                <div className="flex min-w-0 flex-col justify-center">
                    <p className="line-clamp-2 text-sm leading-snug text-ink group-hover:underline">
                        {product.title}
                    </p>

                    {product.price !== null && (
                        <p className="mt-1 flex items-baseline gap-1.5">
                            <span className="text-sm font-semibold text-ink">
                                {formatPrice(product.price, market)}
                            </span>
                            {product.discountPercent !== null && (
                                <span className="rounded-full bg-accent/10 px-1.5 py-px text-2xs font-semibold text-accent-dark">
                                    -{product.discountPercent}%
                                </span>
                            )}
                        </p>
                    )}
                </div>
            </Link>
        )
    }

    return (
        <Link href={product.url} className="group block">
            <div className="aspect-square overflow-hidden rounded-lg bg-cream">
                {product.image && (
                    <img
                        src={product.image}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-contain transition group-hover:scale-105"
                    />
                )}
            </div>

            <p className="mt-2 line-clamp-2 text-sm text-ink">{product.title}</p>

            {product.price !== null && (
                <p className="mt-1 text-sm font-medium text-ink">
                    {formatPrice(product.price, market)}
                    {/*
                      The badge is the claim the discount rail makes, so it is
                      shown wherever the number exists rather than only on that
                      rail: a product that is down 30% is down 30% whichever
                      shelf it turned up on.
                    */}
                    {product.discountPercent !== null && (
                        <span className="ml-2 text-xs font-semibold text-accent-dark">
                            -{product.discountPercent}%
                        </span>
                    )}
                </p>
            )}
        </Link>
    )
}
