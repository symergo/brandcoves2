import { Head, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import SaveToList from '../../Components/SaveToList'

interface BandEntry {
    band: 'exact' | 'warm' | 'cool' | 'cold'
    over: boolean
}

interface Challenge {
    title: string
    brand: string | null
    image: string | null
    category: string | null
    merchantCount: number
    maxAttempts: number
    band: string | null
    over: boolean
    solved: boolean
    attemptsLeft: number
    finished: boolean
    /** Absent until the round is over — see the controller. */
    answer: Cents | null
    bands: BandEntry[]
    productUrl: string | null
    community: { players: number; solvedPercent: number | null } | null
    shareLabel: string
}

interface Find {
    id: number
    groupId: number
    title: string
    image: string | null
    price: Cents | null
    merchantCount: number
    discountPercent: number | null
    blurb: string | null
    url: string
    mindblown: number
    meh: number
}

interface Props {
    edition: {
        id: number
        date: string
        label: string
        theme: string
        blurb: string | null
        isToday: boolean
    }
    challenge: Challenge | null
    finds: Find[]
    guide: {
        title: string
        intro: string | null
        url: string
        itemCount: number
        searchVolume: number
    } | null
    streak: { current: number; longest: number }
    archive: { date: string; label: string; theme: string; url: string }[]
}

const EMOJI: Record<string, [string, string]> = {
    // [under, over] — arrows rather than colours, because a grid has to survive
    // being pasted as plain text and ~8% of men cannot tell red from green.
    exact: ['🎯', '🎯'],
    warm: ['🟩', '🟩'],
    cool: ['🔼', '🔽'],
    cold: ['⬆️', '⬇️'],
}

export default function Edition({ edition, challenge, finds, guide, streak, archive }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const [state, setState] = useState<Challenge | null>(challenge)
    const [guess, setGuess] = useState('')
    const [busy, setBusy] = useState(false)
    const [copied, setCopied] = useState(false)
    const [streakState, setStreakState] = useState(streak)
    const [reactions, setReactions] = useState<Record<number, string>>({})
    const [counts, setCounts] = useState<Record<number, { mindblown: number; meh: number }>>(
        Object.fromEntries(finds.map((f) => [f.id, { mindblown: f.mindblown, meh: f.meh }])),
    )

    const csrf = () =>
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

    /*
     * A fetch rather than an Inertia visit.
     *
     * The round is a small stateful exchange inside one page. A full page
     * response per guess would lose the input focus and the scroll position
     * four times a round, which is four chances to make the game feel clumsy.
     */
    const submitGuess = async () => {
        if (guess === '' || busy || state?.finished) return

        setBusy(true)
        try {
            const response = await fetch(`/${market.key}/daily/${edition.date}/guess`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ guess: Number(guess) }),
            })

            if (!response.ok) return

            const data = await response.json()
            setState({ ...(state as Challenge), ...data })
            setStreakState(data.streak ?? streakState)
            setGuess('')
        } finally {
            setBusy(false)
        }
    }

    const react = async (pickId: number, reaction: 'mindblown' | 'meh') => {
        const response = await fetch(`/${market.key}/picks/${pickId}/react`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ reaction }),
        })

        if (!response.ok) return

        const data = await response.json()
        setReactions({ ...reactions, [pickId]: data.mine })
        setCounts({ ...counts, [pickId]: { mindblown: data.mindblown, meh: data.meh } })
    }

    /*
     * The share artefact: a score, not a link-beg.
     *
     * Nobody feels marketed to by a row of squares, and the grid carries no
     * spoiler — so posting it costs the poster nothing. That is the whole
     * reason this works where a "share this deal!" button does not.
     */
    const shareText = () => {
        const row = (state?.bands ?? [])
            .map((entry) => (EMOJI[entry.band] ?? EMOJI.cold)[entry.over ? 1 : 0])
            .join('')
        const score = state?.solved
            ? `${(state?.bands ?? []).length}/${state?.maxAttempts}`
            : `X/${state?.maxAttempts}`

        return `Brandcoves ${state?.shareLabel} ${score}\n${row}`
    }

    const share = async () => {
        const text = shareText()

        if (navigator.share) {
            try {
                await navigator.share({ text })

                return
            } catch {
                // The user dismissed the sheet. Fall through to the clipboard
                // so the button still does something.
            }
        }

        await navigator.clipboard.writeText(text)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <>
            <Head title={edition.theme} />

            <header className="max-w-2xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">
                    {t('daily.title')} · {edition.label}
                </p>
                <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{edition.theme}</h1>
                {edition.blurb && <p className="mt-2 text-ink-soft">{edition.blurb}</p>}
            </header>

            {/* ── Beat 1: the guess ─────────────────────────────────────── */}
            {state && (
                <section className="mt-8 rounded-lg border border-line bg-card p-5">
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 className="font-medium">{t('daily.hunt_title')}</h2>
                        {streakState.current > 0 && (
                            <span className="text-sm text-ink-soft">
                                🔥 {t('daily.streak', { days: n(streakState.current) })}
                            </span>
                        )}
                    </div>

                    <div className="mt-4 flex flex-col gap-4 sm:flex-row">
                        {state.image && (
                            <img
                                src={state.image}
                                alt=""
                                className="h-40 w-40 shrink-0 self-center object-contain"
                            />
                        )}

                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{state.title}</p>
                            <p className="mt-1 text-sm text-ink-soft">{t('daily.hunt_prompt')}</p>

                            {state.bands.length > 0 && (
                                <p className="mt-3 text-2xl" aria-label={t('daily.your_guesses')}>
                                    {state.bands.map((entry, i) => (
                                        <span key={i}>
                                            {(EMOJI[entry.band] ?? EMOJI.cold)[entry.over ? 1 : 0]}
                                        </span>
                                    ))}
                                </p>
                            )}

                            {!state.finished ? (
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <label className="sr-only" htmlFor="guess">
                                        {t('daily.hunt_prompt')}
                                    </label>
                                    <input
                                        id="guess"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        className="w-32 rounded border border-line px-3 py-2"
                                        value={guess}
                                        onChange={(e) => setGuess(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && submitGuess()}
                                    />
                                    <button
                                        type="button"
                                        className="rounded bg-accent px-4 py-2 font-medium text-white disabled:opacity-50"
                                        onClick={submitGuess}
                                        disabled={busy || guess === ''}
                                    >
                                        {t('daily.guess')}
                                    </button>
                                    <span className="text-sm text-ink-soft">
                                        {t('daily.tries_left', { count: n(state.attemptsLeft) })}
                                    </span>
                                </div>
                            ) : (
                                <div className="mt-4 space-y-2">
                                    <p className="text-lg font-semibold">
                                        {state.solved ? t('daily.solved') : t('daily.missed')}
                                        {state.answer !== null && (
                                            <> — {formatPrice(state.answer, market)}</>
                                        )}
                                    </p>

                                    {state.community?.solvedPercent !== null &&
                                        state.community !== null && (
                                            <p className="text-sm text-ink-soft">
                                                {t('daily.community', {
                                                    percent: n(state.community.solvedPercent ?? 0),
                                                    players: n(state.community.players),
                                                })}
                                            </p>
                                        )}

                                    <div className="flex flex-wrap gap-3 pt-1">
                                        <button
                                            type="button"
                                            className="rounded border border-line px-4 py-2 text-sm"
                                            onClick={share}
                                        >
                                            {copied ? t('daily.copied') : t('daily.share')}
                                        </button>
                                        {state.productUrl && (
                                            <Link
                                                href={state.productUrl}
                                                className="rounded bg-accent px-4 py-2 text-sm font-medium text-white"
                                            >
                                                {t('daily.see_offers')}
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </section>
            )}

            {/* ── Beat 2: the finds ─────────────────────────────────────── */}
            <section className="mt-10">
                <h2 className="text-sm font-medium text-ink-soft">{t('daily.finds_title')}</h2>

                <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {finds.map((find) => (
                        <li
                            key={find.id}
                            className="flex flex-col rounded-lg border border-line bg-card p-4"
                        >
                            <a href={find.url}>
                                {find.image && (
                                    <img
                                        src={find.image}
                                        alt=""
                                        className="mx-auto h-36 object-contain"
                                        loading="lazy"
                                    />
                                )}
                                <h3 className="mt-3 line-clamp-2 font-medium">{find.title}</h3>
                            </a>

                            {find.blurb && <p className="mt-2 text-sm text-ink-soft">{find.blurb}</p>}

                            <div className="mt-auto space-y-3 pt-4">
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold">
                                        {find.price === null ? '—' : formatPrice(find.price, market)}
                                    </span>
                                    <SaveToList groupId={find.groupId} />
                                </div>

                                <div className="flex gap-2 text-sm">
                                    {(['mindblown', 'meh'] as const).map((kind) => (
                                        <button
                                            key={kind}
                                            type="button"
                                            aria-pressed={reactions[find.id] === kind}
                                            className={`rounded-full border px-3 py-1 ${
                                                reactions[find.id] === kind
                                                    ? 'border-accent'
                                                    : 'border-line'
                                            }`}
                                            onClick={() => react(find.id, kind)}
                                        >
                                            {kind === 'mindblown' ? '🤯' : '😐'}{' '}
                                            {n(counts[find.id]?.[kind] ?? 0)}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            </section>

            {/* ── Beat 3: the guide ─────────────────────────────────────── */}
            {guide && (
                <section className="mt-10 rounded-lg border border-line p-5">
                    <h2 className="text-sm font-medium text-ink-soft">{t('daily.guide_title')}</h2>
                    <Link href={guide.url} className="mt-2 block text-lg font-medium hover:underline">
                        {guide.title}
                    </Link>
                    {guide.intro && <p className="mt-2 text-ink-soft">{guide.intro}</p>}
                    {/*
                      Stated plainly. "We wrote this because people searched for
                      it here" is both the honest reason and a fact no
                      competitor has.
                    */}
                    {guide.searchVolume > 0 && (
                        <p className="mt-2 text-xs text-ink-soft">
                            {t('daily.guide_why', { count: n(guide.searchVolume) })}
                        </p>
                    )}
                </section>
            )}

            {archive.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-sm font-medium text-ink-soft">{t('daily.archive')}</h2>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {archive.map((entry) => (
                            <li key={entry.date}>
                                <Link
                                    href={entry.url}
                                    className="block rounded border border-line px-3 py-1.5 text-sm hover:bg-card"
                                >
                                    <span className="text-ink-soft">{entry.label}</span> · {entry.theme}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    )
}
