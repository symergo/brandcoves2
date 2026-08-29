import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ProductCard, { type GroupCard } from '../Components/ProductCard'
import SaveToList from '../Components/SaveToList'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

interface Props {
    brand: {
        name: string
        slug: string
        productCount: number
        merchantCount: number
        discountedCount: number
        minPrice: number | null
        maxPrice: number | null
        category: string | null
        topMerchant: string | null
    }
    /** Words that recur across this brand's products, each a search of its own. */
    terms: { term: string; url: string }[]
    filters: Record<string, unknown>
    sort: string
    view: 'grid' | 'store'
    facets: {
        brands: { value: string; count: number }[]
        merchants: { id: number; name: string; count: number }[]
        price: { min: number | null; max: number | null }
    }
    results: {
        total: number
        currentPage: number
        lastPage: number
        items: GroupCard[]
    }
    /**
     * Offers from a source we may show but not store — Amazon.
     *
     * Fetched during this request and never written down, so they are absent
     * from `results` by construction and cannot be a ProductCard: there is no
     * group behind them, and therefore no offer count, no shop count and no
     * discount measured against a 30-day median. Empty until the Amazon
     * connector is enabled; everything bol returns is already in the grid.
     */
    liveOffers: {
        title: string
        url: string
        image: string | null
        price: number | null
        merchant: string
        inStock: boolean
        needsPriceTimestamp: boolean
        directLink: boolean
        /** What it takes to keep one: the external-source save path. */
        source: string
        externalId: string
    }[]
    coves: { title: string; intro: string | null; url: string }[]
    related: { name: string; url: string; count: number }[]
}

/**
 * A brand page: a search with the brand preselected, plus editorial and links.
 *
 * Below the grid there are articles that mention the brand, and nothing else.
 * The three columns of generated paragraphs and the FAQ that used to sit there
 * went on 2026-08-16 — they restated the numbers on the cards above them, in
 * sentences, identically on every brand page. See BrandController::coves().
 *
 * The brand facet is deliberately absent from the rail — filtering a Sony page
 * by brand is a control with one option. Everything else (shops, stock,
 * discounted, sort, pagination) is the same as search, because it is the same
 * query object underneath.
 */
