import { router, useForm } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { useTranslations } from '../useTranslations'
import ToolIcon from './ToolIcon'

/**
 * The pieces of a Secret Friend group that make it a game rather than a form
 * (owner, 2026-11-12): a countdown, a card to turn, tips in a few taps,
 * anonymous questions, and a guess at the end. Shared by the member's page,
 * the join page and the group page. See docs/features/secret-santa.md, "The
 * game around the draw".
 */

/** Whole days from today to an ISO date or moment; negative once it is behind us. */
export function daysUntil(iso: string): number {
    const target = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso)
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    target.setHours(0, 0, 0, 0)

    return Math.round((target.getTime() - today.getTime()) / 86_400_000)
}

/**
 * "Over 3 dagen wordt er getrokken" before the draw, "Nog 12 dagen tot de
 * cadeaus" after it. One line; nothing when there is no date to count to.
 */
export function Countdown({
    drawn,
    drawAt,
    exchangeDate,
    className = '',
}: {
    drawn: boolean
    drawAt: string | null
    exchangeDate: string | null
    className?: string
}) {
    const { t } = useTranslations()

    // Rendered after mount: "today" on the server is not the visitor's today.
    const [ready, setReady] = useState(false)
    useEffect(() => setReady(true), [])

    if (!ready) return null

    let line: string | null = null

    if (!drawn && drawAt) {
        const days = daysUntil(drawAt)
        line = days <= 0 ? t('santa.draw_today') : days === 1 ? t('santa.draw_in_one') : t('santa.draw_in_days', { days: String(days) })
    } else if (exchangeDate) {
        const days = daysUntil(exchangeDate)

        if (days < 0) return null
        line = days === 0 ? t('santa.exchange_today') : days === 1 ? t('santa.exchange_in_one') : t('santa.exchange_in_days', { days: String(days) })
    }

    if (!line) return null

    return (
        <p className={`inline-flex items-center gap-2 rounded-full border border-accent/40 bg-accent/10 px-3 py-1 text-sm font-medium text-ink ${className}`}>
            <ToolIcon name="santa" className="h-4 w-4 text-accent" />
            {line}
        </p>
    )
}

/** How far the group is, as a bar: never who. */
export function Progress({ drawn, progress }: { drawn: boolean; progress: { members: number; ready: number; done: number } }) {
    const { t } = useTranslations()

    if (progress.members < 2) return null

    const count = drawn ? progress.done : progress.ready
    const label = drawn
        ? t('santa.progress_done', { done: String(count), total: String(progress.members) })
        : t('santa.progress_ready', { ready: String(count), total: String(progress.members) })

    return (
        <div className="mt-4">
            <p className="text-sm text-ink-soft">{label}</p>
            <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-line" role="presentation">
                <div className="h-full rounded-full bg-sage transition-all" style={{ width: `${Math.round((count / progress.members) * 100)}%` }} />
            </div>
        </div>
    )
}

