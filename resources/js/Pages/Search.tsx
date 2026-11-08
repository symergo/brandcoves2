import { Head, Link, router, usePage } from '@inertiajs/react'
import { Fragment, useEffect, useMemo, useRef, useState } from 'react'
import { preferred as preferredView, remember as rememberView } from '../viewPreference'
import PageNarrative, { type Narrative } from '../Components/PageNarrative'
import PageBlocks from '../Components/PageBlocks'
import { type BlockPayload } from '../Components/Parts'
import ProductCard, { type GroupCard } from '../Components/ProductCard'
import { PLACEHOLDER_AFTER, SearchStatus, SkeletonGrid, useElapsed } from '../Components/SearchWait'
import SearchLanding, { type Landing } from '../Components/SearchLanding'
import AmazonSearchCta, { AmazonSearchCard, type AmazonSearch } from '../Components/AmazonSearchCta'
import { buttonClasses } from '../Components/Button'
import SaveToList from '../Components/SaveToList'
import ScanButton from '../Components/ScanButton'
import ToolIcon from '../Components/ToolIcon'
import LaneScroller from '../Components/LaneScroller'
import WatchSearch, { type WatchState } from '../Components/WatchSearch'
import type { SharedProps } from '../types'
import { formatBudget, formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import { searchHref, searchTarget } from '../searchUrl'

interface Facets {
    brands: { value: string }[]
    merchants: { id: number; name: string; logo: string | null }[]
}

/**
 * One page and whether another follows. No total and no last page (owner's
 * decision, 2026-09-27): nothing counts the matches, so the page shows
 * neither a number nor a word standing in for one.
 */
interface SearchResults {
    empty: boolean
    currentPage: number
    hasMore: boolean
    items: GroupCard[]
}

/** Where this browser keeps the shops hidden in the by-store view. */
const HIDDEN_SHOPS_KEY = 'bc:search:hidden-shops'

/** One shop's row in the by-store view. */
/**
 * That shop's offer for a row in its column (SearchController::laneCard): the
 * row opens the shop, with the shop's own price (owner, 2026-11-07).
 */
interface LaneOffer {
    /** The shop's own affiliate link, never our redirector (checked on the server). */
    url: string
    price: number | null
    direct: boolean
    beacon: string | null
    offerId: number
    needsPriceTimestamp: boolean
}

interface Lane {
    shop: string
    logo: string | null
    /** The shop's address, for "Links naar …" in the header; null when unknown. */
    domain: string | null
    items: (GroupCard & { shop: LaneOffer })[]
}

/** A direct link skips the redirector, so the browser reports the click (as on the product page). */
function reportLaneClick(offer: LaneOffer): void {
    if (!offer.direct || !offer.beacon) return

    try {
        navigator.sendBeacon?.(offer.beacon, new Blob([JSON.stringify({ offer: offer.offerId })], { type: 'application/json' }))
    } catch {
        // Analytics must never break an outbound click.
    }
}

/** One filter that is on, drawn as a chip above the results. */
interface FilterChip {
    key: string
    /** `merchant` chips are left out of the by-store view, whose shop chips are the same state. */
    kind: 'brand' | 'merchant' | 'price' | 'switch' | 'tag'
    label: string
    /** Removes it through `go()`, keeping everything else. */
    remove?: () => void
    /** Or a URL without it, for the gift filters the server words. */
    href?: string
}

interface Props {
    q: string
    filters: Record<string, unknown>
    sort: string
    view: 'grid' | 'store'
    facets: Facets
    /**
     * One page and whether another follows. No total and no last page
     * (owner's decision, 2026-09-27): nothing counts the matches, so the
     * page shows neither a number nor a word standing in for one.
     */
    results: SearchResults
    /**
     * The live shops being asked after the page was shown (a thin search,
     * 2026-10-25), by name; empty when nothing is pending. `live` arrives
     * from that follow-up request as a deferred prop: undefined until then,
     * null when there was nothing to ask.
     */
    liveShops: string[]
    /** The market's biggest shops, named in the line under the search bar while a search loads. */
    waitShops: string[]
    live?: { results: SearchResults; lanes: Lane[] | null; added: number; notice: string | null } | null
    lanes: Lane[] | null
    emptyBecauseOfFilters: boolean
    /**
     * A gift search, read (roadmap step 4): what was understood, each piece
     * with the search without it. Null for every ordinary search. See
     * GiftIntentParser.
     */
    intent: {
        chips: { label: string; without: string }[]
        /** The reading as one sentence, worded on the server: "We geven je cadeau-ideeën met … voor je partner." */
        sentence: string
        budget: { min: number | null; max: number | null; without: string } | null
        words: string
        asWordsUrl: string
    } | null
    /** Who, interest and occasion filters (?for=, ?interest=, ?occasion=), each with the search without it. */
    tagFilters?: { label: string; without: string }[]
    /** Ways in, before a search: recent searches, your brands, the tools. Null once there is a term or a filter. */
    landing: Landing | null
    /** Set when the search box held an Amazon URL rather than a search term. */
    pastedLink: {
        asin: string | null
        terms: string
        shortlink: boolean
        usable: boolean
    } | null
    /**
     * The tagged hand-off to Amazon for this term, or null where there is no
     * Associates tag for the market. Built server-side — see AmazonSearchLink.
     */
    amazonSearch: AmazonSearch | null
    /** Null without a term. See SearchController::watch(). */
    watch: WatchState | null
    /** Words that recur in these results, each a search of its own. Empty on thin pages. */
    terms: { term: string; url: string }[]
    /** Lowercase brand name → brand page URL, for brands that have one. */
    brandLinks: Record<string, string>
    /** Long-form copy below the grid. Null on pages that are noindex anyway. */
    narrative: Narrative | null
    intro: BlockPayload[] | null
    emptyCopy: BlockPayload[] | null
}

/**
 * How the results are ordered.
 *
 * In the Filters panel rather than above the grid, because a row above the
 * products competes with the products for the first line of the page. The one
 * panel serves both views, so switching view never takes the sort away. The
 * view itself has its own button since 2026-11-07 (ViewToggle).
 */
function ResultControls({ sort, go }: { sort: string; go: (changes: Record<string, unknown>) => void }) {
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
 * The view as a button of its own beside Filters (owner, 2026-11-07); it was
 * the second control in the Filters panel. One button that names the view it
 * switches to, with that view's icon: an icon on a phone, icon and word from
 * `sm` up, like Filters and the bell beside it.
 */
function ViewToggle({ view, go }: { view: string; go: (changes: Record<string, unknown>) => void }) {
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

export default function Search({
    q,
    filters,
    sort,
    view,
    facets,
    results: storedResults,
    liveShops = [],
    waitShops = [],
    live,
    lanes: storedLanes,
    emptyBecauseOfFilters,
    intent,
    tagFilters = [],
    landing,
    amazonSearch,
    watch,
    pastedLink,
    terms,
    brandLinks,
    narrative,
    intro,
    emptyCopy,
}: Props) {
    const { market, seoTitle } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [term, setTerm] = useState(q)
    const [filtersOpen, setFiltersOpen] = useState(false)

    /*
     * The shops' products join the page after it is shown (2026-10-25), at
     * the top (owner, the same day: what was found online first). That
     * pushes the cards already shown down once, a layout shift the owner
     * accepted; it only happens on a thin search, where there are few cards
     * to push. The page and "more" come from the shops' answer.
     */
    const livePending = liveShops.length > 0 && live === undefined
    const results = useMemo<SearchResults>(() => {
        if (!live) {
            return storedResults
        }
        const shown = new Set(storedResults.items.map((g) => g.id))
        const items = [...live.results.items.filter((g) => !shown.has(g.id)), ...storedResults.items]

        return { ...live.results, items, empty: items.length === 0 }
    }, [storedResults, live])

    /*
     * The by-store view, after the shops answered: the lanes the follow-up
     * computed again, the shops with products found online first, as in the
     * grid. Before that, the lanes the page came with.
     */
    const lanes = useMemo<Lane[] | null>(() => {
        if (!live?.lanes) {
            return storedLanes
        }
        const shown = new Set(storedResults.items.map((g) => g.id))
        const hasNew = (lane: Lane) => lane.items.some((g) => !shown.has(g.id))

        return [...live.lanes.filter(hasNew), ...live.lanes.filter((lane) => !hasNew(lane))]
    }, [storedLanes, storedResults, live])

    /*
     * The box follows the query, not only the first one.
     *
     * Every visit made by `go()` keeps this component mounted, so the state
     * seeded above never saw a second `q`: narrow by a chip and the heading
     * and the grid showed the narrowed query while the box still held the
     * words typed before it — and Enter then searched the stale text, quietly
     * throwing the narrowing away.
     */
    useEffect(() => {
        setTerm(q)
    }, [q])
    const [searching, setSearching] = useState(false)

    /*
     * Shops hidden in the by-store view (owner, 2026-10-25), by name. A
     * per-visitor preference, so it lives in this browser and carries over to
     * the next search: a shop you hid once stays out of the way. Read after
     * mounting (storage does not exist on the SSR container) and inside
     * try/catch, since storage can be blocked or missing.
     */
    const [hiddenShops, setHiddenShops] = useState<string[]>([])
    useEffect(() => {
        try {
            const stored: unknown = JSON.parse(window.localStorage.getItem(HIDDEN_SHOPS_KEY) ?? '[]')
            if (Array.isArray(stored)) {
                setHiddenShops(stored.filter((name): name is string => typeof name === 'string'))
            }
        } catch {
            // No storage: nothing hidden.
        }
    }, [])
    const keepHiddenShops = (next: string[]) => {
        setHiddenShops(next)
        try {
            window.localStorage.setItem(HIDDEN_SHOPS_KEY, JSON.stringify(next))
        } catch {
            // Hidden for this page only.
        }
    }
    const hideShop = (shop: string) => keepHiddenShops([...hiddenShops.filter((name) => name !== shop), shop])
    const showShop = (shop: string) => keepHiddenShops(hiddenShops.filter((name) => name !== shop))
    const showAllShops = () => keepHiddenShops([])
    // How long the search has been loading: drives the line under the search
    // bar ("Even zoeken…", then the tips) and the placeholders.
    const elapsed = useElapsed(searching)
    // Placeholders instead of the old results once a search takes longer
    // than a blink, and instead of "nothing found" while the shops may still
    // fill an empty page.
    const placeholders = (searching && elapsed >= PLACEHOLDER_AFTER) || (livePending && storedResults.empty)
    const base = `/${market.key}/search`

    /*
     * The view the visitor last chose, restored when the URL does not say.
     *
     * Applied by navigating rather than by rendering: the two views are not two
     * arrangements of the same payload - the by-store one is served `lanes`,
     * which the grid never asks for - so the server has to be told. Once, on
     * first mount, and only when the URL is silent, because a link carrying
     * `?view=` means something its sender chose and must show them the same page
     * it shows anyone else.
     */
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

    /*
     * "Search <term> on Amazon too". The server sends no link at all when the
     * URL carries no term (SearchController), so there is always a term to
     * quote by the time this renders.
     */
    const amazonLabel = t('search.amazon_search', { term: q })

    /*
     * Which tile the Amazon card takes: the 3rd, 4th, 5th or 6th (owner,
     * 2026-10-05: "randomly"). Drawn from the term rather than from
     * Math.random(): the page is rendered on the server and again in the
     * browser, and two different draws would move the card under the
     * visitor's eyes as the page wakes up. So it differs between searches and
     * holds still within one. The index is the product it follows.
     */
    let termHash = 0
    for (const char of q) termHash = (termHash * 31 + char.charCodeAt(0)) >>> 0
    const amazonAfter = Math.min(1 + (termHash % 4), results.items.length - 1)

    /*
     * Every filter that is on, as a chip with its own way off.
     *
     * Their number is the count on the Filters button, so a closed panel
     * cannot hide the reason a search looks empty; `q`, `view`, `sort` and
     * `page` are not filters and are not counted. Brand and shop come off
     * through `go()`, which keeps the rest; the gift filters (?for=,
     * ?interest=, ?occasion=) carry the server's own URL without them.
     */
    const brands = ([] as string[]).concat((filters.brand as string[]) ?? [])
    const shops = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)
    const chips: FilterChip[] = [
        ...brands.map((brand) => ({
            key: `brand:${brand}`,
            kind: 'brand' as const,
            label: brand,
            remove: () => go({ brand: brands.filter((b) => b !== brand) }),
        })),
        ...shops.map((id) => ({
            key: `merchant:${id}`,
            kind: 'merchant' as const,
            label: facets.merchants.find((m) => String(m.id) === id)?.name ?? t('search.shop'),
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

    /*
     * The popover closes on Escape anywhere, and on a press outside it on a
     * desktop. On a phone the sheet covers the page, so there is no outside;
     * "Show results" closes it.
     */
    const panelRef = useRef<HTMLDivElement>(null)

    useEffect(() => {
        if (!filtersOpen) return

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setFiltersOpen(false)
        }
        const onPress = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) setFiltersOpen(false)
        }

        document.addEventListener('keydown', onKey)
        document.addEventListener('mousedown', onPress)

        return () => {
            document.removeEventListener('keydown', onKey)
            document.removeEventListener('mousedown', onPress)
        }
    }, [filtersOpen])

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
        // The term goes in the path when it can (/zoek/term), the rest stays
        // in the query. The server names the same URL as canonical, so what
        // the address bar shows and what a crawler is told are one page.
        const target = searchTarget(market.key, next)

        router.get(target.path, target.query as Record<string, string>, {
            /*
             * Asks for `brand[]=HP` rather than `brand[0]=HP`.
             *
             * PHP needs bracket syntax to parse a repeated parameter into an
             * array, so the brackets themselves are not optional — and a
             * browser shows them percent-encoded as %5B and %5D, which is what
             * makes a filtered search URL look mangled when it is pasted
             * somewhere. The index is one more pair of those for nothing.
             *
             * **It does not currently take effect.** Measured on Inertia 3.6.1,
             * 2026-08-30: a brand checkbox still lands on `?brand[0]=Samsung`,
             * and so does a shop chip. The option is still in Inertia's own
             * types and is still passed, so this is left in place rather than
             * deleted — but the comment above it used to state the outcome as
             * fact, and the URL has not looked like that for some time.
             *
             * Nothing is broken by it: `SearchQuery::fromRequest()` casts with
             * `(array)` and reads either shape, which is also why it went
             * unnoticed. Worth chasing only if the URLs matter.
             */
            queryStringArrayFormat: 'brackets',
            /*
             * Keep the scroll for a filter, a sort or a submitted query — the
             * control that was pressed should stay under the finger. Not for
             * a page: the next page used to arrive with the viewport parked at
             * the bottom of the previous one, past every card it had brought.
             */
            preserveScroll: !('page' in changes),
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        })
    }

    return (
        <>
            {/*
              The server's title, not one rebuilt here.

              It carries the market's own buying phrase and drops it again for a
              long term, and that rule lives in SearchController::seo(). Building
              a second copy of it in TypeScript is how <title> and og:title come
              to disagree — see the note on `seoTitle` in HandleInertiaRequests.
            */}
            <Head title={seoTitle ?? (q ? q : t('search.title'))} />

            <form
                /*
                  A real GET form underneath the handler.

                  Without `action`, `method` and a named field, an Enter pressed
                  before React hydrates submitted the current URL with no query
                  at all - the page appeared to ignore the key. The handler below
                  takes over the moment it is attached; until then the browser
                  does the same search by itself.
                */
                action={base}
                method="get"
                onSubmit={(e) => {
                    e.preventDefault()
                    go({ q: term })
                }}
                // One row: the field, then two square buttons of the same
                // height — the camera (phones only) and the magnifier. The
                // search button was a wide bar of text; as an icon it fits
                // beside the field on a phone without pushing it onto its own
                // row.
                // `relative`: the status line under the bar is laid over the
                // margin below it (SearchStatus), so it moves nothing.
                className="relative flex gap-2"
                role="search"
            >
                <SearchStatus elapsed={elapsed} shops={waitShops} liveShops={livePending ? liveShops : []} notice={live?.notice ?? null} />
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
                <div className="relative min-w-0 flex-1">
                    <input
                        type="search"
                        name="q"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={t('search.placeholder')}
                        aria-label={t('search.title')}
                        aria-busy={searching}
                        className="h-12 w-full rounded-card border border-line bg-card px-4"
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
                <ScanButton className="h-12 w-12 shrink-0 rounded-lg border border-line bg-card" />

                {/*
                  Dimmed, not disabled. A disabled button loses focus mid-search
                  and stops answering to a keyboard, and the click it would
                  swallow is harmless anyway — Inertia cancels the in-flight
                  visit and starts the new one.
                */}
                <button
                    aria-busy={searching}
                    className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-accent text-white transition-opacity hover:bg-accent-dark ${searching ? 'opacity-70' : ''}`}
                >
                    <ToolIcon name="search" className="h-5 w-5" />
                    <span className="sr-only">{t('search.submit')}</span>
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
              No link to the search help under the box. One was restored here
              on 2026-09-06 and removed again the next day at the owner's
              request: the help is in the footer and the phone sheet, and a
              line under the field is a line between the field and the results.
            */}

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

            {/*
              Before a search, ways in; after one, the results. The landing has
              no filters and no heading: its sections carry their own.
            */}
            {landing ? (
                <SearchLanding landing={landing} />
            ) : (
            <div className="mt-8">
                {/*
                  The results take the whole width (owner's request,
                  2026-09-26).

                  The filters used to be a 16rem rail beside the grid on a
                  desktop, and the by-store view already had them behind a
                  Filters button because its lanes cannot lose a column. Now
                  both views work the way the by-store one did: one Filters
                  button with a count, opening a popover on a desktop and a
                  sheet on a phone, and every filter that is on shown as a chip
                  above the results, so a closed panel never hides why a search
                  looks the way it does. The owner's rule: content takes the
                  full width when there is nothing for a right column.
                */}
                <section className="min-w-0">
                    {/*
                      The heading this template shipped without.

                      Search was the only top-level page with no <h1>, the
                      highest-volume indexable template on the site. It is the
                      term itself, capitalised because the term arrives as raw
                      user input and a heading that opens lowercase reads as
                      broken.
                    */}
                    {/*
                      Title and bell on one row (owner, 2026-10-05: "less
                      crowded"). "Hou me op de hoogte" was a button on a row of
                      its own under the title; on a phone it was one of four
                      rows between the search box and the first product. The
                      title is a step smaller on a phone for the same reason.
                    */}
                    <div className="mb-4 flex items-start justify-between gap-3">
                        <h1 className="min-w-0 text-xl font-semibold tracking-tight sm:text-3xl">
                            {q ? q.charAt(0).toUpperCase() + q.slice(1) : t('search.title')}
                        </h1>

                        {/*
                          Filters to the left of the bell (owner, 2026-11-07),
                          the same shape: an icon on a phone, icon and word
                          from `sm` up. It was the first thing on the pills'
                          row under the Coves.
                        */}
                        <div className="flex shrink-0 items-start gap-2">
                            {/*
                              Amazon first in the row, before the view switch
                              (owner, 2026-11-07), the same shape as the buttons
                              beside it: the shop's mark on a phone, mark and
                              "Vergelijk met Amazon" from `sm` up. Still a plain
                              anchor straight to the tagged search, never through
                              our redirector (Amazon's rule), sponsored and
                              nofollow, in a new tab.
                            */}
                            {amazonSearch && !results.empty && (
                                <a
                                    href={amazonSearch.url}
                                    target="_blank"
                                    rel="sponsored noopener nofollow"
                                    className={buttonClasses('secondary', 'sm', 'min-w-11 rounded-full sm:min-w-0')}
                                >
                                    <img
                                        src={amazonSearch.icon}
                                        alt=""
                                        width={16}
                                        height={16}
                                        className="h-4 w-4 shrink-0 rounded-sm bg-white"
                                        onError={(e) => {
                                            e.currentTarget.style.display = 'none'
                                        }}
                                    />
                                    <span className="sr-only sm:not-sr-only">{t('search.amazon_compare_with')}</span>
                                </a>
                            )}
                            <ViewToggle view={view} go={go} />

                            <div className="relative" ref={panelRef}>
                                <button
                                    type="button"
                                    className={buttonClasses('secondary', 'sm', 'min-w-11 rounded-full sm:min-w-0')}
                                    aria-expanded={filtersOpen}
                                    aria-controls="search-filters"
                                    title={t('search.filters')}
                                    onClick={() => setFiltersOpen(!filtersOpen)}
                                >
                                    <ToolIcon name="settings" className="h-5 w-5 shrink-0" />
                                    <span className="sr-only sm:not-sr-only">{t('search.filters')}</span>
                                    {chips.length > 0 && (
                                        <span className="rounded-full bg-accent px-1.5 py-0.5 text-xs text-white">
                                            {n(chips.length)}
                                        </span>
                                    )}
                                    <ToolIcon
                                        name="chevron"
                                        className={`hidden h-4 w-4 text-ink-soft transition sm:block ${filtersOpen ? 'rotate-180' : ''}`}
                                    />
                                </button>

                                {/*
                                  A sheet on a phone, a popover on a desktop.

                                  Floating on a desktop so opening it moves nothing:
                                  reading a filter must not cost sight of the
                                  results it filters. On a phone it covers the
                                  screen, and "Show results" is one press away.
                                  Anchored to the button's right edge, because the
                                  button sits at the right of the page.
                                */}
                                <aside
                                    id="search-filters"
                                    aria-label={t('search.filters')}
                                    className={
                                        filtersOpen
                                            ? 'fixed inset-0 z-40 space-y-6 overflow-y-auto bg-cream p-4 pb-24 text-sm lg:absolute lg:top-full lg:right-0 lg:bottom-auto lg:left-auto lg:z-30 lg:mt-2 lg:max-h-[70vh] lg:w-80 lg:space-y-5 lg:rounded-card lg:border lg:border-line lg:bg-card lg:pb-4 lg:shadow-lg'
                                            : 'hidden'
                                    }
                                >
                                    <div className="flex items-center justify-between lg:hidden">
                                        {/* A label, not a heading (2026-11-08): Google reads a page's
                                            headings as what it is about, and "Filters en
                                            sorteren" said nothing about lenovo. */}
                                        <p className="font-medium">{t('search.filters_and_sort')}</p>
                                        <button
                                            type="button"
                                            onClick={() => setFiltersOpen(false)}
                                            className={buttonClasses('primary', 'sm')}
                                        >
                                            {t('search.show_results')}
                                        </button>
                                    </div>

                                    <ResultControls sort={sort} go={go} />

                                    {/*
                                      In the by-store view the shops are columns,
                                      and the menu shows or hides them instead of
                                      filtering the search by shop.
                                    */}
                                    <FilterPanel
                                        facets={facets}
                                        filters={filters}
                                        brandLinks={brandLinks}
                                        go={go}
                                        showShops={view !== 'store'}
                                        shopVisibility={
                                            view === 'store' && lanes
                                                ? {
                                                      shops: lanes.map((lane) => lane.shop),
                                                      hidden: hiddenShops,
                                                      setVisible: (shop, visible) => (visible ? showShop(shop) : hideShop(shop)),
                                                  }
                                                : undefined
                                        }
                                    />
                                </aside>
                            </div>

                            {/* Watch this search. Null on the landing, where there is no term. */}
                            {q && watch && <WatchSearch term={q} watch={watch} compact />}
                        </div>
                    </div>


                    {/*
                      An editor's sentence or two, above the products.

                      Empty unless somebody wrote one — there is no fallback
                      under a region, deliberately. Kept short by the region's
                      own guidance in admin: several hundred words between a
                      shopper and the first card is a worse page for them and for
                      Google alike.
                    */}
                    <PageBlocks blocks={intro} className="mb-5 max-w-3xl" />

                    {/*
                      The narrowing pills: a row that scrolls sideways on a
                      phone, one clipped line from `sm` up. The Filters button
                      shared this row from 2026-10-05 and moved up beside the
                      bell on 2026-11-07 (owner), so the row is only there when
                      there are pills. The Amazon hand-off is a card in the
                      grid since 2026-10-05; see below.
                    */}
                    {terms.length > 0 && (
                        <nav className="mb-4 min-w-0" aria-label={t('search.terms_heading')}>
                            <ul className="-mr-4 flex gap-2 overflow-x-auto pr-4 [scrollbar-width:none] sm:mr-0 sm:max-h-10 sm:flex-wrap sm:overflow-hidden sm:pr-0">
                                {/*
                                  Buttons, not links, and that is the point.

                                  Each pill narrows the search by *adding* its word to the
                                  query. As anchors that was a combinatorial supply of
                                  crawlable URLs, each one logged as a new term in
                                  `search_log`, the table these pills are drawn from. A
                                  button navigates for a visitor and does not exist for a
                                  crawler. The URL is the server's own
                                  (`SearchContext::narrowUrl()`). See docs/features/seo.md.
                                */}
                                {terms.map((item) => (
                                    <li key={item.term} className="shrink-0">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.get(
                                                    item.url,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        preserveState: true,
                                                        onStart: () => setSearching(true),
                                                        onFinish: () => setSearching(false),
                                                    },
                                                )
                                            }
                                            className="inline-flex min-h-10 items-center rounded-full border border-line bg-card px-3 text-sm whitespace-nowrap text-ink-soft transition hover:border-ink hover:text-ink"
                                        >
                                            <span aria-hidden className="mr-1 text-ink-soft">+</span>
                                            {item.term}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    )}

                    {/*
                      Every filter that is on, each with its own way off.

                      The count on the button says that something is filtering;
                      the chips say what, without opening anything. The by-store
                      view leaves shops out here: its shop chips below are the
                      same state, drawn as the shops themselves.
                    */}
                    {chips.filter((chip) => view !== 'store' || chip.kind !== 'merchant').length > 0 && (
                        <ul className="mb-4 flex flex-wrap items-center gap-2" aria-label={t('search.filters')}>
                            {chips
                                .filter((chip) => view !== 'store' || chip.kind !== 'merchant')
                                .map((chip) => (
                                    <li
                                        key={chip.key}
                                        className="inline-flex items-center gap-1 rounded-full border border-line bg-card py-1 pr-1 pl-3 text-sm font-medium"
                                    >
                                        {chip.label}
                                        {chip.href ? (
                                            <Link
                                                href={chip.href}
                                                preserveScroll
                                                aria-label={t('search.intent_remove', { label: chip.label })}
                                                title={t('search.intent_remove', { label: chip.label })}
                                                className="flex h-7 w-7 items-center justify-center rounded-full text-ink-soft hover:bg-line/50 hover:text-ink"
                                            >
                                                <ToolIcon name="close" className="h-3.5 w-3.5 shrink-0" />
                                            </Link>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => chip.remove?.()}
                                                aria-label={t('search.intent_remove', { label: chip.label })}
                                                title={t('search.intent_remove', { label: chip.label })}
                                                className="flex h-7 w-7 items-center justify-center rounded-full text-ink-soft hover:bg-line/50 hover:text-ink"
                                            >
                                                <ToolIcon name="close" className="h-3.5 w-3.5 shrink-0" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            {chips.length > 1 && (
                                <li>
                                    <Link
                                        href={searchHref(market.key, q)}
                                        preserveScroll
                                        className="inline-flex min-h-10 items-center px-2 text-sm text-accent-dark underline hover:text-ink sm:min-h-0"
                                    >
                                        {t('search.clear_filters')}
                                    </Link>
                                </li>
                            )}
                        </ul>
                    )}


                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        {/*
                          Announced, not shown.

                          "Resultaten voor X" repeated the search box directly
                          above it and the heading beside it. It stays in the DOM
                          as a live region because that is how a screen reader
                          learns a new search has landed - `sr-only` keeps the
                          announcement and takes back the line.
                        */}
                        <p className="sr-only" aria-live="polite">
                            {/*
                              No total.

                              "1,284 results" answers a question nobody asked:
                              it describes our catalogue rather than the thing
                              the visitor is looking for, and a big number next
                              to a search that missed reads as a boast. The
                              count is still computed — pagination needs it, and
                              the empty state below branches on it — it is just
                              not something to say out loud.

                              Empty rather than unmounted when there is no term:
                              this is a live region, and it has to be in the DOM
                              before its content changes for a screen reader to
                              announce the first search. It said "Browse the
                              catalogue" there until 2026-09-04, over a grid the
                              visitor is already looking at.
                            */}
                            {q ? t('search.results_for', { term: q }) : ''}
                        </p>

                    </div>


                    {/*
                      No row above the results any more: the Amazon comparison
                      moved into the buttons beside the title on 2026-11-07
                      (owner). The line that counted the lists a term is on went
                      on 2026-10-25: it cost a query over every matching product
                      on each new term, and crawlers ask for a new term every two
                      seconds.
                    */}

                    {/*
                      The reading of a gift search, before its results.

                      Shown back so a wrong reading costs one tap: each chip
                      drops that piece and searches again, and "search the
                      words instead" skips the reading altogether. The results
                      below come from the suggestion engine for this brief.
                    */}
                    {intent && (
                        <div className="mb-6 rounded-card border border-accent/30 bg-accent/5 p-4">
                            {/*
                              One sentence (owner, 2026-10-25): "We geven je
                              cadeau-ideeën met douchegel voor je partner."
                              It replaced "We lezen dit als" over a row of
                              chips. Worded on the server, per language
                              (SearchController::intentSentence()).
                            */}
                            <p className="font-medium">{intent.sentence}</p>
                            <Link href={intent.asWordsUrl} className="mt-3 inline-block text-sm text-accent-dark underline hover:text-ink">
                                {t('search.intent_as_words')}
                            </Link>
                        </div>
                    )}

                    {placeholders ? (
                        <>
                            <SkeletonGrid />
                        </>
                    ) : results.empty ? (
                        <div className="rounded-card border border-line bg-card p-8 text-center">
                            {/*
                              "No results" and "no results with these filters" are
                              very different messages: one asks for a new word,
                              the other for one fewer filter.
                            */}
                            <p className="font-medium">
                                {emptyBecauseOfFilters ? t('search.empty_filters') : t('search.empty', { term: q })}
                            </p>
                            {emptyBecauseOfFilters && (
                                <Link href={searchHref(market.key, q)} className="mt-3 inline-block text-accent underline">
                                    {t('search.clear_filters')}
                                </Link>
                            )}

                            {/*
                              What to try instead, written by an editor.

                              The line above is chrome and always renders; this
                              is the advice under it, and it is the one region
                              shown on a page a crawler is told to ignore —
                              because a dead end is exactly where a human needs a
                              way out.
                            */}
                            <PageBlocks blocks={emptyCopy} className="mx-auto mt-3 max-w-xl" />

                            {/*
                              The dead end is where this link is worth most.
                              We found nothing; the shopper's question is still
                              open, and the next thing they do is try the shop
                              we do not carry. Better they do it from here, with
                              the term already in the URL, than from the address
                              bar.

                              Shown even when the emptiness is our filters'
                              doing: "clear the filters" is the better answer
                              and sits above this, but a shopper who does not
                              want to fiddle with switches still deserves the
                              way out.
                            */}
                            {amazonSearch && (
                                <div className="mx-auto mt-6 max-w-sm text-left">
                                    <AmazonSearchCta link={amazonSearch} label={amazonLabel} />
                                </div>
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
                        <>
                        {/*
                          Hidden columns, and the way back (owner, 2026-10-25:
                          hide a shop on its column instead of the chip row
                          that used to sit above them).
                        */}
                        {lanes.some((lane) => hiddenShops.includes(lane.shop)) && (
                            <p className="mb-3 text-sm text-ink-soft">
                                {(() => {
                                    const count = lanes.filter((lane) => hiddenShops.includes(lane.shop)).length
                                    return count === 1 ? t('search.hidden_shops_one') : t('search.hidden_shops', { count })
                                })()}
                                {' · '}
                                <button type="button" onClick={() => showAllShops()} className="text-accent-dark underline hover:text-ink">
                                    {t('search.show_all_shops')}
                                </button>
                            </p>
                        )}
                        <LaneScroller>
                            {lanes.filter((lane) => !hiddenShops.includes(lane.shop)).map(({ shop, logo, domain, items }) => (
                                /*
                                  Each shop is a card, not a stretch of column.

                                  Stacked as bare bordered rows under a hairline
                                  heading, nothing said where one shop ended and
                                  the next began except the gap between them —
                                  on a strip that scrolls sideways, the reader
                                  loses which column they are in. A single
                                  surface with the shop's name banded across the
                                  top holds the column together as one object,
                                  which is what it is.
                                */
                                <section
                                    key={shop}
                                    className="w-60 shrink-0 snap-start overflow-hidden rounded-card border border-line bg-card sm:w-72 xl:w-80"
                                    aria-label={shop}
                                >
                                    {/*
                                      The shop's mark and its name, and nothing
                                      after them.

                                      The header used to carry the number of
                                      products in the lane, which was never the
                                      number a shopper would read it as: the
                                      lane is capped at store_lane_cap, so a
                                      shop with four hundred matches and one
                                      with exactly eight both said "8". A count
                                      that is really a description of the cap is
                                      worse than no count.

                                      The logo took its place because this is
                                      the one view a shopper scans by shop
                                      rather than by product, and a mark is
                                      recognised across a horizontal scroll
                                      faster than a truncated name — which is
                                      what several of these are at 224px.

                                      Hidden `onError` rather than checked
                                      first: the URL is usually a favicon
                                      guessed from the merchant's domain, so
                                      whether it exists is something only the
                                      browser finds out. `alt=""` because the
                                      name is right beside it — a described logo
                                      would have a screen reader say the shop
                                      twice.
                                    */}
                                    <div className="flex items-center gap-2 border-b border-line bg-cream py-1.5 pr-1.5 pl-3">
                                    <h2 className="flex min-w-0 flex-1 items-center gap-2 py-1 font-medium">
                                        {logo && (
                                            <img
                                                src={logo}
                                                alt=""
                                                loading="lazy"
                                                width={20}
                                                height={20}
                                                className="h-5 w-5 shrink-0 rounded object-contain"
                                                onError={(e) => {
                                                    e.currentTarget.hidden = true
                                                }}
                                            />
                                        )}
                                        <span className="min-w-0">
                                            <span className="block truncate">{shop}</span>
                                            {/*
                                              Where every row in this column goes,
                                              said once (owner, 2026-11-07: "it
                                              should be clear that a link goes to
                                              the store", then "don't mention the
                                              store each time").
                                            */}
                                            {domain && (
                                                <span className="block truncate text-xs font-normal text-ink-soft">
                                                    {t('search.links_to', { shop: domain })}
                                                </span>
                                            )}
                                        </span>
                                    </h2>
                                    {/* Hide this shop's column; the line above the columns brings it back. */}
                                    <button
                                        type="button"
                                        onClick={() => hideShop(shop)}
                                        aria-label={t('search.hide_shop', { shop })}
                                        title={t('search.hide_shop', { shop })}
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/50 hover:text-ink"
                                    >
                                        <ToolIcon name="close" className="h-3.5 w-3.5" />
                                    </button>
                                    </div>

                                    {/*
                                      Rows divided by a hairline rather than
                                      boxed individually: inside a card that
                                      already has an edge, a border per row is
                                      three nested outlines in 224px. The image
                                      gives up most of its height because in a
                                      column it is the title and the price that
                                      get compared.
                                    */}
                                    <ul className="divide-y divide-line">
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
                                                <div className="absolute top-1.5 right-1.5 z-10">
                                                    <SaveToList groupId={g.id} compact />
                                                </div>
                                                {/*
                                                  Straight to this shop (owner,
                                                  2026-11-07): the column is that
                                                  shop's stock, so the row is its
                                                  "Naar de winkel". The same link
                                                  and rel as the product page's
                                                  button: sponsored, because it
                                                  is paid; noopener, a third party
                                                  in a new tab; nofollow, so a
                                                  page of them does not read as
                                                  link-selling.
                                                */}
                                                <a
                                                    href={g.shop.url}
                                                    target="_blank"
                                                    rel="sponsored noopener nofollow"
                                                    onMouseDown={() => reportLaneClick(g.shop)}
                                                    className="group flex gap-3 p-3 pr-14 transition hover:bg-cream"
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
                                                        <span className="line-clamp-2 text-sm text-ink-soft">
                                                            {g.title}
                                                        </span>
                                                        {/*
                                                          The discount trails the
                                                          price as a bare "−20%",
                                                          not the grid's badge
                                                          over the image: the
                                                          lane is 240px wide at least and
                                                          the thumbnail 56px, so
                                                          there is room for a
                                                          suffix and none for
                                                          either a badge or a
                                                          second line. It was
                                                          missing entirely, so the
                                                          same result looked
                                                          full-price in this view
                                                          and reduced in the other
                                                          one.

                                                          The percentage carries
                                                          its own sign rather than
                                                          the translated ":percent%
                                                          off" string, which does
                                                          not fit — so the number
                                                          is announced properly to
                                                          a screen reader, which
                                                          would otherwise read a
                                                          price and an unexplained
                                                          negative.

                                                          The title is the softer
                                                          of the two: in a column
                                                          of one shop's stock the
                                                          price is what is being
                                                          compared, so it is the
                                                          thing that should carry
                                                          the weight.
                                                        */}
                                                        <span className="mt-1.5 flex items-center">
                                                            {/*
                                                              To the shop, as a mark rather than
                                                              words (owner, 2026-11-07: the arrow
                                                              was too plain, the shop is named once
                                                              in the header). Before the price, white
                                                              with a black bag while it is not
                                                              pressed, like the save button beside
                                                              it; red when the row is pointed at.
                                                            */}
                                                            <span
                                                                aria-hidden
                                                                className="mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-white text-ink transition group-hover:border-accent group-hover:bg-accent group-hover:text-white"
                                                            >
                                                                <ToolIcon name="shop" className="h-4 w-4" />
                                                            </span>
                                                            <span className="text-base font-semibold text-ink">
                                                                {g.shop.price === null
                                                                    ? '-'
                                                                    : formatPrice(g.shop.price, market)}
                                                            </span>
                                                            {/* The discount is the cheapest offer's: shown on that price only. */}
                                                            {g.discountPercent !== null && g.shop.price === g.minPrice && (
                                                                <span
                                                                    className="ml-1.5 text-sm font-medium text-accent"
                                                                    aria-label={t('product.off', {
                                                                        percent: g.discountPercent,
                                                                    })}
                                                                >
                                                                    −{n(g.discountPercent)}%
                                                                </span>
                                                            )}
                                                        </span>
                                                        {g.shop.needsPriceTimestamp && (
                                                            <span className="mt-0.5 block text-2xs text-ink-soft">
                                                                {t('product.price_as_of')}
                                                            </span>
                                                        )}
                                                    </span>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </LaneScroller>
                        </>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            {results.items.map((g, i) => (
                                <Fragment key={g.id}>
                                    <ProductCard group={g} brandUrl={g.brand ? brandLinks[g.brand.toLowerCase()] : null} />
                                    {/*
                                      The Amazon hand-off as a card, in the 3rd
                                      to 6th tile (see `amazonAfter`), or after
                                      the last product if there are fewer (owner,
                                      2026-10-05). As a card it reads as one more
                                      place to look. The only Amazon link on the
                                      page: it replaced the toolbar button and,
                                      on a phone, a button under the products.
                                    */}
                                    {amazonSearch && i === amazonAfter && (
                                        <AmazonSearchCard link={amazonSearch} label={amazonLabel} />
                                    )}
                                </Fragment>
                            ))}
                        </div>
                    )}

                    {(results.currentPage > 1 || results.hasMore) && view === 'grid' && (
                        <nav className="mt-8 flex items-center justify-center gap-4 text-sm">
                            <button
                                disabled={results.currentPage <= 1}
                                onClick={() => go({ page: results.currentPage - 1 })}
                                className="rounded border border-line px-3 py-1.5 disabled:opacity-50"
                            >
                                {t('search.previous')}
                            </button>
                            <span className="text-ink-soft">
                                {t('search.page', { current: n(results.currentPage) })}
                            </span>
                            <button
                                disabled={!results.hasMore}
                                onClick={() => go({ page: results.currentPage + 1 })}
                                className="rounded border border-line px-3 py-1.5 disabled:opacity-50"
                            >
                                {t('search.next')}
                            </button>
                        </nav>
                    )}
                </section>
            </div>
            )}

            {/*
              Below the grid, deliberately.

              A shopper came for products; several hundred words between them and
              the first card is a worse page for a human, and Google has been
              explicit for years that it is a worse page for them too. This is
              what gives a crawler something to understand the page as being
              about — the grid itself is almost pure markup.
            */}
            {narrative && <PageNarrative narrative={narrative} />}
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

/**
 * Brand, shop and the two switches — whatever this view has not taken over.
 *
 * `showShops` is false in the store view, where the chip row above the lanes
 * is the shop filter and a second copy in the panel would be two controls for
 * one piece of state.
 */
function FilterPanel({
    facets,
    filters,
    brandLinks,
    go,
    showShops,
    shopVisibility,
}: {
    facets: Facets
    filters: Record<string, unknown>
    brandLinks: Record<string, string>
    go: (next: Record<string, unknown>) => void
    showShops: boolean
    /**
     * The by-store view's columns, each one ticked while it is shown (owner,
     * 2026-10-25). Not a filter on the search: it only hides columns, in this
     * browser, like the × on a column.
     */
    shopVisibility?: { shops: string[]; hidden: string[]; setVisible: (shop: string, visible: boolean) => void }
}) {
    const { t } = useTranslations()

    return (
        <>
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

            {facets.brands.length > 0 && (
                <Facet
                    title={t('search.brand')}
                    collapsible={false}
                    items={facets.brands.map((b) => ({
                        key: b.value,
                        label: b.value,
                        active: ([] as string[]).concat((filters.brand as string[]) ?? []).includes(b.value),
                        // The checkbox filters this page; the arrow goes to the
                        // brand's own page. Two different intentions that a
                        // single control cannot serve — and only the second one
                        // is indexable.
                        href: brandLinks[b.value.toLowerCase()] ?? null,
                    }))}
                    onToggle={(key, active) => {
                        const current = ([] as string[]).concat((filters.brand as string[]) ?? [])
                        go({ brand: active ? current.filter((b) => b !== key) : [...current, key] })
                    }}
                />
            )}

            {showShops && facets.merchants.length > 0 && (
                <Facet
                    title={t('search.shop')}
                    /* Open, like Brand above it. Two facets side by side, one
                       collapsed and one not, reads as an accident rather than a
                       distinction — and a shop list short enough to sit under a
                       heading is not worth a press to reveal. */
                    collapsible={false}
                    items={facets.merchants.map((m) => ({
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

            {/*
              The two switches sit below the facets, not above them.

              Brand and shop are what a shopper is actually looking for in this
              rail; the switches only trim what is already there. Above the
              facets they were the first thing on a collapsed phone panel,
              pushing the lists a screen down.
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
        </>
    )
}

/**
 * One facet list, collapsible or not.
 *
 * Each facet returns up to 15 options, so two of them are thirty rows above the
 * two switches — on a phone, where the whole rail is already behind one
 * disclosure, that is several screens of scrolling to reach a control whose
 * label you can see. Folding a list you are done with puts the other one back
 * in reach.
 *
 * **Brand does not fold.** It is the list a shopper actually came to this rail
 * for, and a control that is one click from being invisible is a worse default
 * for it than a long list is. The fold earns its place on shop, where the
 * question is often already answered.
 *
 * Where it does fold: open by default, never closed. A filter nobody can see is
 * a filter nobody uses, and the rail's job is to show what this page can be
 * narrowed by — the fold is there to put a list away, not to hide it up front.
 * The count of active options rides on the header, so a folded list cannot
 * quietly hold a filter that is changing the results, which is the one way a
 * collapse can genuinely mislead. It is the same bargain the phone-wide filter
 * button already makes.
 *
 * State is deliberately not persisted. It is per-facet, per-visit and cheap to
 * redo; a remembered collapse would greet the next search with a rail that had
 * been folded shut for reasons that no longer apply.
 */
function Facet({
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

    const count = activeCount > 0 && (
        <span className="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-white">
            {n(activeCount)}
        </span>
    )

    /*
      The group's name ("Merk", "Winkel") is a label, not a heading
      (2026-11-08). Search engines read a page's headings as what the page is
      about; the content's own headings ("Lenovo prijzen vergelijken") say
      that, and "Merk" did not. The button keeps aria-expanded, so a screen
      reader still hears a group that opens and closes.
    */
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
                            <input
                                type="checkbox"
                                checked={item.active}
                                onChange={() => onToggle(item.key, item.active)}
                                className="accent-accent"
                            />
                            <span className="flex-1 truncate">{item.label}</span>
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

