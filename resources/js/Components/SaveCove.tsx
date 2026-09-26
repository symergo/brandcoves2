import { Link, router, usePage } from '@inertiajs/react'
import { useCallback, useRef, useState } from 'react'
import { send } from '../http'
import { useSignIn } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import SaveButton, { BookmarkIcon } from './SaveButton'
import SaveSheet from './SaveSheet'

export interface SaveCoveState {
    /** An editorial Cove's id. Absent on a Community Cove, which sends its addresses instead. */
    coveId?: number
    isSaved: boolean
    /*
     * A Community Cove (a list somebody published) posts to other addresses
     * and stashes another intent for a guest; see
     * docs/features/community-coves.md. Left out, the editorial Cove's
     * addresses are built from `coveId` as before.
     */
    saveUrl?: string
    copyUrl?: string
    intent?: Record<string, string | number>
    /** The owner looking at their own Community Cove: nothing to save. */
    isOwn?: boolean
}

/**
 * Save a Cove: keep it in My Coves, or make it my list
 * (docs/features/saved-coves.md, docs/features/save-button.md).
 *
 * Keeping is a bookmark: the Cove stays ours (or its maker's) and changes when
 * it is edited. Make it my list copies its products into a new list of your
 * own, a snapshot.
 *
 * ## One button, and the two choices behind it
 *
 * Until 2026-09-26 these were two controls side by side, "Bewaar" and "Maak er
 * mijn lijst van", next to a product's own bookmark-with-a-chevron. The site
 * now has one Save button; on a Cove, pressing it opens the same sheet a
 * product's does, holding both choices with a line on what each means.
 *
 * A Cove's press opens the sheet rather than saving straight away, unlike a
 * product's, because here there are two different things "save" can mean and
 * guessing the wrong one either leaves you without the list you wanted or
 * gives you a copy you did not. Once kept, the button reads "Bewaard" and the
 * sheet says where it is, with a link there and a way to remove it.
 *
 * Both choices need an account; a guest's choice is stashed (`/save-intent`)
 * and finished after sign-in, the way saving a product works, so nobody is
 * sent to a login form empty-handed.
 */
export default function SaveCove({ state }: { state: SaveCoveState }) {
    const { auth, market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const signIn = useSignIn()
    const [busy, setBusy] = useState(false)
    const [open, setOpen] = useState(false)
    const trigger = useRef<HTMLButtonElement>(null)
    const close = useCallback(() => setOpen(false), [])
    const base = `/${market.key}`
    const saved = state.isSaved
    const saveUrl = state.saveUrl ?? `${base}/coves/${state.coveId}/save`
    const copyUrl = state.copyUrl ?? `${base}/coves/${state.coveId}/copy`

    async function asGuest(action: 'save' | 'copy'): Promise<void> {
        setOpen(false)

        try {
            await send(`${base}/save-intent`, 'POST', {
                ...(state.intent ?? { cove_id: state.coveId ?? 0 }),
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
        // Closed on success: the button itself turns to "Bewaard", which is
        // the confirmation, and the flash names the Cove.
        const options = { preserveScroll: true, onSuccess: close, onFinish: () => setBusy(false) }

        if (saved) {
            router.delete(saveUrl, options)
        } else {
            router.post(saveUrl, {}, options)
        }
    }

    function copy(): void {
        if (!auth.user) {
            void asGuest('copy')

            return
        }

        setBusy(true)
        router.post(copyUrl, {}, { onFinish: () => setBusy(false) })
    }

    if (state.isOwn) {
        return null
    }

    const choice = 'flex w-full items-start gap-3 rounded px-2 py-2 text-left hover:bg-line/40 disabled:opacity-50'

    return (
        <>
            <SaveButton
                ref={trigger}
                saved={saved}
                busy={busy}
                onClick={() => setOpen((v) => !v)}
                opensSheet
                expanded={open}
            />

            <SaveSheet open={open} onClose={close} anchor={trigger} label={t('save_button.cove_title')}>
                {saved ? (
                    <div className="rounded border border-sage bg-sage/10 px-2 py-2">
                        <p className="flex items-center gap-2 text-sm font-medium text-sage">
                            <BookmarkIcon filled />
                            {t('save_button.cove_saved_in')}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 pl-6 text-sm">
                            <Link href={`${base}/lists`} className="font-medium text-accent-dark underline hover:text-ink">
                                {t('save_button.open_my_coves')}
                            </Link>
                            <button
                                type="button"
                                onClick={toggle}
                                disabled={busy}
                                className="text-ink-soft underline hover:text-ink disabled:opacity-50"
                            >
                                {t('save_button.cove_remove')}
                            </button>
                        </div>
                    </div>
                ) : (
                    <button type="button" onClick={toggle} disabled={busy} className={choice}>
                        <span aria-hidden className="mt-0.5 flex w-4 shrink-0 justify-center text-ink">
                            <BookmarkIcon filled={false} />
                        </span>
                        <span>
                            <span className="block text-sm font-medium">{t('save_button.cove_keep')}</span>
                            <span className="block text-xs text-ink-soft">{t('save_button.cove_keep_hint')}</span>
                        </span>
                    </button>
                )}

                <button type="button" onClick={copy} disabled={busy} className={`${choice} mt-1`}>
                    <span aria-hidden className="mt-0.5 flex w-4 shrink-0 justify-center text-sm leading-4 font-bold text-ink">
                        +
                    </span>
                    <span>
                        <span className="block text-sm font-medium">{t('saved_coves.copy')}</span>
                        <span className="block text-xs text-ink-soft">{t('save_button.cove_copy_hint')}</span>
                    </span>
                </button>
            </SaveSheet>
        </>
    )
}
