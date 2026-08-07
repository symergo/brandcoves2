import { Head, Link, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Props {
    stats: {
        products: number
        comparable: number
        guides: number
    }
}

export default function Home({ stats }: Props) {
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
