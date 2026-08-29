import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import SaveToList from '../../Components/SaveToList'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Pick {
    id: number
    title: string
    image: string | null
    price: number | null
    inStock: boolean
    url: string
}

interface Answer {
    id: number
    body: string
    author: string | null
    mine: boolean
    status: string
    answeredAt: string
    picks: Pick[]
}

interface Props {
    question: {
        id: number
        title: string
        body: string | null
        budget: number | null
        /** Already labels, in the reader's language. */
        tags: string[]
        answers: number
        author: string | null
        status: string
        askedAt: string
        url: string
        /** Only ever set for the author of the question. */
        note: string | null
    }
    answers: Answer[]
    canAnswer: boolean
    maxPicks: number
    /** null before a search is run; `[]` once one found nothing. */
    results: { id: number; title: string; image: string | null; price: number | null }[] | null
    searchTerm: string
}

/**
 * One question and what people said.
 *
 * ## An answer carries products, not links
 *
 * The picker below searches our own catalogue and attaches `product_groups`
 * ids. That is the difference between this being useful and being a liability:
 * a pick renders as an ordinary product card with a live price for the right
 * market, and every outbound click leaves through `/go/{offer}` where the
 * scheme is checked (invariant #5). There is no field to paste a URL into,
 * which is why there is no rule about pasting URLs.
 *
 * ## Held answers are shown to whoever wrote them
 *
 * Same reasoning as the board: the alternative is a form that appears to have
 * done nothing. The server decides who sees what — `isVisibleTo` on the model —
 * and this page renders what it is given.
 */
