import { Head } from '@inertiajs/react'
import Button from '../../Components/Button'
import { useTranslations } from '../../useTranslations'

interface Props {
    /** This address already asked for no invitations. */
    stopped: boolean
    stopUrl: string
    undoUrl: string
}

/**
 * "This is spam / not asked for?", opened from an invitation email.
 *
 * The reader may have no account and may never come back, so the page says one
 * thing and offers one button. Plain `<form method="post">` rather than an
 * Inertia visit: both routes are signed and exempt from CSRF, and a form works
 * even when the page's script has not loaded. A press is a POST on purpose,
 * never the GET that opened this page; see InviteNotWantedController.
 */
export default function NotWanted({ stopped, stopUrl, undoUrl }: Props) {
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
            </div>
        </>
    )
}
