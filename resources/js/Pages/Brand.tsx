import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ProductCard, { type GroupCard } from '../Components/ProductCard'
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
    /** Templated prose, every clause backed by a number. See App\Services\Seo\BrandCopy. */
    copy: { lead: string; paragraphs: string[] }
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
    coves: { title: string; intro: string | null; url: string }[]
    related: { name: string; url: string; count: number }[]
}

/**
 * A brand page: a search with the brand preselected, plus prose and links.
 *
 * The brand facet is deliberately absent from the rail — filtering a Sony page
 * by brand is a control with one option. Everything else (shops, stock,
 * discounted, comparable, sort, pagination) is the same as search, because it is
 * the same query object underneath.
 */
export default function Brand({ brand, copy, filters, sort, facets, results, coves, related }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [filtersOpen, setFiltersOpen] = useState(false)
    const base = `/${market.key}/brand/${brand.slug}`

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
                  The prose, above the products.

                  Every sentence is a number this page can back up — product
                  count, shop count, price range, how many are genuinely below
                  their 30-day median. That constraint is what separates this
                  from the generated brand pages every affiliate site has had
                  since 2009, which rank for a fortnight and then drag the domain
                  down with them.
                */}
                <p className="mt-4 leading-relaxed">{copy.lead}</p>

                {copy.paragraphs.length > 0 && (
                    <div className="mt-3 space-y-2 text-sm leading-relaxed text-ink-soft">
                        {copy.paragraphs.map((p) => (
                            <p key={p}>{p}</p>
                        ))}
                    </div>
                )}
            </header>

            <div className="mt-8 grid gap-8 lg:grid-cols-[16rem_1fr]">
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
                    className={`space-y-6 text-sm lg:block ${filtersOpen ? 'block' : 'hidden'}`}
                >
                    <label className="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            checked={filters.in_stock !== '0'}
                            onChange={(e) => go({ in_stock: e.target.checked ? null : '0' })}
                            className="accent-accent"
                        />
                        <span>{t('search.in_stock_only')}</span>
                    </label>
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
                            checked={filters.comparable === '1'}
                            onChange={(e) => go({ comparable: e.target.checked ? '1' : null })}
                            className="accent-accent"
                        />
                        <span>{t('search.comparable_only')}</span>
                    </label>

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

                <section>
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
                      The creative half of the page.

                      The copy above carries facts and cannot carry personality —
                      it is regenerated nightly from numbers. A Cove is written by
                      the AI pass and reads like something a person made, so this
                      is where any voice on a brand page comes from.
                    */}
                    {coves.length > 0 && (
                        <section className="mt-12" aria-labelledby="brand-coves">
                            <h2 id="brand-coves" className="text-xl font-semibold tracking-tight">
                                {t('brand.coves_heading', { brand: brand.name })}
                            </h2>
                            <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                                {coves.map((cove) => (
                                    <li key={cove.url}>
                                        <Link
                                            href={cove.url}
                                            className="block rounded-card border border-line bg-card p-5 transition hover:border-ink"
                                        >
                                            <h3 className="font-medium">{cove.title}</h3>
                                            {cove.intro && (
                                                <p className="mt-2 line-clamp-3 text-sm text-ink-soft">
                                                    {cove.intro}
                                                </p>
                                            )}
                                        </Link>
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
            </div>
        </>
    )
}
