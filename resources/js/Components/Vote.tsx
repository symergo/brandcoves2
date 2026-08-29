import { router } from '@inertiajs/react'
import { useTranslations } from '../useTranslations'

/**
 * "This is the one we should get."
 *
 * The card's primary action on a group list, and deliberately not collapsed
 * behind anything. `Pledge` hides its form because a form standing open under
 * every item reads as the main thing to do with them — but voting *is* the main
 * thing to do with a candidate, and it is one tap rather than a form.
 *
 * Approval voting: back as many as you like, press again to take it back. Not
 * "pick one favourite", which forces a decision the group has not made — the
 * shortlist exists precisely because nobody has.
 *
 * `aria-pressed` because it is a toggle, and the count alone would not tell a
 * screen reader whether this viewer is one of the four.
 */
export default function Vote({
    action,
    votes,
    votedByMe,
    canVote,
}: {
    /** `/{market}/l/{token}/vote/{item}` — POST to back it, DELETE to take it back. */
    action: string
    votes: number
    votedByMe: boolean
    canVote: boolean
}) {
    const { t, n } = useTranslations()

    if (!canVote) {
        // Still shows the tally: somebody reading a group list they cannot vote
        // on — the recipient must never be here, but a member who has not been
        // given an identity yet might — should see where the group has landed.
        return (
            <p className="mt-4 w-full rounded-lg border border-line px-4 py-2 text-center text-sm text-ink-soft">
                {votes === 0 ? t('votes.none') : t('votes.count', { count: n(votes) })}
            </p>
        )
    }

    return (
        <button
            type="button"
            aria-pressed={votedByMe}
            onClick={() =>
                votedByMe
                    ? router.delete(action, { preserveScroll: true })
                    : router.post(action, {}, { preserveScroll: true })
            }
            className={`mt-4 flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition ${
                votedByMe
                    ? 'bg-accent text-white hover:bg-accent-dark'
                    : 'border border-line hover:border-ink'
            }`}
        >
            <span aria-hidden>{votedByMe ? '♥' : '♡'}</span>
            <span>{votedByMe ? t('votes.voted') : t('votes.vote')}</span>
            {votes > 0 && <span className="tabular-nums opacity-80">{n(votes)}</span>}
        </button>
    )
}
