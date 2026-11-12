import { usePage } from '@inertiajs/react'
import { useEffect, useRef } from 'react'
import type { SharedProps } from '../types'
import { useBackCloses } from '../useBackCloses'
import { useTranslations } from '../useTranslations'
import EmailSignIn, { WhySignIn } from './EmailSignIn'

/**
 * Signing in without leaving the page you were on.
 *
 * The same two ways in as `Pages/Auth/Login` — a magic link, or Google — in a
 * dialog over whatever you were doing.
 *
 * ## Why a dialog rather than a link to the login page
 *
 * The places that need this are places somebody has already arrived with an
 * intention: saying "this is me" on a link a friend sent, keeping a product,
 * claiming something. Navigating away to sign in throws that context away, and
 * `wishlists.md` has the record of what that costs — `requireAccount()` used to
 * bounce people to the login page and land them afterwards on an empty list,
 * having forgotten what the product was called. `PendingSave` fixed that for
 * one path by carrying the intent across; a dialog avoids the crossing.
 *
 * A magic link still arrives by email, so the person leaves for their inbox
 * either way. What the dialog protects is the page they come *back* to.
 *
 * ## The native element, not a div
 *
 * `<dialog showModal()>` gives focus trapping, Escape, inertness of the page
 * behind it and a top-layer backdrop, all of which a hand-rolled overlay gets
 * wrong in ways that only show up for somebody using a keyboard or a screen
 * reader.
 */
export default function SignInDialog({
    open,
    onClose,
    hint,
}: {
    open: boolean
    onClose: () => void
    /** Why they are being asked, in the words of whatever sent them here. */
    hint?: string
}) {
    const { market, auth } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`
    const ref = useRef<HTMLDialogElement>(null)

    // Back closes it, like every popup (useBackCloses, 2026-10-06).
    useBackCloses(onClose, open)

    useEffect(() => {
        const el = ref.current

        if (el === null) {
            return
        }

        // `showModal()` rather than the `open` attribute: only the former puts
        // the dialog in the top layer and makes the rest of the page inert.
        if (open && !el.open) {
            el.showModal()
        } else if (!open && el.open) {
            el.close()
        }
    }, [open])

    return (
        <dialog
            ref={ref}
            aria-labelledby="sign-in-title"
            onClose={onClose}
            // Escape and the backdrop both close it; without this the `open`
            // prop and the element disagree the moment somebody presses Escape.
            onClick={(e) => {
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            /*
              `m-auto` is what centres it, and it is not decoration.

              A native <dialog> centres itself with `margin: auto`, which
              Tailwind's preflight resets to 0 along with every other element's
              — so without this the dialog renders hard against the top-left
              corner of the viewport. It looked like a broken overlay rather
              than a sign-in form, and stayed unnoticed while this was one
              page's dialog rather than the whole site's.

              The height cap and scroll are for a small phone in landscape,
              where the form is taller than the viewport and a <dialog> will
              simply overflow off-screen rather than scroll.
            */
            // Full screen on a phone, like every popup (owner, 2026-10-06; see Modal).
            className="m-0 h-dvh max-h-none w-screen max-w-none overflow-y-auto bg-cream p-6 sm:bg-card backdrop:bg-ink/40 sm:m-auto sm:h-fit sm:max-h-[calc(100dvh-2rem)] sm:w-[min(28rem,calc(100vw-2rem))] sm:rounded-card sm:border sm:border-line"
        >
            {/* Named by aria-labelledby, not a heading (2026-11-08): the closed
                dialog is in every page's HTML, and "Inloggen" was a heading on
                every page Google read. */}
            <p id="sign-in-title" className="text-lg font-semibold">
                {t('auth.title')}
            </p>
            {/* A page's own reason to sign in, when it gave one. The general
                line ("we send you a link") went on 2026-10-25 (owner): the
                email form says it now, where it applies. */}
            {hint && <p className="mt-2 text-sm text-ink-soft">{hint}</p>}
            {/* Why at all, when the page gave no reason of its own (owner, 2026-11-12). */}
            {!hint && <WhySignIn className="mt-2" />}

            {/*
              Google first (owner's call, 2026-09-14). See Auth/Login.tsx: an
              account somebody already has is one tap, a magic link is a trip
              to an inbox and back, so the form is the alternative rather than
              the other way round.

              A full page load, deliberately: OAuth leaves the site and comes
              back, so there is no page state to preserve and an Inertia visit
              would only get in the way. Hidden entirely when unconfigured — a
              button that leads to an exception is worse than no button.
            */}
            {auth.googleEnabled && (
                <>
                    <a
                        href={`${base}/auth/google`}
                        // White with Google's own mark, as on the login page: one red button per form.
                        className="mt-5 flex w-full items-center justify-center gap-3 rounded-lg border border-line bg-cream px-5 py-3 font-medium text-ink transition hover:border-ink"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5 shrink-0">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                        </svg>
                        {t('auth.google')}
                    </a>

                    <div className="my-5 flex items-center gap-3 text-xs text-ink-soft">
                        <span className="h-px flex-1 bg-line" />
                        {t('auth.or')}
                        <span className="h-px flex-1 bg-line" />
                    </div>
                </>
            )}

            <EmailSignIn idPrefix="signin" active={open} fieldClass="bg-cream" />

            <button
                type="button"
                onClick={onClose}
                className="mt-5 w-full text-sm text-ink-soft underline hover:text-ink"
            >
                {t('lists.cancel')}
            </button>
        </dialog>
    )
}
