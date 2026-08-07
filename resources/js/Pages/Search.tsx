import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ProductCard, { type GroupCard } from '../Components/ProductCard'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Facets {
    brands: { value: string; count: number }[]
    merchants: { id: number; name: string; count: number }[]
    price: { min: number | null; max: number | null }
}

interface Props {
    q: string
    filters: Record<string, unknown>
    sort: string
    view: 'grid' | 'store'
    facets: Facets
    results: {
        total: number
        currentPage: number
        lastPage: number
        items: GroupCard[]
    }
    lanes: Record<string, GroupCard[]> | null
    emptyBecauseOfFilters: boolean
}

export default function Search({ q, filters, sort, view, facets, results, lanes, emptyBecauseOfFilters }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [term, setTerm] = useState(q)
    const base = `/${market.key}/search`

    /**
     * Every filter is a link, not a form post.
     *
     * That keeps the result set in the URL, so it is shareable, bookmarkable
     * and survives a back button — which a filter panel that lives in component
     * state does not.
     */
    function go(changes: Record<string, unknown>) {
        const next = { ...filters, ...changes }
        // Any filter change invalidates the page number.
        if (!('page' in changes)) delete next.page
        Object.keys(next).forEach((k) => {
            const v = next[k]
            if (v === null || v === undefined || v === '' || v === false) delete next[k]
        })
        router.get(base, next as Record<string, string>, { preserveScroll: true, preserveState: true })
    }

    return (
        <>
            <Head title={q ? t('search.results_for', { term: q }) : t('search.title')} />

            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    go({ q: term })
                }}
                className="flex gap-2"
                role="search"
            >
                <input
                    type="search"
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    placeholder={t('search.placeholder')}
                    aria-label={t('search.title')}
                    className="flex-1 rounded-lg border border-line bg-card px-4 py-3"
                />
                <button className="rounded-lg bg-accent px-5 py-3 font-medium text-white hover:bg-accent-dark">
                    {t('search.submit')}
                </button>
            </form>

            <div className="mt-8 grid gap-8 lg:grid-cols-[16rem_1fr]">
                <aside aria-label={t('search.filters')} className="space-y-6 text-sm">
                    <Toggle
                        label={t('search.in_stock_only')}
                        checked={filters.in_stock !== '0'}
                        onChange={(v) => go({ in_stock: v ? null : '0' })}
                    />
                    <Toggle
                        label={t('search.discounted_only')}
                        checked={filters.discounted === '1'}
                        onChange={(v) => go({ discounted: v ? '1' : null })}
                    />
                    <Toggle
                        label={t('search.comparable_only')}
                        checked={filters.comparable === '1'}
                        onChange={(v) => go({ comparable: v ? '1' : null })}
                    />

                    {facets.brands.length > 0 && (
                        <Facet
                            title={t('search.brand')}
                            items={facets.brands.map((b) => ({
                                key: b.value,
                                label: b.value,
                                count: b.count,
                                active: ([] as string[]).concat((filters.brand as string[]) ?? []).includes(b.value),
                            }))}
                            onToggle={(key, active) => {
                                const current = ([] as string[]).concat((filters.brand as string[]) ?? [])
                                go({ brand: active ? current.filter((b) => b !== key) : [...current, key] })
                            }}
                            format={n}
                        />
                    )}

                    {facets.merchants.length > 0 && (
                        <Facet
                            title={t('search.shop')}
                            items={facets.merchants.map((m) => ({
                                key: String(m.id),
                                label: m.name,
                                count: m.count,
                                active: ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String).includes(String(m.id)),
                            }))}
                            onToggle={(key, active) => {
                                const current = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)
                                go({ merchant: active ? current.filter((m) => m !== key) : [...current, key] })
                            }}
                            format={n}
                        />
                    )}
                </aside>

                <section>
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <p className="text-sm text-ink-soft" aria-live="polite">
                            {q ? t('search.results_for', { term: q }) : t('search.browse')}
                            {results.total > 0 && ` · ${t('search.count', { count: n(results.total) })}`}
                        </p>

                        <div className="ml-auto flex items-center gap-2">
                            <label className="sr-only" htmlFor="sort">{t('search.sort')}</label>
                            <select
                                id="sort"
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

                            <div className="flex rounded border border-line text-sm">
                                {(['grid', 'store'] as const).map((v) => (
                                    <button
                                        key={v}
                                        onClick={() => go({ view: v === 'grid' ? null : v })}
                                        aria-pressed={view === v}
                                        className={`px-3 py-1.5 ${view === v ? 'bg-ink text-cream' : ''}`}
                                    >
                                        {t(`search.view_${v}`)}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {results.total === 0 ? (
                        <div className="rounded-card border border-line bg-card p-8 text-center">
                            {/*
                              "No results" and "no results with these filters" are
                              very different messages: one asks for a new word,
                              the other for one fewer filter.
                            */}
                            <p className="font-medium">
                                {emptyBecauseOfFilters ? t('search.empty_filters') : t('search.empty', { term: q })}
                            </p>
                            {emptyBecauseOfFilters ? (
                                <Link href={`${base}?q=${encodeURIComponent(q)}`} className="mt-3 inline-block text-accent underline">
                                    {t('search.clear_filters')}
                                </Link>
                            ) : (
                                <p className="mt-2 text-sm text-ink-soft">{t('search.empty_hint')}</p>
                            )}
                        </div>
                    ) : view === 'store' && lanes ? (
                        <div className="space-y-8">
                            {Object.entries(lanes).map(([shop, items]) => (
                                <div key={shop}>
                                    <h2 className="mb-3 font-medium">{shop}</h2>
                                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                        {items.map((g) => <ProductCard key={g.id} group={g} />)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {results.items.map((g) => <ProductCard key={g.id} group={g} />)}
                        </div>
                    )}

                    {results.lastPage > 1 && view === 'grid' && (
                        <nav className="mt-8 flex items-center justify-center gap-4 text-sm">
                            <button
                                disabled={results.currentPage <= 1}
                                onClick={() => go({ page: results.currentPage - 1 })}
                                className="rounded border border-line px-3 py-1.5 disabled:opacity-40"
                            >
                                {t('search.previous')}
                            </button>
                            <span className="text-ink-soft">
                                {t('search.page_of', { current: n(results.currentPage), last: n(results.lastPage) })}
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
                </section>
            </div>
        </>
    )
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex cursor-pointer items-center gap-2">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="accent-accent" />
            <span>{label}</span>
        </label>
    )
}

function Facet({
    title,
    items,
    onToggle,
    format,
}: {
    title: string
    items: { key: string; label: string; count: number; active: boolean }[]
    onToggle: (key: string, active: boolean) => void
    format: (n: number) => string
}) {
    return (
        <div>
            <h2 className="mb-2 font-medium">{title}</h2>
            <ul className="space-y-1">
                {items.map((item) => (
                    <li key={item.key}>
                        <label className="flex cursor-pointer items-center gap-2">
                            <input
                                type="checkbox"
                                checked={item.active}
                                onChange={() => onToggle(item.key, item.active)}
                                className="accent-accent"
                            />
                            <span className="flex-1 truncate">{item.label}</span>
                            <span className="text-xs text-ink-soft">{format(item.count)}</span>
                        </label>
                    </li>
                ))}
            </ul>
        </div>
    )
}