export default function Brand({
    brand,
    terms,
    filters,
    sort,
    facets,
    results,
    liveOffers,
    coves,
    related,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [filtersOpen, setFiltersOpen] = useState(false)
    const base = `/${market.key}/brand/${brand.slug}`

    // The words currently narrowing this page. `q` on a brand page is not a
    // search box — there is none here — it is the accumulated result of clicking
    // the term chips, so it is shown back as chips that can be taken off again.
    const narrowedTo = String(filters.q ?? '')
        .split(' ')
        .filter(Boolean)

    function go(changes: Record<string, unknown>) {
        const next = { ...filters, ...changes }
        // The brand is in the path, not the query string — sending it as a
        // filter as well would produce /brand/sony?brand[]=Sony, which is the
        // same page at a second URL.
        delete next.brand
        if (!('page' in changes)) delete next.page
        Object.keys(next).forEach((k) => {
            const v = next[k]
            if (v === null || v === undefined || v === '' || v === false) delete next[k]
        })
        router.get(base, next as Record<string, string>, { preserveScroll: true, preserveState: true })
    }

    return (
        <>
            <Head title={t('brand.title', { brand: brand.name })} />

            <nav aria-label="Breadcrumb" className="text-sm text-ink-soft">
                <Link href={`/${market.key}/brands`} className="hover:text-accent">
                    {t('brand.crumb')}
                </Link>
                <span aria-hidden> / </span>
                <span>{brand.name}</span>
            </nav>

            <header className="mt-3 max-w-3xl">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {t('brand.heading', { brand: brand.name })}
                </h1>

                {/*
                  What this brand makes, in its own words.

                  Four paragraphs of statistics used to open the page — product
                  count, shop count, price range, how many were below their
                  30-day median. All true, all checkable, and all of it counting
                  the grid immediately beneath it. Someone who has typed a brand
                  name came to see the brand's products, not a screen of
                  arithmetic about them, and the facts still exist in the long
                  copy below the grid where a reader can go looking for them.

                  These links used to leave for a plain search on the bare word.
                  They narrow this page instead: somebody on a Kärcher page
                  reading "hogedrukreiniger" is asking which Kärchers those are,
                  not to be shown Bosch. Each click adds its word to the ones
                  already active, so the chips walk further in rather than
                  swapping one filter for another.
                */}
                {narrowedTo.length > 0 && (
                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <span className="text-sm text-ink-soft">{t('brand.narrowed_to')}</span>
                        {/*
                          Every active word is removable on its own, and the
                          whole thing clears back to the brand. Without this the
                          only way out of a sub-search is the browser's back
                          button, which is not a control a page gets to rely on.
                        */}
                        {narrowedTo.map((word) => (
                            <button
                                key={word}
                                type="button"
                                onClick={() => go({ q: narrowedTo.filter((w) => w !== word).join(' ') })}
                                className="inline-flex items-center gap-1.5 rounded-full border border-ink bg-card px-3 py-1 text-sm transition hover:border-accent hover:text-accent"
                            >
                                {word}
                                <span aria-hidden>×</span>
                                <span className="sr-only">{t('search.clear_filters')}</span>
                            </button>
                        ))}
                    </div>
                )}

                {terms.length > 0 && (
                    <nav className="mt-4" aria-label={t('search.terms_heading')}>
                        <h2 className="mb-2 text-sm text-ink-soft">{t('search.terms_heading')}</h2>
                        <ul className="flex flex-wrap gap-2">
                            {terms.map((item) => (
                                <li key={item.term}>
                                    <Link
                                        href={item.url}
                                        className="inline-block rounded-full border border-line bg-card px-3 py-1 text-sm text-ink-soft transition hover:border-ink hover:text-ink"
                                    >
                                        {item.term}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}
            </header>

            {/*
              Two explicit rows, and the first one is `auto`.

              The sidebar holds the filters and, under them, the articles. With
              implicit rows the results column — which spans both — had its
              height shared out between them, so row one grew to roughly half a
              screen of product grid and the articles were pushed far below the
              facets they are supposed to sit against. `auto` pins row one to
              the height of the filters; `1fr` gives row two whatever is left.

              The row gap is tighter than the column gap for the same reason: 2rem
              between two blocks in a 16rem column reads as a gap, where 2rem
              between the sidebar and the results reads as a margin.
            */}
            <div className="mt-8 grid gap-x-8 gap-y-6 lg:grid-cols-[16rem_1fr] lg:grid-rows-[auto_1fr]">
                <button
                    type="button"
                    className="flex items-center justify-between rounded border border-line px-4 py-3 text-sm lg:hidden"
                    aria-expanded={filtersOpen}
                    aria-controls="brand-filters"
                    onClick={() => setFiltersOpen(!filtersOpen)}
                >
                    <span>{t('search.filters')}</span>
                    <span aria-hidden>{filtersOpen ? '▲' : '▼'}</span>
                </button>

                <aside
                    id="brand-filters"
                    aria-label={t('search.filters')}
                    className={`space-y-6 text-sm lg:col-start-1 lg:row-start-1 lg:block ${filtersOpen ? 'block' : 'hidden'}`}
                >
                    {facets.merchants.length > 0 && (
                        <div>
                            <h2 className="mb-2 font-medium">{t('search.shop')}</h2>
                            <ul className="space-y-1">
                                {facets.merchants.map((m) => {
                                    const current = ([] as string[])
                                        .concat((filters.merchant as string[]) ?? [])
                                        .map(String)
                                    const active = current.includes(String(m.id))

                                    return (
                                        <li key={m.id}>
                                            <label className="flex cursor-pointer items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={active}
                                                    onChange={() =>
                                                        go({
                                                            merchant: active
                                                                ? current.filter((x) => x !== String(m.id))
                                                                : [...current, String(m.id)],
                                                        })
                                                    }
                                                    className="accent-accent"
                                                />
                                                <span className="flex-1 truncate">{m.name}</span>
                                                <span className="text-xs text-ink-soft">{n(m.count)}</span>
                                            </label>
                                        </li>
                                    )
                                })}
                            </ul>
                        </div>
                    )}

                    {/*
                      Below the shop facet, mirroring the search rail: the
                      switches trim a set the facet has already chosen. Above
                      it they were the entire collapsed panel on a phone.
                      "Available from several shops" is gone here too — see
                      docs/features/search.md.
                    */}
                    <label className="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            checked={filters.discounted === '1'}
                            onChange={(e) => go({ discounted: e.target.checked ? '1' : null })}
                            className="accent-accent"
                        />
                        <span>{t('search.discounted_only')}</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            checked={filters.in_stock !== '0'}
                            onChange={(e) => go({ in_stock: e.target.checked ? null : '0' })}
                            className="accent-accent"
                        />
                        <span>{t('search.in_stock_only')}</span>
                    </label>

                    {related.length > 0 && (
                        <div>
                            <h2 className="mb-2 font-medium">{t('brand.related_heading')}</h2>
                            <ul className="space-y-1">
                                {related.map((other) => (
                                    <li key={other.url}>
                                        <Link href={other.url} className="flex gap-2 hover:text-accent">
                                            <span className="flex-1 truncate">{other.name}</span>
                                            <span className="text-xs text-ink-soft">{n(other.count)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </aside>

                {/*
                  Spans both rows, so the articles below the filters do not push
                  the results down a screen on a wide viewport.
                */}
                <section className="lg:col-start-2 lg:row-span-2 lg:row-start-1">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <h2 className="text-sm text-ink-soft">
                            {t('brand.products_heading', { brand: brand.name })}
                            {results.total > 0 && ` · ${t('search.count', { count: n(results.total) })}`}
                        </h2>

                        <div className="ml-auto">
                            <label className="sr-only" htmlFor="brand-sort">
                                {t('search.sort')}
                            </label>
                            <select
                                id="brand-sort"
                                value={sort}
                                onChange={(e) => go({ sort: e.target.value })}
                                className="rounded border border-line bg-card px-2 py-1.5 text-sm"
                            >
                                <option value="relevance">{t('search.sort_relevance')}</option>
                                <option value="price_asc">{t('search.sort_price_asc')}</option>
                                <option value="price_desc">{t('search.sort_price_desc')}</option>
                                <option value="discount">{t('search.sort_discount')}</option>
                                <option value="newest">{t('search.sort_newest')}</option>
                            </select>
                        </div>
                    </div>

                    {results.total === 0 ? (
                        <div className="rounded-card border border-line bg-card p-8 text-center">
                            <p className="font-medium">{t('brand.empty', { brand: brand.name })}</p>
                            <p className="mt-2 text-sm text-ink-soft">{t('brand.empty_hint')}</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {results.items.map((g) => (
                                // No brandUrl: every card on this page is this
                                // brand, so a link back to the page you are on
                                // is noise.
                                <ProductCard key={g.id} group={g} />
                            ))}
                        </div>
                    )}

                    {results.lastPage > 1 && (
                        <nav className="mt-8 flex items-center justify-center gap-4 text-sm">
                            <button
                                disabled={results.currentPage <= 1}
                                onClick={() => go({ page: results.currentPage - 1 })}
                                className="rounded border border-line px-3 py-1.5 disabled:opacity-40"
                            >
                                {t('search.previous')}
                            </button>
                            <span className="text-ink-soft">
                                {t('search.page_of', {
                                    current: n(results.currentPage),
                                    last: n(results.lastPage),
                                })}
                            </span>
                            <button
                                disabled={results.currentPage >= results.lastPage}
                                onClick={() => go({ page: results.currentPage + 1 })}
                                className="rounded border border-line px-3 py-1.5 disabled:opacity-40"
                            >
                                {t('search.next')}
                            </button>
                        </nav>
                    )}

                    {/*
                      Offers we may show but not keep.

                      Their own section rather than mixed into the grid above,
                      and that separation is the honest one: every card up there
                      is a physical product with every shop's price under it,
                      because those offers are stored and grouped. These are not
                      grouped with anything — nothing wrote them down — so
                      showing them as comparison cards would promise a comparison
                      that was never made.

                      The price note is a condition of display, not a nicety: a
                      price fetched a moment ago may already have moved, and the
                      programme requires saying so. Same reason the link is a
                      plain anchor and skips the /go/ redirector every other
                      outbound link on the site uses.
                    */}
                    {liveOffers.length > 0 && (
                        <section className="mt-12" aria-labelledby="brand-live">
                            <h2 id="brand-live" className="text-xl font-semibold tracking-tight">
                                {t('brand.live_heading', { brand: brand.name })}
                            </h2>
                            <p className="mt-1 text-sm text-ink-soft">{t('brand.live_note')}</p>

                            <ul className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                {liveOffers.map((offer) => (
                                    <li
                                        key={offer.url}
                                        className="flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30"
                                    >
                                        <div className="relative aspect-square overflow-hidden bg-cream">
                                            {offer.image && (
                                                <img
                                                    src={offer.image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-full w-full object-contain p-4"
                                                    onError={(e) => {
                                                        e.currentTarget.style.visibility = 'hidden'
                                                    }}
                                                />
                                            )}

                                            {/*
                                              A live offer was the one product on
                                              the site that could be looked at
                                              and not kept. The whole external
                                              save path — `source` +
                                              `external_id`, and
                                              `ItemSaver::saveExternal()` behind
                                              it — was built and reachable from
                                              no UI at all.

                                              The snapshot fields are hints: the
                                              server stores them only for a
                                              source it is allowed to mirror, so
                                              an Amazon offer keeps the decision
                                              and nothing else (invariant #6).
                                            */}
                                            <div className="absolute right-2 bottom-2">
                                                <SaveToList
                                                    source={offer.source}
                                                    externalId={offer.externalId}
                                                    title={offer.title}
                                                    imageUrl={offer.image}
                                                    price={offer.price}
                                                    compact
                                                />
                                            </div>
                                        </div>

                                        <div className="flex flex-1 flex-col p-4">
                                            <div className="text-xs tracking-wide text-ink-soft uppercase">
                                                {offer.merchant}
                                            </div>

                                            <h3 className="mt-1 line-clamp-2 text-sm font-medium">
                                                <a
                                                    href={offer.url}
                                                    // Unobscured, as the
                                                    // programme requires — and
                                                    // sponsored + noopener, as
                                                    // any outbound affiliate
                                                    // link needs.
                                                    rel="sponsored noopener nofollow"
                                                    target="_blank"
                                                    className="hover:text-accent"
                                                >
                                                    {offer.title}
                                                </a>
                                            </h3>

                                            <div className="mt-auto pt-3">
                                                {offer.price !== null && (
                                                    <div className="text-lg font-semibold">
                                                        {formatPrice(offer.price, market)}
                                                    </div>
                                                )}
                                                <div
                                                    className={`mt-1 text-xs ${offer.inStock ? 'text-sage' : 'text-ink-soft'}`}
                                                >
                                                    {offer.inStock
                                                        ? t('product.in_stock')
                                                        : t('product.out_of_stock')}
                                                </div>
                                                {offer.needsPriceTimestamp && (
                                                    <div className="mt-0.5 text-[11px] text-ink-soft/70">
                                                        {t('product.price_as_of')}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {brand.minPrice !== null && (
                        <p className="mt-10 text-xs text-ink-soft">
                            {t('product.price_as_of')}{' '}
                            {formatPrice(brand.minPrice, market)}
                            {brand.maxPrice !== null && brand.maxPrice > brand.minPrice
                                ? ` – ${formatPrice(brand.maxPrice, market)}`
                                : ''}
                        </p>
                    )}
                </section>

                {/*
                  The written half of the page, and now the only half.

                  A grid of cards states facts and cannot carry a voice. An
                  article was written once, about a real question — so this is
                  where any personality on a brand page comes from, and it is a
                  link out of the page rather than a paragraph about the page.

                  ## Where it sits, and why that differs by width

                  On a wide screen it sits directly under the filters, in the
                  same column — close enough to read as part of the same rail
                  rather than as something stranded at the bottom of the page.
                  `self-start` keeps it against the filters instead of stretching
                  down the row.

                  On a narrow screen it goes last, after the results. The column
                  collapses to one, and putting articles between the filters and
                  the products would make somebody who came here to look at
                  products scroll past prose to reach them.

                  Explicit placement rather than `order`: the results span both
                  rows, so this has to be told which cell it belongs in. In DOM
                  order it already comes last, which is exactly what the mobile
                  single-column flow wants — no ordering classes needed there.
                */}
                {coves.length > 0 && (
                    <aside
                        className="lg:col-start-1 lg:row-start-2 lg:self-start"
                        aria-labelledby="brand-coves"
                    >
                        <h2 id="brand-coves" className="text-lg font-semibold tracking-tight">
                            {t('brand.coves_heading', { brand: brand.name })}
                        </h2>
                        <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                            {coves.map((cove) => (
                                <li key={cove.url}>
                                    <Link
                                        href={cove.url}
                                        className="block rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                    >
                                        <h3 className="text-sm font-medium">{cove.title}</h3>
                                        {cove.intro && (
                                            <p className="mt-2 line-clamp-3 text-sm text-ink-soft">
                                                {cove.intro}
                                            </p>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </aside>
                )}
            </div>

        </>
    )
}
