import { Head, router, useForm, usePage } from '@inertiajs/react'
import Badge, { type BadgeTone } from '../Components/Badge'
import Button, { buttonClasses } from '../Components/Button'
import FeedbackForm from '../Components/FeedbackForm'
import InfoTip from '../Components/InfoTip'
import SignInLink from '../Components/SignInLink'
import ToolIcon from '../Components/ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

type Status = 'building' | 'planned' | 'considering' | 'done'

interface Idea {
    id: number
    title: string
    body: string
    status: Status
    votes: number
    votedByMe: boolean
    canVote: boolean
}

interface Props {
    ideas: Idea[]
    waiting: { id: number; title: string }[]
    isSignedIn: boolean
    path: string | null
}

/*
 * The pill per status. Building is the accent wash (the one thing moving),
 * planned is sage (decided, good news), considering and done are neutral: the
 * first is still a question, the second no longer is.
 */
const tones: Record<Status, BadgeTone> = {
    building: 'accent',
    planned: 'sage',
    considering: 'neutral',
    done: 'neutral',
}

/**
 * "Denk mee": feedback, the ideas we are weighing, and suggesting one
 * (owner, 2026-09-27; docs/features/contribute.md).
 *
 * One column, as /help: there is nothing to put beside it, and a side column
 * with nothing in it is the thing the site does not do.
 *
 * The order is the owner's: say what could be better (the /help form, the
 * same component, not a copy), then the board, then suggesting something.
 * The board comes sorted from the server (FeatureBoard); what is done folds
 * away at the end, because it is news rather than a question.
 *
 * Explanations sit behind an (i), the site's standard: how voting works and
 * what happens to a suggestion are one tap away, and the page itself stays a
 * list of ideas.
 */
