import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../../types'
import { formatDay } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import { Honeypot, useFormClock } from '../../Components/BotTrap'
import Button, { fieldClasses } from '../../Components/Button'
import ToolIcon from '../../Components/ToolIcon'

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
 * The hub: what this is, the groups you are in, and the form to start one.
 *
 * Rebuilt 2026-11-12, the day after the owner called it "very unclear". It was
 * a title, a button, and under it an open form of seven fields ending in the
 * same button again, with nothing saying what happens next. Now, top to bottom:
 *
 * 1. Your groups, when you have any: they are why a returning visitor is here.
 * 2. Three steps, so a first visitor knows the whole thing before typing.
 * 3. One form that asks for one thing, the group's name (and, for a visitor
 *    without an account, a name and an address). Budget, date, theme, list
 *    and the automatic draw are behind "more options": all optional, all
 *    changeable later, none worth stopping for.
 *
 * Public and indexable. Starting a group needs no account since the same day
 * (SecretSantaController::store); joining never did.
 */
export default function SantaIndex({ groups, isSignedIn, myLists }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    // Somebody with groups came for those: the form waits behind a button.
    // Everybody else gets it open. `?new` (the homepage band) opens it too.
    const [creating, setCreating] = useState(groups.length === 0)

    useEffect(() => {
        if (new URLSearchParams(window.location.search).has('new')) {
            setCreating(true)
        }
    }, [])

    const elapsed = useFormClock(creating)

    const form = useForm({
        // Only asked of somebody who is not signed in: they get an account on
        // the spot.
        organiser_email: '',
        organiser_name: '',
        website: '',
        title: '',
        budget_max: '',
        exchange_date: '',
        theme: '',
        draw_at: '',
        // The organiser's own list, chosen here rather than afterwards: they
        // are a player too. Empty means "none yet".
        wishlist_id: '',
    })

    const field = fieldClasses()
    const steps: { title: string; body: string }[] = [1, 2, 3].map((step) => ({
        title: t(`santa.hub.step${step}_title`),
        body: t(`santa.hub.step${step}_body`),
    }))

    return (
        <>
            <Head title={t('santa.title')} />

            {/*
              One screen: what it is on the left, the form on the right (under
              it on a phone). Third pass, 2026-11-12: the first rebuild had a
              steps card, a form card and two headings and was "busy"; the
              second took the colour out and was "boring". This one has fewer
              things, and lets the form be the colourful one.
            */}
            <div className="grid gap-8 lg:grid-cols-2 lg:items-start lg:gap-12">
                <div>
                    <ToolIcon name="santa" className="h-14 w-14 text-accent" duo />
                    <h1 className="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">{t('santa.title')}</h1>
                    <p className="mt-3 text-lg text-ink-soft">{t('santa.hub.lead')}</p>

                    <ol className="mt-6 space-y-3">
                        {steps.map((step, index) => (
                            <li key={step.title} className="flex items-start gap-3">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent/10 text-sm font-semibold text-accent-dark">
                                    {index + 1}
                                </span>
                                <span>
                                    <span className="font-medium">{step.title}.</span> <span className="text-ink-soft">{step.body}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>

                <div>
                    {groups.length > 0 && (
                        <section className="mb-6" aria-labelledby="santa-groups">
                            <h2 id="santa-groups" className="font-semibold">
                                {t('santa.hub.your_groups')}
                            </h2>
                            <ul className="mt-2 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                                {groups.map((group) => (
                                    <li key={group.id}>
                                        <Link href={group.url} className="flex items-center gap-3 p-4 transition hover:bg-cream">
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium">{group.title}</span>
                                                <span className="block text-sm text-ink-soft">
                                                    {[
                                                        group.drawn ? t('santa.hub.status_drawn') : t('santa.hub.status_open'),
                                                        t('santa.members_count', { count: String(group.members) }),
                                                        group.exchangeDate ? formatDay(group.exchangeDate, market, { year: 'auto' }) : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                            <span aria-hidden className="text-ink-soft">
                                                →
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {!creating ? (
                        <Button type="button" onClick={() => setCreating(true)}>
                            {t('santa.hub.start_another')}
                        </Button>
                    ) : (
                        <form
                            id="santa-create"
                            className="rounded-card bg-accent/10 p-5 sm:p-6"
                            onSubmit={(e) => {
                                e.preventDefault()
                                // How long the form was open: the bot trap, see Components/BotTrap.
                                form.transform((data) => ({ ...data, elapsed_ms: elapsed() }))
                                form.post(`/${market.key}/santa`)
                            }}
                        >
                            <div className="space-y-4">
                                <label className="block">
                                    <span className="font-medium">{t('santa.group_name')}</span>
                                    <input
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                        required
                                        maxLength={120}
                                        placeholder={t('santa.hub.name_placeholder')}
                                        className={field}
                                    />
                                </label>

                                {!isSignedIn && (
                                    <>
                                        <Honeypot value={form.data.website} onChange={(v) => form.setData('website', v)} />
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <label className="block">
                                                <span className="font-medium">{t('santa.organiser_name')}</span>
                                                <input
                                                    autoComplete="name"
                                                    required
                                                    maxLength={80}
                                                    value={form.data.organiser_name}
                                                    onChange={(e) => form.setData('organiser_name', e.target.value)}
                                                    className={field}
                                                />
                                            </label>
                                            <label className="block">
                                                <span className="font-medium">{t('santa.organiser_email')}</span>
                                                <input
                                                    type="email"
                                                    autoComplete="email"
                                                    required
                                                    value={form.data.organiser_email}
                                                    onChange={(e) => form.setData('organiser_email', e.target.value)}
                                                    className={field}
                                                />
                                            </label>
                                        </div>
                                    </>
                                )}
                            </div>

                            {/* All optional, all changeable later on the group page. */}
                            <details className="mt-4">
                                <summary className="cursor-pointer text-sm text-ink-soft hover:text-ink">{t('santa.hub.more_options')}</summary>
                                <div className="mt-3 grid gap-4 sm:grid-cols-2">
                                    <label className="block">
                                        <span className="text-sm font-medium">{t('santa.hub.budget_label', { currency: market.currency })}</span>
                                        {/* Whole euros in, cents stored by the server. */}
                                        <input
                                            type="number"
                                            min={0}
                                            step="1"
                                            inputMode="numeric"
                                            placeholder="25"
                                            value={form.data.budget_max}
                                            onChange={(e) => form.setData('budget_max', e.target.value)}
                                            className={field}
                                        />
                                    </label>

                                    <label className="block">
                                        <span className="text-sm font-medium">{t('santa.exchange_date')}</span>
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

                                    <label className="block">
                                        <span className="text-sm font-medium">{t('santa.draw_at_label')}</span>
                                        <input
                                            type="datetime-local"
                                            value={form.data.draw_at}
                                            onChange={(e) => form.setData('draw_at', e.target.value)}
                                            className={field}
                                        />
                                    </label>

                                    {/* Only when there is a list to choose. */}
                                    {myLists.length > 0 && (
                                        <label className="block sm:col-span-2">
                                            <span className="text-sm font-medium">{t('santa.your_list')}</span>
                                            <select
                                                value={form.data.wishlist_id}
                                                onChange={(e) => form.setData('wishlist_id', e.target.value)}
                                                className={field}
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
                                </div>
                            </details>

                            {/* What the server refused, beside the button that sent it. */}
                            {Object.entries(form.errors).map(([name, message]) => (
                                <p key={name} className="mt-4 text-sm text-danger" role="alert">
                                    {message}
                                </p>
                            ))}

                            <Button type="submit" size="lg" busy={form.processing} className="mt-5 w-full">
                                {t('santa.create')}
                            </Button>

                            <p className="mt-3 text-center text-sm text-ink-soft">
                                {t('santa.hub.perks')}
                                {!isSignedIn && (
                                    <>
                                        {' '}
                                        <SignInLink hint={t('santa.create')} className="underline hover:text-ink">
                                            {t('nav.sign_in')}
                                        </SignInLink>
                                    </>
                                )}
                            </p>
                        </form>
                    )}
                </div>
            </div>

            <p className="mt-12 text-sm text-ink-soft">
                <Link href={`/${market.key}/lists-help/santa`} className="underline hover:text-ink">
                    {t('santa.how_it_works')}
                </Link>
            </p>
        </>
    )
}
