import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useRef, type FormEvent } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

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

    const form = useForm({ email: '', name: '' })

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

    function submit(e: FormEvent) {
        e.preventDefault()

        /*
         * `preserveScroll` and no redirect handling: the controller answers
         * with a redirect back, and `FlashMessage` in the layout renders
         * "check your inbox". The dialog stays open on purpose — the commonest
         * next action is "it did not arrive, send another", which is exactly
         * the reasoning the login page records for keeping its form on screen.
         */
        form.post(`${base}/login`, { preserveScroll: true })
    }

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            // Escape and the backdrop both close it; without this the `open`
            // prop and the element disagree the moment somebody presses Escape.
            onClick={(e) => {
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            className="w-[min(28rem,calc(100vw-2rem))] rounded-card border border-line bg-card p-6 backdrop:bg-ink/40"
        >
            <h2 className="text-lg font-semibold">{t('auth.title')}</h2>
            <p className="mt-2 text-sm text-ink-soft">{hint ?? t('auth.intro')}</p>

            <form onSubmit={submit} className="mt-5 space-y-3">
                {/* Optional, and only used when the account is created. A magic
                    link is the whole of registration here, so this is the one
                    moment there is to ask — and without a name a shared wishlist
                    cannot say whose it is. */}
                <label htmlFor="signin-name" className="block text-sm font-medium">
                    {t('auth.name')}
                </label>
                <input
                    id="signin-name"
                    type="text"
                    autoComplete="name"
                    maxLength={80}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className="mt-1 mb-3 w-full rounded-lg border border-line px-3 py-2"
                />

                <label htmlFor="signin-email" className="block text-sm font-medium">
                    {t('auth.email')}
                </label>
                <input
                    id="signin-email"
                    type="email"
                    inputMode="email"
                    autoComplete="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    aria-invalid={form.errors.email ? true : undefined}
                    aria-describedby={form.errors.email ? 'signin-email-error' : undefined}
                    className="w-full rounded-lg border border-line bg-cream px-4 py-3"
                />

                {form.errors.email && (
                    <p id="signin-email-error" className="text-sm text-accent" role="alert">
                        {form.errors.email}
                    </p>
                )}

                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-full rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark disabled:opacity-60"
                >
                    {t('auth.send')}
                </button>
            </form>

            {/* Hidden entirely when unconfigured — a button that leads to an
                exception is worse than no button. */}
            {auth.googleEnabled && (
                <>
                    <div className="my-5 flex items-center gap-3 text-xs text-ink-soft">
                        <span className="h-px flex-1 bg-line" />
                        {t('auth.or')}
                        <span className="h-px flex-1 bg-line" />
                    </div>

                    {/*
                      A full page load, deliberately: OAuth leaves the site and
                      comes back, so there is no page state to preserve and an
                      Inertia visit would only get in the way.
                    */}
                    <a
                        href={`${base}/auth/google`}
                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-line px-5 py-3 font-medium transition hover:border-ink"
                    >
                        {t('auth.google')}
                    </a>
                </>
            )}

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
