import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Option {
    value: string
    label: string
}

interface Props {
    /** The interests offered as chips. The full wizard offers all of them. */
    interests: Option[]
    className?: string
}

/** Enough to describe somebody, few enough to read in one pass. */
const SHOWN = 12

/** The wizard's own cap, so the teaser cannot post a brief the page would refuse. */
const MAX = 8

/**
 * The Gift Whisperer, as a few chips under the search form.
 *
 * Back on Find a gift on 2026-09-14, after a day away: it was pulled from the
 * menu on 2026-09-13 because the suggestions were not good enough to be the
 * most prominent answer to "find a gift", and what changed in between is that
 * a board now spreads across the interests somebody actually named instead of
 * answering the first one eight times over.
 *
 * A teaser rather than the wizard itself, and the shape is the home page's:
 * the search card asks the visitor who knows what they want, and the wizard
 * under it asks the one who does not. Two chips and a budget is a brief worth
 * answering, and anyone who wants the other five questions — age, vibe, taste,
 * what to avoid, who it is for — follows the link to the page, which is the
 * same POST landing on the same screen.
 */
export default function GiftWizardCard({ interests, className = '' }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const [chosen, setChosen] = useState<string[]>([])
    const [budget, setBudget] = useState('')
    const [sending, setSending] = useState(false)

    const toggle = (value: string) =>
        setChosen((current) =>
            current.includes(value)
                ? current.filter((v) => v !== value)
                : current.length >= MAX
                  ? current
                  : [...current, value],
        )

    const submit = () => {
        setSending(true)

        // The wizard's own endpoint, so the brief lands on the full page with
        // its board already on it — there is no second, lesser result screen
        // to keep in step with this one.
        router.post(
            `${base}/gift`,
            { interests: chosen, budget_max: budget === '' ? null : budget },
            { onFinish: () => setSending(false) },
        )
    }

    return (
        <section
            aria-labelledby="gift-wizard-heading"
            className={`rounded-card border border-line bg-card p-5 sm:p-6 ${className}`}
        >
            <h2 id="gift-wizard-heading" className="text-lg font-medium">
                {t('gift.title')}
            </h2>
            <p className="mt-1 text-sm text-ink-soft">{t('gift.subtitle')}</p>

            <p className="mt-5 text-sm font-medium">{t('gift.step_interests')}</p>

            <ul className="mt-3 flex flex-wrap gap-2">
                {interests.slice(0, SHOWN).map((interest) => {
                    const on = chosen.includes(interest.value)

                    return (
                        <li key={interest.value}>
                            <button
                                type="button"
                                aria-pressed={on}
                                onClick={() => toggle(interest.value)}
                                className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                    on ? 'border-accent bg-accent text-white' : 'border-line hover:border-ink'
                                }`}
                            >
                                {interest.label}
                            </button>
                        </li>
                    )
                })}
            </ul>

            <div className="mt-5 flex flex-wrap items-center gap-3">
                <label htmlFor="gift-teaser-budget" className="text-sm">
                    {t('gift.budget_up_to')}
                </label>
                <input
                    id="gift-teaser-budget"
                    type="number"
                    min="0"
                    step="1"
                    inputMode="numeric"
                    placeholder={t('gift.budget_any')}
                    value={budget}
                    onChange={(e) => setBudget(e.target.value)}
                    className="h-11 w-32 rounded-card border border-line bg-cream px-3"
                />

                <button
                    type="button"
                    onClick={submit}
                    // Nothing chosen is not a brief; the link below is the way
                    // in for somebody who would rather be asked than choose.
                    disabled={chosen.length === 0 || sending}
                    className="h-11 rounded-card bg-accent px-5 font-medium text-white transition hover:bg-accent-dark disabled:opacity-50"
                >
                    {t('gift.find')}
                </button>

                <a href={`${base}/gift`} className="text-sm text-ink-soft underline hover:text-ink">
                    {t('gift.more_questions')}
                </a>
            </div>
        </section>
    )
}
