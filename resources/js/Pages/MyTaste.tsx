import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import Button, { buttonClasses } from '../Components/Button'
import InfoTip from '../Components/InfoTip'
import PageHeader from '../Components/PageHeader'
import ToolIcon from '../Components/ToolIcon'
import { useTranslations } from '../useTranslations'

interface Option {
    value: string
    label: string
}

interface Props {
    taste: {
        interests: string[]
        vibe: string | null
        preferences: string[]
        values: string[]
        avoid: string[]
        ageBand: string | null
    }
    options: {
        interests: Option[]
        vibes: Option[]
        preferences: { axis: string; poles: Option[] }[]
        ages: Option[]
        values: string[]
    }
    urls: { update: string; learn: string }
}

/** The same bounds as the server's (MyTasteController::update). */
const MAX_INTERESTS = 8
const MAX_PREFERENCES = 3
const MAX_VALUES = 3

/** An interest learned as "not this" in This or that, kept in the tag's spelling. */
const LEARNED = 'interest:'

const chip = (on: boolean) =>
    `inline-flex min-h-11 items-center rounded-full border px-3 py-1 text-sm sm:min-h-0 ${
        on ? 'border-accent bg-accent/10 text-accent-dark' : 'border-line hover:border-ink'
    }`

/**
 * "Mijn smaak": your own gift taste (owner, 2026-09-29).
 *
 * Filled in here or learned from This or that; friends who look for a gift
 * for you start from it (OwnTaste), and so does "Voor mezelf" in Find a gift.
 * No budget: what somebody spends is theirs to choose, in their own search.
 * See docs/features/my-taste.md.
 */
