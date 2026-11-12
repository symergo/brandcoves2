import { buttonClasses } from './Button'
import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useState, type FormEvent } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import { Honeypot, useFormClock } from './BotTrap'
import ToolIcon from './ToolIcon'

/**
 * The email way in: one form, used by the login page and the sign-in popup.
 *
 * They were two copies, and they had drifted: the popup showed "check your
 * inbox" beside its button, the page left it to the banner at the top of the
 * layout, and neither said anything when the request was refused outright.
 * Owner, 2026-11-12: "The send me a link login does not give a response."
 *
 * ## It always answers, beside the button
 *
 * - Sent: the controller's own sentence, in a box above the button. The form
 *   stays, because the commonest next step is "it did not arrive, send
 *   another".
 * - Refused by validation or the per-address limit: the message under the
 *   email field (`form.errors.email`).
 * - Refused before the controller (a 419 on an expired session, the route's
 *   429, a 500) or never arrived (offline): `auth.failed`. Inertia's default
 *   for a non-Inertia answer is to open the response in an overlay, which sits
 *   *behind* a `<dialog>` in the top layer, so in the popup nothing showed at
 *   all. Returning false from the callbacks keeps that overlay away.
 *
 * ## Email first, name last (owner, same day)
 *
 * The address is the one thing needed; the name is optional and only used when
 * the account is created, so it no longer stands in front of the field that
 * matters.
 */
export default function EmailSignIn({
    email = '',
    idPrefix,
    active = true,
    fieldClass = 'bg-card',
}: {
    /** Prefilled from `?email=` on the login page. */
    email?: string
    /** Keeps the ids of the page's and the popup's fields apart. */
    idPrefix: string
    /** False while the popup is closed, so the bot-trap clock starts at opening. */
    active?: boolean
    fieldClass?: string
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const form = useForm({ email, name: '', website: '' })
    const elapsed = useFormClock(active)
    const [sent, setSent] = useState<string | null>(null)
    const [failed, setFailed] = useState(false)

    // A reopened popup starts clean.
    useEffect(() => {
        if (active) {
            setSent(null)
            setFailed(false)
        }
    }, [active])

    function submit(e: FormEvent) {
        e.preventDefault()
        setSent(null)
        setFailed(false)

        // How long the form was open: the bot trap, see Components/BotTrap.
        form.transform((data) => ({ ...data, elapsed_ms: elapsed() }))
        form.post(`/${market.key}/login`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = (page.props as unknown as SharedProps).flash

                if (flash.error) {
                    setFailed(true)
                } else {
                    setSent(flash.success ?? flash.status ?? t('auth.link_sent'))
                }
            },
            onHttpException: () => {
                setFailed(true)

                return false
            },
            onNetworkError: () => {
                setFailed(true)

                return false
            },
        })
    }

    const emailId = `${idPrefix}-email`
    const nameId = `${idPrefix}-name`

    return (
        <form onSubmit={submit} className="relative space-y-3">
            <p className="flex items-center gap-2 text-sm font-medium text-ink">
                <ToolIcon name="mailAccount" className="h-6 w-6 shrink-0 text-accent" />
                <span>{t('auth.email_easy')}</span>
            </p>
            <Honeypot value={form.data.website} onChange={(v) => form.setData('website', v)} />

            <label htmlFor={emailId} className="block text-sm font-medium">
                {t('auth.email')}
            </label>
            <input
                id={emailId}
                type="email"
                inputMode="email"
                autoComplete="email"
                required
                value={form.data.email}
                onChange={(e) => form.setData('email', e.target.value)}
                aria-invalid={form.errors.email ? true : undefined}
                aria-describedby={form.errors.email ? `${emailId}-error` : undefined}
                className={`w-full rounded-lg border border-line px-4 py-3 ${fieldClass}`}
            />

            {form.errors.email && (
                <p id={`${emailId}-error`} className="text-sm text-danger" role="alert">
                    {form.errors.email}
                </p>
            )}

            {/* Optional, and only used when the account is created. A magic
                link is the whole of registration here, so this is the one
                moment there is to ask: without a name a shared wishlist
                cannot say whose it is. */}
            <label htmlFor={nameId} className="block text-sm font-medium">
                {t('auth.name')}
            </label>
            <input
                id={nameId}
                type="text"
                autoComplete="name"
                maxLength={80}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                className={`w-full rounded-lg border border-line px-4 py-3 ${fieldClass}`}
            />

            {sent && (
                <p
                    className="flex items-start gap-3 rounded-lg border border-sage bg-sage/10 px-4 py-3 text-base font-medium text-sage"
                    role="status"
                    aria-live="polite"
                >
                    <ToolIcon name="mailAccount" className="h-7 w-7 shrink-0" />
                    <span>{sent}</span>
                </p>
            )}

            {failed && (
                <p className="rounded-lg border border-danger/40 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
                    {t('auth.failed')}
                </p>
            )}

            <button
                type="submit"
                disabled={form.processing}
                className={buttonClasses('primary', 'lg', 'w-full')}
            >
                {form.processing ? t('auth.sending') : t('auth.send')}
            </button>
        </form>
    )
}

/** "Waarom aanmelden?", above both ways in (owner, 2026-11-12). */
export function WhySignIn({ className = '' }: { className?: string }) {
    const { t } = useTranslations()

    return (
        <p className={`text-sm text-ink-soft ${className}`}>
            <span className="font-medium text-ink">{t('auth.why_title')}</span> {t('auth.why_body')}
        </p>
    )
}
