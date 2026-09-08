import { Head, Link } from '@inertiajs/react'
import EntityRails, { RailCard, type EntityRailSet } from '../../Components/EntityRails'
import PageBlocks from '../../Components/PageBlocks'
import type { BlockPayload } from '../../Components/Parts'
import { useTranslations } from '../../useTranslations'

interface Props {
    entity: { name: string; kind: 'brand' | 'shop'; total: number | null; logo: string | null }
    cove: { title: string; intro: string; body: string[] }
    rails: EntityRailSet | null
    /** Where "see all" goes: the search page, filtered to this entity. */
    searchUrl: string
    /** Admin-editable copy, keyed by region. */
    copy: {
        above_prose: BlockPayload[] | null
        below_prose: BlockPayload[] | null
        sidebar: BlockPayload[] | null
    }
}

/**
 * A written page about a brand or a shop.
 *
 * ## It is an article, not a result set
 *
 * This is the fork the whole entity design turns on. Where nobody has written
 * about a brand, `/brand/{slug}` is a filtered search — facets down the left, a
 * grid of everything. Where somebody *has*, the writing is the page: no facets,
 * no grid, and a sidebar carrying the handful of products worth putting beside a
 * paragraph.
 *
 * Two things follow, and both are the reason for the shape rather than
 * decoration on it:
 *
 * - **The grid has to stay reachable**, so the sidebar ends in a link to the
 *   filtered search — the same destination a word in the prose narrows to. One
 *   answer to "show me the rest", not two that differ by which control was used.
 * - **The prose names ranges, never products.** A page's words and its products
 *   move at different speeds; a frozen "biggest discounts" list is wrong within
 *   days. So the writing talks about categories and sub-brands, which do not
 *   move, and the sidebar talks about products, which do.
 *
 * ## Why wish-listed sits under the writing and the other two do not
 *
 * Discounts and popularity are a shelf: eight small cards read fine in a narrow
 * column beside a paragraph. Wish-listed is a *claim about our own visitors* —
 * the only rail here that is first-party — and burying it in a sidebar column
 * with the two borrowed from merchants states it more quietly than it deserves.
 * It gets the full width under the article.
 */
export default function EntityCove({ entity, cove, rails, searchUrl, copy }: Props) {
    const { t } = useTranslations()
    const sidebar = rails
        ? [
              { key: 'discounts', products: rails.discounts },
              { key: 'popular', products: rails.popular },
          ].filter((rail) => rail.products.length > 0)
        : []

    // Only the wish-listed rail goes below; the component renders nothing when
    // it is empty, which is most entities most of the time — see its floor.
    const below: EntityRailSet | null = rails
        ? { discounts: [], popular: [], wishlisted: rails.wishlisted }
        : null

    return (
        <>
            <Head title={cove.title} />

            <div className="mt-8 grid gap-x-10 gap-y-10 lg:grid-cols-[1fr_18rem]">
                <div className="min-w-0">
                    <header>
                        {entity.logo && (
                            <img src={entity.logo} alt="" className="mb-4 h-10 w-10 rounded" loading="lazy" />
                        )}
                        <h1 className="text-2xl font-semibold text-ink sm:text-3xl">{cove.title}</h1>
                    </header>

                    <PageBlocks blocks={copy.above_prose} className="mt-6 max-w-2xl" />

                    {/*
                      `intro` and `body` arrive as HTML because the link tokens
                      in them — [[search:…]], [[brand:…]] — are resolved server
                      side against an allowlist. Anything the writer named that
                      the entity does not actually sell came back as plain text
                      rather than as a link to nothing.
                    */}
                    <div
                        className="mt-6 max-w-2xl text-lg leading-relaxed text-ink"
                        dangerouslySetInnerHTML={{ __html: cove.intro }}
                    />
                    {/*
                      One element per paragraph. The server splits on blank
                      lines and resolves tokens within each, so a piece written
                      in three paragraphs reads as three.
                    */}
                    <div className="mt-6 max-w-2xl space-y-4 leading-relaxed text-ink">
                        {cove.body.map((paragraph, index) => (
                            <p key={index} dangerouslySetInnerHTML={{ __html: paragraph }} />
                        ))}
                    </div>

                    <PageBlocks blocks={copy.below_prose} className="mt-10 max-w-2xl" />

                    <EntityRails rails={below} />
                </div>

                <aside className="lg:sticky lg:top-6 lg:self-start" aria-labelledby="entity-sidebar">
                    <h2 id="entity-sidebar" className="sr-only">
                        {t('entity_rails.sidebar_heading')}
                    </h2>

                    {sidebar.map((rail) => (
                        <section key={rail.key} className="mb-10">
                            <h3 className="mb-1 text-xs font-semibold tracking-wide text-ink-soft uppercase">
                                {t(`entity_rails.${rail.key}.title`)}
                            </h3>

                            {/*
                              A column, not the horizontal shelf the rail uses
                              under an article. Eight cards scrolling sideways in
                              an 18rem sidebar would hide most of themselves.
                            */}
                            <ul className="mt-2 space-y-1">
                                {rail.products.slice(0, 4).map((product) => (
                                    <li key={product.id}>
                                        <RailCard product={product} layout="row" />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}

                    {/*
                      A note about the products above it — what a discount is
                      measured against, where a ranking came from. Under the
                      lists because it explains them, and above the link out
                      because that stays the last thing in the column.
                    */}
                    <PageBlocks blocks={copy.sidebar} className="mb-6 text-sm text-ink-soft" />

                    {/*
                      The way back to everything. An article about a brand that
                      offers no route to that brand's products is an article on
                      a shopping site that forgot what it was for.

                      Without the count. "Bekijk alle 1.284 producten van Sony"
                      made a number the headline of a link whose job is to say
                      where it goes. The owner asked for the plain line on
                      2026-09-08: all the brand's offers, all the shop's.
                    */}
                    <Link
                        href={searchUrl}
                        className="block rounded-lg border border-line px-4 py-3 text-sm font-medium hover:border-ink"
                    >
                        {t('entity_rails.see_all', { entity: entity.name })}
                    </Link>
                </aside>
            </div>
        </>
    )
}
