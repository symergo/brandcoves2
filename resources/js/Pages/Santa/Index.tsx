import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../../types'
import { formatDay } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import { Honeypot, useFormClock } from '../../Components/BotTrap'
import Button, { fieldClasses } from '../../Components/Button'
import PageHeader from '../../Components/PageHeader'

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

            <PageHeader className="max-w-3xl" title={t('santa.title')}>
                <p className="mt-3 text-lg text-ink-soft">{t('santa.hub.lead')}</p>
            </PageHeader>

            {groups.length > 0 && (
                <section className="mt-8" aria-labelledby="santa-groups">
                    <h2 id="santa-groups" className="text-lg font-semibold">
                        {t('santa.hub.your_groups')}
                    </h2>
                    <ul className="mt-3 grid gap-4 sm:grid-cols-2">
                        {groups.map((group) => (
                            <li key={group.id}>
                                <Link href={group.url} className="block rounded-card border border-line bg-card p-5 transition hover:border-ink">
                                    <span className="block font-medium">{group.title}</span>
                                    <span className="mt-1 block text-sm text-ink-soft">
                                        {[
                                            group.drawn ? t('santa.hub.status_drawn') : t('santa.hub.status_open'),
                                            t('santa.members_count', { count: String(group.members) }),
                                            group.exchangeDate ? formatDay(group.exchangeDate, market, { year: 'auto' }) : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/* The whole thing in three steps, before anybody types anything. */}
            <section className="mt-10" aria-labelledby="santa-how">
                <h2 id="santa-how" className="text-lg font-semibold">
                    {t('santa.hub.how_title')}
                </h2>
                <ol className="mt-3 grid gap-x-8 gap-y-4 rounded-card border border-line bg-card p-5 md:grid-cols-3">
                    {steps.map((step, index) => (
                        <li key={step.title}>
                            <span className="font-medium">
                                {index + 1}. {step.title}
                            </span>
                            <p className="mt-1 text-sm text-ink-soft">{step.body}</p>
                        </li>
                    ))}
                </ol>
                <p className="mt-3 text-sm text-ink-soft">{t('santa.hub.perks')}</p>
            </section>

            <section className="mt-10 max-w-3xl" aria-labelledby="santa-start">
                {!creating ? (
                    <Button type="button" onClick={() => setCreating(true)}>
                        {t('santa.hub.start_another')}
                    </Button>
                ) : (
                    <form
                        id="santa-create"
                        className="rounded-card border border-line bg-card p-5 sm:p-6"
                        onSubmit={(e) => {
                            e.preventDefault()
                            // How long the form was open: the bot trap, see Components/BotTrap.
                            form.transform((data) => ({ ...data, elapsed_ms: elapsed() }))
                            form.post(`/${market.key}/santa`)
                        }}
                    >
                        <h2 id="santa-start" className="text-lg font-semibold">
                            {t('santa.hub.form_title')}
                        </h2>
                        <p className="mt-1 text-sm text-ink-soft">{t('santa.hub.form_hint')}</p>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <label className="block sm:col-span-2">
                                <span className="text-sm font-medium">{t('santa.group_name')}</span>
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
                                    <label className="block">
                                        <span className="text-sm font-medium">{t('santa.organiser_name')}</span>
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
                                        <span className="text-sm font-medium">{t('santa.organiser_email')}</span>
                                        <input
                                            type="email"
                                            autoComplete="email"
                                            required
                                            value={form.data.organiser_email}
                                            onChange={(e) => form.setData('organiser_email', e.target.value)}
                                            className={field}
                                        />
                                    </label>
                                    <p className="text-sm text-ink-soft sm:col-span-2">
                                        {t('santa.organiser_hint')}{' '}
                                        <SignInLink hint={t('santa.create')} className="underline hover:text-ink">
                                            {t('nav.sign_in')}
                                        </SignInLink>
                                    </p>
                                </>
                            )}
                        </div>

                        {/* All optional, all changeable later on the group page. */}
                        <details className="mt-5 border-t border-line pt-4">
                            <summary className="cursor-pointer text-sm font-medium">{t('santa.hub.more_options')}</summary>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
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
                                    <span className="mt-1 block text-xs text-ink-soft">{t('santa.budget_hint')}</span>
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
                                    <span className="mt-1 block text-xs text-ink-soft">{t('santa.hub.draw_at_hint')}</span>
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
                                        <span className="mt-1 block text-xs text-ink-soft">{t('santa.your_list_hint')}</span>
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

                        <div className="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <Button type="submit" busy={form.processing}>
                                {t('santa.create')}
                            </Button>
                            <span className="text-sm text-ink-soft">{t('santa.hub.next_hint')}</span>
                        </div>
                    </form>
                )}
            </section>

            <p className="mt-12 border-t border-line pt-6 text-sm text-ink-soft">
                <Link href={`/${market.key}/lists-help/santa`} className="underline hover:text-ink">
                    {t('santa.how_it_works')}
                </Link>
            </p>
        </>
    )
}
