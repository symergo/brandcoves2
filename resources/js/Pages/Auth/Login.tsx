import { Head, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import EmailSignIn, { WhySignIn } from '../../Components/EmailSignIn'
import GoogleSignIn from '../../Components/GoogleSignIn'

interface Props {
    googleEnabled: boolean
    /** Filled in from `?email=`, which the button in an invitation email carries. */
    email?: string | null
}

export default function Login({ email }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

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
                <GoogleSignIn className="mt-6" />

                <EmailSignIn email={email ?? ''} idPrefix="login" />
            </div>
        </>
    )
}
