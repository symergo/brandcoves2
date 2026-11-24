import { Link, router } from '@inertiajs/react'
import { Fragment, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { preferred as preferredView, remember as rememberView } from '../viewPreference'
import ProductCard, { type GroupCard } from './ProductCard'
import AmazonProductCard, { AMAZON_SHOP, type AmazonItem } from './AmazonProductCard'
import { AmazonSearchCard, type AmazonSearch } from './AmazonSearchCta'
import { buttonClasses } from './Button'
import ScanButton from './ScanButton'
import ToolIcon from './ToolIcon'
import { formatPrice, type CurrentMarket } from '../types'
import { useTranslations } from '../useTranslations'

/*
 * The parts of a results page, shared by the search page and the brand page
 * (owner, 2026-11-24: "The brand page should look the same as the search page
 * (same filter ux design, searchfield)"). A brand page is a search with the brand
 * preselected (BrandController), so it gets the same search bar, the same Filters
 * button with its popover and sheet, the same chips, pills, grid and page buttons.
 * Each part was written for Search.tsx first; the reasons are kept with the code.
 */

export interface Facets {
    brands: { value: string }[]
    merchants: { id: number; name: string; logo?: string | null }[]
}

/**
 * One page and whether another follows. No total and no last page (owner's
 * decision, 2026-09-27): nothing counts the matches, so the page shows
 * neither a number nor a word standing in for one.
 */
export interface ResultPage {
    empty: boolean
    currentPage: number
    hasMore: boolean
    items: GroupCard[]
}

/** One filter that is on, drawn as a chip above the results. */
export interface FilterChip {
    key: string
    /** `merchant` chips are left out of the by-store view, whose shop chips are the same state. */
    kind: 'brand' | 'merchant' | 'price' | 'switch' | 'tag'
    label: string
    /** Removes it through `go()`, keeping everything else. */
    remove?: () => void
    /** Or a URL without it, for the gift filters the server words. */
    href?: string
}

export type Go = (changes: Record<string, unknown>) => void

/**
 * Every filter is a link, not a form post.
 *
 * That keeps the result set in the URL, so it is shareable, bookmarkable and
 * survives a back button, which a filter panel that lives in component state does
 * not. `target` turns the next set of parameters into the page's own address: the
 * search page puts the term in the path, the brand page keeps its /brand/{slug}.
 *
 * Every visit through here raises `searching` (the scanner on the field): a
 * submitted query, a filter, a sort, a page. All four replace the grid while the
 * previous results stay on screen, so with no signal a slow one reads as a control
 * that did nothing and gets clicked a second time. `preserveState` keeps the page
 * mounted across the visit, which is what lets the instance that raised the flag
 * lower it.
 */
export function useResultNavigation(
    filters: Record<string, unknown>,
    target: (next: Record<string, unknown>) => { path: string; query: Record<string, unknown> },
): { go: Go; visit: (url: string) => void; searching: boolean } {
    const [searching, setSearching] = useState(false)

    const go: Go = (changes) => {
        const next = { ...filters, ...changes }
        // Any filter change invalidates the page number.
        if (!('page' in changes)) delete next.page
        Object.keys(next).forEach((k) => {
            const v = next[k]
            if (v === null || v === undefined || v === '' || v === false) delete next[k]
        })

        const { path, query } = target(next)

        router.get(path, query as Record<string, string>, {
            /*
             * Asks for `brand[]=HP` rather than `brand[0]=HP`. It does not currently
             * take effect (measured on Inertia 3.6.1, 2026-08-30) and breaks nothing:
             * `SearchQuery::fromRequest()` casts with `(array)` and reads either shape.
             */
            queryStringArrayFormat: 'brackets',
            /*
             * Keep the scroll for a filter, a sort or a submitted query, so the
             * control that was pressed stays under the finger. Not for a page: the
             * next page used to arrive with the viewport parked at the bottom of
             * the previous one, past every card it had brought.
             */
            preserveScroll: !('page' in changes),
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        })
    }

    /** A URL the server built, such as a narrowing pill's. */
    const visit = (url: string) =>
        router.get(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            },
        )

    return { go, visit, searching }
}

/**
 * The shops' products join the page after it is shown (2026-10-25), at the top
 * (owner, the same day: what was found online first). That pushes the cards
 * already shown down once, a layout shift the owner accepted; it only happens on a
 * thin page, where there are few cards to push. The page and "more" come from the
 * shops' answer.
 */
