import { Head, Link, usePage } from '@inertiajs/react'
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
        hasPuzzle: boolean
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    coves: Cove[]
}

export default function Home({ stats, today, coves }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    return (
        <>
            <Head title={t('home.title')} />

            <section className="max-w-2xl">
                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {t('home.headline_1')}
                    <br />
                    {t('home.headline_2')}
                </h1>
                <p className="mt-5 text-lg text-ink-soft">{t('home.intro')}</p>

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
                            {today.hasPuzzle && (
                                <span className="text-sm text-ink-soft">· {t('home.today_puzzle')}</span>
                            )}
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
