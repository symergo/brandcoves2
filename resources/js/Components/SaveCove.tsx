import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { send } from '../http'
import { useSignIn } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export interface SaveCoveState {
    coveId: number
    isSaved: boolean
}

/**
 * Save a Cove into My Coves, and "Make it my list" (docs/features/saved-coves.md).
 *
 * Save is a bookmark: the Cove stays ours and changes when we edit it. Make it
 * my list copies its products into a new list of your own, a snapshot. Both
 * need an account; a guest's press is stashed (`/save-intent`) and finished
 * after sign-in, the way saving a product works, so nobody is sent to a login
 * form empty-handed.
 *
 * The bookmark is the same one as on a product card (SaveToList), filled once
 * saved, so the two read as one idea: keep this.
 */
export default function SaveCove({ state }: { state: SaveCoveState }) {
    const { auth, market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const signIn = useSignIn()
    const [busy, setBusy] = useState(false)
    const base = `/${market.key}`
    const saved = state.isSaved

    async function asGuest(action: 'save' | 'copy'): Promise<void> {
        try {
            await send(`${base}/save-intent`, 'POST', {
                cove_id: state.coveId,
                cove_action: action,
                return_to: window.location.pathname + window.location.search,
            })
        } catch {
            // Losing the intent makes for a worse sign-in, not a broken one.
        }

        signIn.open(t('saved_coves.sign_in_hint'))
    }

    function toggle(): void {
        if (!auth.user) {
            void asGuest('save')

            return
        }

        setBusy(true)
        const options = { preserveScroll: true, onFinish: () => setBusy(false) }

        if (saved) {
            router.delete(`${base}/coves/${state.coveId}/save`, options)
        } else {
            router.post(`${base}/coves/${state.coveId}/save`, {}, options)
        }
    }

    function copy(): void {
        if (!auth.user) {
            void asGuest('copy')

            return
        }

        setBusy(true)
        router.post(`${base}/coves/${state.coveId}/copy`, {}, { onFinish: () => setBusy(false) })
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={toggle}
                disabled={busy}
                aria-pressed={saved}
                className={`inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm font-medium transition disabled:opacity-50 ${
                    saved ? 'border-sage bg-sage text-white' : 'border-line bg-card text-ink hover:border-ink'
                }`}
            >
                <svg viewBox="0 0 24 24" className="h-4 w-4" aria-hidden>
                    <path
                        d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"
                        fill={saved ? 'currentColor' : 'none'}
                        stroke="currentColor"
                        strokeWidth="1.8"
                        strokeLinejoin="round"
                    />
                </svg>
                {saved ? t('saved_coves.saved') : t('saved_coves.save')}
            </button>
            <button
                type="button"
                onClick={copy}
                disabled={busy}
                className="inline-flex min-h-10 items-center rounded-lg px-2 text-sm text-accent-dark underline hover:text-ink disabled:opacity-50"
            >
                {t('saved_coves.copy')}
            </button>
        </div>
    )
}
