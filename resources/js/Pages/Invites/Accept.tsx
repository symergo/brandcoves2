import { Head } from '@inertiajs/react'
import Button from '../../Components/Button'
import { useTranslations } from '../../useTranslations'

interface Props {
    /** Who sent the invitation; null when a signed-in visitor opens a link that no longer works. */
    inviterName: string | null
    /** Set when somebody is already signed in: no button then, see InviteAcceptController. */
    signedInAs: string | null
    /** Where the button POSTs; null when there is no button. */
    acceptUrl: string | null
    csrfToken: string
}

/**
 * "Anna nodigt je uit op GiftCoves", opened from the button in an invitation
 * email (2026-09-27). Pressing its button creates the account and signs in.
 *
 * Opening this page does nothing: mail scanners open every link, so only the
 * button's POST uses the invitation. A plain `<form method="post">` with the
 * session's CSRF token, so it works before the page's script has loaded.
 */
export default function Accept({ inviterName, signedInAs, acceptUrl, csrfToken }: Props) {
    const { t } = useTranslations()
    const title = inviterName === null ? t('invite_accept.title_plain') : t('invite_accept.title', { name: inviterName })

    return (
        <>
            <Head title={title} />

            <div className="mx-auto max-w-md">
                <h1 className="text-xl sm:text-2xl font-semibold">{title}</h1>

                {signedInAs !== null ? (
                    <p className="mt-3">{t('invite_accept.signed_in_as', { email: signedInAs })}</p>
                ) : (
                    acceptUrl !== null && (
                        <>
                            <p className="mt-3">{t('invite_accept.what', { name: inviterName ?? '' })}</p>
                            <form method="post" action={acceptUrl} className="mt-5">
                                <input type="hidden" name="_token" value={csrfToken} />
                                <Button type="submit">{t('invite_accept.button')}</Button>
                            </form>
                        </>
                    )
                )}
            </div>
        </>
    )
}
