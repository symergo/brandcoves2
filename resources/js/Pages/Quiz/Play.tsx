import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Option {
    id: number
    title: string
    image: string | null
}

interface Props {
    quiz: {
        title: string
        owner: string | null
        /* Questions only — the answer is never in the payload. */
        rounds: { title: null; options: Option[] }[]
    }
    isOwner: boolean
    result: { score: number; total: number; grid: string } | null
    stats: { played: number; average: number | null }
}

/**
 * "How well do you know them?"
 *
 * The share artefact is a row of squares and a score. It works because it says
 * how the round went without spoiling what the answers were — so posting it
 * costs the poster nothing, and everyone playing the same quiz makes a posted
 * result a conversation rather than a broadcast.
 */
export default function QuizPlay({ quiz, isOwner, result, stats }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [answers, setAnswers] = useState<Record<number, number>>({})
    const [copied, setCopied] = useState(false)

    const token = window.location.pathname.split('/').filter(Boolean).pop()
    const answered = Object.keys(answers).length

    async function share() {
        if (!result) return

        const text = `${t('quiz.title')} ${result.score}/${result.total}\n${result.grid}\n${window.location.href}`

        // The native sheet where it exists — a gift list's job is to travel
        // through a group chat, and that is where the sheet sends it.
        if (navigator.share) {
            await navigator.share({ text }).catch(() => undefined)

            return
        }

        await navigator.clipboard.writeText(text)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <>
            {/* Reachable only with the link, and never indexed. */}
            <Head title={t('quiz.title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold">{t('quiz.title')}</h1>
                <p className="mt-2 text-ink-soft">
                    {/*
                      Name a person, or say nothing about a person. Falling back
                      to the list title told players that one of these was on
                      "the list of Saved" — the list has a name, and it is not
                      anybody's.
                    */}
                    {quiz.owner ? t('quiz.intro', { name: quiz.owner }) : t('quiz.intro_anon')}
                </p>

                {stats.played > 0 && (
                    <p className="mt-3 text-sm text-ink-soft">
                        {t('quiz.played', { count: String(stats.played) })}
                        {stats.average !== null &&
                            ` · ${t('quiz.average', { score: String(stats.average) })}`}
                    </p>
                )}
            </header>

            {isOwner ? (
                <p className="mt-8 max-w-2xl rounded-card border border-amber/40 bg-amber/10 p-4 text-sm">
                    {t('quiz.owner_note')}
                </p>
            ) : result ? (
                <section className="mt-8 max-w-2xl">
                    <p className="text-3xl font-semibold">
                        {t('quiz.score', {
                            score: String(result.score),
                            total: String(result.total),
                        })}
                    </p>

                    <p className="mt-4 text-2xl tracking-widest" aria-label={result.grid}>
                        {result.grid}
                    </p>

                    <button
                        type="button"
                        onClick={share}
                        className="mt-6 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {copied ? t('quiz.copied') : t('quiz.share')}
                    </button>

                    {/*
                      The result screen ends on the list. Every wrong answer is a
                      thing they genuinely want, one tap from being claimed —
                      which is the whole commercial point of the game.
                    */}
                    <p className="mt-8 text-sm text-ink-soft">{t('quiz.missed_hint')}</p>
                    <a
                        href={`/${market.key}/l/${token}`}
                        className="mt-2 inline-block rounded-lg border border-line px-4 py-2 text-sm"
                    >
                        {t('lists.share')}
                    </a>
                </section>
            ) : (
                <form
                    className="mt-8 space-y-10"
                    onSubmit={(e) => {
                        e.preventDefault()
                        router.post(`/${market.key}/q/${token}`, {
                            answers: quiz.rounds.map((_, i) => answers[i] ?? null),
                        })
                    }}
                >
                    {quiz.rounds.map((round, index) => (
                        <fieldset key={index}>
                            <legend className="text-sm text-ink-soft">
                                {t('quiz.round', {
                                    current: String(index + 1),
                                    total: String(quiz.rounds.length),
                                })}
                            </legend>

                            <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {round.options.map((option) => (
                                    <button
                                        key={option.id}
                                        type="button"
                                        aria-pressed={answers[index] === option.id}
                                        onClick={() =>
                                            setAnswers((prev) => ({ ...prev, [index]: option.id }))
                                        }
                                        className={`rounded-card border p-4 text-left transition ${
                                            answers[index] === option.id
                                                ? 'border-accent bg-accent/5'
                                                : 'border-line hover:border-ink'
                                        }`}
                                    >
                                        {option.image && (
                                            <img
                                                src={option.image}
                                                alt=""
                                                loading="lazy"
                                                className="mx-auto h-28 w-auto max-w-full object-contain"
                                            />
                                        )}
                                        <span className="mt-3 block text-sm">{option.title}</span>
                                    </button>
                                ))}
                            </div>
                        </fieldset>
                    ))}

                    <button
                        type="submit"
                        disabled={answered < quiz.rounds.length}
                        className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white disabled:opacity-50"
                    >
                        {t('quiz.share')}
                    </button>
                </form>
            )}
        </>
    )
}
