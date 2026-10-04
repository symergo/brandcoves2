import { Head, Link } from '@inertiajs/react'
import Button from '../../Components/Button'
import ToolIcon from '../../Components/ToolIcon'
import { useTranslations } from '../../useTranslations'

interface Props {
    /** Where the button POSTs; null when the link is used up or expired. */
    confirmUrl: string | null
    /** The sign-in form, for a new link. */
    loginUrl: string
    csrfToken: string
}

/**
 * What the link in a sign-in email opens (2026-09-28): one button that signs in.
 *
 * Opening the page does nothing, because company mail scanners open every link
 * in a message; only the button's POST uses the link. A plain `<form
 * method="post">` with the session's CSRF token, so it works before the page's
 * script has loaded, as on the invitation page (Invites/Accept).
 */
export default function ConfirmLink({ confirmUrl, loginUrl, csrfToken }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('auth.confirm_title')} />

            <div className="mx-auto max-w-md">
                <h1 className="text-xl sm:text-2xl font-semibold">{t('auth.confirm_title')}</h1>

                {confirmUrl !== null ? (
                    <>
                        <p className="mt-3 text-ink-soft">{t('auth.confirm_body')}</p>
                        <form method="post" action={confirmUrl} className="mt-5">
                            <input type="hidden" name="_token" value={csrfToken} />
                            <Button type="submit">
                                <span className="inline-flex items-center gap-2">
                                    <ToolIcon name="click" />
                                    {t('auth.confirm_button')}
                                </span>
                            </Button>
                        </form>
                    </>
                ) : (
                    <>
                        <p className="mt-3 text-ink-soft">{t('auth.link_invalid')}</p>
                        <Link href={loginUrl} className="mt-5 inline-block underline">
                            {t('auth.confirm_new_link')}
                        </Link>
                    </>
                )}
            </div>
        </>
    )
}
