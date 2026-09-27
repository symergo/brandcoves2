import { Head } from '@inertiajs/react'
import Button from '../../Components/Button'
import { useTranslations } from '../../useTranslations'

interface Props {
    /** This address already asked for no invitations. */
    stopped: boolean
    /** This address already reported this member's invitation as spam. */
    reported: boolean
    stopUrl: string
    spamUrl: string
    undoUrl: string
}

/**
 * "Wil je geen uitnodigingen meer ontvangen?", opened from an invitation email.
 *
 * Two answers, kept apart on purpose (owner, 2026-09-27): "no more invitations"
 * only stops the emails; "report as spam" also counts a complaint against the
 * member who sent it, so it is the smaller, second button, never the default.
 *
 * Plain `<form method="post">` rather than an Inertia visit: the routes are
 * signed and exempt from CSRF, and a form works even when the page's script
 * has not loaded. A press is a POST on purpose, never the GET that opened this
 * page; see InviteNotWantedController.
 */
export default function NotWanted({ stopped, reported, stopUrl, spamUrl, undoUrl }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('invite_mail.page_title')} />

            <div className="mx-auto max-w-md">
                <h1 className="text-xl sm:text-2xl font-semibold">{t('invite_mail.page_title')}</h1>

                {stopped ? (
                    <>
                        <p className="mt-3">{t('invite_mail.stopped')}</p>
                        <p className="mt-2 text-ink-soft">{t('invite_mail.stopped_more')}</p>
                        <form method="post" action={undoUrl} className="mt-5">
                            <Button type="submit" variant="secondary">
                                {t('invite_mail.undo')}
                            </Button>
                        </form>
                    </>
                ) : (
                    <>
                        <p className="mt-3">{t('invite_mail.ask')}</p>
                        <form method="post" action={stopUrl} className="mt-5">
                            <Button type="submit">{t('invite_mail.stop')}</Button>
                        </form>
                    </>
                )}

                <div className="mt-8 border-t border-line pt-5">
                    {reported ? (
                        <p className="text-ink-soft">{t('invite_mail.reported')}</p>
                    ) : (
                        <>
                            <p className="text-ink-soft">{t('invite_mail.spam_ask')}</p>
                            <form method="post" action={spamUrl} className="mt-3">
                                <Button type="submit" variant="secondary">
                                    {t('invite_mail.spam')}
                                </Button>
                            </form>
                        </>
                    )}
                </div>
            </div>
        </>
    )
}
