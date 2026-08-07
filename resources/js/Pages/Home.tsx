import { Head, Link, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'

interface Props {
    stats: {
        products: number
        comparable: number
        guides: number
    }
}

export default function Home({ stats }: Props) {
    const { market } = usePage<SharedProps>().props
    const base = `/${market.key}`

    return (
        <>
            <Head title="Find it, compare it, gift it" />

            <section className="max-w-2xl">
                <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
                    You don&apos;t know what you want.
                    <br />
                    You know who it&apos;s for.
                </h1>
                <p className="mt-5 text-lg text-ink-soft">
                    Search across bol, Amazon and hundreds of shops at once, compare every
                    offer for the same product, and let the Gift Whisperer turn a description
                    of a person into something worth wrapping.
                </p>

                <div className="mt-8 flex flex-wrap gap-3">
                    <Link
                        href={`${base}/gift`}
                        className="rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark"
                    >
                        Find a gift
                    </Link>
                    <Link
                        href={`${base}/search`}
                        className="rounded-lg border border-line px-5 py-3 font-medium transition hover:border-ink"
                    >
                        Search products
                    </Link>
                </div>
            </section>

            {/*
              Real counts, not placeholders. Phase 0 has an empty catalogue and
              the page should say so — a scaffold that fakes data hides exactly
              the thing you need to see while building ingestion.
            */}
            <section className="mt-16 grid gap-4 sm:grid-cols-3" aria-label="Catalogue status">
                <Stat label="Products indexed" value={stats.products} />
                <Stat
                    label="With more than one offer"
                    value={stats.comparable}
                    hint="Comparable across shops"
                />
                <Stat label="Buying guides published" value={stats.guides} />
            </section>

            {stats.products === 0 && (
                <p className="mt-6 rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                    The catalogue is empty. Run a feed ingestion to populate it:{' '}
                    <code className="rounded bg-cream px-1.5 py-0.5">
                        php artisan bc:ingest --market={market.key}
                    </code>
                </p>
            )}
        </>
    )
}

function Stat({ label, value, hint }: { label: string; value: number; hint?: string }) {
    return (
        <div className="rounded-card border border-line bg-card p-5">
            <div className="text-3xl font-semibold tabular-nums">
                {value.toLocaleString()}
            </div>
            <div className="mt-1 text-sm text-ink-soft">{label}</div>
            {hint && <div className="mt-0.5 text-xs text-ink-soft/70">{hint}</div>}
        </div>
    )
}
