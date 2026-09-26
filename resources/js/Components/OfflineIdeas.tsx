import { usePage } from '@inertiajs/react'
import { useState } from 'react'
import { send } from '../http'
import { listFrom, show as showToast } from '../saveToast'
import { useSignIn } from '../signIn'
import type { SavingTo, SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import type { ListKind } from './ListKindBadge'

export interface OfflineIdea {
    id: number
    title: string
}

interface SaveResult {
    itemId: number
    listId: string
    listTitle: string
    /** For drawing the list's name in the toast; see App\Support\ListName. */
    listKind?: ListKind
    messageTemplate?: string
    message: string
}

/**
 * Ideas nobody sells here: things other people typed onto their own lists by
 * hand (a workshop, a day out), shown under Find a gift's and This or
 * that's results once a person approved the wording.
 *
 * Only an id and the approved wording ever reach the page, never who wrote it
 * or how many. "Add to my list" adds the idea as an offline item; the server
 * reads the wording from the idea, not from here. Signed out, the press is
 * kept (as an id) through sign-in, like any other save.
 * See docs/features/offline-ideas.md.
 */
export default function OfflineIdeas({ ideas, into }: { ideas: OfflineIdea[]; into?: SavingTo | null }) {
    const { market, auth } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const signIn = useSignIn()
    const [added, setAdded] = useState<number[]>([])
    const [busy, setBusy] = useState<number | null>(null)

    if (ideas.length === 0) {
        return null
    }

    async function add(idea: OfflineIdea): Promise<void> {
        if (busy !== null) return

        if (!auth.user) {
            try {
                await send(`/${market.key}/save-intent`, 'POST', {
                    idea_id: idea.id,
                    return_to: window.location.pathname + window.location.search,
                })
            } catch {
                // Losing the intent makes for a worse sign-in, not a broken one.
            }

            signIn.open(t('lists.sign_in_hint'))

            return
        }

        setBusy(idea.id)

        try {
            const result = await send<SaveResult>(`/${market.key}/list-items`, 'POST', {
                source: 'manual',
                idea_id: idea.id,
                ...(into ? { wishlist_id: into.id } : {}),
            })

            setAdded((ids) => [...ids, idea.id])
            showToast({ message: result.message, list: listFrom(result), tone: 'ok', undo: { itemId: result.itemId }, listId: result.listId })
        } catch {
            showToast({ message: t('lists.save_failed'), tone: 'error' })
        } finally {
            setBusy(null)
        }
    }

    return (
        <section className="mt-10">
            <h2 className="flex items-center gap-1.5 text-sm font-medium text-ink-soft">
                {t('gift.offline_ideas.title')}
                <InfoTip>{t('gift.offline_ideas.hint')}</InfoTip>
            </h2>
            <ul className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {ideas.map((idea) => (
                    <li
                        key={idea.id}
                        className="flex items-center justify-between gap-3 rounded-card border border-dashed border-line bg-card px-4 py-3"
                    >
                        <span className="font-medium">{idea.title}</span>
                        {added.includes(idea.id) ? (
                            <span className="shrink-0 text-xs text-sage">{t('gift.offline_ideas.added')}</span>
                        ) : (
                            <button
                                type="button"
                                disabled={busy === idea.id}
                                onClick={() => void add(idea)}
                                className="shrink-0 text-xs text-accent underline disabled:opacity-50"
                            >
                                {t('gift.offline_ideas.add')}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    )
}
