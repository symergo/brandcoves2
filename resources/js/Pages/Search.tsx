import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import PageNarrative, { type Narrative } from '../Components/PageNarrative'
import ProductCard, { type GroupCard } from '../Components/ProductCard'
import SaveToList from '../Components/SaveToList'
import ScanButton from '../Components/ScanButton'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
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
    /** Set when the search box held an Amazon URL rather than a search term. */
    pastedLink: {
        asin: string | null
        terms: string
        shortlink: boolean
        usable: boolean
    } | null
    /** Words that recur in these results, each a search of its own. Empty on thin pages. */
    terms: { term: string; url: string }[]
    /** Lowercase brand name → brand page URL, for brands that have one. */
    brandLinks: Record<string, string>
    /** Long-form copy below the grid. Null on pages that are noindex anyway. */
    narrative: Narrative | null
}

export default function Search({
    q,
    filters,
    sort,
    view,
    facets,
    results,
    lanes,
    emptyBecauseOfFilters,
    pastedLink,
    terms,
    brandLinks,
    narrative,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [term, setTerm] = useState(q)
    const [filtersOpen, setFiltersOpen] = useState(false)
    const [searching, setSearching] = useState(false)
    const base = `/${market.key}/search`

    /*
     * How many filters are narrowing the results.
     *
     * Shown on the collapsed toggle, because a hidden panel must not be able to
     * conceal the reason a search looks empty. `q`, `view`, `sort` and `page`
     * are excluded — they are not filters, and counting them would put a badge
     * on every search anyone ever runs.
     */
    const activeFilterCount = Object.entries(filters).filter(([key, value]) => {
        if (['q', 'view', 'sort', 'page'].includes(key)) return false
        if (Array.isArray(value)) return value.length > 0

        return value !== null && value !== undefined && value !== '' && value !== false
    }).length

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

        /*
         * Every visit through here raises the scanner: a submitted query, a
         * filter, a sort, a page. All four replace the grid while the previous
         * results stay on screen, so all four have the same problem — with no
         * signal, a slow one reads as a control that did nothing and gets
         * clicked a second time.
         *
         * `preserveState` keeps this component mounted across the visit, which
         * is what lets the same instance that raised the flag lower it.
         */
        router.get(base, next as Record<string, string>, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        })
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
                {/*
                  A scanner beam sweeping the bottom edge of the field, inside
                  the border, rather than a bar above or below the row.

                  Absolutely positioned so that appearing and disappearing moves
                  nothing: a 2px strip that pushed the whole results grid down on
                  every search would be more disruptive than the thing it is
                  reporting. It is also the reason it is *here* and not the
                  page-wide Inertia bar at the top of the window — the answer
                  being replaced is on this screen, so the signal belongs on it.
                */}
                <div className="relative flex-1">
                    <input
                        type="search"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={t('search.placeholder')}
                        aria-label={t('search.title')}
                        aria-busy={searching}
                        className="w-full rounded-lg border border-line bg-card px-4 py-3"
                    />
                    {searching && (
                        <span
                            className="pointer-events-none absolute inset-x-px bottom-px h-0.5 overflow-hidden rounded-b-lg"
                            aria-hidden
                        >
                            {/*
                              Faded at both ends rather than a hard-edged block.
                              A solid rectangle sliding back and forth reads as an
                              object being dragged; a beam has no edges, which is
                              what makes the same motion read as light passing
                              over the field.

                              `w-1/4` is paired with the 300% travel in the `scan`
                              keyframes — together they put the turn exactly at
                              each edge. Changing one without the other either
                              overshoots or leaves a dead margin.
                            */}
                            <span className="animate-scan absolute inset-y-0 left-0 w-1/4 bg-gradient-to-r from-transparent via-accent to-transparent" />
                        </span>
                    )}
                </div>
                {/*
                  Next to the search box, not buried in the nav. It is also the
                  only place someone standing in a shop will look for it — and
                  the home page has the same button, for the same reason.
                */}
                <ScanButton />

                {/*
                  Dimmed, not disabled. A disabled button loses focus mid-search
                  and stops answering to a keyboard, and the click it would
                  swallow is harmless anyway — Inertia cancels the in-flight
                  visit and starts the new one.
                */}
                <button
                    aria-busy={searching}
                    className={`rounded-lg bg-accent px-5 py-3 font-medium text-white transition-opacity hover:bg-accent-dark ${searching ? 'opacity-70' : ''}`}
                >
                    {t('search.submit')}
                </button>
            </form>

            {/*
              The bar is decoration and hidden from the accessibility tree, so
              the same news is given in words. Rendered empty rather than
              unmounted: a live region has to exist before its content changes
              for a screen reader to announce it.
            */}
            <p role="status" className="sr-only">
                {searching ? t('search.searching') : ''}
            </p>

            {/*
              The box accepts a barcode and an Amazon URL as readily as it
              accepts words, and nothing about it says so. One quiet link rather
              than three lines of placeholder text: the field stays a field, and
              the answer is somewhere it can be read properly.
            */}
            <p className="mt-2 text-sm text-ink-soft">
                <Link href={`/${market.key}/search-help`} className="underline hover:text-accent">
                    {t('search_help.link')}
                </Link>
            </p>

            {/*
              What we made of a pasted Amazon link.

              Directly under the box, because the query that ran is not the text
              that was pasted. Without this the page is unreadable: a grid of
              headphones under a URL gives no way to tell whether we found *that*
              product or something sharing a word with it.
            */}
            {pastedLink && (
                <p className="mt-3 rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-soft">
                    {pastedLink.shortlink
                        ? t('search.pasted_shortlink')
                        : pastedLink.usable
                          ? t('search.pasted_searched', { terms: pastedLink.terms })
                          : t('search.pasted_unreadable')}
                </p>
            )}

            <div className="mt-8 grid gap-8 lg:grid-cols-[16rem_1fr]">
                {/*
                  Collapsed on mobile, always open from `lg`.

                  Stacked above the results, the filter rail pushed every product
                  off a phone screen — the page opened on a column of switches
                  and you had to scroll past all of them to see whether the
                  search had found anything. `activeFilterCount` goes on the
                  button so a collapsed panel cannot hide the fact that
                  something is filtering the results.
                */}
                <button
                    type="button"
                    className="flex items-center justify-between rounded border border-line px-4 py-3 text-sm lg:hidden"
                    aria-expanded={filtersOpen}
                    aria-controls="search-filters"
                    onClick={() => setFiltersOpen(!filtersOpen)}
                >
                    <span>
                        {t('search.filters')}
                        {activeFilterCount > 0 && (
                            <span className="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-white">
                                {n(activeFilterCount)}
                            </span>
                        )}
                    </span>
                    <span aria-hidden>{filtersOpen ? '▲' : '▼'}</span>
                </button>

                <aside
                    id="search-filters"
                    aria-label={t('search.filters')}
                    className={`space-y-6 text-sm lg:block ${filtersOpen ? 'block' : 'hidden'}`}
                >
                    {facets.brands.length > 0 && (
                        <Facet
                            title={t('search.brand')}
                            items={facets.brands.map((b) => ({
                                key: b.value,
                                label: b.value,
                                count: b.count,
                                active: ([] as string[]).concat((filters.brand as string[]) ?? []).includes(b.value),
                                // The checkbox filters this page; the arrow goes
                                // to the brand's own page. Two different
                                // intentions that a single control cannot serve —
                                // and only the second one is indexable.
                                href: brandLinks[b.value.toLowerCase()] ?? null,
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

                    {/*
                      The two switches sit below the facets, not above them.

                      Brand and shop are what a shopper is actually looking for
                      in this rail; the switches only trim what is already
                      there. Above the facets they were the first thing on a
                      collapsed phone panel, pushing the lists a screen down.
                    */}
                    <Toggle
                        label={t('search.discounted_only')}
                        checked={filters.discounted === '1'}
                        onChange={(v) => go({ discounted: v ? '1' : null })}
                    />
                    <Toggle
                        label={t('search.in_stock_only')}
                        checked={filters.in_stock !== '0'}
                        onChange={(v) => go({ in_stock: v ? null : '0' })}
                    />
                </aside>

                <section>
                    {/*
                      The vocabulary of the results, above the grid.

                      One row of links where four paragraphs of statistics used
                      to be. The numbers described the grid directly beneath
                      them, which is a paragraph nobody reads and most of a phone
                      screen between a shopper and the first product.

                      The words survived because they are the part that is not a
                      restatement: they say what kind of thing this page holds,
                      and each one is a real query. That also makes them the
                      page's internal links — server-rendered via SSR, so a
                      crawler receives them as anchors, not as a comma-separated
                      sentence.
                    */}
                    {terms.length > 0 && (
                        <nav className="mb-5" aria-label={t('search.terms_heading')}>
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
                        /*
                         * Shops side by side, one column each.
                         *
                         * Stacked, this view answered "what does Krefel have"
                         * and then, several screens later, "what does Coolblue
                         * have" — which is two answers to one question and
                         * defeats the point of grouping by shop at all. In
                         * columns the comparison is the layout.
                         *
                         * Horizontal scroll rather than wrapping: a fourth shop
                         * belongs beside the third, not underneath the first,
                         * and the column width is fixed so the scroll is
                         * legible rather than a squeeze.
                         */
                        <div className="-mx-1 flex snap-x gap-4 overflow-x-auto px-1 pb-2">
                            {Object.entries(lanes).map(([shop, items]) => (
                                <section
                                    key={shop}
                                    className="w-56 shrink-0 snap-start sm:w-64"
                                    aria-label={shop}
                                >
                                    <h2 className="mb-3 truncate border-b border-line pb-2 font-medium">
                                        {shop}{' '}
                                        <span className="text-ink-soft">{n(items.length)}</span>
                                    </h2>

                                    {/* Compact cards: in a column the price and
                                        the title are what get compared, so the
                                        image gives up most of its height. */}
                                    <ul className="space-y-3">
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
                                                <div className="absolute top-1 right-1 z-10">
                                                    <SaveToList groupId={g.id} compact />
                                                </div>
                                                <a
                                                    href={`/${market.key}/p/${g.id}/${g.slug}`}
                                                    className="flex gap-3 rounded border border-line bg-card p-2 pr-16 hover:bg-cream"
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
                                                        <span className="line-clamp-2 text-sm">
                                                            {g.title}
                                                        </span>
                                                        <span className="mt-1 block text-sm font-semibold">
                                                            {g.minPrice === null
                                                                ? '-'
                                                                : formatPrice(g.minPrice, market)}
                                                        </span>
                                                    </span>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                            {results.items.map((g) => (
                                <ProductCard
                                    key={g.id}
                                    group={g}
                                    brandUrl={g.brand ? brandLinks[g.brand.toLowerCase()] : null}
                                />
                            ))}
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

            {/*
              Below the grid, deliberately.

              A shopper came for products; several hundred words between them and
              the first card is a worse page for a human, and Google has been
              explicit for years that it is a worse page for them too. This is
              what gives a crawler something to understand the page as being
              about — the grid itself is almost pure markup.
            */}
            {narrative && (
                <PageNarrative
                    narrative={narrative}
                    faqHeading={t('narrative.faq_heading', { term: q })}
                    relatedHeading={t('narrative.related_heading')}
                    relatedIntro={t('narrative.related_intro', { term: q })}
                />
            )}
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
    items: { key: string; label: string; count: number; active: boolean; href?: string | null }[]
    onToggle: (key: string, active: boolean) => void
    format: (n: number) => string
}) {
    return (
        <div>
            <h2 className="mb-2 font-medium">{title}</h2>
            <ul className="space-y-1">
                {items.map((item) => (
                    <li key={item.key} className="flex items-center gap-1">
                        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                            <input
                                type="checkbox"
                                checked={item.active}
                                onChange={() => onToggle(item.key, item.active)}
                                className="accent-accent"
                            />
                            <span className="flex-1 truncate">{item.label}</span>
                            <span className="text-xs text-ink-soft">{format(item.count)}</span>
                        </label>
                        {item.href && (
                            <Link
                                href={item.href}
                                className="shrink-0 px-1 text-xs text-ink-soft hover:text-accent"
                                aria-label={item.label}
                                title={item.label}
                            >
                                <span aria-hidden>→</span>
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    )
}
