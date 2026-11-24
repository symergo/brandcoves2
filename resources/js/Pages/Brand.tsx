import { Head, Link, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import PageNarrative, { type Narrative } from '../Components/PageNarrative'
import PageBlocks from '../Components/PageBlocks'
import { type BlockPayload } from '../Components/Parts'
import LiveOfferCard, { type LiveOffer } from '../Components/LiveOfferCard'
import { type GroupCard } from '../Components/ProductCard'
import { PLACEHOLDER_AFTER, SearchStatus, SkeletonGrid, useElapsed } from '../Components/SearchWait'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import AmazonSearchCta, { type AmazonSearch } from '../Components/AmazonSearchCta'
import { AMAZON_SHOP, mixWithAmazon, type AmazonItem } from '../Components/AmazonProductCard'
import StoreLanes, { type Lane, useHiddenShops, useLiveLanes } from '../Components/StoreLanes'
import WatchSearch, { type WatchState } from '../Components/WatchSearch'
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
    type ResultPage,
} from '../Components/SearchChrome'

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
    /** Words that recur across this brand's products, each narrowing this page. */
    terms: { term: string; url: string }[]
    filters: Record<string, unknown>
    sort: string
    view: 'grid' | 'store'
    facets: Facets
    /**
     * One page and whether another follows. No total and no last page
     * (owner's decision, 2026-09-27): nothing counts the matches, so the
     * page shows neither a number nor a word standing in for one.
     */
    results: ResultPage
    /**
     * The live shops being asked after the page was shown (a thin brand page,
     * 2026-10-25), by name; empty when nothing is pending. `live` arrives
     * from that follow-up request as a deferred prop: undefined until then,
     * null when there was nothing to ask.
     */
    liveShops: string[]
    /** The market's biggest shops, named in the line under the search bar while the page loads. */
    waitShops?: string[]
    live?: { results: ResultPage; lanes?: Lane[] | null; added: number; notice: string | null } | null
    /** The shops' columns in the by-store view; null in the grid. */
    lanes?: Lane[] | null
    /** The follow bell, on the brand plus its words as a search term. See SearchWatch. */
    watch?: WatchState | null
    /**
     * Offers from a source we may show but not store.
     *
     * Fetched during this request and never written down, so they are absent
     * from `results` by construction and cannot be a ProductCard: there is no
     * group behind them, and therefore no offer count, no shop count and no
     * discount measured against a previous price.
     */
    liveOffers: LiveOffer[]
    coves: { title: string; intro: string | null; url: string }[]
    related: { name: string; url: string }[]
    /**
     * The tagged Amazon search for this brand, narrowed by any words.
     * Null where the market has no Associates tag — see AmazonSearchLink.
     */
    amazonSearch: AmazonSearch | null
    /**
     * Amazon's products for the brand, without a price (owner, 2026-11-24), from a
     * follow-up request: undefined while it runs, null when the page asks none.
     */
    amazon?: AmazonItem[] | null
    /** Amazon's storefront when it is a shop in the shop filter; see Search.tsx. */
    amazonShop?: string | null
    /** The long copy under the grid. Null on any variant that is noindex. */
    narrative: Narrative | null
    intro: BlockPayload[] | null
    emptyCopy: BlockPayload[] | null
}

/**
 * A brand page: a search with the brand preselected, plus editorial and links.
 *
 * It looks and works like the search page (owner, 2026-11-24: "The brand page
 * should look the same as the search page (same filter ux design, searchfield)"),
 * built from the same parts (Components/SearchChrome): the search field, which
 * searches within the brand and keeps this address; the Filters button with its
 * popover and sheet; the chips; the narrowing pills; the grid with Amazon mixed in.
 * What it keeps of its own: the breadcrumb, the brand heading, the other brands
 * (first in the Filters panel), the articles that mention the brand and the long
 * copy below the grid.
 *
 * The brand facet is deliberately absent: filtering a Sony page by brand is a
 * control with one option. The by-store view, the view button and the follow bell
 * came the same day ("I don't see a grid/store button? And no follow icon"); the
 * bell follows the brand plus its words as a search term.
 */
