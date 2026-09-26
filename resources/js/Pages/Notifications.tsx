import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { rich, useTranslations } from '../useTranslations'
import type { ListKind } from '../Components/ListKindBadge'
import ListName from '../Components/ListName'
import InfoTip from '../Components/InfoTip'

interface Notice {
    id: number
    kind: string
    title: string
    body: string | null
    url: string | null
    price: Cents | null
    baseline: Cents | null
    readAt: string | null
    /** How many, on a watched-search notification. */
    count: number | null
    /**
     * The title in pieces when it names a list (`App\Support\ListName`), so
     * the name is drawn as a list's name. Null on older rows and other kinds.
     */
    list?: { template: string; name: string; kind: ListKind | null } | null
    createdAt: string
    /** Day and month, formatted on the server in the market's language. */
    createdAtLabel: string
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
    /** Reminder emails on; off keeps reminders in this inbox only. */
    reminderEmails?: boolean
    /** Ask others and your people. All on unless turned off. */
    askPeople?: { ask: boolean; receive: boolean; email: boolean }
}

export default function Notifications({
    notifications,
    watching,
    reminderEmails = true,
    askPeople = { ask: true, receive: true, email: true },
}: Props) {
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

    function askSetting(setting: 'ask' | 'receive' | 'email', on: boolean) {
        router.post(`/${market.key}/notifications/ask-people`, { setting, on }, { preserveScroll: true })
    }

    /**
     * The second line under a notification's title, or null when it has none.
     *
     * `body` wins wherever it is set — a reminder writes its sentence in the
     * recipient's language at the time it is created, which is the only moment
     * that knows which language that is. Price alerts write no body and have
     * their line built from the payload here instead.
     *
     * Everything else returns null rather than falling through to the price
     * line, which is what put "dropped to - (was -)" under list activity.
     */
    function detailOf(notice: Notice): string | null {
        if (notice.body !== null) {
            return notice.body
        }

        if (notice.kind === 'restock') {
            return t('notifications.back_in_stock')
        }

        if (notice.kind === 'search_match') {
            return t('notifications.search_match', { count: String(notice.count ?? 0) })
        }

        // The list price digest: how many lines the morning mail had for this list.
        if (notice.kind === 'list_price_digest') {
            return t('notifications.list_price_digest', { count: String(notice.count ?? 0) })
        }

        if (notice.kind === 'price_drop') {
            return t('notifications.dropped_to', {
                price: notice.price === null ? '-' : formatPrice(notice.price, market),
                was: notice.baseline === null ? '-' : formatPrice(notice.baseline, market),
            })
        }

        return null
    }

    /**
     * The title, with a list's name drawn as one where the row says which
     * part is the name. The rest of the title is already `font-medium`, so
     * the name is set apart by its icon and colour rather than its weight.
     */
    function titleOf(notice: Notice) {
        return notice.list
            ? rich(notice.list.template, { list: <ListName name={notice.list.name} kind={notice.list.kind} /> })
            : notice.title
    }

    return (
        <>
            <Head title={t('notifications.title')} />

            <h1 className="text-xl sm:text-2xl font-semibold">{t('notifications.title')}</h1>

            {/*
              The switch for reminder emails, the way back on after the stop
              link in an email. Saved at once, like every switch on this site.
            */}
            <label className="mt-4 flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={reminderEmails}
                    onChange={(e) =>
                        router.post(
                            `/${market.key}/notifications/reminder-emails`,
                            { on: e.target.checked },
                            { preserveScroll: true },
                        )
                    }
                />
                <span>{t('reminders.email_toggle')}</span>
                <InfoTip>{t('reminders.email_toggle_hint')}</InfoTip>
            </label>

            {/*
              Ask others and your people: whether my questions go to my
              people, and whether theirs come to me (in this inbox, and by
              email). Beside the reminder switch, because it is the same kind
              of choice: what may reach me, and what I send.
            */}
            <label className="mt-2 flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={askPeople.ask}
                    onChange={(e) => askSetting('ask', e.target.checked)}
                />
                <span>{t('ask.people.setting_ask')}</span>
                <InfoTip>{t('ask.people.setting_ask_hint')}</InfoTip>
            </label>
            <label className="mt-2 flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={askPeople.receive}
                    onChange={(e) => askSetting('receive', e.target.checked)}
                />
                <span>{t('ask.people.setting_receive')}</span>
                <InfoTip>{t('ask.people.setting_receive_hint')}</InfoTip>
            </label>
            {/* By email only means something while their questions reach you at all. */}
            {askPeople.receive && (
                <label className="ml-6 mt-2 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={askPeople.email}
                        onChange={(e) => askSetting('email', e.target.checked)}
                    />
                    <span>{t('ask.people.setting_email')}</span>
                </label>
            )}

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
                                        : notice.kind === 'search_match'
                                          ? '🔎'
                                        : notice.kind.startsWith('occasion.')
                                          ? '🎁'
                                        : notice.kind.startsWith('ask.')
                                          ? '💬'
                                          : /*
                                              List activity: somebody shared,
                                              suggested, added, wrote or chipped
                                              in. A fourth family, and without
                                              its own mark every one of them
                                              arrived wearing the price-drop
                                              arrow — which is how the two kinds
                                              that are not price drops already
                                              got here.
                                            */
                                            notice.kind.startsWith('list.')
                                            ? '📋'
                                            : '↓'}
                                </span>
                                <div className="min-w-0 flex-1">
                                    {notice.url ? (
                                        <Link href={notice.url} className="font-medium hover:underline">
                                            {titleOf(notice)}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">{titleOf(notice)}</span>
                                    )}
                                    {/*
                                      A second line, only when there is one.

                                      Three cases, and the third was missing.
                                      A reminder stores its finished sentence in
                                      `body`. A price alert stores `body: null`
                                      and has its line computed from the payload.
                                      **List activity has neither** — the title
                                      is the whole message ("Somebody added
                                      something to Wedding") — and it fell into
                                      the price branch, rendering "Nu -, was -"
                                      under every one of them.

                                      This is the same bug the previous comment
                                      here described happening to reminders, and
                                      the fix it applied only covered kinds that
                                      write a body. So the price line is now
                                      asked for **by kind** rather than used as a
                                      fallback for everything, and a kind that
                                      says everything in its title renders no
                                      paragraph at all.
                                    */}
                                    {detailOf(notice) !== null && (
                                        <p className="text-ink-soft">{detailOf(notice)}</p>
                                    )}
                                </div>
                                <time className="shrink-0 text-xs text-ink-soft" dateTime={notice.createdAt}>
                                    {notice.createdAtLabel}
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
