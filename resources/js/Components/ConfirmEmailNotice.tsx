import { router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import ToolIcon from './ToolIcon'

/**
 * One quiet line under the header while the signed-in address is not
 * confirmed yet.
 *
 * A new address typed into a form is signed in at once and gets no mail of
 * its own until the mailed link is pressed (docs/features/auth.md,
 * "Unconfirmed accounts"). This says so, once per page, with a way to mail
 * the link again (it expires in fifteen minutes).
 *
 * Discreet on purpose (owner, 2026-11-12: "make the banner on non-confirmed
 * emails more discrete"). It was a tinted bar with an icon, the address in
 * bold and an outlined button, on every page. Now it is one small grey line
 * with a text link, and it can be put away for the visit. Nothing is lost by
 * ignoring it: since the same day the account keeps its sign-in either way,
 * and confirming only switches the mails on.
 */
const DISMISSED = 'bc.confirm-notice-dismissed'

export default function ConfirmEmailNotice() {
    const { auth, market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle')
    const [hidden, setHidden] = useState(false)

    // Read after mount: the server does not know what this browser put away.
    useEffect(() => {
        try {
            setHidden(sessionStorage.getItem(DISMISSED) === '1')
        } catch {
            // Without storage it simply shows.
        }
    }, [])

    const user = auth.user

    if (!user || user.confirmed || hidden) return null

    function resend() {
        setState('sending')

        router.post(
            `/${market.key}/auth/confirm-link`,
            {},
            {
                preserveScroll: true,
                // The outcome is said here; `flash.error` is the refusal.
                onSuccess: (page) => setState((page.props as unknown as SharedProps).flash.error ? 'failed' : 'sent'),
                onError: () => setState('failed'),
                onHttpException: () => {
                    setState('failed')

                    return false
                },
                onNetworkError: () => {
                    setState('failed')

                    return false
                },
            },
        )
    }

    function dismiss() {
        setHidden(true)

        try {
            sessionStorage.setItem(DISMISSED, '1')
        } catch {
            // It is back on the next page, which is no harm.
        }
    }

    return (
        // A soft tint and a small envelope (owner, later that evening: "Color
        // background ... and add icon"): still one thin line, but seen.
        <div role="status" className="border-b border-accent/20 bg-accent/10 text-xs text-ink">
            <div className="mx-auto flex w-full max-w-6xl items-center gap-x-2 px-4 py-1.5">
                <ToolIcon name="mailAccount" className="h-4 w-4 shrink-0 text-accent" />
                <p className="min-w-0 flex-1">
                    {t('auth.confirm_notice')}{' '}
                    {state === 'sent' ? (
                        <span aria-live="polite">{t('auth.confirm_resent')}</span>
                    ) : (
                        <button type="button" onClick={resend} disabled={state === 'sending'} className="underline hover:text-ink disabled:opacity-50">
                            {state === 'sending' ? t('auth.sending') : t('auth.confirm_resend')}
                        </button>
                    )}
                    {state === 'failed' && (
                        <span className="ml-2 text-danger" role="alert">
                            {t('auth.failed')}
                        </span>
                    )}
                </p>
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label={t('auth.confirm_dismiss')}
                    title={t('auth.confirm_dismiss')}
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-base leading-none text-ink-soft hover:bg-accent/20 hover:text-ink"
                >
                    ×
                </button>
            </div>
        </div>
    )
}
