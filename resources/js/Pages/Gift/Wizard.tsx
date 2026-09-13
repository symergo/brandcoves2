import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, SavingTo, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import ChipInput from '../../Components/ChipInput'
import InfoTip from '../../Components/InfoTip'
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
    ageBand: string | null
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
    age_band?: string | null
    recipient_id?: string | null
    remember?: boolean
}

interface Props {
    options: { interests: Option[]; vibes: Option[]; values: string[]; ages: Option[] }
    recipients: Recipient[]
    picks: Pick[] | null
    brief: Brief | null
    /** The chosen person's list, where a save lands. Null without a person. */
    recipientList: SavingTo | null
}

const STEPS = ['who', 'interests', 'age', 'vibe', 'budget', 'avoid', 'values'] as const

/** The server caps a brief at eight interests; refusing the ninth here is the only visible place. */
const MAX_INTERESTS = 8

export default function GiftWizard({ options, recipients, picks, brief, recipientList }: Props) {
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
    const [values, setValues] = useState<string[]>(brief?.values ?? [])
    const [relationship, setRelationship] = useState<string | null>(brief?.relationship ?? null)
    // One of the fixed groups the server offers, never typed: an editor tags
    // a product with the same strings, so the two meet as one value.
    const [ageBand, setAgeBand] = useState<string | null>(brief?.age_band ?? null)
    const [recipientId, setRecipientId] = useState<string | null>(brief?.recipient_id ?? null)
    const [remember, setRemember] = useState<boolean>(brief?.remember ?? false)

    /*
     * "Adjust" shows the questions again with the answers kept, and no request:
     * the state above is already the truth. The results stay in props, so
     * "Back to the ideas" is the same flag the other way.
     */
    const [editing, setEditing] = useState(false)

    /*
     * A step with one button is not a step. "Who is it for?" exists to offer
     * the people you have already described; with nobody saved it is filtered
     * out rather than skipped over, so the counter, Back and Next all stay
     * correct without knowing it was ever there.
     */
    const steps = recipients.length > 0 ? STEPS : STEPS.filter((s) => s !== 'who')
    const firstQuestion = steps.indexOf('interests')

    const recipient = recipients.find((r) => r.id === recipientId) ?? null

    /*
     * Which of the interests are chips and which are the visitor's own words.
     * One list on the wire, because the engine does not care; two on screen,
     * because a typed word has no chip to light up.
     */
    const enumValues = new Set(options.interests.map((o) => o.value))
    const chosenChips = interests.filter((i) => enumValues.has(i))
    const ownWords = interests.filter((i) => !enumValues.has(i))
    const interestsFull = interests.length >= MAX_INTERESTS

    // Every key is posted every time, including the empty ones. The server
    // fills an *absent* key from the saved person's profile, so a cleared
    // answer has to travel as "cleared", not as "not mentioned".
    const payload = (overrides: Partial<{ remember: boolean }> = {}) => ({
        interests,
        vibe,
        budget_max: budgetMax === '' ? null : Number(budgetMax),
        avoid,
        values,
        relationship,
        age_band: ageBand,
        recipient_id: recipientId,
        remember,
        ...overrides,
    })

    const submit = () => {
        setEditing(false)
        router.post(`/${market.key}/gift`, payload(), { preserveScroll: false })
    }

    /*
     * "Something else."
     *
     * The rejected list used to live here, in component state, and be posted
     * back with each swap — and the swap's own response destroyed it, because
     * this posts without `preserveState` and Inertia rebuilds the component. So
     * the accumulator reset to empty on every round trip and the promise that
     * a rejected pick never returns was kept only until the second swap.
     *
     * The server remembers now (`RejectionMemory`), keyed by the brief. All
     * this has to send is which one was rejected.
     */
    const swap = (pickId: number) => {
        router.post(
            `/${market.key}/gift/swap`,
            { ...payload(), rejected: pickId },
            { preserveScroll: true },
        )
    }

    /*
     * "Four more" — past this board to the next one. The server works out
     * what is on screen for itself; nothing here lists the four ids.
     */
    const more = () => {
        router.post(`/${market.key}/gift/more`, payload(), { preserveScroll: true })
    }

    /*
     * Ticking "remember" on the results is saved at once, by re-posting the
     * brief. A plain post with the same brief returns the same four cards, so
     * the board does not move under the visitor; the tick is the only change.
     */
    const rememberNow = (on: boolean) => {
        setRemember(on)
        router.post(`/${market.key}/gift`, payload({ remember: on }), { preserveScroll: true })
    }

    const toggle = (list: string[], setter: (v: string[]) => void, value: string) => {
        setter(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
    }

    const useRecipient = (chosen: Recipient) => {
        // The second time you buy for your mother you should not have to
        // describe her again.
        setRecipientId(chosen.id)
        setInterests(chosen.interests)
        setVibe(chosen.vibe)
        setAvoid(chosen.avoid)
        setValues(chosen.values)
        setRelationship(chosen.relationship)
        setAgeBand(chosen.ageBand)
        setBudgetMax(chosen.budgetMax != null ? String(chosen.budgetMax / 100) : '')
        setStep(firstQuestion)
    }

    const someoneNew = () => {
        setRecipientId(null)
        setRemember(false)
        setStep(firstQuestion)
    }

    const reason = (pick: Pick) =>
        t(`gift.reasons.${pick.reason}`, { match: pick.reasonMatch ?? '' })

    const interestLabel = (value: string) =>
        options.interests.find((o) => o.value === value)?.label ?? value

    const chip = (selected: boolean, disabled = false) =>
        `rounded-full border px-3 py-1.5 text-sm ${
            selected ? 'border-accent bg-accent text-white' : 'border-line hover:bg-card'
        } ${disabled ? 'cursor-not-allowed opacity-50' : ''}`

    /**
     * The tick that keeps these answers on the person. Shown on the last
     * question and on the results; only when somebody saved was chosen,
     * because there is nobody to remember them for otherwise.
     */
    const rememberBox = (onChange: (on: boolean) => void) =>
        recipient && (
            <label className="flex flex-wrap items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="h-4 w-4"
                    checked={remember}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span>{t('gift.remember', { name: recipient.name })}</span>
                <InfoTip>{t('gift.remember_hint', { name: recipient.name })}</InfoTip>
            </label>
        )

    const showResults = picks !== null && !editing

    return (
        <>
            <Head title={t('gift.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift.subtitle')}</p>
            </header>

            {showResults ? (
                <section className="mt-8">
                    {/*
                      What you told us, in one line of chips, with the way back
                      to change one of them. The answers used to vanish behind
                      the results, so disliking one card meant "Start over" and
                      six questions again.
                    */}
                    <div className="flex flex-wrap items-center gap-2">
                        {recipient && (
                            <span className="text-sm font-medium">
                                {t('gift.summary_for', { name: recipient.name })}
                            </span>
                        )}
                        {interests.map((value) => (
                            <span key={value} className="rounded-full border border-line px-3 py-1 text-sm">
                                {interestLabel(value)}
                            </span>
                        ))}
                        {ageBand && (
                            <span className="rounded-full border border-line px-3 py-1 text-sm">
                                {options.ages.find((o) => o.value === ageBand)?.label ?? ageBand}
                            </span>
                        )}
                        {vibe && (
                            <span className="rounded-full border border-line px-3 py-1 text-sm">
                                {options.vibes.find((o) => o.value === vibe)?.label ?? vibe}
                            </span>
                        )}
                        <span className="rounded-full border border-line px-3 py-1 text-sm">
                            {budgetMax === ''
                                ? t('gift.budget_any')
                                : t('gift.summary_budget', {
                                      amount: formatPrice(Math.round(Number(budgetMax) * 100), market),
                                  })}
                        </span>
                        {avoid.map((word) => (
                            <span key={word} className="rounded-full border border-line px-3 py-1 text-sm text-ink-soft">
                                {t('gift.summary_avoid', { word })}
                            </span>
                        ))}
                        {values.map((value) => (
                            <span key={value} className="rounded-full border border-line px-3 py-1 text-sm">
                                {t(`gift.values.${value}`)}
                            </span>
                        ))}
                        <button
                            type="button"
                            className="text-sm text-accent underline"
                            onClick={() => {
                                setEditing(true)
                                setStep(firstQuestion)
                            }}
                        >
                            {t('gift.adjust')}
                        </button>
                    </div>

                    {recipient && <div className="mt-3">{rememberBox(rememberNow)}</div>}

                    <h2 className="mt-6 text-sm font-medium text-ink-soft">{t('gift.results_title')}</h2>

                    {recipientList && (
                        <p className="mt-1 text-sm text-ink-soft">
                            {t('gift.saving_to', { list: recipientList.title })}
                        </p>
                    )}

                    {picks.length === 0 ? (
                        <p className="mt-4 text-ink-soft">{t('gift.no_results')}</p>
                    ) : (
                        <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {picks.map((pick) => (
                                <li
                                    key={pick.id}
                                    className="flex flex-col rounded-card border border-line bg-card p-4"
                                >
                                    <Link href={pick.url}>
                                        {pick.image && (
                                            <img
                                                src={pick.image}
                                                alt=""
                                                className="mx-auto h-36 object-contain"
                                                loading="lazy"
                                            />
                                        )}
                                        <h3 className="mt-3 line-clamp-2 font-medium">{pick.title}</h3>
                                    </Link>

                                    {/* One reason. Three read as a machine justifying itself. */}
                                    <p className="mt-2 text-sm text-ink-soft">{reason(pick)}</p>

                                    <div className="mt-auto space-y-2 pt-4">
                                        <span className="block font-semibold">
                                            {pick.price === null ? '—' : formatPrice(pick.price, market)}
                                        </span>
                                        <div className="flex items-center gap-3">
                                            <SaveToList groupId={pick.id} into={recipientList ?? undefined} />
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

                    <div className="mt-8 flex flex-wrap gap-3">
                        <button
                            type="button"
                            className="rounded border border-line px-4 py-2 text-sm"
                            onClick={() => router.get(`/${market.key}/gift`)}
                        >
                            {t('gift.start_over')}
                        </button>
                        {/*
                          Nothing to move past when the board is empty; the
                          answers are what need changing, and Adjust is above.
                        */}
                        {picks.length > 0 && (
                            <button
                                type="button"
                                className="rounded bg-accent px-4 py-2 text-sm font-medium text-white"
                                onClick={more}
                            >
                                {t('gift.more')}
                            </button>
                        )}
                    </div>
                </section>
            ) : (
                <section className="mt-8 max-w-2xl">
                    <div className="flex items-baseline justify-between gap-3">
                        <p className="text-xs text-ink-soft">
                            {t('gift.step', { current: step + 1, total: steps.length })}
                        </p>
                        {editing && (
                            <button
                                type="button"
                                className="text-xs text-ink-soft underline"
                                onClick={() => setEditing(false)}
                            >
                                {t('gift.back_to_ideas')}
                            </button>
                        )}
                    </div>

                    <h2 className="mt-1 text-lg font-medium">{t(`gift.step_${steps[step]}`)}</h2>

                    <div className="mt-4">
                        {steps[step] === 'who' && (
                            <div className="space-y-2">
                                {recipients.map((person) => (
                                    <button
                                        key={person.id}
                                        type="button"
                                        className="block w-full rounded border border-line px-4 py-3 text-left hover:bg-card"
                                        onClick={() => useRecipient(person)}
                                    >
                                        {t('gift.recipient_use', { name: person.name })}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="block w-full rounded border border-line px-4 py-3 text-left hover:bg-card"
                                    onClick={someoneNew}
                                >
                                    {t('gift.recipient_none')}
                                </button>
                            </div>
                        )}

                        {steps[step] === 'interests' && (
                            <div>
                                <div className="flex flex-wrap gap-2">
                                    {options.interests.map((option) => {
                                        const selected = chosenChips.includes(option.value)
                                        const blocked = !selected && interestsFull

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                aria-pressed={selected}
                                                disabled={blocked}
                                                className={chip(selected, blocked)}
                                                onClick={() => toggle(interests, setInterests, option.value)}
                                            >
                                                {option.label}
                                            </button>
                                        )
                                    })}
                                </div>

                                {/*
                                  Words of your own. The engine searches them
                                  as typed — "wielrennen" retrieves what a chip
                                  never could — and the docs promised this long
                                  before the wizard offered a box for it.
                                */}
                                <div className="mt-5">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <label htmlFor="own-interest" className="text-sm font-medium">
                                            {t('gift.interests_other')}
                                        </label>
                                        <span className="text-xs text-ink-soft">{t('gift.interests_max')}</span>
                                    </div>
                                    <div className="mt-2">
                                        <ChipInput
                                            inputId="own-interest"
                                            value={ownWords}
                                            onChange={(words) => setInterests([...chosenChips, ...words])}
                                            placeholder={t('gift.interests_other_placeholder')}
                                            addLabel={t('gift.add')}
                                            max={MAX_INTERESTS - chosenChips.length}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        {steps[step] === 'age' && (
                            <div className="flex flex-wrap gap-2">
                                {options.ages.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={ageBand === option.value}
                                        className={chip(ageBand === option.value)}
                                        onClick={() => setAgeBand(ageBand === option.value ? null : option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {steps[step] === 'vibe' && (
                            <div className="flex flex-wrap gap-2">
                                {options.vibes.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={vibe === option.value}
                                        className={chip(vibe === option.value)}
                                        onClick={() => setVibe(vibe === option.value ? null : option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {steps[step] === 'budget' && (
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

                        {steps[step] === 'avoid' && (
                            <div>
                                <ChipInput
                                    value={avoid}
                                    onChange={setAvoid}
                                    placeholder={t('gift.avoid_placeholder')}
                                    addLabel={t('gift.add')}
                                    max={10}
                                />
                                <p className="mt-2 text-xs text-ink-soft">{t('gift.avoid_hint')}</p>
                            </div>
                        )}

                        {steps[step] === 'values' && (
                            <div>
                                <div className="flex flex-wrap gap-2">
                                    {options.values.map((value) => (
                                        <button
                                            key={value}
                                            type="button"
                                            aria-pressed={values.includes(value)}
                                            className={chip(values.includes(value))}
                                            onClick={() => toggle(values, setValues, value)}
                                        >
                                            {t(`gift.values.${value}`)}
                                        </button>
                                    ))}
                                </div>
                                {recipient && <div className="mt-5">{rememberBox(setRemember)}</div>}
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

                        {step < steps.length - 1 ? (
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
