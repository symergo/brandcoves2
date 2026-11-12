import { Head, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import EmailSignIn, { WhySignIn } from '../../Components/EmailSignIn'

interface Props {
    googleEnabled: boolean
    /** Filled in from `?email=`, which the button in an invitation email carries. */
    email?: string | null
}

export default function Login({ googleEnabled, email }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    return (
        <>
            <Head title={t('auth.title')} />

            {/* A white card on the page (site review, 2026-11-12): the form floated on the cream. */}
            <div className="mx-auto max-w-md rounded-card border border-line bg-card p-6 shadow-[0_8px_24px_rgb(42_18_19/0.08)] sm:p-8">
                <h1 className="text-xl sm:text-2xl font-semibold">{t('auth.title')}</h1>
                <WhySignIn className="mt-2" />

                {/* "Check your inbox" and any refusal are shown by EmailSignIn, beside
                    its button; the layout's banner stays quiet on this page
                    (FlashMessage) so the sentence is not printed twice. */}
                {/*
                  Google first (owner's call, 2026-09-14).

                  It was under the email form behind an "or", which reads as
                  the fallback and is the wrong way round: signing in with an
                  account you already have is one tap, and a magic link is a
                  trip to an inbox and back. The form stays right below it for
                  anyone who would rather not connect an account at all.

                  Hidden entirely when unconfigured — a button that leads to an
                  exception is worse than no button.
                */}
                {googleEnabled && (
                    <>
                        <a
                            href={`${base}/auth/google`}
                            // White, with Google's own mark: two red buttons in one
                            // form competed, and "Stuur me een link" is this site's own.
                            className="mt-6 flex w-full items-center justify-center gap-3 rounded-lg border border-line bg-card px-5 py-3 font-medium text-ink transition hover:border-ink"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5 shrink-0">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                            </svg>
                            {t('auth.google')}
                        </a>

                        <div className="my-6 flex items-center gap-3 text-xs text-ink-soft">
                            <span className="h-px flex-1 bg-line" />
                            {t('auth.or')}
                            <span className="h-px flex-1 bg-line" />
                        </div>
                    </>
                )}

                <EmailSignIn email={email ?? ''} idPrefix="login" />
            </div>
        </>
    )
}