export default function Contribute({ ideas, waiting, isSignedIn, path }: Props) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const base = `/${market.key}`

    const open = ideas.filter((idea) => idea.status !== 'done')
    const done = ideas.filter((idea) => idea.status === 'done')

    return (
        <>
            <Head title={t('contribute.title')} />

            <div className="mx-auto max-w-3xl py-4 sm:py-8">
                <h1 className="text-3xl font-semibold tracking-tight text-balance text-ink sm:text-4xl">
                    {t('contribute.title')}
                </h1>
                <p className="mt-3 max-w-2xl text-lg text-ink-soft">{t('contribute.intro')}</p>

                <section className="mt-10" aria-labelledby="contribute-feedback">
                    <h2 id="contribute-feedback" className="text-xl font-semibold tracking-tight">
                        {t('contribute.feedback_title')}
                    </h2>
                    <div className="mt-4">
                        <FeedbackForm path={path} autoFocus={false} />
                    </div>
                </section>

                <section className="mt-12 border-t border-line pt-10" aria-labelledby="contribute-board">
                    <div className="flex items-center gap-1">
                        <h2 id="contribute-board" className="text-xl font-semibold tracking-tight">
                            {t('contribute.board_title')}
                        </h2>
                        <InfoTip>{t('contribute.board_info')}</InfoTip>
                    </div>

                    {!isSignedIn && (
                        <p className="mt-2 text-sm text-ink-soft">
                            {t('contribute.sign_in_to_vote')}{' '}
                            <SignInLink hint={t('contribute.sign_in_to_vote')} className="font-medium text-accent-dark hover:text-ink">
                                {t('contribute.sign_in')}
                            </SignInLink>
                        </p>
                    )}

                    {ideas.length === 0 ? (
                        <p className="mt-4 text-ink-soft">{t('contribute.board_empty')}</p>
                    ) : (
                        <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                            {open.map((idea) => (
                                <IdeaCard key={idea.id} idea={idea} base={base} isSignedIn={isSignedIn} />
                            ))}
                        </ul>
                    )}

                    {done.length > 0 && (
                        <details className="group mt-6">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 font-medium text-ink">
                                <ToolIcon name="chevron" className="h-4 w-4 transition group-open:rotate-180" />
                                {t('contribute.done_title', { count: done.length })}
                            </summary>
                            <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                                {done.map((idea) => (
                                    <IdeaCard key={idea.id} idea={idea} base={base} isSignedIn={isSignedIn} />
                                ))}
                            </ul>
                        </details>
                    )}
                </section>

                <section className="mt-12 border-t border-line pt-10" aria-labelledby="contribute-suggest">
                    <div className="flex items-center gap-1">
                        <h2 id="contribute-suggest" className="text-xl font-semibold tracking-tight">
                            {t('contribute.suggest_title')}
                        </h2>
                        <InfoTip>{t('contribute.suggest_info')}</InfoTip>
                    </div>

                    {isSignedIn ? (
                        <SuggestForm base={base} />
                    ) : (
                        <p className="mt-3">
                            <SignInLink hint={t('contribute.sign_in_to_vote')} className={buttonClasses('secondary', 'md')}>
                                {t('contribute.sign_in')}
                            </SignInLink>
                        </p>
                    )}

                    {waiting.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-sm font-semibold text-ink">{t('contribute.waiting_title')}</h3>
                            <ul className="mt-2 space-y-1 text-sm text-ink-soft">
                                {waiting.map((item) => (
                                    <li key={item.id} className="flex items-start gap-2">
                                        <ToolIcon name="suggestions" className="mt-0.5 h-4 w-4 shrink-0 text-accent" />
                                        <span>{item.title}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
            </div>
        </>
    )
}

function IdeaCard({ idea, base, isSignedIn }: { idea: Idea; base: string; isSignedIn: boolean }) {
    const { t, n } = useTranslations()

    const count =
        idea.votes === 0
            ? t('contribute.votes_none')
            : idea.votes === 1
              ? t('contribute.votes_one')
              : t('contribute.votes_many', { count: n(idea.votes) })

    const action = `${base}/contribute/ideas/${idea.id}/vote`

    const toggle = () =>
        idea.votedByMe
            ? router.delete(action, { preserveScroll: true, only: ['ideas'] })
            : router.post(action, {}, { preserveScroll: true, only: ['ideas'] })

    return (
        <li className="flex flex-col rounded-card border border-line bg-card p-4">
            <div>
                <Badge tone={tones[idea.status]} size="xs">
                    {t(`contribute.status.${idea.status}`)}
                </Badge>
            </div>
            <h3 className="mt-2 font-semibold text-ink">{idea.title}</h3>
            {idea.body !== '' && <p className="mt-1 text-sm text-ink-soft">{idea.body}</p>}

            <div className="mt-auto flex items-center justify-end pt-3">
                {isSignedIn && idea.canVote ? (
                    /*
                     * A toggle: `aria-pressed` says whether this reader is one
                     * of the count, which the number alone does not. Voted is
                     * the accent wash, never solid: solid accent is the one
                     * main action on a view, and a page of ten solid buttons
                     * has none.
                     */
                    <button
                        type="button"
                        aria-pressed={idea.votedByMe}
                        aria-label={`${t('contribute.vote_on', { title: idea.title })} (${count})`}
                        onClick={toggle}
                        className={buttonClasses(
                            'secondary',
                            'sm',
                            idea.votedByMe ? 'border-accent bg-accent/10 text-accent-dark hover:border-accent-dark' : '',
                        )}
                    >
                        <ToolIcon name="vote" className="h-4 w-4" />
                        <span>{idea.votedByMe ? t('contribute.voted') : t('contribute.vote')}</span>
                        <span className="tabular-nums">{n(idea.votes)}</span>
                    </button>
                ) : (
                    <span className="inline-flex items-center gap-1.5 text-sm text-ink-soft">
                        <ToolIcon name="vote" className="h-4 w-4" />
                        {count}
                    </span>
                )}
            </div>
        </li>
    )
}

function SuggestForm({ base }: { base: string }) {
    const { t } = useTranslations()
    const form = useForm<{ title: string; body: string }>({ title: '', body: '' })

    function submit(event: React.FormEvent) {
        event.preventDefault()

        form.post(`${base}/contribute/suggestions`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        })
    }

    return (
        <form onSubmit={submit} className="mt-4 space-y-4">
            <label className="block text-sm font-medium">
                {t('contribute.suggest_title_label')}
                <input
                    type="text"
                    required
                    minLength={5}
                    maxLength={120}
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder={t('contribute.suggest_title_placeholder')}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                />
            </label>
            {form.errors.title && <p className="text-sm text-danger">{form.errors.title}</p>}

            <label className="block text-sm font-medium">
                {t('contribute.suggest_body_label')}
                <textarea
                    rows={4}
                    maxLength={2000}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                />
            </label>
            {form.errors.body && <p className="text-sm text-danger">{form.errors.body}</p>}

            <Button type="submit" size="lg" busy={form.processing}>
                {form.processing ? t('contribute.suggest_sending') : t('contribute.suggest_submit')}
            </Button>
        </form>
    )
}
