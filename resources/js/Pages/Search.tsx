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
import StoreLanes, { type Lane, useHiddenShops, useLiveLanes } from '../Components/StoreLanes'
import WatchSearch, { type WatchState } from '../Components/WatchSearch'
import type { SharedProps } from '../types'
import { formatBudget } from '../types'
import { useTranslations } from '../useTranslations'
import { searchHref, searchTarget } from '../searchUrl'



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
    const lanes = useLiveLanes(storedLanes, storedResults, live)


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
    const { hidden: hiddenShops, hide: hideShop, show: showShop, showAll: showAllShops } = useHiddenShops()

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
                        <StoreLanes
                            lanes={lanes}
                            hidden={hiddenShops}
                            hide={hideShop}
                            showAll={showAllShops}
                            amazon={amazon}
                            amazonSearch={amazonSearch}
                        />
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
