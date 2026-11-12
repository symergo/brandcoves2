import { usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * "Aanmelden met Google" and the "of" under it: the same on the login page and
 * in the sign-in popup, which each carried a copy (2026-11-12).
 *
 * Google first (owner's call, 2026-09-14): an account somebody already has is
 * one tap, a magic link is a trip to an inbox and back, so the form under it
 * is the alternative rather than the other way round.
 *
 * White with Google's own mark (site review, 2026-11-12): it was red, beside
 * the red "Stuur me een link", and two red buttons in one form compete.
 *
 * A full page load, deliberately: OAuth leaves the site and comes back, so
 * there is no page state to keep and an Inertia visit would only get in the
 * way. Nothing at all when unconfigured: a button that leads to an exception
 * is worse than no button.
 */
export default function GoogleSignIn({ className = 'mt-5' }: { className?: string }) {
    const { market, auth } = usePage<SharedProps>().props
    const { t } = useTranslations()

    if (!auth.googleEnabled) return null

    return (
        <>
            <a
                href={`/${market.key}/auth/google`}
                className={`${className} flex w-full items-center justify-center gap-3 rounded-lg border border-line bg-card px-5 py-3 font-medium text-ink transition hover:border-ink`}
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
    )
}
