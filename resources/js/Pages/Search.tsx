import { Head, Link, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import PageNarrative, { type Narrative } from '../Components/PageNarrative'
import PageBlocks from '../Components/PageBlocks'
import { type BlockPayload } from '../Components/Parts'
import { type GroupCard } from '../Components/ProductCard'
import { PLACEHOLDER_AFTER, SearchStatus, SkeletonGrid, useElapsed } from '../Components/SearchWait'
import SearchLanding, { type Landing } from '../Components/SearchLanding'
import AmazonSearchCta, { type AmazonSearch } from '../Components/AmazonSearchCta'
import { AMAZON_SHOP as AMAZON, mixWithAmazon, type AmazonItem } from '../Components/AmazonProductCard'
import {
    AmazonBanner,
    amazonCardAfter,
    FilterChips,
    FilterPanel,
    FiltersMenu,
    Pagination,
    ResultControls,
    ResultGrid,
    SearchBar,
    TermPills,
    useFilterChips,
    useLiveResults,
    useRestoredView,
    useResultNavigation,
    ViewToggle,
    type Facets,
    type ResultPage as SearchResults,
} from '../Components/SearchChrome'
import SaveToList from '../Components/SaveToList'
import ToolIcon from '../Components/ToolIcon'
import LaneScroller from '../Components/LaneScroller'
import WatchSearch, { type WatchState } from '../Components/WatchSearch'
import type { SharedProps } from '../types'
import { formatBudget, formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import { searchHref, searchTarget } from '../searchUrl'

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
    /**
     * Amazon's own products for the term, without a price (2026-11-23), from a
     * follow-up request of their own: undefined while it runs, null when this page
     * asks none, an empty list when Amazon found nothing. See AmazonSearch.
     */
    amazon?: AmazonItem[] | null
    /**
     * Amazon's storefront (amazon.com.be) when it is a shop in the shop filter (owner,
     * 2026-11-24), null where Amazon has no products to offer this page. Its value in
     * `merchant` is the word "amazon", not an id. See AmazonSearch::FILTER.
     */
    amazonShop?: string | null
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
    amazon,
    amazonShop = null,
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

    /*
     * The shops' products join the page after it is shown (2026-10-25), at
     * the top (owner, the same day: what was found online first). That
     * pushes the cards already shown down once, a layout shift the owner
     * accepted; it only happens on a thin search, where there are few cards
     * to push. The page and "more" come from the shops' answer.
     */
    const livePending = liveShops.length > 0 && live === undefined
    const results = useLiveResults(storedResults, live)

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
     * Amazon's products among ours (owner, 2026-11-23: "mix the amazon products
     * with the other", mixed by relevance in every order and filter). Neither list
     * scores the other, so relevance is each list's own order, interleaved evenly:
     * Amazon's best match lands near our best, its second near our second, and so
     * on down the page. They arrive a moment after the page (a follow-up request
     * of their own), so the cards after each one shift once; the owner chose that
     * over making every search wait for Amazon.
     */
    const amazonHost = amazonSearch ? amazonSearch.host.replace(/^www\./, '') : ''
    /*
     * Only Amazon ticked in the shop filter: Amazon's cards alone. Our results are not
     * filtered by it on the server (Amazon is no merchant of ours), so they are left
     * out here, and so are the page buttons, which page through ours.
     */
    const pickedShops = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)
    const amazonOnly = pickedShops.length === 1 && pickedShops[0] === AMAZON
    const mixed = useMemo(
        () => mixWithAmazon<GroupCard>(amazonOnly ? [] : results.items, amazon ?? []),
        [results, amazon, amazonOnly],
    )

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
    const { go, visit, searching } = useResultNavigation(filters, (next) => searchTarget(market.key, next))

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

    useRestoredView(filters, view, go)

    /*
     * "Search <term> on Amazon too". The server sends no link at all when the
     * URL carries no term (SearchController), so there is always a term to
     * quote by the time this renders.
     */
    const amazonLabel = t('search.amazon_search', { term: q })

    // Which tile the Amazon card takes (amazonCardAfter()).
    const amazonAfter = amazonCardAfter(q, results.items.length)

    const chips = useFilterChips({ filters, facets, go, market, amazonShop, tagFilters })

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

            <SearchBar
                action={base}
                term={term}
                setTerm={setTerm}
                onSubmit={() => go({ q: term })}
                searching={searching}
                placeholder={t('search.placeholder')}
                label={t('search.title')}
                status={<SearchStatus elapsed={elapsed} shops={waitShops} liveShops={livePending ? liveShops : []} notice={live?.notice ?? null} />}
            />

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
                              The "Vergelijk met Amazon" button that sat first in
                              this row (2026-11-07) became the banner above the
                              products on 2026-11-22 (owner).
                            */}
                            <ViewToggle view={view} go={go} />

                            <FiltersMenu count={chips.length}>
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
                                    amazonShop={amazonShop}
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
                            </FiltersMenu>

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
                    <TermPills terms={terms} visit={visit} />

                    {/*
                      Every filter that is on, each with its own way off.

                      The count on the button says that something is filtering;
                      the chips say what, without opening anything. The by-store
                      view leaves shops out here: its shop chips below are the
                      same state, drawn as the shops themselves.
                    */}
                    <FilterChips
                        chips={chips.filter((chip) => view !== 'store' || chip.kind !== 'merchant')}
                        clearHref={searchHref(market.key, q)}
                    />


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
                      The line that counted the lists a term is on went on
                      2026-10-25: it cost a query over every matching product on
                      each new term, and crawlers ask for a new term every two
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

                    {/*
                      Amazon, as a banner just above the products (owner,
                      2026-11-22). It replaced the "Vergelijk met Amazon" button
                      beside the title, which had replaced a row here on
                      2026-11-07; the card inside the grid stays. Two links: the
                      sentence searches the term, Amazon's mark at the right
                      opens the storefront's front page. Both tagged by the
                      server, both plain anchors (never through our redirector,
                      Amazon's rule), sponsored and nofollow, in a new tab. The
                      mark is Amazon's own favicon from Amazon's site, not a copy
                      of its logo: the Associates trademark rules allow the logo
                      only in the forms Amazon supplies.

                      Results pages only. An empty result has its own Amazon
                      button below the "nothing found" text, and a gift search
                      gets no Amazon link from the server at all.
                    */}
                    {amazonSearch && !results.empty && !placeholders && <AmazonBanner link={amazonSearch} term={q} />}

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
                            {/*
                              Amazon's column (owner, 2026-11-23: "add also amazon
                              column to the by store view"), first, as on the
                              product page. Drawn like the shops' columns: the
                              storefront as the name, Amazon's mark, a row per
                              product. Where the others show a price, each row says
                              "Bekijk op Amazon": Amazon's price may not be shown
                              without its own API. No hide button and no save
                              button: Amazon's products are not in our catalogue.
                            */}
                            {amazon && amazon.length > 0 && amazonSearch && (
                                <section
                                    className="w-60 shrink-0 snap-start overflow-hidden rounded-card border border-line bg-card sm:w-72 xl:w-80"
                                    aria-label={amazonHost}
                                >
                                    <div className="flex items-center gap-2 border-b border-line bg-cream py-1.5 pr-1.5 pl-3">
                                        <h2 className="flex min-w-0 flex-1 items-center gap-2 py-1 font-medium">
                                            <img
                                                src={amazonSearch.icon}
                                                alt=""
                                                loading="lazy"
                                                width={20}
                                                height={20}
                                                className="h-5 w-5 shrink-0 rounded bg-white object-contain"
                                                onError={(e) => {
                                                    e.currentTarget.hidden = true
                                                }}
                                            />
                                            <span className="min-w-0">
                                                <span className="block truncate">{amazonHost}</span>
                                                <span className="block truncate text-xs font-normal text-ink-soft">
                                                    {t('search.links_to', { shop: amazonHost })}
                                                </span>
                                            </span>
                                        </h2>
                                    </div>
                                    <ul className="divide-y divide-line">
                                        {amazon.map((item) => (
                                            <li key={item.asin} className="relative">
                                                <a
                                                    href={item.url}
                                                    target="_blank"
                                                    rel="sponsored noopener nofollow"
                                                    className="group flex gap-3 p-3 transition hover:bg-cream"
                                                >
                                                    {item.image && (
                                                        <img src={item.image} alt="" className="h-14 w-14 shrink-0 object-contain" loading="lazy" />
                                                    )}
                                                    <span className="min-w-0 flex-1">
                                                        <span className="line-clamp-2 text-sm text-ink-soft">{item.title}</span>
                                                        <span className="mt-1.5 flex items-center">
                                                            <span
                                                                aria-hidden
                                                                className="mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-line bg-white text-ink transition group-hover:border-accent group-hover:bg-accent group-hover:text-white"
                                                            >
                                                                <ToolIcon name="shop" className="h-4 w-4" />
                                                            </span>
                                                            <span className="text-sm font-semibold text-ink">{t('product.check_on_amazon')}</span>
                                                        </span>
                                                    </span>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
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
                        <ResultGrid
                            entries={mixed}
                            amazonHost={amazonHost}
                            brandUrl={(g) => (g.brand ? (brandLinks[g.brand.toLowerCase()] ?? null) : null)}
                            amazonCard={amazonSearch ? { link: amazonSearch, label: amazonLabel, after: amazonAfter } : null}
                        />
                    )}

                    {view === 'grid' && !amazonOnly && <Pagination page={results} go={go} />}
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
