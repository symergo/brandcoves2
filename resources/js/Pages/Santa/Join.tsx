import { Head, useForm, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatBudget, formatDay } from '../../types'
import { useTranslations } from '../../useTranslations'
import PageHeader from '../../Components/PageHeader'
import SantaBadge from '../../Components/SantaBadge'
import { buttonClasses, fieldClasses } from '../../Components/Button'
import { chipClasses, Countdown, InterestPicker } from '../../Components/SantaGame'

interface Props {
    group: {
        id: string
        title: string
        token: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        theme: string | null
        drawn: boolean
        members: number
        drawAt: string | null
    }
    members: string[]
    you: { name: string | null; email: string | null }
    interestKeys: string[]
}

/**
 * The page an invite link opens.
 *
 * There was none. `join` was a POST-only route and the invite the organiser
 * shares is that exact URL, so every link sent through a group chat answered
 * with 405 Method Not Allowed — and since no join form existed anywhere either,
 * nobody but the organiser had ever been in a group.
 *
 * No account asked for. Requiring a login before somebody can be in an office
 * Secret Santa is how most of the office does not join; the email is what the
 * draw needs to reach them, and the join token is what gets them back in.
 */
export default function SantaJoin({ group, members, you, interestKeys }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const form = useForm({
        display_name: you.name ?? '',
        email: you.email ?? '',
        // Names tapped below (owner, 2026-11-12): it was a line of names or
        // addresses to type, which asked people for addresses they do not have.
        exclusions: [] as string[],
        interests: [] as string[],
        hints: '',
    })

    const budget =
        group.budgetMax === null
            ? null
            : group.budgetMin === null || group.budgetMin === group.budgetMax
              ? formatBudget(group.budgetMax, market)
              : `${formatBudget(group.budgetMin, market)} – ${formatBudget(group.budgetMax, market)}`

    return (
        <>
            {/* An invite is a private URL. It must never be indexed. */}
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="mx-auto max-w-xl">
                {/* The pill, as on the group page, rather than an eyebrow
                    line: one way of saying what this is, everywhere. */}
                <PageHeader size="md" title={group.title} beside={<SantaBadge />} />

                <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-soft">
                    {budget && (
                        <div>
                            <dt className="inline">{t('santa.budget')}: </dt>
                            <dd className="inline font-medium text-ink">{budget}</dd>
                        </div>
                    )}
                    {group.exchangeDate && (
                        <div>
                            <dt className="inline">{t('santa.exchange_date')}: </dt>
                            <dd className="inline font-medium text-ink">{formatDay(group.exchangeDate, market, { year: 'auto' })}</dd>
                        </div>
                    )}
                    <div>
                        <dt className="inline">{t('santa.members')}: </dt>
                        <dd className="inline font-medium text-ink">{n(group.members)}</dd>
                    </div>
                </dl>

                {group.theme && <p className="mt-3 text-ink-soft">{group.theme}</p>}

                <p className="mt-3">
                    <Countdown drawn={group.drawn} drawAt={group.drawAt} exchangeDate={group.exchangeDate} />
                </p>

                {/*
                  Who else is in. Not a secret, and it is what tells somebody
                  they have opened the right group rather than a stranger's.
                */}
                {members.length > 0 && (
                    <p className="mt-4 text-sm text-ink-soft">{members.join(', ')}</p>
                )}

                {/*
                  Joining after the draw is fine since 2026-11-12: one giver
                  hands over their giftee, so the latecomer gets a name at
                  once. Said before they type anything.
                */}
                {group.drawn && (
                    <p className="mt-6 text-sm text-ink-soft">
                        {t('santa.late_note')}
                    </p>
                )}

                {(
                    <form
                        className="mt-6 space-y-4 rounded-card border border-line bg-card p-5"
                        onSubmit={(e) => {
                            e.preventDefault()

                            // The short route; the page was opened by this code.
                            form.post(`/${market.key}/s/${group.token}`)
                        }}
                    >
                        <div>
                            <label className="block text-sm font-medium" htmlFor="display_name">
                                {t('santa.your_name')}
                            </label>
                            <input
                                id="display_name"
                                required
                                maxLength={80}
                                value={form.data.display_name}
                                onChange={(e) => form.setData('display_name', e.target.value)}
                                className={fieldClasses()}
                            />
                            {form.errors.display_name && (
                                <p className="mt-1 text-sm text-danger">{form.errors.display_name}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium" htmlFor="email">
                                {t('santa.your_email')}
                            </label>
                            <input
                                id="email"
                                type="email"
                                required
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className={fieldClasses()}
                            />
                            <p className="mt-1 text-xs text-ink-soft">{t('santa.email_hint')}</p>
                            {form.errors.email && (
                                <p className="mt-1 text-sm text-danger">{form.errors.email}</p>
                            )}
                        </div>

                        {/* Who may I not draw: the names already in, to tap. */}
                        {members.length > 0 && (
                            <fieldset>
                                <legend className="block text-sm font-medium">{t('santa.exclusions')}</legend>
                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {members.map((name) => {
                                        const on = form.data.exclusions.includes(name)

                                        return (
                                            <li key={name}>
                                                <button
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() =>
                                                        form.setData(
                                                            'exclusions',
                                                            on ? form.data.exclusions.filter((v) => v !== name) : [...form.data.exclusions, name],
                                                        )
                                                    }
                                                    className={`${chipClasses(on)} ${on ? 'line-through' : ''}`}
                                                >
                                                    {name}
                                                </button>
                                            </li>
                                        )
                                    })}
                                </ul>
                                <p className="mt-1 text-xs text-ink-soft">{t('santa.exclusions_hint')}</p>
                            </fieldset>
                        )}

                        {/* A list in a few taps: what whoever draws me has to go on. */}
                        <fieldset>
                            <legend className="block text-sm font-medium">{t('santa.interests_title')}</legend>
                            <p className="mb-2 mt-1 text-xs text-ink-soft">{t('santa.interests_hint')}</p>
                            <InterestPicker keys={interestKeys} value={form.data.interests} onChange={(v) => form.setData('interests', v)} />
                        </fieldset>

                        <div>
                            <label className="block text-sm font-medium" htmlFor="hints">
                                {t('santa.hints_label')}
                            </label>
                            <input
                                id="hints"
                                maxLength={500}
                                value={form.data.hints}
                                onChange={(e) => form.setData('hints', e.target.value)}
                                placeholder={t('santa.hints_placeholder')}
                                className={fieldClasses()}
                            />
                        </div>

                        <button
                            disabled={form.processing}
                            className={buttonClasses('primary')}
                        >
                            {t('santa.join')}
                        </button>
                    </form>
                )}
            </div>
        </>
    )
}
