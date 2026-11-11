import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../types'
import { rich, useTranslations } from '../useTranslations'
import ToolIcon from './ToolIcon'

/**
 * The bar under the header while the signed-in address is not confirmed yet.
 *
 * A new address typed into the sign-in form is signed in at once, for this
 * session only (docs/features/auth.md, "Unconfirmed accounts"). Close the
 * browser and the way back in is the mailed link, so until that link has been
 * pressed every page says so. Owner, 2026-11-12: "show a header at the top to
 * remind the user to click on link to keep access".
 *
 * Not dismissible: it goes away by doing what it asks. The link expires in
 * fifteen minutes, so the bar can send a fresh one.
 */
export default function ConfirmEmailNotice() {
    const { auth, market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle')

    const user = auth.user

    if (!user || user.confirmed) return null

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

    return (
        <div role="status" className="border-b border-accent/30 bg-accent/10 text-ink">
            <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 text-sm">
                <ToolIcon name="mailAccount" className="h-6 w-6 shrink-0 text-accent" />
                <p className="min-w-0 flex-1 basis-64">
                    {rich(t('auth.confirm_notice'), { email: <strong className="break-all font-semibold">{user.email}</strong> })}
                </p>
                {state === 'sent' ? (
                    <span className="font-medium text-sage" aria-live="polite">
                        {t('auth.confirm_resent')}
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={resend}
                        disabled={state === 'sending'}
                        className="shrink-0 rounded-lg border border-accent px-3 py-1.5 font-medium text-accent transition hover:bg-accent hover:text-white disabled:opacity-50"
                    >
                        {state === 'sending' ? t('auth.sending') : t('auth.confirm_resend')}
                    </button>
                )}
                {state === 'failed' && (
                    <span className="basis-full text-danger" role="alert">
                        {t('auth.failed')}
                    </span>
                )}
            </div>
        </div>
    )
}
