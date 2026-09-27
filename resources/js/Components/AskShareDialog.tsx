import { useEffect, useRef } from 'react'
import ShareRow from './ShareRow'
import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

/**
 * A question's link, as a popup: "Link kopiëren" and "Stuur via…" (ShareRow),
 * with the question itself as the message.
 *
 * Opened at once after asking your people (owner, 2026-09-27: "provide a share
 * link after posting"), and from the Share button on your own questions, on
 * the board page and on the question. A native <dialog> through showModal(),
 * like the list overview's share popup: focus stays inside, Escape closes it,
 * the page behind is inert.
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
    const ref = useRef<HTMLDialogElement>(null)

    useEffect(() => {
        const el = ref.current

        if (el !== null && !el.open) {
            el.showModal()
        }
    }, [])

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onClick={(e) => {
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            aria-label={t('ask.share_heading')}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[min(32rem,calc(100vw-2rem))] overflow-y-auto rounded-card border border-line bg-card p-6 backdrop:bg-ink/40"
        >
            <div className="flex items-start justify-between gap-3">
                <h2 className="text-lg font-semibold">{t('ask.share_heading')}</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('nav.close')}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink"
                >
                    <ToolIcon name="close" className="h-4 w-4" />
                </button>
            </div>

            <p className="mt-2 text-sm text-ink-soft">“{title}”</p>

            <div className="mt-4">
                <ShareRow
                    url={url}
                    text={t('ask.share_text', { question: title })}
                    hint={forPeople ? t('ask.people_only.share_hint') : undefined}
                />
            </div>
        </dialog>
    )
}
