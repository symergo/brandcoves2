import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import Button from '../../Components/Button'
import InfoTip from '../../Components/InfoTip'

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
    /** The lists this person could point the group at: their own, about themselves. */
    myLists: { id: string; title: string }[]
}

/**
 * The hub, and the form to start a group.
 *
 * Public, so the page can explain what this is before asking for an account.
 * Creating a group needs one — somebody has to own it and be reachable when the
 * draw happens — but *joining* deliberately does not.
 */
export default function SantaIndex({ groups, isSignedIn, myLists }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [creating, setCreating] = useState(false)

    const form = useForm({
        title: '',
        budget_max: '',
        exchange_date: '',
        theme: '',
        /*
         * The organiser's own list, chosen here rather than afterwards.
         *
         * The organiser is a player too, and until 2026-09-12 the only way
         * to give whoever drew them something to go on was to leave this page,
         * open the list, and find "Use this list" next to the group. Asked on
         * the form, at the moment they are thinking about the group, it is
         * one field; asked later it is a trip most people never made.
         */
        wishlist_id: '',
    })

    const field = 'mt-1 w-full rounded-lg border border-line px-3 py-2'

    return (
        <>
            <Head title={t('santa.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight">{t('santa.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('santa.subtitle')}</p>
            </header>

            {!isSignedIn ? (
                <p className="mt-8 max-w-2xl rounded-card border border-line bg-card p-6">
                    <SignInLink hint={t('santa.create')} className="font-medium underline">
                        {t('nav.sign_in')}
                    </SignInLink>{' '}
                    <span className="text-ink-soft">{t('santa.create')}</span>
                </p>
            ) : (
                <div className="mt-8">
                    <button
                        type="button"
                        onClick={() => setCreating((v) => !v)}
                        aria-expanded={creating}
                        aria-controls="santa-create"
                        className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('santa.create')}
                    </button>

                    {/*
                      Full width, two fields to a row from `sm`.

                      The form was capped at `max-w-lg` and stacked five short
                      fields in one column, so on a desktop it was a narrow
                      strip down the left of an empty page and needed a scroll
                      to reach its button. Budget and date are both a few
                      characters wide, as are theme and the list; pairing them
                      halves the height and lets the form fill the width the
                      page already has. The name stays on its own row: it is
                      the one field long enough to want it.
                    */}
                    {creating && (
                        <form
                            id="santa-create"
                            className="mt-6 grid gap-4 rounded-card border border-line bg-card p-6 sm:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault()
                                form.post(`/${market.key}/santa`)
                            }}
                        >
                            <label className="block sm:col-span-2">
                                <span className="text-sm font-medium">{t('santa.group_name')}</span>
                                <input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    required
                                    maxLength={120}
                                    className={field}
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">
                                    {t('santa.budget')}
                                    <InfoTip className="ml-1">{t('santa.budget_hint')}</InfoTip>
                                </span>
                                {/* Euros here, cents in the column — the form
                                    shows the currency people think in. */}
                                <input
                                    type="number"
                                    min={0}
                                    step="1"
                                    value={form.data.budget_max}
                                    onChange={(e) => form.setData('budget_max', e.target.value)}
                                    className={field}
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">
                                    {t('santa.exchange_date')}
                                </span>
                                <input
                                    type="date"
                                    value={form.data.exchange_date}
                                    onChange={(e) => form.setData('exchange_date', e.target.value)}
                                    className={field}
                                />
                            </label>

                            <label className="block">
                                <span className="text-sm font-medium">{t('santa.theme')}</span>
                                <input
                                    value={form.data.theme}
                                    onChange={(e) => form.setData('theme', e.target.value)}
                                    maxLength={120}
                                    className={field}
                                />
                            </label>

                            {/*
                              Only when there is a list to choose. An empty
                              select with one "no list yet" option would be a
                              field that cannot be filled in; the group page
                              nudges list-building at the right moment instead.
                            */}
                            {myLists.length > 0 && (
                                <label className="block">
                                    <span className="text-sm font-medium">
                                        {t('santa.your_list')}
                                        <InfoTip className="ml-1">{t('santa.your_list_hint')}</InfoTip>
                                    </span>
                                    <select
                                        value={form.data.wishlist_id}
                                        onChange={(e) => form.setData('wishlist_id', e.target.value)}
                                        className={`${field} bg-card`}
                                    >
                                        <option value="">{t('santa.no_list_option')}</option>
                                        {myLists.map((list) => (
                                            <option key={list.id} value={list.id}>
                                                {list.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}

                            {/*
                              What the server refused. The form rendered none of
                              `form.errors`, so a bad budget or a date in the
                              past looked like a button that did not fire.
                            */}
                            {Object.entries(form.errors).map(([field, message]) => (
                                <p key={field} className="text-sm text-danger sm:col-span-2" role="alert">{message}</p>
                            ))}

                            <div className="sm:col-span-2">
                                <Button type="submit" busy={form.processing}>
                                    {t('santa.create')}
                                </Button>
                            </div>
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
                                {/* A labelled count, and no dangling separator
                                    when there is no date. */}
                                <p className="mt-1 text-sm text-ink-soft">
                                    {t('santa.members_count', { count: String(group.members) })}
                                    {group.exchangeDate && ` · ${group.exchangeDate}`}
                                    {group.drawn && ` · ${t('santa.drawn')}`}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {/*
              Under everything, as My Lists does with its own help link.
              Somebody with groups already does not need the explanation
              above their groups; somebody arriving cold reaches it in a
              screen, and the help topic walks through the whole thing:
              starting, inviting, drawing, dropping out, attaching a list.
            */}
            <p className="mt-12 border-t border-line pt-6 text-sm text-ink-soft">
                <Link href={`/${market.key}/lists-help/santa`} className="underline hover:text-ink">
                    {t('santa.how_it_works')}
                </Link>
            </p>
        </>
    )
}
