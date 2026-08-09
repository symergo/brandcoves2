import { useEffect, useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * Send a link to people.
 *
 * One button, sitting next to the link it shares. It used to be three — a share
 * button plus standalone WhatsApp and email links — which put a row of channel
 * buttons on the page for a decision most people make in the share sheet
 * anyway, and made a simple "here is your link" area look like a toolbar.
 *
 * The native sheet already offers WhatsApp, mail, Messages and everything else
 * the device has, so the separate links were duplicating it on the one platform
 * where it works best. Where there is no sheet — most desktop browsers — this
 * falls back to copying, which is what the person was going to do anyway.
 *
 * This is also the reason no friend graph is imported: Facebook's friend list is
 * unavailable to a new app and Google's contacts are a restricted scope, while
 * WhatsApp has been one tap away the whole time.
 */
export default function ShareLink({ url, text }: { url: string; text?: string }) {
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)
    const [native, setNative] = useState(false)

    // Resolved after mount: `navigator` does not exist during SSR, and reading
    // it while rendering would make the server and client disagree.
    useEffect(() => {
        setNative(typeof navigator !== 'undefined' && typeof navigator.share === 'function')
    }, [])

    const message = text ? `${text} ${url}` : url

    async function share() {
        if (native) {
            // Cancelling the sheet rejects. That is a choice, not an error.
            await navigator.share({ text: message, url }).catch(() => undefined)

            return
        }

        await navigator.clipboard.writeText(message)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <button
            type="button"
            onClick={share}
            className="rounded-lg border border-line px-3 py-2 text-sm whitespace-nowrap hover:border-ink"
        >
            {copied ? t('lists.copied') : t('lists.share')}
        </button>
    )
}
