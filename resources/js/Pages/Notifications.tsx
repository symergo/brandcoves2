import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

interface Notice {
    id: number
    kind: string
    title: string
    body: string | null
    url: string | null
    price: Cents | null
    baseline: Cents | null
    readAt: string | null
    createdAt: string
}

interface Watched {
    groupId: number
    title: string
    image: string | null
    url: string
    currentPrice: Cents | null
    baseline: Cents | null
    target: Cents | null
    state: string
    restock: boolean
}

interface Props {
    notifications: Notice[]
    watching: Watched[]
}

export default function Notifications({ notifications, watching }: Props) {
    const { market, unreadCount } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Opening the page is the acknowledgement — a separate "mark as read"
     * button is a chore nobody performs, and the badge then never clears.
     * preserveScroll/preserveState so the list does not jump under the reader.
     */
    useEffect(() => {
        if (unreadCount > 0) {
            router.post(
                `/${market.key}/notifications/read`,
                {},
                { preserveScroll: true, preserveState: true, only: ['unreadCount'] },
            )
        }
    }, [unreadCount, market.key])

    const dateFormat = new Intl.DateTimeFormat(market.hrefLang, {
        day: 'numeric',
        month: 'short',
    })

    return (
        <>
            <Head title={t('notifications.title')} />

            <h1 className="text-xl sm:text-2xl font-semibold">{t('notifications.title')}</h1>

            <section className="mt-8">
                <h2 className="text-sm font-medium text-ink-soft">{t('notifications.recent')}</h2>

                {notifications.length === 0 ? (
                    <p className="mt-3 text-sm text-ink-soft">{t('notifications.empty')}</p>
                ) : (
                    <ul className="mt-3 divide-y divide-line rounded border border-line">
                        {notifications.map((notice) => (
                            <li
                                key={notice.id}
                                className={`flex items-baseline gap-3 px-4 py-3 text-sm ${
                                    notice.readAt === null ? 'bg-card' : ''
                                }`}
                            >
                                {/*
                                  Three families, not two.

                                  This column was `restock ? 📦 : ↓`, which made
                                  every kind that is not a restock a price drop.
                                  Occasion reminders write here too — a birthday,
                                  a Secret Friend exchange, the date on a list —
                                  and arrived wearing a down arrow.
                                */}
                                <span aria-hidden>
                                    {notice.kind === 'restock'
                                        ? '📦'
                                        : notice.kind.startsWith('occasion.')
                                          ? '🎁'
                                          : '↓'}
                                </span>
                                <div className="min-w-0 flex-1">
                                    {notice.url ? (
                                        <Link href={notice.url} className="font-medium hover:underline">
                                            {notice.title}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">{notice.title}</span>
                                    )}
                                    <p className="text-ink-soft">
                                        {/*
                                          A notification that wrote its own
                                          sentence gets to keep it.

                                          The price alerts store `body: null` and
                                          have their line computed from the
                                          payload; a reminder stores the finished
                                          sentence, already in the recipient's
                                          language. Reading `body` first is what
                                          lets a new kind arrive without a third
                                          branch here — and it is why a reminder
                                          used to render as "dropped to - (was
                                          -)": its text was on the row all along
                                          and nothing looked at it.
                                        */}
                                        {notice.body
                                            ?? (notice.kind === 'restock'
                                                ? t('notifications.back_in_stock')
                                                : t('notifications.dropped_to', {
                                                      price:
                                                          notice.price === null
                                                              ? '-'
                                                              : formatPrice(notice.price, market),
                                                      was:
                                                          notice.baseline === null
                                                              ? '-'
                                                              : formatPrice(notice.baseline, market),
                                                  }))}
                                    </p>
                                </div>
                                <time className="shrink-0 text-xs text-ink-soft" dateTime={notice.createdAt}>
                                    {dateFormat.format(new Date(notice.createdAt))}
                                </time>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mt-10">
                <h2 className="text-sm font-medium text-ink-soft">{t('notifications.watching')}</h2>

                {watching.length === 0 ? (
                    <p className="mt-3 text-sm text-ink-soft">{t('notifications.watching_empty')}</p>
                ) : (
                    <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                        {watching.map((item) => (
                            <li
                                key={item.groupId}
                                className="flex items-center gap-3 rounded border border-line p-3"
                            >
                                {item.image && (
                                    <img
                                        src={item.image}
                                        alt=""
                                        className="h-12 w-12 shrink-0 object-contain"
                                        loading="lazy"
                                    />
                                )}
                                <div className="min-w-0 flex-1 text-sm">
                                    <Link href={item.url} className="font-medium hover:underline">
                                        {item.title}
                                    </Link>
                                    <p className="text-ink-soft">
                                        {item.restock
                                            ? t('notifications.await_restock')
                                            : item.target !== null
                                              ? t('notifications.until', {
                                                    price: formatPrice(item.target, market),
                                                })
                                              : t('notifications.any_drop')}
                                        {item.currentPrice !== null &&
                                            ` · ${t('notifications.now', {
                                                price: formatPrice(item.currentPrice, market),
                                            })}`}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className="shrink-0 text-xs text-ink-soft underline hover:text-ink"
                                    onClick={() =>
                                        router.delete(`/${market.key}/alerts/${item.groupId}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {t('alerts.stop')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    )
}
