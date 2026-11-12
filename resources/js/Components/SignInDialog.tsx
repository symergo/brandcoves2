import { usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import EmailSignIn, { WhySignIn } from './EmailSignIn'
import GoogleSignIn from './GoogleSignIn'
import Modal from './Modal'

/**
 * Sign in, without leaving the page.
 *
 * The same two ways in as `Pages/Auth/Login`, a magic link or Google, in a
 * popup that `SignInProvider` opens from wherever somebody hits the wall: a
 * bookmark, a claim, "Maak een Cove". `hint` is the reason they were stopped,
 * shown under the title; without one the popup says why signing in is worth
 * it at all.
 *
 * ## The shared popup frame since 2026-11-12
 *
 * This was a `<dialog>` of its own: its own `showModal()`, its own backdrop
 * click, its own back-button handling, its own corner radius and its own
 * missing X. It is `Modal` now, like every other popup, so those are written
 * once. Two things that followed from the old shape are gone with it:
 *
 * - It sat in every page's HTML, closed, which is why its title was a `<p>`:
 *   "Inloggen" as a heading on every page Google read. It is only rendered
 *   while open now, so the title is the frame's ordinary heading.
 * - `EmailSignIn` was told when the popup opened so its bot-trap clock started
 *   then rather than at page load. Mounting on open does that by itself.
 */
export default function SignInDialog({
    open,
    onClose,
    hint,
}: {
    open: boolean
    onClose: () => void
    /** Why the visitor was stopped: "Log in om dit te bewaren". */
    hint?: string
}) {
    const { t } = useTranslations()
    const signedIn = usePage<SharedProps>().props.auth.user !== null

    // A new address is signed in on the spot (docs/features/auth.md), and the
    // page behind the popup reloads signed in: the popup has done its job and
    // puts itself away, rather than standing over the thing they came to do.
    useEffect(() => {
        if (open && signedIn) {
            onClose()
        }
    }, [open, signedIn, onClose])

    if (!open) return null

    return (
        <Modal title={t('auth.title')} onClose={onClose} width="sm">
            {hint ? <p className="mt-2 text-sm text-ink-soft">{hint}</p> : <WhySignIn className="mt-2" />}

            <GoogleSignIn />

            <div className={hint ? 'mt-5' : ''}>
                <EmailSignIn idPrefix="signin" />
            </div>
        </Modal>
    )
}