export default function AskShow({ question, answers, canAnswer, maxPicks, results, searchTerm }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    const [query, setQuery] = useState(searchTerm)
    const [picks, setPicks] = useState<Pick[]>([])
    const form = useForm({ body: '' })

    function submit(event: React.FormEvent) {
        event.preventDefault()

        form.transform((data) => ({ ...data, picks: picks.map((p) => p.id) }))

        form.post(`${base}/ask/${question.id}/answers`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset()
                setPicks([])
            },
        })
    }

    return (
        <>
            <Head title={question.title} />

            <nav className="text-sm">
                <Link href={`${base}/ask`} className="text-ink-soft underline hover:text-ink">
                    {t('ask.title')}
                </Link>
            </nav>

            <header className="mt-4 max-w-2xl">
                <h1 className="text-2xl font-semibold tracking-tight">{question.title}</h1>

                {question.body && (
                    // Plain text, never markup: this is a stranger's writing on
                    // our domain. `whitespace-pre-line` keeps their paragraphs
                    // without giving them any other control over the page.
                    <p className="mt-3 whitespace-pre-line text-ink-soft">{question.body}</p>
                )}

                {/*
                  What the asker said about the person, if anything. Chips,
                  because they are a handful of one-word facts and the point of
                  collecting them is that an answerer can take them in without
                  reading a paragraph.
                */}
                {question.tags.length > 0 && (
                    <ul className="mt-4 flex flex-wrap gap-1.5">
                        {question.tags.map((tag) => (
                            <li
                                key={tag}
                                className="rounded-full bg-line/60 px-2.5 py-1 text-xs text-ink-soft"
                            >
                                {tag}
                            </li>
                        ))}
                    </ul>
                )}

                <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-soft">
                    {question.author && <span>{t('ask.asked_by', { name: question.author })}</span>}
                    {question.budget !== null && (
                        <>
                            <span aria-hidden>·</span>
                            <span>{t('ask.budget_up_to', { amount: formatPrice(question.budget, market) })}</span>
                        </>
                    )}
                </p>

                {question.status !== 'published' && (
                    <p className="mt-4 rounded-card border border-amber/40 bg-amber/10 p-4 text-sm">
                        {question.status === 'rejected' ? t('ask.rejected_notice') : t('ask.pending_notice')}
                    </p>
                )}
            </header>

            <section className="mt-10">
                <h2 className="text-lg font-medium">
                    {answers.length === 0
                        ? t('ask.answers_heading')
                        : answers.length === 1
                          ? t('ask.one_answer')
                          : t('ask.answers', { count: n(answers.length) })}
                </h2>

                {answers.length === 0 ? (
                    <p className="mt-3 text-ink-soft">{t('ask.be_first')}</p>
                ) : (
                    <ul className="mt-4 space-y-6">
                        {answers.map((answer) => (
                            <li
                                key={answer.id}
                                className={`rounded-card border bg-card p-5 ${
                                    answer.status === 'published' ? 'border-line' : 'border-amber/40'
                                }`}
                            >
                                <p className="whitespace-pre-line">{answer.body}</p>

                                {answer.picks.length > 0 && (
                                    <ul className="mt-4 grid gap-3 sm:grid-cols-3">
                                        {answer.picks.map((pick) => (
                                            <li key={pick.id} className="relative">
                                                {/*
                                                  Somebody recommended this to a
                                                  stranger, and the stranger had
                                                  no way to keep it. Outside the
                                                  anchor, which owns the click.
                                                */}
                                                <div className="absolute top-2 right-2 z-10">
                                                    <SaveToList groupId={pick.id} compact />
                                                </div>
                                                <Link
                                                    href={pick.url}
                                                    className="flex h-full flex-col rounded-lg border border-line p-3 transition hover:border-ink"
                                                >
                                                    {pick.image && (
                                                        <img
                                                            src={pick.image}
                                                            alt=""
                                                            loading="lazy"
                                                            className="mx-auto h-24 w-auto max-w-full object-contain"
                                                            onError={(e) => {
                                                                e.currentTarget.style.visibility = 'hidden'
                                                            }}
                                                        />
                                                    )}
                                                    <p className="mt-2 line-clamp-2 text-sm font-medium">{pick.title}</p>
                                                    {pick.price !== null && (
                                                        <p className="mt-1 text-sm text-ink-soft">
                                                            {formatPrice(pick.price, market)}
                                                        </p>
                                                    )}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <p className="mt-3 text-xs text-ink-soft">
                                    {answer.author}
                                    {answer.status !== 'published' && ` · ${t('ask.pending_notice')}`}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {canAnswer ? (
                <section className="mt-12 max-w-2xl rounded-card border border-line bg-card p-6">
                    <h2 className="font-medium">{t('ask.answer_heading')}</h2>

                    <form onSubmit={submit} className="mt-4 space-y-4">
                        <textarea
                            required
                            rows={4}
                            maxLength={2000}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            placeholder={t('ask.answer_placeholder')}
                            className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                        />
                        {form.errors.body && <p className="text-sm text-accent">{form.errors.body}</p>}

                        <div className="border-t border-line pt-4">
                            <h3 className="text-sm font-medium">{t('ask.picks_heading')}</h3>
                            <p className="mt-1 text-xs text-ink-soft">
                                {t('ask.picks_hint', { count: n(maxPicks) })}
                            </p>

                            {picks.length > 0 && (
                                <ul className="mt-3 flex flex-wrap gap-2">
                                    {picks.map((pick) => (
                                        <li
                                            key={pick.id}
                                            className="flex items-center gap-2 rounded-full border border-accent bg-accent/10 px-3 py-1 text-xs text-accent"
                                        >
                                            <span className="max-w-40 truncate">{pick.title}</span>
                                            <button
                                                type="button"
                                                onClick={() => setPicks(picks.filter((p) => p.id !== pick.id))}
                                                aria-label={t('lists.remove')}
                                            >
                                                ✕
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {/*
                              A GET back to this same page carrying `?q=`, the
                              same shape the suggestion picker on a shared list
                              uses. One route and one search rather than a second
                              endpoint with its own gate. `preserveState` keeps
                              the half-typed answer and the picks already chosen.
                            */}
                            <div className="mt-3 flex flex-wrap gap-2">
                                <input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault()
                                            router.get(
                                                question.url,
                                                { q: query },
                                                { preserveState: true, preserveScroll: true },
                                            )
                                        }
                                    }}
                                    placeholder={t('ask.picks_search')}
                                    aria-label={t('ask.picks_search')}
                                    className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get(
                                            question.url,
                                            { q: query },
                                            { preserveState: true, preserveScroll: true },
                                        )
                                    }
                                    className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                                >
                                    {t('search.submit')}
                                </button>
                            </div>

                            {results !== null && results.length === 0 && (
                                <p className="mt-3 text-sm text-ink-soft">{t('ask.picks_none_found')}</p>
                            )}

                            {results !== null && results.length > 0 && (
                                <ul className="mt-3 grid gap-3 sm:grid-cols-4">
                                    {results.map((result) => {
                                        const chosen = picks.some((p) => p.id === result.id)
                                        const full = picks.length >= maxPicks

                                        return (
                                            <li
                                                key={result.id}
                                                className="flex flex-col rounded-lg border border-line p-3"
                                            >
                                                {result.image && (
                                                    <img
                                                        src={result.image}
                                                        alt=""
                                                        loading="lazy"
                                                        className="mx-auto h-20 w-auto max-w-full object-contain"
                                                        onError={(e) => {
                                                            e.currentTarget.style.visibility = 'hidden'
                                                        }}
                                                    />
                                                )}
                                                <p className="mt-2 line-clamp-2 text-xs font-medium">{result.title}</p>
                                                {result.price !== null && (
                                                    <p className="mt-1 text-xs text-ink-soft">
                                                        {formatPrice(result.price, market)}
                                                    </p>
                                                )}
                                                <button
                                                    type="button"
                                                    disabled={chosen || full}
                                                    onClick={() =>
                                                        setPicks([
                                                            ...picks,
                                                            {
                                                                id: result.id,
                                                                title: result.title,
                                                                image: result.image,
                                                                price: result.price,
                                                                inStock: true,
                                                                url: '',
                                                            },
                                                        ])
                                                    }
                                                    className="mt-2 rounded-lg border border-line px-2 py-1 text-xs disabled:opacity-50"
                                                >
                                                    {chosen ? t('ask.picks_added') : t('ask.picks_add')}
                                                </button>
                                            </li>
                                        )
                                    })}
                                </ul>
                            )}

                            {picks.length >= maxPicks && (
                                <p className="mt-2 text-xs text-ink-soft">{t('ask.picks_full')}</p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60"
                        >
                            {t('ask.answer_submit')}
                        </button>
                    </form>
                </section>
            ) : (
                question.status === 'published' && (
                    <p className="mt-12 max-w-2xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                        {t('ask.sign_in_to_ask')}{' '}
                        <Link href={`${base}/login`} className="underline">
                            {t('nav.sign_in')}
                        </Link>
                    </p>
                )
            )}
        </>
    )
}
