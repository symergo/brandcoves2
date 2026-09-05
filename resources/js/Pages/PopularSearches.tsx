import { Head, Link } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

type Movement = 'up' | 'down' | 'new' | 'same' | null

interface Term {
    term: string
    movement?: Movement
    url: string
}

/**
 * Which way a term has moved against the column before it.
 *
 * Colour is never the only signal, and since the counts came off the rows it is
 * the only signal there is: the triangles point, "new" is a shape of its own, and
 * every state carries a screen-reader label and a tooltip. A red and a green
 * triangle are the same triangle to a red-green colour-blind reader, who is a
 * tenth of the men looking at this page.
 *
 * `null` means there is no baseline to compare against yet, which is not the
 * same as "unchanged" and renders nothing at all. See SearchTermStats.
 */
function Movement({ movement }: { movement: Movement }) {
    const { t } = useTranslations()

    if (movement === null || movement === undefined) {
        return null
    }

    if (movement === 'new') {
        return (
            <span className="text-amber" title={t('popular_searches.movement_new')}>
                <svg
                    viewBox="0 0 10 10"
                    className="h-2.5 w-2.5"
                    fill="currentColor"
                    aria-hidden
                    focusable="false"
                >
                    {/* A four-point star: neither of the triangles, so "new" is
                        never mistaken for a direction at a glance. */}
                    <path d="M5 0 L6.2 3.8 L10 5 L6.2 6.2 L5 10 L3.8 6.2 L0 5 L3.8 3.8 Z" />
                </svg>
                <span className="sr-only">{t('popular_searches.movement_new')}</span>
            </span>
        )
    }

    if (movement === 'same') {
        return (
            <span className="text-ink-soft" title={t('popular_searches.movement_same')}>
                <span aria-hidden>–</span>
                <span className="sr-only">{t('popular_searches.movement_same')}</span>
            </span>
        )
    }

    const up = movement === 'up'
    const label = up ? t('popular_searches.movement_up') : t('popular_searches.movement_down')

    return (
        <span className={up ? 'text-sage' : 'text-accent'} title={label}>
            <svg
                viewBox="0 0 10 10"
                className="h-2.5 w-2.5"
                fill="currentColor"
                aria-hidden
                focusable="false"
            >
                {/* A solid triangle, pointing the way it moved. */}
                <path d={up ? 'M5 1 L9 8 L1 8 Z' : 'M5 9 L1 2 L9 2 Z'} />
            </svg>
            <span className="sr-only">{label}</span>
        </span>
    )
}

interface Column {
    label: string
    terms: Term[]
}

interface Props extends SharedProps {
    months: Column[]
    trending: Term[]
    latest: Term[]
    urls: { search: string; searchHelp: string }
}

/**
 * What people search for here.
 *
 * The hub that replaced the related-search chips under every result set: one
 * ranked column per period, plus rising-fastest and searched-recently, from a
 * single cached pass. See App\Services\Search\SearchTermStats.
 *
 * No counts anywhere. They are not rendered and they are not in the payload
 * either — shipping exact search volumes in the page source while choosing not
 * to print them would publish the same numbers to anyone who looked.
 *
 * The links are ordinary anchors and are followed on purpose, unlike the chips
 * this replaces: every term here has already been typed by real people, so
 * following one mints nothing new in `search_log`.
 */
