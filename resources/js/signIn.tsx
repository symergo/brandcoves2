import { createContext, useCallback, useContext, useMemo, useState, type PropsWithChildren } from 'react'
import SignInDialog from './Components/SignInDialog'

/**
 * One sign-in dialog for the whole site.
 *
 * Signing in used to mean a page navigation: every "Sign in" on the site was a
 * link to `Pages/Auth/Login`, so whatever you were part-way through — a half
 * typed question, a product you had open, a list you were about to keep — was
 * gone by the time the form appeared. `SignInDialog` already existed and had
 * this argued out for one surface (`Recipients/SelfDescribe`); this makes it
 * the default everywhere instead of the exception in one place.
 *
 * ## Why a provider rather than a dialog per caller
 *
 * The callers are scattered — the header, the mobile menu, a price alert, the
 * save picker, four pages with a "sign in to do this" notice — and each one
 * would otherwise carry its own `useState`, its own `<SignInDialog>` and its
 * own chance to get the wiring subtly wrong. There is also only ever *one*
 * sign-in happening, so one dialog mounted in the layout is the honest shape.
 *
 * `SiteLayout` is the mount point because every page goes through it (nothing
 * sets its own `layout` today). A page that ever opts out of the site chrome
 * must wrap itself, which is what the fallback below makes survivable.
 */
interface SignIn {
    /** @param hint Why they are being asked, in the words of whatever asked. */
    open: (hint?: string) => void
}

const Context = createContext<SignIn | null>(null)

/**
 * Ask for the dialog, and fall back to the login page without one.
 *
 * The fallback exists so that a component used outside `SiteLayout` degrades to
 * the behaviour it had before this file existed, rather than throwing. A dialog
 * that fails to open is a nuisance; a page that fails to render is an outage.
 */
export function useSignIn(): SignIn {
    const context = useContext(Context)

    return (
        context ?? {
            open: () => {
                // The market prefix is the first path segment, which is the
                // one piece of `SharedProps` obtainable without the provider.
                window.location.assign(`/${window.location.pathname.split('/')[1]}/login`)
            },
        }
    )
}

export function SignInProvider({ children }: PropsWithChildren) {
    // `null` is closed. Storing the hint alongside the open state keeps the
    // reason on screen for as long as the dialog is, without a second setter
    // that could be updated out of step with it.
    const [hint, setHint] = useState<string | null>(null)
    const [open, setOpen] = useState(false)

    const value = useMemo<SignIn>(
        () => ({
            open: (reason?: string) => {
                setHint(reason ?? null)
                setOpen(true)
            },
        }),
        [],
    )

    const close = useCallback(() => setOpen(false), [])

    return (
        <Context.Provider value={value}>
            {children}
            {/*
              Mounted always, not conditionally.

              `<dialog>` needs a live element to call `showModal()` on, and the
              effect in `SignInDialog` is what opens it — mounting the element
              in the same commit that flips `open` works, but only by accident
              of effect ordering. Closed, it renders nothing visible.
            */}
            <SignInDialog open={open} onClose={close} hint={hint ?? undefined} />
        </Context.Provider>
    )
}
