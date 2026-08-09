import { useEffect, useRef, useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * Share a link, or a score, through the channels people actually use.
 *
 * ## Why this is a menu and not just the native sheet
 *
 * `navigator.share` is the best option where it exists — it offers every app on
 * the device — but it does not exist on most desktop browsers, and desktop is
 * where lists get built. The previous version fell back to silently copying, so
 * anyone on a laptop pressed "Share", saw "Copied", and never found WhatsApp at
 * all. Native first when available, explicit channels always.
 *
 * ## What each channel can actually accept
 *
 * These are not interchangeable, and pretending they are is how a share button
 * posts an empty message:
 *
 * - **WhatsApp** and **Telegram** take arbitrary text, so the link rides along
 *   inside it. This is the one that matters — a gift list lives in a group chat.
 * - **Facebook** takes a URL and nothing else. It removed support for
 *   prefilled text years ago and silently drops it, so passing a score to it
 *   would post a bare link and lose the point.
 * - **Email** takes a subject and a body.
 * - **Instagram has no web sharing at all.** There is no URL scheme that
 *   accepts a link or a caption; the only honest option is copying, so it is
 *   offered as "copy for Instagram" rather than as a button that quietly does
 *   nothing.
 */
export default function ShareMenu({
    url,
    text,
    label,
}: {
    url: string
    /** Prose to accompany the link. Ignored by Facebook, which only takes URLs. */
    text?: string
    label?: string
}) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [copied, setCopied] = useState(false)
    const [native, setNative] = useState(false)
    const box = useRef<HTMLDivElement>(null)

    // After mount: `navigator` does not exist during SSR, and reading it while
    // rendering would make server and client disagree.
    useEffect(() => {
        setNative(typeof navigator !== 'undefined' && typeof navigator.share === 'function')
    }, [])

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            if (!box.current?.contains(e.target as Node)) setOpen(false)
        }
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
        }
    }, [open])

    const message = text ? `${text}\n${url}` : url

    async function copy() {
        await navigator.clipboard.writeText(message)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
        setOpen(false)
    }

    async function shareNatively() {
        setOpen(false)
        await navigator.share({ text: message, url }).catch(() => undefined)
    }

    const channels = [
        {
            key: 'whatsapp',
            label: 'WhatsApp',
            href: `https://wa.me/?text=${encodeURIComponent(message)}`,
        },
        {
            key: 'facebook',
            label: 'Facebook',
            // URL only: Facebook drops prefilled text.
            href: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`,
        },
        {
            key: 'telegram',
            label: 'Telegram',
            href: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text ?? '')}`,
        },
        {
            key: 'x',
            label: 'X',
            href: `https://twitter.com/intent/tweet?text=${encodeURIComponent(text ?? '')}&url=${encodeURIComponent(url)}`,
        },
        {
            key: 'email',
            label: t('lists.share_email'),
            href: `mailto:?subject=${encodeURIComponent(text ?? '')}&body=${encodeURIComponent(message)}`,
        },
    ]

    return (
        <div ref={box} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                className="rounded-lg border border-line px-3 py-2 text-sm whitespace-nowrap hover:border-ink"
            >
                {copied ? t('lists.copied') : (label ?? t('lists.share'))}
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-50 mt-1 w-56 rounded-card border border-line bg-card p-1 shadow-xl"
                >
                    {native && (
                        <button
                            type="button"
                            role="menuitem"
                            onClick={shareNatively}
                            className="block w-full rounded px-3 py-2 text-left text-sm hover:bg-line/40"
                        >
                            {t('lists.share_native')}
                        </button>
                    )}

                    {channels.map((channel) => (
                        <a
                            key={channel.key}
                            role="menuitem"
                            href={channel.href}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={() => setOpen(false)}
                            className="block rounded px-3 py-2 text-sm hover:bg-line/40"
                        >
                            {channel.label}
                        </a>
                    ))}

                    <button
                        type="button"
                        role="menuitem"
                        onClick={copy}
                        className="block w-full rounded px-3 py-2 text-left text-sm hover:bg-line/40"
                    >
                        {t('lists.copy_link')}
                    </button>

                    {/* Instagram accepts nothing from the web. Saying so beats a
                        button that appears to work and does not. */}
                    <p className="px-3 py-2 text-xs text-ink-soft">{t('lists.share_instagram')}</p>
                </div>
            )}
        </div>
    )
}