export default function Brand({
    brand,
    terms,
    filters,
    sort,
    view,
    facets,
    results: storedResults,
    lanes: storedLanes = null,
    watch = null,
    liveShops = [],
    waitShops = [],
    live,
    liveOffers,
    amazonSearch,
    amazon,
    amazonShop = null,
    coves,
    related,
    narrative,
    intro,
    emptyCopy,
}: Props) {
    const { market, seoTitle } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}/brand/${brand.slug}`

    /*
     * The field holds the words narrowing this page. `q` on a brand page is a search
     * within the brand: the pills add to it, the field replaces it, and an empty
     * field is the brand's own page again.
     */
    const q = String(filters.q ?? '')
    const [term, setTerm] = useState(q)
    useEffect(() => {
        setTerm(q)
    }, [q])

    const { go, visit, searching } = useResultNavigation(filters, (next) => {
        // The brand is in the path, not the query string: sending it as a filter as
        // well would produce /brand/sony?brand[]=Sony, the same page at a second URL.
        delete next.brand

        return { path: base, query: next }
    })

    const livePending = liveShops.length > 0 && live === undefined
    const results = useLiveResults(storedResults, live)
    const lanes = useLiveLanes(storedLanes, storedResults, live)
    const { hidden: hiddenShops, hide: hideShop, show: showShop, showAll: showAllShops } = useHiddenShops()
    useRestoredView(filters, view, go)
    const elapsed = useElapsed(searching)
    const placeholders = (searching && elapsed >= PLACEHOLDER_AFTER) || (livePending && storedResults.empty)

    /*
     * Amazon's products among the brand's, as on the search page. Only Amazon ticked
     * in the shop filter: Amazon's alone, without the page buttons, which page
     * through ours.
     */
    const pickedShops = ([] as string[]).concat((filters.merchant as string[]) ?? []).map(String)
    const amazonOnly = pickedShops.length === 1 && pickedShops[0] === AMAZON_SHOP
    const amazonHost = amazonShop ?? (amazonSearch ? amazonSearch.host.replace(/^www\./, '') : '')
    const mixed = useMemo(() => mixWithAmazon<GroupCard>(amazonOnly ? [] : results.items, amazon ?? []), [results, amazon, amazonOnly])

    // What the Amazon link is labelled with, and what the server searched for: the
    // brand plus the words narrowing the page. Kept in step with BrandController by
    // construction: same two pieces, same order.
    const brandTerm = [brand.name, ...q.split(' ').filter(Boolean)].join(' ')
    const amazonLabel = t('search.amazon_search', { term: brandTerm })
    const amazonAfter = amazonCardAfter(brandTerm, results.items.length)

    const chips = useFilterChips({ filters, facets, go, market, amazonShop, withBrands: false })
    // The by-store view leaves shops out of the chips: its columns are the same state.
    const shownChips = chips.filter((chip) => view !== 'store' || chip.kind !== 'merchant')

    return (
        <>
            {/*
              The server's title, which drops the modifier for a long brand name.
              Rebuilding it from the key here would lose that guard and put the
              <title> and the og:title out of step. See BrandController.
            */}
            <Head title={seoTitle ?? brand.name} />

            <nav aria-label="Breadcrumb" className="mb-3 text-sm text-ink-soft">
                <Link href={`/${market.key}/brands`} className="hover:text-accent">
                    {t('brand.crumb')}
                </Link>
                <span aria-hidden> / </span>
                <span>{brand.name}</span>
            </nav>

            <SearchBar
                action={base}
                term={term}
                setTerm={setTerm}
                onSubmit={() => go({ q: term })}
                searching={searching}
                placeholder={t('brand.search_placeholder', { brand: brand.name })}
                label={t('brand.search_placeholder', { brand: brand.name })}
                status={<SearchStatus elapsed={elapsed} shops={waitShops} liveShops={livePending ? liveShops : []} notice={live?.notice ?? null} />}
            />

            <section className="mt-8 min-w-0">
                {/* Title and Filters on one row, as on the search page. */}
                <div className="mb-4 flex items-start justify-between gap-3">
                    {/* What the page holds, not only whose (2026-11-24): "Producten van
                        BlueBuilt" tells a reader and a search engine more than the bare
                        name, which is in the breadcrumb right above. */}
                    <h1 className="min-w-0 text-xl font-semibold tracking-tight sm:text-3xl">{t('brand.products_heading', { brand: brand.name })}</h1>

                    <div className="flex shrink-0 items-start gap-2">
                        <ViewToggle view={view} go={go} />

                        <FiltersMenu count={chips.length}>
                            <ResultControls sort={sort} go={go} />

                            <FilterPanel
                                facets={facets}
                                filters={filters}
                                go={go}
                                showBrands={false}
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
                                before={
                                    /*
                                      First in the panel, above the shops. It is the only
                                      thing in here that leaves the page, and it is what
                                      a reader who has decided this is the wrong brand
                                      is looking for. The shops narrow a set the visitor
                                      has already accepted; this offers a different set.
                                    */
                                    related.length > 0 && (
                                        <div>
                                            <p className="py-1 font-medium">{t('brand.related_heading')}</p>
                                            <ul className="mt-2 space-y-1">
                                                {related.map((other) => (
                                                    <li key={other.url}>
                                                        <Link href={other.url} className="flex gap-2 hover:text-accent">
                                                            <span className="flex-1 truncate">{other.name}</span>
                                                            <span aria-hidden className="text-xs text-ink-soft">
                                                                →
                                                            </span>
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )
                                }
                            />
                        </FiltersMenu>

                        {watch && <WatchSearch term={brandTerm} watch={watch} compact />}
                    </div>
                </div>

                {/*
                  An editor's sentence about this brand, above the products. This page
                  opened with templated statistics until 2026-08-10, when they were
                  removed for being arithmetic in sentences, the same on every brand.
                  Nothing generates what goes here and it ships empty.
                */}
                <PageBlocks blocks={intro} className="mb-5 max-w-3xl" />

                {/*
                  The words that come up across this brand's products. They narrow
                  this page rather than leaving for a plain search: somebody on a
                  Kärcher page reading "hogedrukreiniger" is asking which Kärchers
                  those are, not to be shown Bosch.
                */}
                <TermPills terms={terms} visit={visit} />

                <FilterChips chips={shownChips} clearHref={q ? `${base}?q=${encodeURIComponent(q)}` : base} />

                {amazonSearch && !results.empty && !placeholders && <AmazonBanner link={amazonSearch} term={brandTerm} />}

                {placeholders ? (
                    <SkeletonGrid />
                ) : results.empty ? (
                    <div className="rounded-card border border-line bg-card p-8 text-center">
                        <p className="font-medium">{t('brand.empty', { brand: brand.name })}</p>

                        {/* The editor's advice under the line above. Shown even on a
                            noindex sub-search, because it is for the reader. */}
                        <PageBlocks blocks={emptyCopy} className="mx-auto mt-2 max-w-xl" />

                        {/* The dead end is where this link is worth most. */}
                        {amazonSearch && (
                            <div className="mx-auto mt-6 max-w-sm text-left">
                                <AmazonSearchCta link={amazonSearch} label={amazonLabel} />
                            </div>
                        )}
                    </div>
                ) : view === 'store' && lanes ? (
                    <StoreLanes lanes={lanes} hidden={hiddenShops} hide={hideShop} showAll={showAllShops} amazon={amazon} amazonSearch={amazonSearch} />
                ) : (
                    // No brandUrl: every card on this page is this brand, so a link
                    // back to the page you are on is noise.
                    <ResultGrid
                        entries={mixed}
                        amazonHost={amazonHost}
                        amazonCard={amazonSearch ? { link: amazonSearch, label: amazonLabel, after: amazonAfter } : null}
                    />
                )}

                {view === 'grid' && !amazonOnly && <Pagination page={results} go={go} />}

                {/*
                  Offers we may show but not keep, in their own section: every card
                  above is a physical product with every shop's price under it,
                  because those offers are stored and grouped. These are not grouped
                  with anything, so showing them as comparison cards would promise a
                  comparison that was never made. Each live card renders its own
                  price note, a condition of display.
                */}
                {liveOffers.length > 0 && (
                    <section className="mt-12" aria-labelledby="brand-live">
                        <h2 id="brand-live" className="text-xl font-semibold tracking-tight">
                            {t('brand.live_heading', { brand: brand.name })}
                        </h2>
                        <p className="mt-1 text-sm text-ink-soft">{t('brand.live_note')}</p>

                        <ul className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            {liveOffers.map((offer) => (
                                <LiveOfferCard key={offer.url} offer={offer} />
                            ))}
                        </ul>
                    </section>
                )}
            </section>

            {/*
              The articles that mention the brand, under the products now that the
              results take the whole width (they sat under the filter rail). An
              article was written once, about a real question, so this is where any
              voice on a brand page comes from, as a link out of the page.
            */}
            {coves.length > 0 && (
                <section className="mt-12" aria-labelledby="brand-coves">
                    <h2 id="brand-coves" className="text-lg font-semibold tracking-tight">
                        {t('brand.coves_heading', { brand: brand.name })}
                    </h2>
                    <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link href={cove.url} className="block h-full rounded-card border border-line bg-card p-4 transition hover:border-ink">
                                    <h3 className="text-sm font-medium">{cove.title}</h3>
                                    {cove.intro && <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              Full width, the last thing on the page, and prose: the long copy, drawn
              from the editable bank. See BrandController::narrative().
            */}
            {narrative && <PageNarrative narrative={narrative} />}
        </>
    )
}