/** Interests as chips to tap. `keys` come from the server (SantaGame::INTERESTS). */
export function InterestPicker({
    keys,
    value,
    onChange,
    max = 8,
}: {
    keys: string[]
    value: string[]
    onChange: (value: string[]) => void
    max?: number
}) {
    const { t } = useTranslations()

    return (
        <ul className="flex flex-wrap gap-2">
            {keys.map((key) => {
                const on = value.includes(key)

                return (
                    <li key={key}>
                        <button
                            type="button"
                            aria-pressed={on}
                            onClick={() => onChange(on ? value.filter((v) => v !== key) : value.length >= max ? value : [...value, key])}
                            className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                on ? 'border-accent bg-accent text-white' : 'border-line bg-card text-ink hover:border-ink'
                            }`}
                        >
                            {t(`santa.interest.${key}`)}
                        </button>
                    </li>
                )
            })}
        </ul>
    )
}

/**
 * The reveal. The name is on the back of a card; pressing turns it, and a
 * moment later the page is told, so from then on it simply says the name.
 * The name is in the page from the start (it is their own assignment); the
 * card is theatre, not a lock.
 */
export function RevealCard({ name, revealUrl }: { name: string; revealUrl: string }) {
    const { t } = useTranslations()
    const [turned, setTurned] = useState(false)

    function turn() {
        if (turned) return
        setTurned(true)
        // Long enough to read the name on the card before the page redraws.
        window.setTimeout(() => router.post(revealUrl, {}, { preserveScroll: true }), 2200)
    }

    const face = 'absolute inset-0 flex flex-col items-center justify-center rounded-card border-2 p-6 text-center [backface-visibility:hidden]'

    return (
        <section className="mt-8" aria-labelledby="reveal-title">
            <h2 id="reveal-title" className="text-lg font-medium">
                {t('santa.reveal_title')}
            </h2>
            <p className="mt-1 text-sm text-ink-soft">{t('santa.reveal_tap')}</p>

            <button
                type="button"
                onClick={turn}
                aria-live="polite"
                className="mt-4 block h-56 w-full max-w-sm [perspective:1000px] focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            >
                <span
                    className="relative block h-full w-full transition-transform duration-700 [transform-style:preserve-3d] motion-reduce:transition-none"
                    style={{ transform: turned ? 'rotateY(180deg)' : 'none' }}
                >
                    <span className={`${face} border-accent bg-accent text-white`}>
                        <ToolIcon name="santa" className="h-14 w-14" />
                        <span className="mt-3 text-xl font-semibold">{t('santa.reveal_front')}</span>
                    </span>
                    <span className={`${face} border-accent bg-card text-ink [transform:rotateY(180deg)]`}>
                        <span className="text-sm text-ink-soft">{t('santa.you_have', { name: '' }).trim()}</span>
                        <span className="mt-2 text-3xl font-semibold text-accent-dark">{turned ? name : ''}</span>
                    </span>
                </span>
            </button>
        </section>
    )
}

/** My own tips for whoever drew me: a few taps and one line. */
export function HintsForm({
    url,
    keys,
    interests,
    hints,
    open,
}: {
    url: string
    keys: string[]
    interests: string[]
    hints: string | null
    /** Shown open while there is nothing yet; folded away once filled in. */
    open: boolean
}) {
    const { t } = useTranslations()
    const form = useForm({ interests, hints: hints ?? '' })

    return (
        <details open={open} className="mt-8 rounded-card border border-line bg-card p-5">
            <summary className="cursor-pointer font-medium">{t('santa.my_tips_title')}</summary>
            <form
                className="mt-3 space-y-4"
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(url, { preserveScroll: true })
                }}
            >
                <p className="text-sm text-ink-soft">{t('santa.interests_hint')}</p>
                <InterestPicker keys={keys} value={form.data.interests} onChange={(v) => form.setData('interests', v)} />
                <label className="block">
                    <span className="text-sm font-medium">{t('santa.hints_label')}</span>
                    <input
                        value={form.data.hints}
                        onChange={(e) => form.setData('hints', e.target.value)}
                        maxLength={500}
                        placeholder={t('santa.hints_placeholder')}
                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2"
                    />
                </label>
                <button disabled={form.processing} className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                    {t('santa.save')}
                </button>
            </form>
        </details>
    )
}

export interface SantaQuestion {
    id: number
    question: string
    answer: string | null
}

/** What my Secret Friend asked me. I answer; I never see who asked. */
export function QuestionInbox({ baseUrl, questions }: { baseUrl: string; questions: SantaQuestion[] }) {
    const { t } = useTranslations()

    if (questions.length === 0) return null

    return (
        <section className="mt-8 rounded-card border border-accent/40 bg-accent/5 p-5" aria-labelledby="inbox-title">
            <h2 id="inbox-title" className="font-medium">
                {t('santa.inbox_title')}
            </h2>
            <p className="mt-1 text-sm text-ink-soft">{t('santa.inbox_hint')}</p>
            <ul className="mt-3 space-y-4">
                {questions.map((q) => (
                    <li key={q.id}>
                        <p className="font-medium">{q.question}</p>
                        {q.answer !== null ? <p className="mt-1 text-sm text-ink-soft">{q.answer}</p> : <AnswerForm url={`${baseUrl}/questions/${q.id}`} />}
                    </li>
                ))}
            </ul>
        </section>
    )
}

function AnswerForm({ url }: { url: string }) {
    const { t } = useTranslations()
    const form = useForm({ answer: '' })

    return (
        <form
            className="mt-2 flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault()
                form.post(url, { preserveScroll: true })
            }}
        >
            <input
                required
                maxLength={500}
                value={form.data.answer}
                onChange={(e) => form.setData('answer', e.target.value)}
                placeholder={t('santa.answer_placeholder')}
                aria-label={t('santa.answer_placeholder')}
                className="min-w-0 flex-1 rounded-lg border border-line bg-card px-3 py-2"
            />
            <button disabled={form.processing} className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                {t('santa.answer_send')}
            </button>
        </form>
    )
}

/** Ask the person I drew something, without my name on it. */
export function AskBox({ baseUrl, name, asked }: { baseUrl: string; name: string; asked: SantaQuestion[] }) {
    const { t } = useTranslations()
    const form = useForm({ question: '' })

    return (
        <section className="mt-8" aria-labelledby="ask-title">
            <h2 id="ask-title" className="text-lg font-medium">
                {t('santa.ask_title', { name })}
            </h2>
            <p className="mt-1 text-sm text-ink-soft">{t('santa.ask_hint')}</p>

            {asked.length > 0 && (
                <ul className="mt-3 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                    {asked.map((q) => (
                        <li key={q.id} className="p-4 text-sm">
                            <p className="font-medium">{q.question}</p>
                            <p className={`mt-1 ${q.answer === null ? 'text-ink-soft italic' : ''}`}>{q.answer ?? t('santa.waiting_answer')}</p>
                        </li>
                    ))}
                </ul>
            )}

            <form
                className="mt-3 flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(`${baseUrl}/questions`, { preserveScroll: true, onSuccess: () => form.reset() })
                }}
            >
                <input
                    required
                    minLength={3}
                    maxLength={300}
                    value={form.data.question}
                    onChange={(e) => form.setData('question', e.target.value)}
                    placeholder={t('santa.ask_placeholder')}
                    aria-label={t('santa.ask_title', { name })}
                    className="min-w-0 flex-1 rounded-lg border border-line bg-card px-3 py-2"
                />
                <button disabled={form.processing} className="rounded-lg border border-accent px-4 py-2 text-sm font-medium text-accent-dark hover:bg-accent hover:text-white disabled:opacity-50">
                    {t('santa.ask_send')}
                </button>
            </form>
            {form.errors.question && <p className="mt-1 text-sm text-danger">{form.errors.question}</p>}
        </section>
    )
}

export interface GuessState {
    options: { id: number; name: string }[]
    guessedId: number | null
    santaName: string | null
    correct: boolean | null
}

/** After the exchange: one guess at who had me, then the answer and a thank-you. */
export function GuessSanta({ baseUrl, guess, thanked }: { baseUrl: string; guess: GuessState; thanked: boolean }) {
    const { t } = useTranslations()
    const thanks = useForm({ message: '' })

    return (
        <section className="mt-10 rounded-card border border-accent/40 bg-accent/5 p-5" aria-labelledby="guess-title">
            <h2 id="guess-title" className="text-lg font-medium">
                {t('santa.guess_title')}
            </h2>

            {guess.guessedId === null ? (
                <>
                    <p className="mt-1 text-sm text-ink-soft">{t('santa.guess_hint')}</p>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {guess.options.map((option) => (
                            <li key={option.id}>
                                <button
                                    type="button"
                                    onClick={() => router.post(`${baseUrl}/guess`, { member_id: option.id }, { preserveScroll: true })}
                                    className="rounded-full border border-line bg-card px-4 py-2 text-sm font-medium hover:border-accent"
                                >
                                    {option.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <>
                    {guess.santaName && (
                        <p className="mt-2 text-lg font-medium" role="status">
                            {guess.correct ? t('santa.guess_right', { name: guess.santaName }) : t('santa.guess_wrong', { name: guess.santaName })}
                        </p>
                    )}

                    {!thanked && (
                        <form
                            className="mt-4 flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault()
                                thanks.post(`${baseUrl}/thanks`, { preserveScroll: true })
                            }}
                        >
                            <input
                                required
                                maxLength={500}
                                value={thanks.data.message}
                                onChange={(e) => thanks.setData('message', e.target.value)}
                                placeholder={t('santa.thanks_placeholder')}
                                aria-label={t('santa.thanks_label')}
                                className="min-w-0 flex-1 rounded-lg border border-line bg-card px-3 py-2"
                            />
                            <button disabled={thanks.processing} className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                {t('santa.thanks_send')}
                            </button>
                        </form>
                    )}
                </>
            )}
        </section>
    )
}
