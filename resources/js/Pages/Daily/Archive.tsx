import { Head, Link, usePage } from '@inertiajs/react'
import { formatDay, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Edition {
    id: number
    title: string
    intro: string | null
    url: string
    date: string
    isToday: boolean
    images: string[]
}

interface Props {
    editions: Edition[]
    pagination: { current: number; last: number; prev: string | null; next: string | null }
}

/**
 * Every Cove van de dag, newest first, grouped by month (owner, 2026-10-02).
 *
 * The "Cove van de dag" entry in the menu, the home page card and the /coves
 * band open this page; today's edition is its first card, marked as such. One
 * column of month headings with a grid under each, and no intro line (the
 * owner removed it on 2026-10-03: the title says it), so a reader scanning for
 * "that one about slippers in October" can find the month first.
 * docs/features/daily-cove.md.
 */
export default function Archive({ editions, pagination }: Props) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props

    // Consecutive editions of one month under one heading; the list arrives
    // sorted newest first, so a month never comes back.
    const months: { key: string; label: string; editions: Edition[] }[] = []

    for (const edition of editions) {
        const key = edition.date.slice(0, 7)
        let month = months[months.length - 1]

        if (month === undefined || month.key !== key) {
            month = { key, label: monthLabel(edition.date, market.hrefLang), editions: [] }
            months.push(month)
        }

        month.editions.push(edition)
    }

    return (
        <>
            <Head title={t('daily_archive.title')} />

            <div className="py-4 sm:py-8">
                <h1 className="text-3xl font-semibold tracking-tight text-balance text-ink sm:text-4xl">
                    {t('daily_archive.title')}
                </h1>

                {editions.length === 0 && <p className="mt-10 text-ink-soft">{t('daily_archive.empty')}</p>}

                {months.map((month) => (
                    <section key={month.key} className="mt-10" aria-labelledby={`month-${month.key}`}>
                        <h2 id={`month-${month.key}`} className="text-xl font-semibold tracking-tight first-letter:uppercase">
                            {month.label}
                        </h2>
                        <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {month.editions.map((edition) => (
                                <li key={edition.id}>
                                    <Link
                                        href={edition.url}
                                        className="group flex h-full flex-col rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                    >
                                        {edition.images.length > 0 && (
                                            <div className="grid grid-cols-4 gap-2" aria-hidden="true">
                                                {edition.images.map((src) => (
                                                    <div key={src} className="aspect-square overflow-hidden rounded-lg bg-card">
                                                        <img src={src} alt="" loading="lazy" className="h-full w-full object-contain" />
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                                            <time dateTime={edition.date}>{formatDay(edition.date, market, { year: 'auto' })}</time>
                                            {edition.isToday && (
                                                <span className="rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium uppercase tracking-wide text-accent">
                                                    {t('home.today_badge')}
                                                </span>
                                            )}
                                        </div>
                                        <h3 className="mt-1 font-semibold text-ink group-hover:text-accent">{edition.title}</h3>
                                        {edition.intro && <p className="mt-1 line-clamp-3 text-sm text-ink-soft">{edition.intro}</p>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                {pagination.last > 1 && (
                    <nav className="mt-10 flex items-center justify-between gap-4" aria-label={t('daily_archive.pages')}>
                        {pagination.prev ? (
                            <Link href={pagination.prev} className="font-medium text-accent-dark hover:text-ink">
                                ← {t('daily_archive.newer')}
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span className="text-sm text-ink-soft tabular-nums">
                            {pagination.current} / {pagination.last}
                        </span>
                        {pagination.next ? (
                            <Link href={pagination.next} className="font-medium text-accent-dark hover:text-ink">
                                {t('daily_archive.older')} →
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </div>
        </>
    )
}

/** "oktober 2026", in the market's language. Falls back to the ISO month. */
function monthLabel(iso: string, locale: string): string {
    try {
        return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(`${iso.slice(0, 10)}T00:00:00`))
    } catch {
        return iso.slice(0, 7)
    }
}
