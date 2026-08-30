import { Head, Link, usePage } from '@inertiajs/react'
import type { CoveSceneKey } from '../Components/CoveIllustration'
import CoveIllustration from '../Components/CoveIllustration'
import CoveSubscribe from '../Components/CoveSubscribe'
import HomeIllustration from '../Components/HomeIllustration'
import ListIllustration, { type ListSceneKey } from '../Components/ListIllustration'
import SaveToList from '../Components/SaveToList'
import ScanButton from '../Components/ScanButton'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    title: string
    intro: string | null
    url: string
    searches: number
}

interface Props {
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    gifting: {
        lists: number
        people: number
        santaGroups: number
        /**
         * The visitor's own registry, or null when they have none. Carries the
         * occasion and the date and deliberately no claim state — this is the
         * owner's front page (invariant #4).
         */
        registry: {
            title: string
            occasion: string
            date: string | null
            /** The recipient, on a list about somebody else. Null on your own. */
            for: string | null
            url: string
        } | null
        urls: { gift: string; lists: string; santa: string }
    }
    coves: Cove[]
    recentSearches: { term: string; url: string; images: string[] }[]
}

export default function Home({ today, gifting, coves, recentSearches }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    /*
     * Day and month, plus the year only when it is not this one.
     *
     * A registry is often dated a long way out — a wedding gets booked eighteen
     * months ahead — and "14 Jun" for a date in 2027 is wrong in the one
     * direction that matters here. Adding the year to every date instead makes
     * the ordinary case heavier than it needs to be.
     */
    function registryDate(iso: string): string {
        const date = new Date(iso)
        const thisYear = date.getFullYear() === new Date().getFullYear()

        return new Intl.DateTimeFormat(market.hrefLang, {
            day: 'numeric',
            month: 'short',
            ...(thisYear ? {} : { year: 'numeric' }),
        }).format(date)
    }

    return (
        <>
            <Head title={t('home.title')} />

            {/*
              Wider than the body copy that follows it.

              Each headline line is a whole sentence, and at 5xl in a 2xl column
              both of them wrapped — which breaks the rhythm the two lines exist
              to create. The paragraph keeps its own narrower measure, because
              prose at this width is genuinely harder to read.
            */}
            <section className="max-w-5xl">
                {/*
                  The drawing sits beside the whole pitch, not just the
                  paragraph, so the headline and the search box stay on one
                  optical column rather than being stepped around it.

                  Hidden below md, deliberately. Stacked on a phone it costs
                  roughly a screen of height and pushes the search field — the
                  one thing this page wants pressed — under the fold, to say
                  nothing that a decorative drawing already says in words above
                  it.
                */}
                <div className="flex flex-col gap-10 md:flex-row md:items-center md:gap-12">
                    <div className="min-w-0 flex-1">
                        <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            {t('home.headline_1')}
                            <br />
                            {t('home.headline_2')}
                        </h1>
                        {/*
                          `text-lg` only from `sm`. At phone width this
                          paragraph ran to seven lines of near-heading type and
                          took the whole first screen on its own, so the search
                          field — the one thing this page wants pressed — sat
                          under the fold behind a wall of prose.
                        */}
                        <p className="mt-5 max-w-2xl text-ink-soft sm:text-lg">{t('home.intro')}</p>

                        {/*
                          A search field, where the Gift Finder button used to
                          be.

                          The Whisperer is not good enough to be the first thing
                          the site asks you to trust — it is still reachable from
                          the Gift Cove, and it comes back here when it earns the
                          place.

                          A real <form method="get"> rather than a controlled
                          input and a router call: it submits without JavaScript,
                          the browser offers previous searches, and Enter works
                          the way it does in every other search box the visitor
                          has ever used.
                        */}
                        <form
                            action={`${base}/search`}
                            method="get"
                            role="search"
                            /*
                              Wraps on a phone, one row from `sm`.

                              Three controls on one 390px line left the field
                              about 200px wide and clipped its own placeholder
                              mid-word — "Koptelefoon, koffiem…" — so the
                              example that tells a visitor what this box accepts
                              was the part cut off. The field now takes its own
                              line and the two buttons share the next.
                            */
                            className="mt-8 flex max-w-xl flex-wrap gap-2"
                        >
                            <input
                                type="search"
                                name="q"
                                aria-label={t('home.search_label')}
                                placeholder={t('home.search_placeholder')}
                                className="w-full min-w-0 rounded-lg border border-line bg-card px-4 py-3 text-ink placeholder:text-ink-soft/70 focus:border-ink focus:outline-none sm:w-auto sm:flex-1"
                            />
                            {/*
                              The camera, beside the field, on the first screen
                              of the site.

                              Someone standing in a shop with the product in
                              their hand has the highest intent this site ever
                              sees, and until now the only way to reach the
                              scanner was to run a search they did not want, in
                              order to find the button on the results page. The
                              weight is not the reason it was absent either: the
                              wasm decoder is fetched inside the click handler,
                              so a home page nobody scans from still loads
                              nothing extra.
                            */}
                            <ScanButton className="shrink-0 rounded-lg border border-line bg-card px-4 py-3 text-ink transition hover:border-ink" />
                            <button
                                type="submit"
                                className="flex-1 rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark sm:flex-none"
                            >
                                {t('nav.search')}
                            </button>
                        </form>

                        <p className="mt-3 text-sm text-ink-soft">
                            <Link href={`${base}/search-help`} className="underline hover:text-accent">
                                {t('search_help.link')}
                            </Link>
                        </p>
                    </div>

                    <HomeIllustration className="hidden w-72 shrink-0 text-ink-soft md:block lg:w-80" />
                </div>
            </section>

            {/*
              What other people have been looking for.

              Pictures rather than a list of words: a row of terms reads as a
              tag cloud, and a tag cloud is something visitors have learned to
              skip. The images are the invitation and the term is the label.

              Every card links to the **search**, not to a product. These are
              not recommendations — nothing here has been chosen, ranked or
              checked, and sending someone to one product implies it was. The
              search results are the honest destination, and they are also where
              the visitor can immediately do something else.

              No prices, deliberately. A price on a picture that was resolved up
              to an hour ago is a number that can be wrong, and a wrong price is
              worse than no price. Prices belong on the product page, where they
              are read live.

              Precomputed hourly by RefreshRecentSearches; absent until the
              first run, and absent on a market with no search history, which is
              why the whole band is conditional.
            */}
            {recentSearches.length > 0 && (
                <section className="mt-12" aria-labelledby="recent-heading">
                    <h2 id="recent-heading" className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                        {t('home.recent_heading')}
                    </h2>

                    {/*
                      Three across at lg, stacked below it. No two-column step:
                      with three cards it would always leave one orphan on its
                      own row, and each card carries four 40px thumbnails that
                      have nowhere to shrink to in a narrow column.
                    */}
                    <ul className="mt-4 grid gap-3 lg:grid-cols-3">
                        {recentSearches.map((recent) => (
                            <li key={recent.term}>
                                <Link
                                    href={recent.url}
                                    className="flex items-center gap-3 rounded-card border border-line bg-card p-3 transition hover:border-ink"
                                >
                                    <span className="flex shrink-0 gap-1">
                                        {recent.images.map((image) => (
                                            <span
                                                key={image}
                                                className="h-10 w-10 overflow-hidden rounded bg-cream"
                                            >
                                                <img
                                                    src={image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-full w-full object-contain"
                                                />
                                            </span>
                                        ))}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm text-ink">{recent.term}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              The Organise band — the front-page half of the header's Organise
              verb, and it mirrors that menu entry for entry.

              It replaced a "Buying for someone else" band offering the Gift
              Finder, Lists and Secret Santa. That heading described only half
              of what is here: three of these four are equally about lists you
              keep for yourself, and the site's own pitch two bands above now
              says "yourself included". The Gift Finder moved out entirely — it
              suggests things rather than organising them, and it already has
              the primary CTA in the pitch.

              Counts where the visitor has something, hints where they do not.
              "3 lists" is a reason to click; "make a list" stops being one the
              moment lists exist.
            */}
            <section className="mt-14" aria-labelledby="organise-heading">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="organise-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                        {t('nav.organise')}
                    </h2>
                    <Link
                        href={`${base}/gift-cove`}
                        className="text-sm font-medium text-accent hover:text-accent-dark"
                    >
                        {t('nav.cove')} →
                    </Link>
                </div>
                <p className="mt-1 max-w-2xl text-ink-soft">{t('home.organise_intro')}</p>

                {/* Five across at desktop rather than four-plus-a-widow. The
                    cards are illustration-led and scale down happily; a lone
                    fifth on its own row reads as an afterthought bolted on. */}
                <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {(
                        [
                            {
                                key: 'mine',
                                href: gifting.urls.lists,
                                name: t('nav.lists'),
                                hint:
                                    gifting.lists > 0
                                        ? t('home.gifting_lists_count', { count: n(gifting.lists) })
                                        : t('home.gifting_lists_hint'),
                            },
                            {
                                key: 'shared',
                                href: `${gifting.urls.lists}?view=shared`,
                                name: t('nav.shared_lists'),
                                hint: t('lists.shared_subtitle'),
                            },
                            {
                                key: 'group',
                                href: `${gifting.urls.lists}?view=group`,
                                name: t('nav.group_lists'),
                                hint: t('home.organise_group_hint'),
                            },
                            {
                                key: 'santa',
                                href: gifting.urls.santa,
                                name: t('nav.santa'),
                                hint:
                                    gifting.santaGroups > 0
                                        ? t('home.gifting_santa_count', { count: n(gifting.santaGroups) })
                                        : t('home.gifting_santa_hint'),
                            },
                            /*
                              A registry is a wish list with an occasion and a
                              date on it, so the card leads to that list rather
                              than to a surface of its own — there isn't one, and
                              inventing a URL for a thing that is really a panel
                              on your own list is how two names for one page
                              start.
                            */
                            {
                                key: 'registry',
                                href: gifting.registry?.url ?? gifting.urls.lists,
                                name: t('home.organise_occasion'),
                                /*
                                 * Names the person when the occasion is not the
                                 * visitor's own. "Wedding on 14 June" on a list
                                 * about your father reads as though you are the
                                 * one getting married.
                                 */
                                hint: gifting.registry
                                    ? [
                                          gifting.registry.date
                                              ? t('home.organise_registry_on', {
                                                    occasion: gifting.registry.occasion,
                                                    date: registryDate(gifting.registry.date),
                                                })
                                              : gifting.registry.occasion,
                                          gifting.registry.for,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : t('home.organise_occasion_hint'),
                            },
                        ] as { key: ListSceneKey; href: string; name: string; hint: string }[]
                    ).map((tool) => (
                        <li key={tool.key}>
                            {/*
                              A row on a phone, the illustration-led card from
                              `sm` up.

                              Stacked, these five cards ran 226px each — 96px of
                              it artwork — and this section plus Discover came to
                              54% of the whole page: three screens of navigation
                              before a phone reader reached any content at all.
                              Laid on their side the art still reads at a glance
                              and the card is about a third of the height.

                              The SVG keeps its 160x116 viewBox and its default
                              `preserveAspectRatio`, so the small box letterboxes
                              it rather than squashing it.
                            */}
                            <Link
                                href={tool.href}
                                className="flex h-full flex-row items-center gap-4 rounded-card border border-line bg-card p-4 text-ink transition hover:border-ink hover:text-accent sm:flex-col sm:items-stretch sm:gap-0 sm:p-5"
                            >
                                <ListIllustration
                                    name={tool.key}
                                    className="h-12 w-16 shrink-0 sm:h-24 sm:w-full"
                                />
                                <div className="min-w-0 sm:mt-4">
                                    <h3 className="font-medium">{tool.name}</h3>
                                    <p className="mt-1 text-sm text-ink-soft sm:mt-2">{tool.hint}</p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            {/*
              The discovery band, and the header for everything under it.

              Two of these three are demonstrated immediately below with real
              content — today's edition, and the Coves themselves — so this is a
              signpost followed by proof rather than a signpost on its own.
              Surprise is the one that has nowhere else to appear on this page,
              and it is also the one whose name promises least: "Surprise me"
              cannot be evaluated before you press it, so the sentence under it
              is doing the work the label cannot.

              Heading and intro come from `discover_cove.*`, the same keys the
              hub page uses. One source, so the front page and the page it links
              to cannot drift into describing the same three things differently
              — which is the defect that produced two names for the Gift Cove.
            */}
            <section className="mt-14" aria-labelledby="discover-heading">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="discover-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                        {t('discover_cove.title')}
                    </h2>
                    <Link
                        href={`${base}/discover-cove`}
                        className="text-sm font-medium text-accent hover:text-accent-dark"
                    >
                        {t('nav.discover_cove')} →
                    </Link>
                </div>
                <p className="mt-1 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

                <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {(
                        [
                            {
                                key: 'daily',
                                href: `${base}/daily`,
                                name: t('nav.daily'),
                                what: t('discover_cove.daily_what'),
                            },
                            {
                                key: 'surprise',
                                href: `${base}/surprise`,
                                name: t('nav.surprise'),
                                what: t('discover_cove.surprise_what'),
                            },
                            {
                                key: 'idea',
                                href: `${base}/guides`,
                                name: t('nav.inspiration_coves'),
                                what: t('discover_cove.idea_what'),
                            },
                            /*
                              The fourth is the one that is not ours.

                              Daily, Surprise and the Coves are all this site
                              showing you something it chose; Ask others is the
                              one where the answer comes from another person. Its
                              sentence comes from `ask.nav_hint` — the same key
                              the Discover hub uses — so the two pages describing
                              it cannot drift into describing it differently.

                              That is also why `what` is now spelled out per
                              entry rather than derived from the key: three of
                              these live under `discover_cove.*` and this one
                              does not, and inventing a fourth `discover_cove`
                              key would be a second copy of a sentence that
                              already exists.
                            */
                            {
                                key: 'ask',
                                href: `${base}/ask`,
                                name: t('ask.title'),
                                what: t('ask.nav_hint'),
                            },
                        ] as { key: CoveSceneKey; href: string; name: string; what: string }[]
                    ).map((cove) => (
                        <li key={cove.key}>
                            {/* Same treatment as Organise above, and for the
                                same reason — the two bands sit one under the
                                other, so one of them staying tall would undo
                                half the saving and read as the odd one out. */}
                            <Link
                                href={cove.href}
                                className="flex h-full flex-row items-center gap-4 rounded-card border border-line bg-card p-4 text-ink transition hover:border-ink hover:text-accent sm:flex-col sm:items-stretch sm:gap-0 sm:p-5"
                            >
                                <CoveIllustration
                                    name={cove.key}
                                    className="h-12 w-16 shrink-0 sm:h-28 sm:w-full"
                                />
                                <div className="min-w-0 sm:mt-4">
                                    <h3 className="font-medium">{cove.name}</h3>
                                    <p className="mt-1 text-sm text-ink-soft sm:mt-2">{cove.what}</p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            {today && (
                <section className="mt-14" aria-labelledby="today-heading">
                    <div className="rounded-card border border-line bg-card p-6 sm:p-8">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="rounded-full bg-accent/10 px-3 py-1 text-xs font-medium uppercase tracking-wide text-accent">
                                {t('home.today_badge')}
                            </span>
                            <time dateTime={today.date} className="text-sm text-ink-soft">
                                {today.label}
                            </time>
                        </div>

                        <h2 id="today-heading" className="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">
                            <Link href={today.url} className="hover:text-accent">
                                {today.theme}
                            </Link>
                        </h2>
                        {today.blurb && <p className="mt-2 max-w-2xl text-ink-soft">{today.blurb}</p>}

                        {today.finds.length > 0 && (
                            <ul className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                {today.finds.map((find) => (
                                    <li key={find.id} className="relative">
                                        {/*
                                          Outside the anchor and above it on the
                                          z-axis. A tile is one big link, and a
                                          button nested inside it is not a
                                          button — the anchor takes the click.
                                        */}
                                        <div className="absolute top-2 right-2 z-10">
                                            <SaveToList groupId={find.id} compact />
                                        </div>
                                        <Link href={find.url} className="group block">
                                            <div className="aspect-square overflow-hidden rounded-lg bg-cream">
                                                {find.image && (
                                                    <img
                                                        src={find.image}
                                                        alt=""
                                                        loading="lazy"
                                                        className="h-full w-full object-contain transition group-hover:scale-105"
                                                    />
                                                )}
                                            </div>
                                            <div className="mt-2 line-clamp-2 text-sm group-hover:text-accent">
                                                {find.title}
                                            </div>
                                            {find.price !== null && (
                                                <div className="text-sm font-medium tabular-nums">
                                                    {formatPrice(find.price, market)}
                                                </div>
                                            )}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link
                            href={today.url}
                            className="mt-6 inline-block font-medium text-accent hover:text-accent-dark"
                        >
                            {t('home.today_cta')} →
                        </Link>
                    </div>
                </section>
            )}

            {/* Only where there is a Cove to subscribe to. Offering a daily
                email on a site with no editions yet is a promise we would then
                have to keep. */}
            {today && (
                <div className="mt-10">
                    <CoveSubscribe source="home" />
                </div>
            )}

            {coves.length > 0 && (
                <section className="mt-14" aria-labelledby="coves-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="coves-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                            {t('home.coves_heading')}
                        </h2>
                        {/*
                          "All Coves" now goes to the page that is all Coves.
                          It pointed at /guides, which is the theme archive —
                          one of three — so the homepage promised the whole
                          shelf and delivered a third of it. Two links reading
                          "All Coves" and landing in different places is the
                          drift this codebase keeps writing about.
                        */}
                        <Link href={`${base}/coves`} className="text-sm font-medium text-accent hover:text-accent-dark">
                            {t('home.coves_all')} →
                        </Link>
                    </div>
                    <p className="mt-1 max-w-2xl text-ink-soft">{t('home.coves_intro')}</p>

                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                                >
                                    <h3 className="font-medium">{cove.title}</h3>
                                    {cove.intro && (
                                        <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>
                                    )}
                                    {cove.searches > 0 && (
                                        <span className="mt-auto pt-3 text-xs text-ink-soft/70">
                                            {t('home.coves_volume', { count: n(cove.searches) })}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              The catalogue counters and the `bc:ingest` hint that used to close
              this page are gone.

              They were scaffolding: honest numbers, shown while ingestion was
              being built, to prove the pipeline was real. To a visitor they are
              a boast about our warehouse — "412,908 products" says nothing
              about whether we have the one they want, and a page that ends on
              inventory size ends on us instead of on them. The empty state was
              worse: an artisan command, on the front page, telling a shopper to
              run something on a server they do not have.

              The numbers that survive here are the ones that belong to the
              visitor (their lists, their people) or to a Cove (its monthly
              search volume) — those are reasons to click.
            */}
        </>
    )
}
