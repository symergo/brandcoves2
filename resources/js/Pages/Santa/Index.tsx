import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Group {
    id: string
    title: string
    members: number
    drawn: boolean
    exchangeDate: string | null
    url: string
}

interface Props {
    groups: Group[]
    isSignedIn: boolean
}

/**
 * The hub, and the form to start a group.
 *
 * Public, so the page can explain what this is before asking for an account.
 * Creating a group needs one — somebody has to own it and be reachable when the
 * draw happens — but *joining* deliberately does not.
 */
export default function SantaIndex({ groups, isSignedIn }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [creating, setCreating] = useState(false)

    const form = useForm({
        title: '',
        budget_max: '',
        exchange_date: '',
        theme: '',
    })

    return (
        <>
            <Head title={t('santa.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight">{t('santa.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('santa.subtitle')}</p>
            </header>

            {!isSignedIn ? (
                <p className="mt-8 max-w-2xl rounded-card border border-line bg-card p-6">
                    <Link href={`/${market.key}/login`} className="font-medium underline">
                        {t('nav.sign_in')}
                    </Link>{' '}
                    <span className="text-ink-soft">{t('santa.create')}</span>
                </p>
            ) : (
                <div className="mt-8">
                    <button
                        type="button"
                        onClick={() => setCreating((v) => !v)}
                        className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('santa.create')}
                    </button>

                    {creating && (
                        <form
                            className="mt-6 max-w-lg space-y-4 rounded-card border border-line bg-card p-6"
                            onSubmit={(e) => {
                                e.preventDefault()
                                form.post(`/${market.key}/santa`)
                            }}
                        >
                            <label className="block">
                                <span className="text-sm font-medium">{t('santa.group_name')}</span>
                                <input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    required
                                    maxLength={120}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">{t('santa.budget')}</span>
                                {/* Euros here, cents in the column — the form
                                    shows the currency people think in. */}
                                <input
                                    type="number"
                                    min={0}
                                    step="1"
                                    value={form.data.budget_max}
                                    onChange={(e) => form.setData('budget_max', e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                />
                                <span className="mt-1 block text-xs text-ink-soft">
                                    {t('santa.budget_hint')}
                                </span>
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">
                                    {t('santa.exchange_date')}
                                </span>
                                <input
                                    type="date"
                                    value={form.data.exchange_date}
                                    onChange={(e) => form.setData('exchange_date', e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">{t('santa.theme')}</span>
                                <input
                                    value={form.data.theme}
                                    onChange={(e) => form.setData('theme', e.target.value)}
                                    maxLength={120}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2"
                                />
                            </label>

                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                            >
                                {t('santa.create')}
                            </button>
                        </form>
                    )}
                </div>
            )}

            {groups.length > 0 && (
                <ul className="mt-10 grid gap-4 sm:grid-cols-2">
                    {groups.map((group) => (
                        <li key={group.id}>
                            <Link
                                href={group.url}
                                className="block rounded-card border border-line bg-card p-6 transition hover:border-ink"
                            >
                                <h2 className="font-medium">{group.title}</h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {group.members} · {group.exchangeDate}
                                    {group.drawn && ` · ${t('santa.drawn')}`}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
