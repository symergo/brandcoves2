import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface ListSummary {
    id: string
    title: string
    kind: string
    itemCount: number
    url: string
    recipient: { id: string; name: string } | null
}

interface Props {
    lists: ListSummary[]
    recipients: { id: string; name: string; relationship: string | null }[]
    isSignedIn: boolean
}

export default function ListsIndex({ lists, recipients, isSignedIn }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const [creating, setCreating] = useState(false)

    // The recipient decides the kind; there is no separate switch to disagree with it.
    const form = useForm({ title: '', recipient_id: '' })

    return (
        <>
            <Head title={t('lists.title')} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">{t('lists.title')}</h1>
                    <p className="mt-1 text-ink-soft">{t('lists.subtitle')}</p>
                </div>
                <button
                    onClick={() => setCreating((v) => !v)}
                    className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                >
                    {t('lists.new_list')}
                </button>
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

                    <button
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60"
                    >
                        {t('lists.create')}
                    </button>
                </form>
            )}

            {lists.length === 0 ? (
                <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                    <p className="font-medium">{t('lists.empty')}</p>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.empty_hint')}</p>
                    <Link href={`/${market.key}/search`} className="mt-3 inline-block text-accent underline">
                        {t('nav.search')}
                    </Link>
                </div>
            ) : (
                <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {lists.map((list) => (
                        <li key={list.id}>
                            <Link
                                href={list.url}
                                className="block rounded-card border border-line bg-card p-5 transition hover:border-ink/30"
                            >
                                <h2 className="font-medium">{list.title}</h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {list.itemCount === 1
                                        ? t('lists.one_item')
                                        : t('lists.items', { count: n(list.itemCount) })}
                                    {list.recipient && ` · ${list.recipient.name}`}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
