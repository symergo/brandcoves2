import Modal from './Modal'
import ShareRow from './ShareRow'
import { useTranslations } from '../useTranslations'

/**
 * A question's link, as a popup: "Link kopiëren" and "Stuur via…" (ShareRow),
 * with the question itself as the message.
 *
 * Opened at once after asking your people (owner, 2026-09-27: "provide a share
 * link after posting"), and from the Share button on your own questions, on
 * the board page and on the question. A native <dialog> through showModal(),
 * like the list overview's share popup: focus stays inside, Escape closes it,
 * the page behind is inert. The popup itself is `Modal` since 2026-09-27.
 *
 * On a people question the link *is* the permission, so the hint says so.
 */
export default function AskShareDialog({
    title,
    url,
    forPeople,
    onClose,
}: {
    title: string
    url: string
    forPeople: boolean
    onClose: () => void
}) {
    const { t } = useTranslations()

    return (
        <Modal title={t('ask.share_heading')} onClose={onClose}>
            <p className="mt-2 text-sm text-ink-soft">“{title}”</p>

            <div className="mt-4">
                <ShareRow
                    url={url}
                    text={t('ask.share_text', { question: title })}
                    hint={forPeople ? t('ask.people_only.share_hint') : undefined}
                />
            </div>
        </Modal>
    )
}
