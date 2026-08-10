import { Head, Link, usePage } from '@inertiajs/react'
import CoveSubscribe from '../Components/CoveSubscribe'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    title: string
    intro: string | null
    url: string
    searches: number
}

interface Props {
    stats: {
        products: number
        comparable: number
        guides: number
    }
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

export default function Home({ stats, today, gifting, coves }: Props) {
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
              The gifting band.

              The headline claims the site is about buying for other people, and
              until now the only way to act on that was one button. A gift list
              somebody else fills in, a Secret Santa and the quiz were all
              reachable only by knowing the URL — which is exactly how v1 shipped
              its gift finder unlinked and unreachable.
            */}
            <section className="mt-14" aria-labelledby="gifting-heading">
                <h2 id="gifting-heading" className="text-2xl font-semibold tracking-tight">
                    {t('home.gifting_heading')}
                </h2>
                <p className="mt-2 max-w-2xl text-ink-soft">{t('home.gifting_intro')}</p>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        href={gifting.urls.gift}
                        className="rounded-card border border-line bg-card p-6 transition hover:border-ink"
                    >
                        <h3 className="font-medium">{t('home.gifting_whisperer')}</h3>
                        <p className="mt-2 text-sm text-ink-soft">
                            {gifting.people > 0
                                ? t('home.gifting_people_count', { count: n(gifting.people) })
                                : t('home.gifting_whisperer_hint')}
                        </p>
                    </Link>

                    <Link
                        href={gifting.urls.lists}
                        className="rounded-card border border-line bg-card p-6 transition hover:border-ink"
                    >
                        <h3 className="font-medium">{t('home.gifting_lists')}</h3>
                        <p className="mt-2 text-sm text-ink-soft">
                            {/* A count is a reason to click; "make a list" stops
                                being one the moment lists exist. */}
                            {gifting.lists > 0
                                ? t('home.gifting_lists_count', { count: n(gifting.lists) })
                                : t('home.gifting_lists_hint')}
                        </p>
                    </Link>

                    <Link
                        href={gifting.urls.santa}
                        className="rounded-card border border-line bg-card p-6 transition hover:border-ink"
                    >
                        <h3 className="font-medium">{t('home.gifting_santa')}</h3>
                        <p className="mt-2 text-sm text-ink-soft">
                            {gifting.santaGroups > 0
                                ? t('home.gifting_santa_count', { count: n(gifting.santaGroups) })
                                : t('home.gifting_santa_hint')}
                        </p>
                    </Link>
                </div>
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
              Real counts, not placeholders. An empty catalogue should say so —
              a scaffold that fakes data hides exactly the thing you need to see
              while building ingestion.
            */}
            <section className="mt-16 grid gap-4 sm:grid-cols-3" aria-label={t('home.stats_label')}>
                <Stat label={t('home.stat_products')} value={n(stats.products)} />
                <Stat
                    label={t('home.stat_comparable')}
                    value={n(stats.comparable)}
                    hint={t('home.stat_comparable_hint')}
                />
                <Stat label={t('home.stat_guides')} value={n(stats.guides)} />
            </section>

            {stats.products === 0 && (
                <p className="mt-6 rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                    {t('home.empty_catalogue')}{' '}
                    <code className="rounded bg-cream px-1.5 py-0.5">
                        php artisan bc:ingest --market={market.key}
                    </code>
                </p>
            )}
        </>
    )
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="rounded-card border border-line bg-card p-5">
            <div className="text-3xl font-semibold tabular-nums">{value}</div>
            <div className="mt-1 text-sm text-ink-soft">{label}</div>
            {hint && <div className="mt-0.5 text-xs text-ink-soft/70">{hint}</div>}
        </div>
    )
}