export default function PopularSearches({ months, trending, latest, urls }: Props) {
    const { t } = useTranslations()

    // The columns always exist; only their contents vary. A page of three empty
    // headings is the empty state, not a table.
    const hasColumns = months.some((column) => column.terms.length > 0)
    const empty = !hasColumns && trending.length === 0 && latest.length === 0

    return (
        <>
            <Head title={t('popular_searches.title')} />

            <header className="max-w-3xl">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                    {t('popular_searches.title')}
                </h1>
            </header>

            {empty ? (
                /*
                  A market that opened yesterday has no history, and the footer
                  links here from every page in every market. Saying so is a
                  better page than three empty tables, and it points at the box
                  rather than leaving the reader at a dead end.
                */
                <p className="mt-8 max-w-3xl text-ink-soft">
                    {t('popular_searches.empty')}{' '}
                    <Link href={urls.search} className="underline hover:text-accent">
                        {t('popular_searches.empty_link')}
                    </Link>
                </p>
            ) : (
                <>
                    {/*
                      Rising and recent first, side by side, because they are the
                      two short lists and the two that change. The long ranked
                      table below is the reference, and a hundred rows above them
                      would bury both.
                    */}
                    <div className="mt-10 grid gap-10 sm:grid-cols-2">
                        {trending.length > 0 && (
                            <section aria-labelledby="trending-heading">
                                <h2 id="trending-heading" className="text-lg font-semibold">
                                    {t('popular_searches.trending_heading')}
                                </h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {t('popular_searches.trending_intro')}
                                </p>
                                <ul className="mt-3 flex flex-wrap gap-2">
                                    {trending.map((item) => (
                                        <li key={item.term}>
                                            <Link
                                                href={item.url}
                                                className="inline-block rounded-full border border-line bg-card px-3 py-1 text-sm transition hover:border-ink hover:text-accent"
                                            >
                                                {item.term}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {latest.length > 0 && (
                            <section aria-labelledby="latest-heading">
                                <h2 id="latest-heading" className="text-lg font-semibold">
                                    {t('popular_searches.latest_heading')}
                                </h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {t('popular_searches.latest_intro')}
                                </p>
                                <ul className="mt-3 flex flex-wrap gap-2">
                                    {latest.map((item) => (
                                        <li key={item.term}>
                                            <Link
                                                href={item.url}
                                                className="inline-block rounded-full border border-line bg-card px-3 py-1 text-sm transition hover:border-ink hover:text-accent"
                                            >
                                                {item.term}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>

                    {hasColumns && (
                        <section aria-labelledby="popular-heading" className="mt-12">
                            <h2 id="popular-heading" className="text-lg font-semibold">
                                {t('popular_searches.popular_heading')}
                            </h2>
                            {/*
                              One column per period, newest on the left, each
                              ranked on its own. Side by side rather than as one
                              list, because the whole point is reading the same
                              term across periods — the arrows say which way it
                              moved, the columns show it.
                            */}
                            <div className="mt-4 grid gap-x-8 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
                                {months.map((column) => (
                                    <section key={column.label} aria-label={column.label}>
                                        <h3 className="border-b border-line pb-2 text-sm font-semibold">
                                            {column.label}
                                        </h3>

                                        {column.terms.length === 0 ? (
                                            <p className="py-3 text-sm text-ink-soft">
                                                {t('popular_searches.period_empty')}
                                            </p>
                                        ) : (
                                            <ol>
                                                {column.terms.map((item, i) => (
                                                    <li
                                                        key={item.term}
                                                        className="flex items-baseline gap-2 border-b border-line py-2"
                                                    >
                                                        {/* The rank is the statistic a
                                                            reader actually uses; the
                                                            count is the evidence. */}
                                                        <span className="w-5 shrink-0 text-sm tabular-nums text-ink-soft">
                                                            {i + 1}
                                                        </span>
                                                        <span className="w-3 shrink-0">
                                                            <Movement movement={item.movement ?? null} />
                                                        </span>
                                                        <Link
                                                            href={item.url}
                                                            className="flex-1 hover:text-accent"
                                                        >
                                                            {item.term}
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ol>
                                        )}
                                    </section>
                                ))}
                            </div>
                        </section>
                    )}
                </>
            )}

            <p className="mt-10 max-w-3xl text-sm text-ink-soft">
                {t('popular_searches.note')}{' '}
                <Link href={urls.searchHelp} className="underline hover:text-accent">
                    {t('search_help.footer_link')}
                </Link>
            </p>
        </>
    )
}