export function useLiveResults(stored: ResultPage, live: { results: ResultPage } | null | undefined): ResultPage {
    return useMemo<ResultPage>(() => {
        if (!live) {
            return stored
        }
        const shown = new Set(stored.items.map((g) => g.id))
        const items = [...live.results.items.filter((g) => !shown.has(g.id)), ...stored.items]

        return { ...live.results, items, empty: items.length === 0 }
    }, [stored, live])
}

/**
 * The view the visitor last chose, restored when the URL does not say.
 *
 * Applied by navigating rather than by rendering: the two views are not two
 * arrangements of the same payload (the by-store one is served `lanes`, which the
 * grid never asks for), so the server has to be told. Once, on first mount, and
 * only when the URL is silent, because a link carrying `?view=` means something
 * its sender chose and must show them the same page it shows anyone else.
 */
export function useRestoredView(filters: Record<string, unknown>, view: string, go: Go): void {
    const restored = useRef(false)

    useEffect(() => {
        if (restored.current) return
        restored.current = true

        if (filters.view !== undefined && filters.view !== null) return

        const wanted = preferredView()

        if (wanted !== null && wanted !== view) {
            go({ view: wanted === 'grid' ? null : wanted })
        }
        // Mount only: this restores a preference, it does not enforce one, so a
        // visitor switching view mid-session must not be pulled back.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])
}

/**
 * The search field: the field, then two square buttons of the same height, the
 * camera (phones only) and the magnifier.
 *
 * A real GET form underneath the handler. Without `action`, `method` and a named
 * field, an Enter pressed before React hydrates submitted the current URL with no
 * query at all, and the page appeared to ignore the key. The handler takes over the
 * moment it is attached; until then the browser does the same search by itself.
 * `status` is the line laid over the margin under the bar (SearchStatus), so it
 * moves nothing.
 */
export function SearchBar({
    action,
    term,
    setTerm,
    onSubmit,
    searching,
    placeholder,
    label,
    status,
}: {
    action: string
    term: string
    setTerm: (term: string) => void
    onSubmit: () => void
    searching: boolean
    placeholder: string
    label: string
    status?: ReactNode
}) {
    const { t } = useTranslations()

    return (
        <>
            <form
                action={action}
                method="get"
                onSubmit={(e) => {
                    e.preventDefault()
                    onSubmit()
                }}
                className="relative flex gap-2"
                role="search"
            >
                {status}
                {/*
                  A scanner beam sweeping the bottom edge of the field, inside the
                  border. Absolutely positioned so that appearing and disappearing
                  moves nothing, and on the field rather than the page-wide Inertia
                  bar because the answer being replaced is on this screen.
                */}
                <div className="relative min-w-0 flex-1">
                    <input
                        type="search"
                        name="q"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={placeholder}
                        aria-label={label}
                        aria-busy={searching}
                        className="h-12 w-full rounded-card border border-line bg-card px-4"
                    />
                    {searching && (
                        <span className="pointer-events-none absolute inset-x-px bottom-px h-0.5 overflow-hidden rounded-b-lg" aria-hidden>
                            {/* `w-1/4` is paired with the 300% travel in the `scan` keyframes:
                                together they put the turn exactly at each edge. */}
                            <span className="animate-scan absolute inset-y-0 left-0 w-1/4 bg-gradient-to-r from-transparent via-accent to-transparent" />
                        </span>
                    )}
                </div>
                {/* Next to the search box: the only place someone standing in a shop will look for it. */}
                <ScanButton className="h-12 w-12 shrink-0 rounded-lg border border-line bg-card" />

                {/* Dimmed, not disabled: a disabled button loses focus mid-search and
                    stops answering to a keyboard, and Inertia cancels the visit in
                    flight anyway. */}
                <button
                    aria-busy={searching}
                    className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-accent text-white transition-opacity hover:bg-accent-dark ${searching ? 'opacity-70' : ''}`}
                >
                    <ToolIcon name="search" className="h-5 w-5" />
                    <span className="sr-only">{t('search.submit')}</span>
                </button>
            </form>

            {/* The beam is decoration and hidden from the accessibility tree, so the
                same news is given in words. Rendered empty rather than unmounted: a
                live region has to exist before its content changes to be announced. */}
            <p role="status" className="sr-only">
                {searching ? t('search.searching') : ''}
            </p>
        </>
    )
}

/**
 * The Filters button, with a count, opening a sheet on a phone and a popover on a
 * desktop (owner, 2026-09-26: the results take the whole width).
 *
 * Floating on a desktop so opening it moves nothing: reading a filter must not cost
 * sight of the results it filters. On a phone it covers the screen, and "Show
 * results" is one press away. Anchored to the button's right edge, because the
 * button sits at the right of the page. Closes on Escape anywhere, and on a press
 * outside it on a desktop.
 */
export function FiltersMenu({ count, children }: { count: number; children: ReactNode }) {
    const { t, n } = useTranslations()
    const [open, setOpen] = useState(false)
    const panelRef = useRef<HTMLDivElement>(null)

    useEffect(() => {
        if (!open) return

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false)
        }
        const onPress = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) setOpen(false)
        }

        document.addEventListener('keydown', onKey)
        document.addEventListener('mousedown', onPress)

        return () => {
            document.removeEventListener('keydown', onKey)
            document.removeEventListener('mousedown', onPress)
        }
    }, [open])

    return (
        <div className="relative" ref={panelRef}>
            <button
                type="button"
                className={buttonClasses('secondary', 'sm', 'min-w-11 rounded-full sm:min-w-0')}
                aria-expanded={open}
                aria-controls="search-filters"
                title={t('search.filters')}
                onClick={() => setOpen(!open)}
            >
                <ToolIcon name="settings" className="h-5 w-5 shrink-0" />
                <span className="sr-only sm:not-sr-only">{t('search.filters')}</span>
                {count > 0 && <span className="rounded-full bg-accent px-1.5 py-0.5 text-xs text-white">{n(count)}</span>}
                <ToolIcon name="chevron" className={`hidden h-4 w-4 text-ink-soft transition sm:block ${open ? 'rotate-180' : ''}`} />
            </button>

            <aside
                id="search-filters"
                aria-label={t('search.filters')}
                className={
                    open
                        ? 'fixed inset-0 z-40 space-y-6 overflow-y-auto bg-cream p-4 pb-24 text-sm lg:absolute lg:top-full lg:right-0 lg:bottom-auto lg:left-auto lg:z-30 lg:mt-2 lg:max-h-[70vh] lg:w-80 lg:space-y-5 lg:rounded-card lg:border lg:border-line lg:bg-card lg:pb-4 lg:shadow-lg'
                        : 'hidden'
                }
            >
                <div className="flex items-center justify-between lg:hidden">
                    {/* A label, not a heading (2026-11-08): Google reads a page's headings
                        as what it is about, and "Filters en sorteren" said nothing about it. */}
                    <p className="font-medium">{t('search.filters_and_sort')}</p>
                    <button type="button" onClick={() => setOpen(false)} className={buttonClasses('primary', 'sm')}>
                        {t('search.show_results')}
                    </button>
                </div>

                {children}
            </aside>
        </div>
    )
}

/**
 * How the results are ordered. In the Filters panel rather than above the grid,
 * because a row above the products competes with the products for the first line
 * of the page.
 */
export function ResultControls({ sort, go }: { sort: string; go: Go }) {
    const { t } = useTranslations()

    return (
        <div className="space-y-3">
            <div>
                <label className="mb-1 block text-xs font-semibold tracking-wide text-ink-soft uppercase" htmlFor="sort">
                    {t('search.sort')}
                </label>
                <select
                    id="sort"
                    value={sort}
                    onChange={(e) => go({ sort: e.target.value })}
                    className="w-full rounded-card border border-line bg-card px-2 py-1.5 text-sm"
                >
                    <option value="relevance">{t('search.sort_relevance')}</option>
                    <option value="price_asc">{t('search.sort_price_asc')}</option>
                    <option value="price_desc">{t('search.sort_price_desc')}</option>
                    <option value="discount">{t('search.sort_discount')}</option>
                    <option value="newest">{t('search.sort_newest')}</option>
                </select>
            </div>
        </div>
    )
}

/**
 * The view as a button of its own beside Filters (owner, 2026-11-07). One button
 * that names the view it switches to, with that view's icon: an icon on a phone,
 * icon and word from `sm` up.
 */
export function ViewToggle({ view, go }: { view: string; go: Go }) {
    const { t } = useTranslations()
    const next = view === 'store' ? 'grid' : 'store'
    const label = t(`search.view_${next}`)

    return (
        <button
            type="button"
            className={buttonClasses('secondary', 'sm', 'min-w-11 rounded-full sm:min-w-0')}
            title={label}
            onClick={() => {
                rememberView(next)
                go({ view: next === 'grid' ? null : next })
            }}
        >
            <ToolIcon name={next === 'grid' ? 'grid' : 'lanes'} className="h-5 w-5 shrink-0" />
            <span className="sr-only sm:not-sr-only">{label}</span>
        </button>
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

/**
 * Brand, shop and the two switches: whatever this view has not taken over.
 *
 * `showShops` is false in the store view, where the chip row above the lanes is
 * the shop filter and a second copy in the panel would be two controls for one
 * piece of state. `showBrands` is false on a brand page: filtering a Sony page by
 * brand is a control with one option. `before` is what a page adds at the top (the
 * brand page's other brands).
 */
export function FilterPanel({
    facets,
    filters,
    brandLinks = {},
    go,
    showShops = true,
    showBrands = true,
    amazonShop = null,
    shopVisibility,
    before,
}: {
    facets: Facets
    filters: Record<string, unknown>
    brandLinks?: Record<string, string>
    go: Go
    showShops?: boolean
    showBrands?: boolean
    /** Amazon's storefront, listed first among the shops when it has products to offer. */
    amazonShop?: string | null
    /**
     * The by-store view's columns, each one ticked while it is shown (owner,
     * 2026-10-25). Not a filter on the search: it only hides columns, in this
     * browser, like the × on a column.
     */
    shopVisibility?: { shops: string[]; hidden: string[]; setVisible: (shop: string, visible: boolean) => void }
    before?: ReactNode
}) {
    const { t } = useTranslations()

    return (
        <>
            {before}

            {/* First in the by-store view: there the shops are what the page is laid out by. */}
            {shopVisibility && shopVisibility.shops.length > 0 && (
                <Facet
                    title={t('search.shops_shown')}
                    collapsible={false}
                    items={shopVisibility.shops.map((shop) => ({
                        key: shop,
                        label: shop,
                        active: !shopVisibility.hidden.includes(shop),
                    }))}
                    onToggle={(shop, active) => shopVisibility.setVisible(shop, !active)}
                />
            )}

            {showBrands && facets.brands.length > 0 && (
                <Facet
                    title={t('search.brand')}
                    collapsible={false}
                    items={facets.brands.map((b) => ({
                        key: b.value,
                        label: b.value,
                        active: ([] as string[]).concat((filters.brand as string[]) ?? []).includes(b.value),
                        // The checkbox filters this page; the arrow goes to the brand's
                        // own page. Two different intentions that a single control
                        // cannot serve, and only the second one is indexable.
                        href: brandLinks[b.value.toLowerCase()] ?? null,
                    }))}
                    onToggle={(key, active) => {
                        const current = ([] as string[]).concat((filters.brand as string[]) ?? [])
                        go({ brand: active ? current.filter((b) => b !== key) : [...current, key] })
                    }}
                />
            )}

            {showShops && (facets.merchants.length > 0 || amazonShop) && (
                <Facet
                    title={t('search.shop')}
                    /* Open, like Brand above it: two facets side by side, one collapsed
                       and one not, reads as an accident rather than a distinction. */
                    collapsible={false}
                    items={[...(amazonShop ? [{ id: AMAZON_SHOP, name: amazonShop }] : []), ...facets.merchants].map((m) => ({
                        key: String(m.id),
                        label: m.name,
                        active: ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String).includes(String(m.id)),
                    }))}
                    onToggle={(key, active) => {
                        const current = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)
                        go({ merchant: active ? current.filter((m) => m !== key) : [...current, key] })
                    }}
                />
            )}

            {/* The two switches sit below the facets: brand and shop are what a shopper
                is looking for; the switches only trim what is already there. */}
            <Toggle label={t('search.discounted_only')} checked={filters.discounted === '1'} onChange={(v) => go({ discounted: v ? '1' : null })} />
            <Toggle label={t('search.in_stock_only')} checked={filters.in_stock !== '0'} onChange={(v) => go({ in_stock: v ? null : '0' })} />
        </>
    )
}

/**
 * One facet list, collapsible or not.
 *
 * Brand does not fold: it is the list a shopper came to this rail for. Where a
 * list does fold it is open by default, and the count of active options rides on
 * the header, so a folded list cannot quietly hold a filter that is changing the
 * results. The fold state is not persisted.
 *
 * The group's name ("Merk", "Winkel") is a label, not a heading (2026-11-08):
 * search engines read a page's headings as what the page is about.
 */
export function Facet({
    title,
    items,
    onToggle,
    collapsible = true,
}: {
    title: string
    items: { key: string; label: string; active: boolean; href?: string | null }[]
    onToggle: (key: string, active: boolean) => void
    collapsible?: boolean
}) {
    const { n } = useTranslations()
    const [open, setOpen] = useState(true)
    const activeCount = items.filter((item) => item.active).length
    const panelId = `facet-${title.replace(/\s+/g, '-').toLowerCase()}`
    const shown = open || !collapsible

    const count = activeCount > 0 && <span className="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-white">{n(activeCount)}</span>

    return (
        <div>
            {collapsible ? (
                <div>
                    <button
                        type="button"
                        className="flex w-full items-center justify-between gap-2 py-1 text-left font-medium"
                        aria-expanded={open}
                        aria-controls={panelId}
                        onClick={() => setOpen(!open)}
                    >
                        <span>
                            {title}
                            {count}
                        </span>
                        <ToolIcon name="chevron" className={`h-4 w-4 text-ink-soft transition ${open ? 'rotate-180' : ''}`} />
                    </button>
                </div>
            ) : (
                <p className="py-1 font-medium">
                    {title}
                    {count}
                </p>
            )}
            <ul id={panelId} className="mt-2 space-y-1" hidden={!shown}>
                {items.map((item) => (
                    <li key={item.key} className="flex items-center gap-1">
                        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                            <input type="checkbox" checked={item.active} onChange={() => onToggle(item.key, item.active)} className="accent-accent" />
                            <span className="flex-1 truncate">{item.label}</span>
                        </label>
                        {item.href && (
                            <Link href={item.href} className="shrink-0 px-1 text-xs text-ink-soft hover:text-accent" aria-label={item.label} title={item.label}>
                                <span aria-hidden>→</span>
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    )
}

/**
 * Every filter that is on, as a chip with its own way off.
 *
 * Their number is the count on the Filters button, so a closed panel cannot hide
 * the reason a page looks empty; `q`, `view`, `sort` and `page` are not filters and
 * are not counted. Brand and shop come off through `go()`, which keeps the rest; the
 * gift filters (?for=, ?interest=, ?occasion=) carry the server's own URL without them.
 */
export function useFilterChips({
    filters,
    facets,
    go,
    market,
    amazonShop = null,
    tagFilters = [],
    withBrands = true,
}: {
    filters: Record<string, unknown>
    facets: Facets
    go: Go
    market: CurrentMarket
    amazonShop?: string | null
    tagFilters?: { label: string; without: string }[]
    /** False on a brand page, whose brand is the page itself. */
    withBrands?: boolean
}): FilterChip[] {
    const { t } = useTranslations()
    const brands = withBrands ? ([] as string[]).concat((filters.brand as string[]) ?? []) : []
    const shops = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)

    return [
        ...brands.map((brand) => ({
            key: `brand:${brand}`,
            kind: 'brand' as const,
            label: brand,
            remove: () => go({ brand: brands.filter((b) => b !== brand) }),
        })),
        ...shops.map((id) => ({
            key: `merchant:${id}`,
            kind: 'merchant' as const,
            label: id === AMAZON_SHOP ? (amazonShop ?? 'Amazon') : (facets.merchants.find((m) => String(m.id) === id)?.name ?? t('search.shop')),
            remove: () => go({ merchant: shops.filter((m) => m !== id) }),
        })),
        ...(filters.min
            ? [{ key: 'min', kind: 'price' as const, label: t('search.chip_min', { price: formatPrice(Math.round(Number(filters.min) * 100), market) }), remove: () => go({ min: null }) }]
            : []),
        ...(filters.max
            ? [{ key: 'max', kind: 'price' as const, label: t('search.chip_max', { price: formatPrice(Math.round(Number(filters.max) * 100), market) }), remove: () => go({ max: null }) }]
            : []),
        ...(filters.discounted === '1'
            ? [{ key: 'discounted', kind: 'switch' as const, label: t('search.discounted_only'), remove: () => go({ discounted: null }) }]
            : []),
        ...(filters.in_stock === '0'
            ? [{ key: 'in_stock', kind: 'switch' as const, label: t('search.chip_with_out_of_stock'), remove: () => go({ in_stock: null }) }]
            : []),
        ...(filters.comparable === '1'
            ? [{ key: 'comparable', kind: 'switch' as const, label: t('search.chip_comparable'), remove: () => go({ comparable: null }) }]
            : []),
        ...tagFilters.map((chip) => ({ key: `tag:${chip.without}`, kind: 'tag' as const, label: chip.label, href: chip.without })),
    ]
}

/**
 * Every filter that is on, each with its own way off. The count on the button says
 * that something is filtering; the chips say what, without opening anything.
 * `clearHref` is the page without any of them.
 */
export function FilterChips({ chips, clearHref }: { chips: FilterChip[]; clearHref: string }) {
    const { t } = useTranslations()

    if (chips.length === 0) {
        return null
    }

    const closeClasses = 'flex h-7 w-7 items-center justify-center rounded-full text-ink-soft hover:bg-line/50 hover:text-ink'

    return (
        <ul className="mb-4 flex flex-wrap items-center gap-2" aria-label={t('search.filters')}>
            {chips.map((chip) => (
                <li key={chip.key} className="inline-flex items-center gap-1 rounded-full border border-line bg-card py-1 pr-1 pl-3 text-sm font-medium">
                    {chip.label}
                    {chip.href ? (
                        <Link
                            href={chip.href}
                            preserveScroll
                            aria-label={t('search.intent_remove', { label: chip.label })}
                            title={t('search.intent_remove', { label: chip.label })}
                            className={closeClasses}
                        >
                            <ToolIcon name="close" className="h-3.5 w-3.5 shrink-0" />
                        </Link>
                    ) : (
                        <button
                            type="button"
                            onClick={() => chip.remove?.()}
                            aria-label={t('search.intent_remove', { label: chip.label })}
                            title={t('search.intent_remove', { label: chip.label })}
                            className={closeClasses}
                        >
                            <ToolIcon name="close" className="h-3.5 w-3.5 shrink-0" />
                        </button>
                    )}
                </li>
            ))}
            {chips.length > 1 && (
                <li>
                    <Link href={clearHref} preserveScroll className="inline-flex min-h-10 items-center px-2 text-sm text-accent-dark underline hover:text-ink sm:min-h-0">
                        {t('search.clear_filters')}
                    </Link>
                </li>
            )}
        </ul>
    )
}

/**
 * The narrowing pills: a row that scrolls sideways on a phone, one clipped line
 * from `sm` up.
 *
 * Buttons, not links, and that is the point. Each pill narrows the search by
 * *adding* its word to the query. As anchors that was a combinatorial supply of
 * crawlable URLs, each one logged as a new term in `search_log`, the table these
 * pills are drawn from. A button navigates for a visitor and does not exist for a
 * crawler. The URL is the server's own (`SearchContext::narrowUrl()`). See
 * docs/features/seo.md.
 */
export function TermPills({ terms, visit }: { terms: { term: string; url: string }[]; visit: (url: string) => void }) {
    const { t } = useTranslations()

    if (terms.length === 0) {
        return null
    }

    return (
        <nav className="mb-4 min-w-0" aria-label={t('search.terms_heading')}>
            <ul className="-mr-4 flex gap-2 overflow-x-auto pr-4 [scrollbar-width:none] sm:mr-0 sm:max-h-10 sm:flex-wrap sm:overflow-hidden sm:pr-0">
                {terms.map((item) => (
                    <li key={item.term} className="shrink-0">
                        <button
                            type="button"
                            onClick={() => visit(item.url)}
                            className="inline-flex min-h-10 items-center rounded-full border border-line bg-card px-3 text-sm whitespace-nowrap text-ink-soft transition hover:border-ink hover:text-ink"
                        >
                            <span aria-hidden className="mr-1 text-ink-soft">
                                +
                            </span>
                            {item.term}
                        </button>
                    </li>
                ))}
            </ul>
        </nav>
    )
}

/**
 * Amazon, as a banner just above the products (owner, 2026-11-22). Two links: the
 * sentence searches the term, Amazon's mark at the right opens the storefront's
 * front page. Both tagged by the server, both plain anchors (never through our
 * redirector, Amazon's rule), sponsored and nofollow, in a new tab. The mark is
 * Amazon's own favicon from Amazon's site, not a copy of its logo: the Associates
 * trademark rules allow the logo only in the forms Amazon supplies.
 */
export function AmazonBanner({ link, term }: { link: AmazonSearch; term: string }) {
    const { t } = useTranslations()
    const host = link.host.replace(/^www\./, '')

    return (
        <div className="mb-4 flex min-h-12 items-center gap-2 rounded-card border border-line bg-card pl-4 text-sm">
            <a
                href={link.url}
                target="_blank"
                rel="sponsored noopener nofollow"
                className="flex min-h-12 min-w-0 flex-1 items-center gap-2 font-medium text-ink hover:text-accent-dark"
            >
                <span className="truncate">{t('search.amazon_banner', { term })}</span>
                <span aria-hidden="true" className="shrink-0 text-accent">
                    →
                </span>
            </a>
            <a
                href={link.home}
                target="_blank"
                rel="sponsored noopener nofollow"
                aria-label={t('search.amazon_home', { host })}
                title={host}
                className="flex min-h-12 min-w-12 shrink-0 items-center justify-center rounded-r-card border-l border-line px-3 hover:bg-cream"
            >
                <img
                    src={link.icon}
                    alt=""
                    width={24}
                    height={24}
                    className="h-6 w-6 rounded-sm bg-white"
                    onError={(e) => {
                        e.currentTarget.style.display = 'none'
                    }}
                />
            </a>
        </div>
    )
}

/**
 * The product grid: ours and Amazon's mixed (mixWithAmazon), and the Amazon
 * hand-off card in the 3rd to 6th tile (`amazonCard.after`), or after the last
 * product if there are fewer (owner, 2026-10-05). As a card it reads as one more
 * place to look.
 */
export function ResultGrid({
    entries,
    amazonHost,
    brandUrl,
    amazonCard,
}: {
    entries: ({ kind: 'ours'; g: GroupCard; index: number } | { kind: 'amazon'; item: AmazonItem })[]
    amazonHost: string
    /** The brand page link under a card; none on a brand page, where it is the page itself. */
    brandUrl?: (g: GroupCard) => string | null
    amazonCard?: { link: AmazonSearch; label: string; after: number } | null
}) {
    return (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {entries.map((entry) =>
                entry.kind === 'amazon' ? (
                    <AmazonProductCard key={`amazon-${entry.item.asin}`} item={entry.item} host={amazonHost} />
                ) : (
                    <Fragment key={entry.g.id}>
                        <ProductCard group={entry.g} brandUrl={brandUrl ? brandUrl(entry.g) : null} />
                        {amazonCard && entry.index === amazonCard.after && <AmazonSearchCard link={amazonCard.link} label={amazonCard.label} />}
                    </Fragment>
                ),
            )}
        </div>
    )
}

/**
 * Which tile the Amazon card takes: the 3rd, 4th, 5th or 6th (owner, 2026-10-05:
 * "randomly"). Drawn from the term rather than from Math.random(): the page is
 * rendered on the server and again in the browser, and two different draws would
 * move the card under the visitor's eyes as the page wakes up. The index is the
 * product it follows.
 */
export function amazonCardAfter(term: string, count: number): number {
    let hash = 0
    for (const char of term) hash = (hash * 31 + char.charCodeAt(0)) >>> 0

    return Math.min(1 + (hash % 4), count - 1)
}

/** Previous, the page number, next. No last page: nothing counts the matches. */
export function Pagination({ page, go }: { page: ResultPage; go: Go }) {
    const { t, n } = useTranslations()

    if (page.currentPage <= 1 && !page.hasMore) {
        return null
    }

    return (
        <nav className="mt-8 flex items-center justify-center gap-4 text-sm">
            <button disabled={page.currentPage <= 1} onClick={() => go({ page: page.currentPage - 1 })} className="rounded border border-line px-3 py-1.5 disabled:opacity-50">
                {t('search.previous')}
            </button>
            <span className="text-ink-soft">{t('search.page', { current: n(page.currentPage) })}</span>
            <button disabled={!page.hasMore} onClick={() => go({ page: page.currentPage + 1 })} className="rounded border border-line px-3 py-1.5 disabled:opacity-50">
                {t('search.next')}
            </button>
        </nav>
    )
}
