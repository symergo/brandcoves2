import { Head, Link, router, usePage } from '@inertiajs/react'
import { Fragment, useState } from 'react'
import type { Cents, SavingTo, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import ChipInput from '../../Components/ChipInput'
import GiftResults, { type GiftPick, type GiftResultsExtras } from '../../Components/GiftResults'
import InfoTip from '../../Components/InfoTip'
import SceneIllustration, { type SceneKey } from '../../Components/SceneIllustration'
import ToolIcon from '../../Components/ToolIcon'
import GiftProfileCardBanner, { type GiftProfileCardProps } from '../../Components/GiftProfileCardBanner'

interface Option {
    value: string
    label: string
}

interface Recipient {
    id: string
    name: string
    relationship: string | null
    /** The relationship read as the closed vocabulary ("mama" as `mother`), or null. */
    relationshipType?: string | null
    interests: string[]
    vibe: string | null
    preferences: string[]
    budgetMin: Cents | null
    budgetMax: Cents | null
    avoid: string[]
    values: string[]
    ageBand: string | null
}

interface Brief {
    interests?: string[]
    vibe?: string | null
    preferences?: string[]
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

/** A persona Cove: the third way in, "Start from a type". */
interface PersonaCard {
    title: string
    intro: string | null
    url: string
    scene: SceneKey | null
    /** Who its plan was written for, when it was written for somebody in particular. */
    relationship?: string | null
}

interface Props extends GiftResultsExtras {
    /** The persona Coves for "Start from a type"; empty means that way is not offered. */
    personas?: PersonaCard[]
    options: {
        interests: Option[]
        vibes: Option[]
        /** Taste as axes: each one drawn as its two ends, so picking a side is one click. */
        preferences: { axis: string; poles: [Option, Option] }[]
        ages: Option[]
        /** "Who is it for?" without a saved person: the closed vocabulary. */
        relationships?: Option[]
    }
    recipients: Recipient[]
    picks: GiftPick[] | null
    brief: Brief | null
    /** The chosen person's list, where a save lands. Null without a person. */
    recipientList: SavingTo | null
    /** Opened from somebody's gift profile card: the answers below come from it. */
    card?: GiftProfileCardProps | null
    /** This or that, the second way in. */
    tasteUrl?: string
}

/*
  The questions, after "Who is it for?" and the choice of way.

  No values step (owner's call, 2026-09-14): a saved person still carries
  values from their own page and the brief picks them up server-side. No
  "who" step either since "Find a gift" became one flow (2026-09-26): who it
  is for is the flow's first question, asked once for all three ways.
*/
const STEPS = ['interests', 'age', 'vibe', 'budget', 'avoid'] as const

/** The server caps a brief at eight interests; refusing the ninth here is the only visible place. */
const MAX_INTERESTS = 8

/** How many persona Coves the "Start from a type" column shows before "All". */
const TYPES_SHOWN = 4

/**
 * "Find a gift": one flow, one results page.
 *
 * 1. **Who is it for?** One of your saved people, or a kind of person
 *    (partner, mum, a colleague), or skip it.
 * 2. **Three ways, side by side**: answer a few questions (below), choose
 *    between two things (This or that, carrying who it is for), or start
 *    from a type (the persona Coves, the ones for that kind of person first).
 * 3. **One results page** (GiftResults), which This or that ends on as well.
 *
 * A profile card opens straight on the questions, filled in; `?for=` opens
 * straight on the results. See docs/features/find-a-gift.md.
 */
export default function GiftWizard(props: Props) {
    const { options, recipients, picks, brief, recipientList, card = null, personas = [], tasteUrl } = props
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Answers live in component state, not in the URL.
     *
     * A brief describes a real person — their tastes, what to avoid, what you
     * are willing to spend on them. That does not belong in a URL that ends up
     * in a referrer header or in a browser history someone else can read.
     */
    const [stage, setStage] = useState<'who' | 'ways' | 'questions'>(card || brief ? 'questions' : 'who')
    const [step, setStep] = useState(0)
    const [interests, setInterests] = useState<string[]>(brief?.interests ?? [])
    const [vibe, setVibe] = useState<string | null>(brief?.vibe ?? null)
    const [preferences, setPreferences] = useState<string[]>(brief?.preferences ?? [])
    const [budgetMax, setBudgetMax] = useState<string>(
        brief?.budget_max != null ? String(brief.budget_max) : '',
    )
    // Not asked, but carried: This or that learns a band, and "Refine with
    // the questions" posts it here, so it has to travel back with each post.
    const [budgetMin, setBudgetMin] = useState<number | null>(brief?.budget_min ?? null)
    const [avoid, setAvoid] = useState<string[]>(brief?.avoid ?? [])
    /*
      No step asks for these any more, but a saved person carries them from
      their own page and the brief echoes them back, so they stay in the
      payload and on the summary row.
    */
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

    const recipient = recipients.find((r) => r.id === recipientId) ?? null
    const relationshipOptions = options.relationships ?? []

    /** Who it is for as the closed vocabulary: the saved person's, or the chip picked. */
    const kind: string | null =
        recipient?.relationshipType ??
        (relationship && relationshipOptions.some((o) => o.value === relationship) ? relationship : null)

    const whoLabel = recipient
        ? recipient.name
        : kind
          ? (relationshipOptions.find((o) => o.value === kind)?.label ?? kind)
          : null

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
        preferences,
        // Only when there is one: an absent floor is filled from the saved
        // person, and a posted null would wipe theirs.
        ...(budgetMin !== null && budgetMax !== '' ? { budget_min: budgetMin } : {}),
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
     * The server remembers what was rejected (`RejectionMemory`), keyed by the
     * brief, so all this sends is which one. A list kept here was destroyed
     * by the swap's own response, which rebuilds the component.
     */
    const swap = (pickId: number) => {
        router.post(`/${market.key}/gift/swap`, { ...payload(), rejected: pickId }, { preserveScroll: true })
    }

    /*
     * "Eight more" — past this board to the next one. The server works out
     * what is on screen for itself; nothing here lists the ids.
     */
    const more = () => {
        router.post(`/${market.key}/gift/more`, payload(), { preserveScroll: true })
    }

    /*
     * Ticking "remember" on the results is saved at once, by re-posting the
     * brief. A plain post with the same brief returns the same cards, so the
     * board does not move under the visitor; the tick is the only change.
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
        setPreferences(chosen.preferences ?? [])
        setAvoid(chosen.avoid)
        setValues(chosen.values)
        setRelationship(chosen.relationship)
        setAgeBand(chosen.ageBand)
        setBudgetMax(chosen.budgetMax != null ? String(chosen.budgetMax / 100) : '')
        setBudgetMin(null)
        setStage('ways')
    }

    /** A kind of person, or nobody in particular (null). Clears a saved person picked before. */
    const useKind = (value: string | null) => {
        if (recipientId !== null) {
            setRecipientId(null)
            setRemember(false)
            setInterests([])
            setVibe(null)
            setPreferences([])
            setAvoid([])
            setValues([])
            setAgeBand(null)
            setBudgetMax('')
        }

        setRelationship(value)
        setStage('ways')
    }

    const interestLabel = (value: string) => options.interests.find((o) => o.value === value)?.label ?? value

    /*
      An avoided *interest*, as This or that learns it ("not gaming"), is kept
      in the tag's spelling, `interest:gaming`, so the engine leaves out the
      tag and never a title word (TasteBrief::avoidedInterests). It reads as
      the interest's name, and it is removed with a tap rather than typed.
    */
    const LEARNED = 'interest:'
    const avoidLabel = (word: string) => (word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word)
    const learnedAvoid = avoid.filter((word) => word.startsWith(LEARNED))
    const typedAvoid = avoid.filter((word) => !word.startsWith(LEARNED))

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
                <input type="checkbox" className="h-4 w-4" checked={remember} onChange={(e) => onChange(e.target.checked)} />
                <span>{t('gift.remember', { name: recipient.name })}</span>
                <InfoTip>{t('gift.remember_hint', { name: recipient.name })}</InfoTip>
            </label>
        )

    /*
      This or that, carrying who it is for: a saved person by id (the server
      checks it is yours, and leaves out what they were given), a kind of
      person by its value. Neither describes the person, so both may sit in a
      URL, where the answers may not.
    */
    const tasteHref = (() => {
        const base = tasteUrl ?? `/${market.key}/gift/taste`

        if (recipient) {
            return `${base}?person=${encodeURIComponent(recipient.id)}`
        }

        return kind ? `${base}?relationship=${encodeURIComponent(kind)}` : base
    })()

    /*
      The persona Coves for this kind of person first, then the ones for
      anybody, then the rest; newest first within each, as the server sent
      them. Nothing is filtered out: a type is recognised on sight, and "the
      home cook" may be the right shelf for a colleague.
    */
    const types = [...personas]
        .map((persona, index) => ({ persona, index }))
        .sort((a, b) => {
            const rank = (p: PersonaCard) => (kind && p.relationship === kind ? 0 : p.relationship ? 2 : 1)
            return rank(a.persona) - rank(b.persona) || a.index - b.index
        })
        .slice(0, TYPES_SHOWN)
        .map(({ persona }) => persona)

    const showResults = picks !== null && !editing

    /** "For Mum · change", above the ways and the questions. */
    const forLine = !card && (
        <p className="flex flex-wrap items-center gap-2 text-sm">
            <span className="rounded-full bg-accent/10 px-3 py-1 font-medium text-accent-dark">
                {whoLabel ? t('gift.for_label', { who: whoLabel }) : t('gift.for_someone')}
            </span>
            <button
                type="button"
                className="text-accent underline"
                onClick={() => {
                    setEditing(false)
                    setStage('who')
                }}
            >
                {t('gift.change')}
            </button>
        </p>
    )

    return (
        <>
            <Head title={card?.title ?? t('gift.title')}>{card && <meta name="robots" content="noindex, nofollow" />}</Head>

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift.subtitle')}</p>
            </header>

            {card && !showResults && <GiftProfileCardBanner card={card} onSeeIdeas={submit} />}

            {showResults ? (
                <GiftResults
                    picks={picks}
                    pageUrl={props.pageUrl}
                    offlineIdeas={props.offlineIdeas}
                    communityCoves={props.communityCoves}
                    nextSteps={props.nextSteps}
                    personUrl={props.personUrl}
                    askUrl={props.askUrl}
                    into={recipientList}
                    personName={recipient?.name ?? null}
                    onSwap={swap}
                    interestLabel={interestLabel}
                    top={
                        <>
                            {/*
                              What you told us, in one line of chips, with the
                              way back to change one of them.
                            */}
                            <div className="flex flex-wrap items-center gap-2">
                                {whoLabel && (
                                    <span className="text-sm font-medium">{t('gift.summary_for', { name: whoLabel })}</span>
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
                                {preferences.map((preference) => (
                                    <span key={preference} className="rounded-full border border-line px-3 py-1 text-sm">
                                        {t(`gift.preferences.${preference}`)}
                                    </span>
                                ))}
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
                                        {t('gift.summary_avoid', { word: avoidLabel(word) })}
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
                                        setStage('questions')
                                        setStep(0)
                                    }}
                                >
                                    {t('gift.adjust')}
                                </button>
                            </div>

                            {recipient && <div className="mt-3">{rememberBox(rememberNow)}</div>}
                        </>
                    }
                    actions={
                        <>
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
                        </>
                    }
                />
            ) : stage === 'who' ? (
                <section className="mt-8" aria-labelledby="gift-who">
                    <h2 id="gift-who" className="text-lg font-medium">
                        {t('gift.who_title')}
                    </h2>

                    {recipients.length > 0 && (
                        <div className="mt-4">
                            <p className="text-sm text-ink-soft">{t('gift.who_people')}</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {recipients.map((person) => (
                                    <button
                                        key={person.id}
                                        type="button"
                                        aria-pressed={recipientId === person.id}
                                        className={chip(recipientId === person.id)}
                                        onClick={() => useRecipient(person)}
                                    >
                                        {person.name}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {relationshipOptions.length > 0 && (
                        <div className="mt-5">
                            {recipients.length > 0 && <p className="text-sm text-ink-soft">{t('gift.who_type')}</p>}
                            <div className="mt-2 flex flex-wrap gap-2">
                                {relationshipOptions.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={recipient === null && relationship === option.value}
                                        className={chip(recipient === null && relationship === option.value)}
                                        onClick={() => useKind(option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <button type="button" className="mt-6 text-sm text-ink-soft underline hover:text-ink" onClick={() => useKind(null)}>
                        {t('gift.who_skip')}
                    </button>
                </section>
            ) : stage === 'ways' ? (
                <section className="mt-8" aria-labelledby="gift-ways">
                    {forLine}

                    <h2 id="gift-ways" className="mt-5 text-lg font-medium">
                        {t('gift.ways_title')}
                    </h2>

                    {/*
                      Three ways side by side, or two when this market has no
                      persona Coves yet: nothing for a column means no column
                      (owner, 2026-09-26), so the other two share the width.
                    */}
                    <div className={`mt-4 grid gap-4 sm:grid-cols-2 ${types.length > 0 ? 'lg:grid-cols-3' : ''}`}>
                        <button
                            type="button"
                            onClick={() => {
                                setStep(0)
                                setStage('questions')
                            }}
                            className="group flex flex-col rounded-card border border-line bg-card p-5 text-left transition hover:border-ink"
                        >
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                <ToolIcon name="suggestions" className="h-5 w-5" />
                            </span>
                            <span className="mt-3 font-medium">{t('gift.way_questions')}</span>
                            <span className="mt-1 text-sm text-ink-soft">{t('gift.way_questions_hint')}</span>
                            <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.way_questions_cta')} →</span>
                        </button>

                        <Link
                            href={tasteHref}
                            className="group flex flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                        >
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                <ToolIcon name="taste" className="h-5 w-5" />
                            </span>
                            <span className="mt-3 font-medium text-ink">{t('gift.way_taste')}</span>
                            <span className="mt-1 text-sm text-ink-soft">{t('gift.way_taste_hint')}</span>
                            <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.way_taste_cta')} →</span>
                        </Link>

                        {types.length > 0 && (
                            <div className="flex flex-col rounded-card border border-line bg-card p-5 sm:col-span-2 lg:col-span-1">
                                <span className="font-medium">{t('gift.way_types')}</span>
                                <span className="mt-1 text-sm text-ink-soft">{t('gift.personas_hint')}</span>
                                <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
                                    {types.map((persona) => (
                                        <li key={persona.url}>
                                            <Link
                                                href={persona.url}
                                                className="group flex items-center gap-3 rounded-lg border border-line p-2 transition hover:border-ink"
                                            >
                                                <SceneIllustration
                                                    name={persona.scene}
                                                    className="h-9 w-12 shrink-0 text-ink-soft transition group-hover:text-accent"
                                                />
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium text-ink">{persona.title}</span>
                                                    {persona.intro && (
                                                        <span className="line-clamp-1 block text-xs text-ink-soft">{persona.intro}</span>
                                                    )}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                <Link
                                    href={`/${market.key}/gift-ideas`}
                                    className="mt-auto pt-3 text-sm font-medium text-accent-dark hover:text-ink"
                                >
                                    {t('discover_cove.persona_all')} →
                                </Link>
                            </div>
                        )}
                    </div>
                </section>
            ) : (
                <section className="mt-8 max-w-3xl">
                    {forLine}

                    <div className={`${card ? '' : 'mt-5'} flex items-baseline justify-between gap-3`}>
                        <p className="text-xs text-ink-soft">{t('gift.step', { current: step + 1, total: STEPS.length })}</p>
                        {editing && (
                            <button type="button" className="text-xs text-ink-soft underline" onClick={() => setEditing(false)}>
                                {t('gift.back_to_ideas')}
                            </button>
                        )}
                    </div>

                    <h2 className="mt-1 text-lg font-medium">{t(`gift.step_${STEPS[step]}`)}</h2>

                    <div className="mt-4">
                        {STEPS[step] === 'interests' && (
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
                                  never could.
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

                                {/*
                                  Stuck here? The other way to answer this
                                  step: choose between products and let the
                                  choices say it (taste-discovery.md).
                                */}
                                {!card && (
                                    <Link href={tasteHref} className="mt-5 inline-flex items-center gap-2 text-sm text-accent underline">
                                        <ToolIcon name="taste" className="h-4 w-4" />
                                        {t('gift.taste.from_finder')}
                                    </Link>
                                )}
                            </div>
                        )}

                        {STEPS[step] === 'age' && (
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

                        {STEPS[step] === 'vibe' && (
                            /*
                              Two rows, one question: what a present is for and
                              what it looks like are independent, but nobody
                              experiences them as two questions about the same
                              person, and a step of its own is a step people skip.
                            */
                            <div className="space-y-5">
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

                                {/*
                                  Taste as pairs of opposites, one row an axis.
                                  Shown both ends, a person recognises their own
                                  taste; shown a bag of words, they read all of
                                  them and pick none. Picking one end clears the
                                  other; the cap of three counts the whole answer.
                                */}
                                <div>
                                    <p className="mb-3 text-sm text-ink-soft">{t('gift.preference_label')}</p>
                                    <div className="space-y-2">
                                        {options.preferences.map(({ axis, poles }) => (
                                            <div key={axis} className="flex flex-wrap items-center gap-2">
                                                {poles.map((pole, index) => (
                                                    <Fragment key={pole.value}>
                                                        {index === 1 && (
                                                            <span aria-hidden className="text-xs text-ink-soft">
                                                                {t('gift.preference_or')}
                                                            </span>
                                                        )}
                                                        <button
                                                            type="button"
                                                            aria-pressed={preferences.includes(pole.value)}
                                                            className={chip(preferences.includes(pole.value))}
                                                            onClick={() =>
                                                                setPreferences((current) => {
                                                                    const other = poles[index === 0 ? 1 : 0].value
                                                                    const without = current.filter(
                                                                        (value) => value !== pole.value && value !== other,
                                                                    )

                                                                    return current.includes(pole.value) || without.length >= 3
                                                                        ? without
                                                                        : [...without, pole.value]
                                                                })
                                                            }
                                                        >
                                                            {pole.label}
                                                        </button>
                                                    </Fragment>
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                </div>
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
                                    onChange={(e) => {
                                        setBudgetMax(e.target.value)
                                        // A typed ceiling replaces a learned band.
                                        setBudgetMin(null)
                                    }}
                                />
                            </div>
                        )}

                        {STEPS[step] === 'avoid' && (
                            <div>
                                <ChipInput
                                    value={typedAvoid}
                                    onChange={(words) => setAvoid([...learnedAvoid, ...words])}
                                    placeholder={t('gift.avoid_placeholder')}
                                    addLabel={t('gift.add')}
                                    max={10 - learnedAvoid.length}
                                />
                                {learnedAvoid.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {learnedAvoid.map((word) => (
                                            <button
                                                key={word}
                                                type="button"
                                                className="rounded-full border border-line px-3 py-1 text-sm text-ink-soft hover:border-ink"
                                                onClick={() => setAvoid(avoid.filter((w) => w !== word))}
                                            >
                                                {t('gift.summary_avoid', { word: avoidLabel(word) })} <span aria-hidden>×</span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <p className="mt-2 text-xs text-ink-soft">{t('gift.avoid_hint')}</p>
                                {/* The last step, so this is where keeping the answers is offered. */}
                                {recipient && <div className="mt-5">{rememberBox(setRemember)}</div>}
                            </div>
                        )}
                    </div>

                    <div className="mt-8 flex flex-wrap items-center gap-3">
                        {/* Back from the first question is back to the three ways, unless there were none. */}
                        {(step > 0 || (!card && !editing)) && (
                            <button
                                type="button"
                                className="rounded border border-line px-4 py-2 text-sm"
                                onClick={() => (step > 0 ? setStep(step - 1) : setStage('ways'))}
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
                                  Every step is skippable. The engine treats an
                                  unanswered question as "does not apply" rather
                                  than as a zero, so a person who only knows one
                                  thing about the recipient still gets a real answer.
                                */}
                                <button type="button" className="text-sm text-ink-soft underline" onClick={submit}>
                                    {t('gift.find')}
                                </button>
                            </>
                        ) : (
                            <button type="button" className="rounded bg-accent px-5 py-2 font-medium text-white" onClick={submit}>
                                {t('gift.find')}
                            </button>
                        )}
                    </div>
                </section>
            )}
        </>
    )
}