export default function MyTaste({ taste, options, urls }: Props) {
    const { t } = useTranslations()
    const [interests, setInterests] = useState<string[]>(taste.interests)
    const [vibe, setVibe] = useState<string | null>(taste.vibe)
    const [preferences, setPreferences] = useState<string[]>(taste.preferences)
    const [values, setValues] = useState<string[]>(taste.values)
    const [avoid, setAvoid] = useState<string[]>(taste.avoid)
    const [ageBand, setAgeBand] = useState<string | null>(taste.ageBand)
    const [ownWord, setOwnWord] = useState('')
    const [avoidWord, setAvoidWord] = useState('')
    const [busy, setBusy] = useState(false)

    const vocabulary = new Set(options.interests.map((o) => o.value))
    const ownWords = interests.filter((i) => !vocabulary.has(i))
    const interestsFull = interests.length >= MAX_INTERESTS
    const interestLabel = (value: string) => options.interests.find((o) => o.value === value)?.label ?? value
    const avoidLabel = (word: string) => (word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word)

    const toggle = (list: string[], set: (next: string[]) => void, value: string, max: number) => {
        if (list.includes(value)) {
            set(list.filter((v) => v !== value))
        } else if (list.length < max) {
            set([...list, value])
        }
    }

    // One pole per axis: picking one end clears the other, as in Find a gift.
    const togglePole = (axis: { poles: Option[] }, value: string) => {
        const others = axis.poles.map((p) => p.value).filter((v) => v !== value)
        const without = preferences.filter((p) => !others.includes(p))
        toggle(without, setPreferences, value, MAX_PREFERENCES)
    }

    const addOwn = () => {
        const word = ownWord.trim()
        if (word !== '' && !interests.includes(word) && !interestsFull) setInterests([...interests, word])
        setOwnWord('')
    }

    const addAvoid = () => {
        const word = avoidWord.trim()
        if (word !== '' && !avoid.includes(word) && avoid.length < 10) setAvoid([...avoid, word])
        setAvoidWord('')
    }

    const save = () => {
        setBusy(true)
        router.put(
            urls.update,
            { interests, vibe, preferences, values, avoid, age_band: ageBand },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        )
    }

    /*
     * Clearing is withdrawing the consent the privacy policy names: an empty
     * taste leaves no row (MyTasteController::keep), and friends' searches
     * stop reading it at once.
     */
    const clear = () => {
        setInterests([])
        setVibe(null)
        setPreferences([])
        setValues([])
        setAvoid([])
        setAgeBand(null)
        setBusy(true)
        router.put(
            urls.update,
            { interests: [], vibe: null, preferences: [], values: [], avoid: [], age_band: null },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        )
    }

    const hasAnything =
        taste.interests.length > 0 ||
        taste.vibe !== null ||
        taste.preferences.length > 0 ||
        taste.values.length > 0 ||
        taste.avoid.length > 0 ||
        taste.ageBand !== null

    return (
        <>
            <Head title={t('my_taste.title')} />

            <PageHeader
                className="max-w-2xl"
                icon={
                    <span className="mr-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <ToolIcon name="taste" className="h-6 w-6" />
                    </span>
                }
                title={t('my_taste.title')}
            >
                <p className="mt-2 flex flex-wrap items-center text-ink-soft">
                    {t('my_taste.intro')}
                    <InfoTip>{t('my_taste.privacy')}</InfoTip>
                </p>
            </PageHeader>

            <div className="mt-6 max-w-2xl space-y-6">
                {/* Choosing is quicker than describing: This or that, kept here from its result. */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-card p-4">
                    <div>
                        <p className="font-medium">{t('my_taste.learn_title')}</p>
                        <p className="text-sm text-ink-soft">{t('my_taste.learn_hint')}</p>
                    </div>
                    <Link href={urls.learn} className={buttonClasses('secondary')}>
                        {t('my_taste.learn_cta')}
                    </Link>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        save()
                    }}
                    className="space-y-6 rounded-card border border-line bg-card p-4 sm:p-5"
                >
                    <fieldset>
                        <legend className="font-medium">{t('my_taste.interests')}</legend>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {options.interests.map((option) => {
                                const on = interests.includes(option.value)

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={on}
                                        disabled={!on && interestsFull}
                                        onClick={() => toggle(interests, setInterests, option.value, MAX_INTERESTS)}
                                        className={`${chip(on)} disabled:opacity-40`}
                                    >
                                        {option.label}
                                    </button>
                                )
                            })}
                            {ownWords.map((word) => (
                                <button
                                    key={word}
                                    type="button"
                                    aria-pressed
                                    onClick={() => setInterests(interests.filter((i) => i !== word))}
                                    aria-label={t('my_taste.remove', { word })}
                                    className={chip(true)}
                                >
                                    {word}
                                    <ToolIcon name="close" className="ml-1 h-3.5 w-3.5" />
                                </button>
                            ))}
                        </div>
                        <div className="mt-2 flex max-w-sm gap-2">
                            <input
                                value={ownWord}
                                maxLength={40}
                                disabled={interestsFull}
                                onChange={(e) => setOwnWord(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault()
                                        addOwn()
                                    }
                                }}
                                placeholder={t('gift.interests_other_placeholder')}
                                aria-label={t('my_taste.interests_other')}
                                className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                            />
                            <Button type="button" variant="secondary" size="sm" onClick={addOwn} disabled={interestsFull}>
                                {t('my_taste.add')}
                            </Button>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="font-medium">{t('my_taste.vibe')}</legend>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {options.vibes.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={vibe === option.value}
                                    onClick={() => setVibe(vibe === option.value ? null : option.value)}
                                    className={chip(vibe === option.value)}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="font-medium">{t('my_taste.preferences')}</legend>
                        <div className="mt-2 space-y-2">
                            {options.preferences.map((axis) => (
                                <div key={axis.axis} className="flex flex-wrap items-center gap-1.5">
                                    {axis.poles.map((pole, i) => (
                                        <span key={pole.value} className="contents">
                                            {i > 0 && <span className="text-xs text-ink-soft">/</span>}
                                            <button
                                                type="button"
                                                aria-pressed={preferences.includes(pole.value)}
                                                onClick={() => togglePole(axis, pole.value)}
                                                className={chip(preferences.includes(pole.value))}
                                            >
                                                {pole.label}
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="font-medium">{t('my_taste.values')}</legend>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {options.values.map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={values.includes(value)}
                                    onClick={() => toggle(values, setValues, value, MAX_VALUES)}
                                    className={chip(values.includes(value))}
                                >
                                    {t(`gift.values.${value}`)}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="font-medium">{t('my_taste.age')}</legend>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {options.ages.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={ageBand === option.value}
                                    onClick={() => setAgeBand(ageBand === option.value ? null : option.value)}
                                    className={chip(ageBand === option.value)}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="font-medium">{t('my_taste.avoid')}</legend>
                        {avoid.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {avoid.map((word) => (
                                    <button
                                        key={word}
                                        type="button"
                                        aria-pressed
                                        onClick={() => setAvoid(avoid.filter((w) => w !== word))}
                                        aria-label={t('my_taste.remove', { word: avoidLabel(word) })}
                                        className={chip(true)}
                                    >
                                        {avoidLabel(word)}
                                        <ToolIcon name="close" className="ml-1 h-3.5 w-3.5" />
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="mt-2 flex max-w-sm gap-2">
                            <input
                                value={avoidWord}
                                maxLength={40}
                                onChange={(e) => setAvoidWord(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault()
                                        addAvoid()
                                    }
                                }}
                                placeholder={t('my_taste.avoid_placeholder')}
                                aria-label={t('my_taste.avoid')}
                                className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                            />
                            <Button type="button" variant="secondary" size="sm" onClick={addAvoid}>
                                {t('my_taste.add')}
                            </Button>
                        </div>
                    </fieldset>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" busy={busy}>
                            {t('my_taste.save')}
                        </Button>
                        {hasAnything && (
                            <Button type="button" variant="ghost" disabled={busy} onClick={clear}>
                                {t('my_taste.clear')}
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </>
    )
}
