import { useState } from 'react'
import ShareLink from './ShareLink'
import { useTranslations } from '../useTranslations'

/**
 * A link, a share button and a copy button, in that order.
 *
 * Extracted because the Secret Santa invite was a lone "copy" button with the
 * URL nowhere in sight, while a wishlist showed the link, offered the share
 * sheet and confirmed the copy. Two ways of doing the same thing on one site is
 * a bug in the interface even when both work — and the sparser one was on the
 * screen whose entire purpose is getting a link to other people.
 *
 * The link is shown, not just copyable: people check a URL before they paste it
 * into a group chat, and a button that claims to have copied something is worth
 * less than the thing itself.
 */
export default function ShareRow({
    url,
    text,
    label,
    hint,
}: {
    url: string
    text?: string
    label?: string
    hint?: string
}) {
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)

    async function copy() {
        await navigator.clipboard.writeText(url)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <div>
            {label && <h3 className="text-sm font-medium">{label}</h3>}
            {hint && <p className="mt-1 text-xs text-ink-soft">{hint}</p>}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded border border-line px-3 py-2 text-xs">
                    {url}
                </code>
                <ShareLink url={url} text={text} />
                <button
                    type="button"
                    onClick={copy}
                    className="rounded-lg bg-ink px-3 py-2 text-sm whitespace-nowrap text-cream"
                >
                    {copied ? t('lists.copied') : t('lists.copy_link')}
                </button>
            </div>
        </div>
    )
}
