import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface ListSummary {
    id: string
    title: string
    kind: string
    isDefault: boolean
    visibility: string
    itemCount: number
    covers: string[]
    url: string
    recipient: { id: string; name: string } | null
}

interface Props {
    lists: ListSummary[]
    recipients: { id: string; name: string; relationship: string | null }[]
    isSignedIn: boolean
}

/**
 * One card per list, in two groups.
 *
 * Every list rendered the same way — a title and an item count — even though
 * `kind` was already in the payload. So a wishlist for yourself and private
 * research about your sister were indistinguishable, and the only way to tell
 * them apart was to open them. The save picker has always sorted them into "for
 * me" and "for someone else"; this page now uses the same two words, so the
 * place you save to and the place you look for it agree.
 */
function ListCard({ list }: { list: ListSummary }) {
    const { t, n } = useTranslations()
    const shared = list.visibility !== 'private'

    return (
        <Link
            href={list.url}
            className="flex h-full flex-col rounded-card border border-line bg-card transition hover:border-ink/30"
        >
            {/*
              A strip of what is in it. An empty list gets a placeholder rather
              than a collapsed card, so the grid keeps its rhythm and an empty
              list still reads as a list.
            */}
            <div className="flex gap-1 overflow-hidden rounded-t-card border-b border-line bg-cream p-2">
                {list.covers.length === 0 ? (
                    <span className="flex h-16 w-full items-center justify-center text-xs text-ink-soft">
                        {t('lists.empty_list')}
                    </span>
                ) : (
                    list.covers.map((src, i) => (
                        <img
                            key={i}
                            src={src}
                            alt=""
                            loading="lazy"
                            className="h-16 min-w-0 flex-1 object-contain"
                            onError={(e) => {
                                e.currentTarget.style.visibility = 'hidden'
                            }}
                        />
                    ))
                )}
            </div>

            <div className="flex flex-1 flex-col p-4">
                <h3 className="font-medium">{list.title}</h3>

                <p className="mt-1 text-sm text-ink-soft">
                    {list.itemCount === 1
                        ? t('lists.one_item')
                        : t('lists.items', { count: n(list.itemCount) })}
                    {list.recipient && ` · ${list.recipient.name}`}
                </p>

                <div className="mt-3 flex flex-wrap gap-1.5 text-[11px]">
                    {list.isDefault && (
                        <span className="rounded-full bg-line/60 px-2 py-0.5">{t('lists.default_badge')}</span>
                    )}
                    {/*
                      Shared or not is the fact people most need off this page —
                      it is the difference between a private note and something
                      anyone with the link can read.
                    */}
                    <span
                        className={
                            shared
                                ? 'rounded-full bg-sage/15 px-2 py-0.5 text-sage'
                                : 'rounded-full bg-line/60 px-2 py-0.5 text-ink-soft'
                        }
                    >
                        {shared ? t('lists.shared_short') : t('lists.private_short')}
                    </span>
                </div>
            </div>
        </Link>
    )
}

export default function ListsIndex({ lists, recipients, isSignedIn }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [creating, setCreating] = useState(false)

    // The recipient decides the kind; there is no separate switch to disagree with it.
    const form = useForm({ title: '', recipient_id: '' })

    const mine = lists.filter((l) => l.kind === 'mine')
    const forOthers = lists.filter((l) => l.kind !== 'mine')

    const groups = [
        { key: 'mine', label: t('lists.for_me'), lists: mine },
        { key: 'others', label: t('lists.for_someone_else'), lists: forOthers },
    ].filter((g) => g.lists.length > 0)

    return (
        <>
            <Head title={t('lists.title')} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">{t('lists.title')}</h1>
                    <p className="mt-1 text-ink-soft">{t('lists.subtitle')}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {/*
                      A list is empty until something goes in it, and nothing
                      goes in it from this page — every save starts at a product.
                      Leaving "New list" as the only action here sent people to a
                      list they then had no route out of.
                    */}
                    <Link
                        href={`/${market.key}/search`}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('lists.find_things')}
                    </Link>
                    <button
                        onClick={() => setCreating((v) => !v)}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink"
                    >
                        {t('lists.new_list')}
                    </button>
                </div>
            </header>

            {/*
              Lists work before signup, so this is a nudge rather than a wall.
              Shown only when there is something to lose.
            */}
            {!isSignedIn && lists.length > 0 && (
                <div className="mt-6 rounded-card border border-amber/40 bg-amber/10 p-4">
                    <p className="font-medium">{t('lists.sign_in_to_keep')}</p>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.sign_in_hint')}</p>
                    <Link href={`/${market.key}/login`} className="mt-2 inline-block text-sm text-accent underline">
                        {t('nav.sign_in')}
                    </Link>
                </div>
            )}

            {creating && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        form.post(`/${market.key}/lists`, { onSuccess: () => setCreating(false) })
                    }}
                    className="mt-6 space-y-3 rounded-card border border-line bg-card p-5"
                >
                    <label className="block text-sm font-medium" htmlFor="title">
                        {t('lists.list_name')}
                    </label>
                    <input
                        id="title"
                        required
                        autoFocus
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                    />

                    {recipients.length > 0 && (
                        <>
                            <label className="block text-sm font-medium" htmlFor="recipient">
                                {t('lists.for_whom')}
                            </label>
                            <select
                                id="recipient"
                                value={form.data.recipient_id}
                                onChange={(e) => form.setData('recipient_id', e.target.value)}
                                className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                            >
                                <option value="">{t('lists.no_recipient')}</option>
                                {recipients.map((r) => (
                                    <option key={r.id} value={r.id}>{r.name}</option>
                                ))}
                            </select>
                        </>
                    )}

                    <div className="flex gap-2">
                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60"
                        >
                            {t('lists.create')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setCreating(false)}
                            className="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('lists.cancel')}
                        </button>
                    </div>
                </form>
            )}

            {lists.length === 0 ? (
                <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                    <p className="font-medium">{t('lists.empty')}</p>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.empty_hint')}</p>
                    <Link
                        href={`/${market.key}/search`}
                        className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {t('lists.find_things')}
                    </Link>
                </div>
            ) : (
                groups.map((group) => (
                    <section key={group.key} className="mt-8">
                        {/* The heading only earns its place when both groups
                            exist; with one group it is a label for the obvious. */}
                        {groups.length > 1 && (
                            <h2 className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                                {group.label}
                            </h2>
                        )}
                        <ul className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {group.lists.map((list) => (
                                <li key={list.id}>
                                    <ListCard list={list} />
                                </li>
                            ))}
                        </ul>
                    </section>
                ))
            )}
        </>
    )
}
