import { useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * Send a link to people.
 *
 * The single highest-value thing in the social phase, and the reason no friend
 * graph is imported: a gift list's job is to travel through a group chat, and
 * the native share sheet is what puts it there. Facebook's friend list is
 * unavailable to a new app and Google's contacts are a restricted scope — but
 * WhatsApp has been one tap away the whole time.
 *
 * Explicit fallbacks below the sheet, because `navigator.share` is absent on
 * most desktop browsers and that is where lists are usually built.
 */
export default function ShareLink({ url, text }: { url: string; text?: string }) {
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)

    const message = text ? `${text} ${url}` : url

    async function share() {
        if (navigator.share) {
            // Cancelling the sheet rejects; that is a choice, not an error.
            await navigator.share({ text: message, url }).catch(() => undefined)

            return
        }

        await navigator.clipboard.writeText(message)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={share}
                className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
            >
                {copied ? t('lists.copied') : t('lists.share')}
            </button>

            <a
                href={`https://wa.me/?text=${encodeURIComponent(message)}`}
                target="_blank"
                rel="noopener noreferrer"
                className="rounded-lg border border-line px-3 py-2 text-sm hover:border-ink"
            >
                WhatsApp
            </a>

            <a
                href={`mailto:?body=${encodeURIComponent(message)}`}
                className="rounded-lg border border-line px-3 py-2 text-sm hover:border-ink"
            >
                {t('lists.share_email')}
            </a>
        </div>
    )
}
