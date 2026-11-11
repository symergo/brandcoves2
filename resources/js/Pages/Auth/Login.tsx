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

            <div className="mx-auto max-w-md">
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
                            className="mt-6 flex w-full items-center justify-center gap-2 rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark"
                        >
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
