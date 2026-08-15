import { Head, Link, usePage } from '@inertiajs/react'
import type { CoveKey } from '../Components/CoveIcon'
import CoveIllustration from '../Components/CoveIllustration'
import CoveSubscribe from '../Components/CoveSubscribe'
import ListIllustration, { type ListSceneKey } from '../Components/ListIllustration'
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
        urls: { gift: string; lists: string; santa: string }
    }
    coves: Cove[]
}

export default function Home({ today, gifting, coves }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

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
                <h1 className="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                    {t('home.headline_1')}
                    <br />
                    {t('home.headline_2')}
                </h1>
                <p className="mt-5 max-w-2xl text-lg text-ink-soft">{t('home.intro')}</p>

                <div className="mt-8 flex flex-wrap gap-3">
                    <Link
                        href={`${base}/gift`}
                        className="rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark"
                    >
                        {t('home.cta_gift')}
                    </Link>
                    <Link
                        href={`${base}/search`}
                        className="rounded-lg border border-line px-5 py-3 font-medium transition hover:border-ink"
                    >
                        {t('home.cta_search')}
                    </Link>
                </div>
            </section>

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
                    <h2 id="organise-heading" className="text-2xl font-semibold tracking-tight">
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

                <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                        ] as { key: ListSceneKey; href: string; name: string; hint: string }[]
                    ).map((tool) => (
                        <li key={tool.key}>
                            <Link
                                href={tool.href}
                                className="flex h-full flex-col rounded-card border border-line bg-card p-5 text-ink transition hover:border-ink hover:text-accent"
                            >
                                <ListIllustration name={tool.key} className="h-24 w-full" />
                                <h3 className="mt-4 font-medium">{tool.name}</h3>
                                <p className="mt-2 text-sm text-ink-soft">{tool.hint}</p>
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
                    <h2 id="discover-heading" className="text-2xl font-semibold tracking-tight">
                        {t('discover_cove.title')}
                    </h2>
                    <Link
                        href={`${base}/discover-cove`}
                        className="text-sm font-medium text-accent hover:text-accent-dark"
                    >
                        {t('nav.discover')} →
                    </Link>
                </div>
                <p className="mt-1 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

                <ul className="mt-6 grid gap-4 sm:grid-cols-3">
                    {(
                        [
                            { key: 'daily', href: `${base}/daily`, name: t('nav.daily') },
                            { key: 'surprise', href: `${base}/surprise`, name: t('nav.surprise') },
                            { key: 'idea', href: `${base}/guides`, name: t('nav.coves') },
                        ] as { key: CoveKey; href: string; name: string }[]
                    ).map((cove) => (
                        <li key={cove.key}>
                            <Link
                                href={cove.href}
                                className="flex h-full flex-col rounded-card border border-line bg-card p-5 text-ink transition hover:border-ink hover:text-accent"
                            >
                                <CoveIllustration name={cove.key} className="h-28 w-full" />
                                <h3 className="mt-4 font-medium">{cove.name}</h3>
                                <p className="mt-2 text-sm text-ink-soft">{t(`discover_cove.${cove.key}_what`)}</p>
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
                                    <li key={find.id}>
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
                        <h2 id="coves-heading" className="text-2xl font-semibold tracking-tight">
                            {t('home.coves_heading')}
                        </h2>
                        <Link href={`${base}/guides`} className="text-sm font-medium text-accent hover:text-accent-dark">
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
