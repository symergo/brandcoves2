import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import SaveToList from '../../Components/SaveToList'

interface Option {
    value: string
    label: string
}

interface Recipient {
    id: string
    name: string
    relationship: string | null
    interests: string[]
    vibe: string | null
    budgetMin: Cents | null
    budgetMax: Cents | null
    avoid: string[]
    values: string[]
}

interface Pick {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    merchantCount: number
    url: string
    reason: string
    reasonMatch: string | null
}

interface Brief {
    interests?: string[]
    vibe?: string | null
    budget_min?: number | null
    budget_max?: number | null
    avoid?: string[]
    values?: string[]
    relationship?: string | null
    occasion?: string | null
}

interface Props {
    options: { interests: Option[]; vibes: Option[]; values: string[] }
    recipients: Recipient[]
    picks: Pick[] | null
    brief: Brief | null
    isSwap?: boolean
}

const STEPS = ['who', 'interests', 'vibe', 'budget', 'avoid', 'values'] as const

export default function GiftWizard({ options, recipients, picks, brief }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Answers live in component state, not in the URL.
     *
     * A brief describes a real person — their tastes, what to avoid, what you
     * are willing to spend on them. That does not belong in a URL that ends up
     * in a referrer header or in a browser history someone else can read.
     */
    const [step, setStep] = useState(0)
    const [interests, setInterests] = useState<string[]>(brief?.interests ?? [])
    const [vibe, setVibe] = useState<string | null>(brief?.vibe ?? null)
    const [budgetMax, setBudgetMax] = useState<string>(
        brief?.budget_max != null ? String(brief.budget_max) : '',
    )
    const [avoid, setAvoid] = useState<string[]>(brief?.avoid ?? [])
    const [avoidDraft, setAvoidDraft] = useState('')
    const [values, setValues] = useState<string[]>(brief?.values ?? [])
    const [relationship, setRelationship] = useState<string | null>(brief?.relationship ?? null)

    // Everything already shown or swapped away, so "something else" never loops
    // back to what was just rejected.
    const [rejected, setRejected] = useState<number[]>([])

    const payload = () => ({
        interests,
        vibe,
        budget_max: budgetMax === '' ? null : Number(budgetMax),
        avoid,
        values,
        relationship,
    })

    const submit = () => {
        router.post(`/${market.key}/gift`, payload(), { preserveScroll: false })
    }

    const swap = (pickId: number) => {
        const exclude = [...rejected, ...(picks ?? []).map((p) => p.id)]
        setRejected(exclude)

        router.post(
            `/${market.key}/gift/swap`,
            { ...payload(), exclude, rejected: pickId },
            { preserveScroll: true },
        )
    }

    const toggle = (list: string[], setter: (v: string[]) => void, value: string) => {
        setter(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
    }

    const useRecipient = (recipient: Recipient) => {
        // The second time you buy for your mother you should not have to
        // describe her again.
        setInterests(recipient.interests)
        setVibe(recipient.vibe)
        setAvoid(recipient.avoid)
        setValues(recipient.values)
        setRelationship(recipient.relationship)
        setBudgetMax(recipient.budgetMax != null ? String(recipient.budgetMax / 100) : '')
        setStep(1)
    }

    const reason = (pick: Pick) =>
        t(`gift.reasons.${pick.reason}`, { match: pick.reasonMatch ?? '' })

    return (
        <>
            <Head title={t('gift.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift.subtitle')}</p>
            </header>

            {picks !== null ? (
                <section className="mt-8">
                    <h2 className="text-sm font-medium text-ink-soft">{t('gift.results_title')}</h2>

                    {picks.length === 0 ? (
                        <p className="mt-4 text-ink-soft">{t('gift.no_results')}</p>
                    ) : (
                        <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {picks.map((pick) => (
                                <li
                                    key={pick.id}
                                    className="flex flex-col rounded-lg border border-line bg-card p-4"
                                >
                                    <a href={pick.url}>
                                        {pick.image && (
                                            <img
                                                src={pick.image}
                                                alt=""
                                                className="mx-auto h-36 object-contain"
                                                loading="lazy"
                                            />
                                        )}
                                        <h3 className="mt-3 line-clamp-2 font-medium">{pick.title}</h3>
                                    </a>

                                    {/* One reason. Three read as a machine justifying itself. */}
                                    <p className="mt-2 text-sm text-ink-soft">{reason(pick)}</p>

                                    <div className="mt-auto space-y-2 pt-4">
                                        <span className="block font-semibold">
                                            {pick.price === null ? '—' : formatPrice(pick.price, market)}
                                        </span>
                                        <div className="flex items-center gap-3">
                                            <SaveToList groupId={pick.id} />
                                            <button
                                                type="button"
                                                className="text-xs text-ink-soft underline hover:text-ink"
                                                onClick={() => swap(pick.id)}
                                            >
                                                {t('gift.swap')}
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {/*
                      A refine bar rather than a restart. Someone who dislikes a
                      result wants to change one answer, not describe the person
                      from scratch.
                    */}
                    <div className="mt-8 flex flex-wrap gap-3">
                        <button
                            type="button"
                            className="rounded border border-line px-4 py-2 text-sm"
                            onClick={() => router.get(`/${market.key}/gift`)}
                        >
                            {t('gift.start_over')}
                        </button>
                        <button
                            type="button"
                            className="rounded bg-accent px-4 py-2 text-sm font-medium text-white"
                            onClick={submit}
                        >
                            {t('gift.again')}
                        </button>
                    </div>
                </section>
            ) : (
                <section className="mt-8 max-w-2xl">
                    <p className="text-xs text-ink-soft">
                        {t('gift.step', { current: step + 1, total: STEPS.length })}
                    </p>

                    <h2 className="mt-1 text-lg font-medium">{t(`gift.step_${STEPS[step]}`)}</h2>

                    <div className="mt-4">
                        {STEPS[step] === 'who' && (
                            <div className="space-y-2">
                                {recipients.map((recipient) => (
                                    <button
                                        key={recipient.id}
                                        type="button"
                                        className="block w-full rounded border border-line px-4 py-3 text-left hover:bg-card"
                                        onClick={() => useRecipient(recipient)}
                                    >
                                        {t('gift.recipient_use', { name: recipient.name })}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="block w-full rounded border border-line px-4 py-3 text-left hover:bg-card"
                                    onClick={() => setStep(1)}
                                >
                                    {t('gift.recipient_none')}
                                </button>
                            </div>
                        )}

                        {STEPS[step] === 'interests' && (
                            <div className="flex flex-wrap gap-2">
                                {options.interests.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={interests.includes(option.value)}
                                        className={`rounded-full border px-3 py-1.5 text-sm ${
                                            interests.includes(option.value)
                                                ? 'border-accent bg-accent text-white'
                                                : 'border-line hover:bg-card'
                                        }`}
                                        onClick={() => toggle(interests, setInterests, option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {STEPS[step] === 'vibe' && (
                            <div className="flex flex-wrap gap-2">
                                {options.vibes.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={vibe === option.value}
                                        className={`rounded-full border px-4 py-2 text-sm ${
                                            vibe === option.value
                                                ? 'border-accent bg-accent text-white'
                                                : 'border-line hover:bg-card'
                                        }`}
                                        onClick={() => setVibe(vibe === option.value ? null : option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {STEPS[step] === 'budget' && (
                            <div className="flex items-center gap-2">
                                <label htmlFor="budget-max" className="text-sm">
                                    {t('gift.budget_up_to')}
                                </label>
                                <input
                                    id="budget-max"
                                    type="number"
                                    min="0"
                                    step="1"
                                    className="w-32 rounded border border-line px-3 py-2"
                                    placeholder={t('gift.budget_any')}
                                    value={budgetMax}
                                    onChange={(e) => setBudgetMax(e.target.value)}
                                />
                            </div>
                        )}

                        {STEPS[step] === 'avoid' && (
                            <div>
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        className="flex-1 rounded border border-line px-3 py-2"
                                        placeholder={t('gift.avoid_placeholder')}
                                        value={avoidDraft}
                                        onChange={(e) => setAvoidDraft(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && avoidDraft.trim() !== '') {
                                                e.preventDefault()
                                                setAvoid([...avoid, avoidDraft.trim()])
                                                setAvoidDraft('')
                                            }
                                        }}
                                    />
                                    <button
                                        type="button"
                                        className="rounded border border-line px-4 text-sm"
                                        onClick={() => {
                                            if (avoidDraft.trim() !== '') {
                                                setAvoid([...avoid, avoidDraft.trim()])
                                                setAvoidDraft('')
                                            }
                                        }}
                                    >
                                        {t('gift.avoid_add')}
                                    </button>
                                </div>
                                <p className="mt-2 text-xs text-ink-soft">{t('gift.avoid_hint')}</p>
                                {avoid.length > 0 && (
                                    <ul className="mt-3 flex flex-wrap gap-2">
                                        {avoid.map((word) => (
                                            <li key={word}>
                                                <button
                                                    type="button"
                                                    className="rounded-full border border-line px-3 py-1 text-sm"
                                                    onClick={() => setAvoid(avoid.filter((w) => w !== word))}
                                                >
                                                    {word} ×
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        {STEPS[step] === 'values' && (
                            <div className="flex flex-wrap gap-2">
                                {options.values.map((value) => (
                                    <button
                                        key={value}
                                        type="button"
                                        aria-pressed={values.includes(value)}
                                        className={`rounded-full border px-3 py-1.5 text-sm ${
                                            values.includes(value)
                                                ? 'border-accent bg-accent text-white'
                                                : 'border-line hover:bg-card'
                                        }`}
                                        onClick={() => toggle(values, setValues, value)}
                                    >
                                        {t(`gift.values.${value}`)}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="mt-8 flex items-center gap-3">
                        {step > 0 && (
                            <button
                                type="button"
                                className="rounded border border-line px-4 py-2 text-sm"
                                onClick={() => setStep(step - 1)}
                            >
                                {t('gift.back')}
                            </button>
                        )}

                        {step < STEPS.length - 1 ? (
                            <>
                                <button
                                    type="button"
                                    className="rounded bg-accent px-5 py-2 font-medium text-white"
                                    onClick={() => setStep(step + 1)}
                                >
                                    {t('gift.next')}
                                </button>
                                {/*
                                  Every step after the first is skippable. The
                                  engine treats an unanswered question as "does
                                  not apply" rather than as a zero, so a person
                                  who only knows one thing about the recipient
                                  still gets a real answer.
                                */}
                                <button
                                    type="button"
                                    className="text-sm text-ink-soft underline"
                                    onClick={submit}
                                >
                                    {t('gift.find')}
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                className="rounded bg-accent px-5 py-2 font-medium text-white"
                                onClick={submit}
                            >
                                {t('gift.find')}
                            </button>
                        )}
                    </div>
                </section>
            )}
        </>
    )
}
