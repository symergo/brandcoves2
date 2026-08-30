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
    brands: { value: string }[]
    merchants: { id: number; name: string; logo: string | null }[]
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
    lanes: { shop: string; logo: string | null; items: GroupCard[] }[] | null
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

            {/*
              The by-store view takes the whole width.

              Every other view is a rail plus a grid, and the grid reflows: take
              16rem off it and the cards get narrower. Lanes do not reflow —
              they are fixed-width columns that scroll sideways — so the rail
              costs a whole shop's column, on precisely the view whose entire
              point is holding several shops side by side. So this view drops
              the rail and gets its shop filter as a row of chips instead. See
              ShopChips.
            */}
            <div className={`mt-8 grid gap-8 ${view === 'store' ? '' : 'lg:grid-cols-[16rem_1fr]'}`}>
                {view === 'store' ? null : (
                    <>
                        {/*
                          Collapsed on mobile, always open from `lg`.

                          Stacked above the results, the filter rail pushed every
                          product off a phone screen — the page opened on a column
                          of switches and you had to scroll past all of them to
                          see whether the search had found anything.
                          `activeFilterCount` goes on the button so a collapsed
                          panel cannot hide that something is filtering.
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
                            <FilterPanel
                                facets={facets}
                                filters={filters}
                                brandLinks={brandLinks}
                                go={go}
                                showShops
                            />
                        </aside>
                    </>
                )}

                {/*
                  `min-w-0`, or the whole page scrolls sideways.

                  A grid item's default `min-width: auto` is its content's
                  intrinsic width, and the lane strip's content is every column
                  laid end to end. So the track grew to hold all of them, the
                  strip never became narrower than its contents, and its
                  `overflow-x-auto` had nothing to scroll — the body did
                  instead. Measured on a 390px viewport before the fix:
                  `document.body.scrollWidth` 1204.
                */}
                <section className="min-w-0">
                    {view === 'store' && (
                        /*
                          The shops are the control.

                          A rail of shop checkboxes was answering, in a different
                          place and a different idiom, exactly the question the
                          columns beneath it already pose. Here the chip and the
                          column it governs are the same object, carry the same
                          mark and sit a few pixels apart — so "drop this shop"
                          is one click on the thing you want rid of, rather than
                          a hunt through a list that looks nothing like it.

                          Everything that is not the shop axis — brand, and the
                          two switches — stays behind the popover on the right,
                          because none of it belongs to this view in particular.
                        */
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <ShopChips
                                shops={facets.merchants}
                                selected={([] as string[])
                                    .concat((filters.merchant as string[]) ?? [])
                                    .map(String)}
                                onChange={(next) => go({ merchant: next.length > 0 ? next : null })}
                            />

                            <div className="relative ml-auto">
                                <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-full border border-line bg-card px-3 py-1.5 text-sm transition hover:border-ink"
                                    aria-expanded={filtersOpen}
                                    aria-controls="search-filters"
                                    onClick={() => setFiltersOpen(!filtersOpen)}
                                >
                                    <span>{t('search.filters')}</span>
                                    {activeFilterCount > 0 && (
                                        <span className="rounded-full bg-accent px-1.5 py-0.5 text-xs text-white">
                                            {n(activeFilterCount)}
                                        </span>
                                    )}
                                    <span aria-hidden className="text-xs text-ink-soft">
                                        {filtersOpen ? '▲' : '▼'}
                                    </span>
                                </button>

                                {/*
                                  A popover, not a block.

                                  Opened as a block it pushed the whole lane
                                  strip down the page, so reading a filter cost
                                  you sight of the thing you were filtering.
                                  Floating, the columns never move.
                                */}
                                <aside
                                    id="search-filters"
                                    aria-label={t('search.filters')}
                                    className={`absolute right-0 top-full z-20 mt-2 w-72 space-y-5 rounded-lg border border-line bg-card p-4 text-sm shadow-lg ${filtersOpen ? 'block' : 'hidden'}`}
                                >
                                    <FilterPanel
                                        facets={facets}
                                        filters={filters}
                                        brandLinks={brandLinks}
                                        go={go}
                                        showShops={false}
                                    />
                                </aside>
                            </div>
                        </div>
                    )}

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
                            {/*
                              No total.

                              "1,284 results" answers a question nobody asked:
                              it describes our catalogue rather than the thing
                              the visitor is looking for, and a big number next
                              to a search that missed reads as a boast. The
                              count is still computed — pagination needs it, and
                              the empty state below branches on it — it is just
                              not something to say out loud.
                            */}
                            {q ? t('search.results_for', { term: q }) : t('search.browse')}
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
                        <div className="-mx-1 flex snap-x gap-4 overflow-x-auto px-1 pb-3">
                            {lanes.map(({ shop, logo, items }) => (
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
                                    className="w-56 shrink-0 snap-start overflow-hidden rounded-lg border border-line bg-card sm:w-64"
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
                                    <h2 className="flex items-center gap-2 border-b border-line bg-cream px-3 py-2.5 font-medium">
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
                                        <span className="truncate">{shop}</span>
                                    </h2>

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
                                                <a
                                                    href={`/${market.key}/p/${g.id}/${g.slug}`}
                                                    className="flex gap-3 p-3 pr-14 transition hover:bg-cream"
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
                                                          lane is 224px wide and
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
                                                        <span className="mt-1.5 block">
                                                            <span className="text-base font-semibold text-ink">
                                                                {g.minPrice === null
                                                                    ? '-'
                                                                    : formatPrice(g.minPrice, market)}
                                                            </span>
                                                            {g.discountPercent !== null && (
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

/**
 * Brand, shop and the two switches — whatever this view has not taken over.
 *
 * Extracted so the rail and the by-store popover render the same controls from
 * one definition. `showShops` is false in the store view, where the chip row
 * above the lanes is the shop filter and a second copy in the popover would be
 * two controls for one piece of state.
 */
function FilterPanel({
    facets,
    filters,
    brandLinks,
    go,
    showShops,
}: {
    facets: Facets
    filters: Record<string, unknown>
    brandLinks: Record<string, string>
    go: (next: Record<string, unknown>) => void
    showShops: boolean
}) {
    const { t } = useTranslations()

    return (
        <>
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
 * The shop filter for the by-store view, as the shops themselves.
 *
 * ## Why "no selection" draws every chip as active
 *
 * The underlying filter is a multi-select that means *nothing* when empty, and
 * an empty filter shows every shop. Drawn literally that gives a row of hollow
 * chips above a strip of visible columns, which reads as "none of these are
 * on" directly above the evidence that all of them are. So the chips render
 * what is *true of the page* — every shop shown — rather than what is in the
 * query string.
 *
 * That makes the first click ambiguous, and it is resolved the way the row
 * reads: clicking a shop while everything is shown means "only this one", not
 * "all except this one". The alternative would have to write every other shop
 * into the URL, which also silently excludes any shop that appears later.
 * Deselecting the last one returns to all, so there is no state in which the
 * lanes are empty because of this control alone.
 *
 * `All shops` is a chip rather than a "clear" link because it is the same kind
 * of thing as its neighbours: one of the row's mutually reachable states.
 */
function ShopChips({
    shops,
    selected,
    onChange,
}: {
    shops: { id: number; name: string; logo: string | null }[]
    selected: string[]
    onChange: (next: string[]) => void
}) {
    const { t } = useTranslations()

    if (shops.length === 0) {
        return null
    }

    const filtering = selected.length > 0

    /*
     * Three states, not two.
     *
     * "Shown because nothing is filtered" and "shown because you picked it" are
     * both true of the column, but they are not the same claim, and drawing
     * them the same way made the resting page a row of seven solid pills — a
     * lot of ink to say "no filter is applied", and it left `All shops` with no
     * way to look like the state it is. So only a deliberate choice gets the
     * solid treatment: at rest the shops sit quiet and readable, and `All
     * shops` is the one filled chip.
     */
    const chip = (state: 'on' | 'off' | 'resting') =>
        `flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition ${
            {
                on: 'border-ink bg-ink text-cream',
                resting: 'border-line bg-card text-ink hover:border-ink',
                off: 'border-line bg-transparent text-ink-soft hover:border-ink hover:text-ink',
            }[state]
        }`

    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('search.shop')}>
            <button
                type="button"
                className={chip(filtering ? 'off' : 'on')}
                onClick={() => onChange([])}
            >
                {t('search.all_shops')}
            </button>

            {shops.map((shop) => {
                const id = String(shop.id)
                const active = !filtering || selected.includes(id)

                return (
                    <button
                        key={id}
                        type="button"
                        aria-pressed={active}
                        className={chip(!filtering ? 'resting' : active ? 'on' : 'off')}
                        /*
                          The label says what the click does, because the chip
                          itself only says which shop it is. Without it a screen
                          reader hears "Coolblue, pressed" and has to guess.
                        */
                        aria-label={
                            active && filtering
                                ? t('search.hide_shop', { shop: shop.name })
                                : t('search.only_shop', { shop: shop.name })
                        }
                        onClick={() =>
                            onChange(
                                !filtering
                                    ? [id]
                                    : selected.includes(id)
                                      ? selected.filter((s) => s !== id)
                                      : [...selected, id],
                            )
                        }
                    >
                        {shop.logo && (
                            <img
                                src={shop.logo}
                                alt=""
                                loading="lazy"
                                width={16}
                                height={16}
                                className="h-4 w-4 shrink-0 rounded-sm object-contain"
                                onError={(e) => {
                                    e.currentTarget.hidden = true
                                }}
                            />
                        )}
                        <span>{shop.name}</span>
                    </button>
                )
            })}
        </div>
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

    return (
        <div>
            {collapsible ? (
                <h2>
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
                        <span aria-hidden className="text-xs text-ink-soft">
                            {open ? '▲' : '▼'}
                        </span>
                    </button>
                </h2>
            ) : (
                <h2 className="py-1 font-medium">
                    {title}
                    {count}
                </h2>
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
