import { usePage } from '@inertiajs/react'
import type { MouseEvent, PropsWithChildren } from 'react'
import { useSignIn } from '../signIn'
import type { SharedProps } from '../types'

/**
 * "Sign in", as a real link that opens a dialog.
 *
 * ## Why an anchor and not a button
 *
 * `/{market}/login` still exists and still works, and this element still points
 * at it. That is what keeps middle-click and ⌘-click opening the login page in
 * a new tab, keeps the browser's own "open in new window" honest, and keeps the
 * server-rendered HTML — which is what crawlers and a visitor whose JavaScript
 * failed actually receive — navigable. The dialog is an enhancement on top of a
 * working link, not a replacement for one.
 *
 * A plain left click is the only thing intercepted, which is the same rule
 * Inertia's own `<Link>` applies.
 */
export default function SignInLink({
    className,
    hint,
    onNavigate,
    children,
}: PropsWithChildren<{
    className?: string
    /** Why they are being asked; shown in place of the dialog's own intro. */
    hint?: string
    /** For a caller that has chrome of its own to tidy — closing a menu, say. */
    onNavigate?: () => void
}>) {
    const { market } = usePage<SharedProps>().props
    const signIn = useSignIn()

    function click(e: MouseEvent<HTMLAnchorElement>) {
        onNavigate?.()

        // Anything but an unmodified primary click is the browser's to handle:
        // a new tab is a deliberate request for the page, not the dialog.
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return
        }

        e.preventDefault()
        signIn.open(hint)
    }

    return (
        <a href={`/${market.key}/login`} onClick={click} className={className}>
            {children}
        </a>
    )
}
