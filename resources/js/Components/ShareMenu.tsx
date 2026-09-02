import { useEffect, useRef, useState } from 'react'
import ShareIcon, { type ShareIconKey } from './ShareIcon'
import { useTranslations } from '../useTranslations'

/**
 * Share a link, or a score, through the channels people actually use.
 *
 * ## The native sheet is the whole control where it exists
 *
 * `navigator.share` is not one more destination in a list — it is a better
 * version of the entire list. It offers every app actually installed, in the
 * order that device puts them, including the ones no website can link to.
 * Where it exists, pressing Share opens it and there is no menu at all.
 *
 * It used to be the first row *inside* the menu, under the label "More apps…",
 * which had it exactly backwards: a phone — where sharing actually happens —
 * got a hand-rolled dropdown of five web fallbacks, with the thing that knows
 * what is installed offered last and described as an afterthought. Before that
 * the button silently copied on desktop, so anyone on a laptop pressed "Share",
 * saw "Copied", and never found WhatsApp at all.
 *
 * So: the sheet on a phone, an explicit menu on a desktop, and neither of them
 * pretending to be the other.
 *
 * ## What each channel can actually accept
 *
 * These are not interchangeable, and pretending they are is how a share button
 * posts an empty message:
 *
 * - **WhatsApp** and **Telegram** take arbitrary text, so the link rides along
 *   inside it. This is the one that matters — a gift list lives in a group chat.
 * - **Facebook** takes a URL and nothing else. It removed support for prefilled
 *   text years ago and silently drops it, so passing a score to it would post a
 *   bare link and lose the point.
 * - **Email** takes a subject and a body.
 * - **Instagram has no web sharing at all.** There is no URL scheme that
 *   accepts a link or a caption, so it is a line of explanation rather than a
 *   button that appears to work and does not.
 *
 * ## Keyboard
 *
 * The popup is a real `menu`: opening moves focus into it, ↑/↓/Home/End move
 * between items, Escape and Tab close it and hand focus back to the button that
 * opened it. It carried `role="menu"` and none of that behaviour before, which
 * is the combination that is worse than no role at all — a screen reader
 * announces a menu and then the arrow keys scroll the page.
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
    const [status, setStatus] = useState('')
    const [native, setNative] = useState(false)

    const box = useRef<HTMLDivElement>(null)
    const trigger = useRef<HTMLButtonElement>(null)
    const items = useRef<(HTMLElement | null)[]>([])

    /*
     * After mount, never during render: `navigator` does not exist while the
     * page is being server-rendered, and reading it in the render body would
     * make the server and the client disagree about which control this is.
     */
    useEffect(() => {
        setNative(typeof navigator !== 'undefined' && typeof navigator.share === 'function')
    }, [])

    // Say it once, then stop. A status that stays on screen stops being read.
    useEffect(() => {
        if (status === '') return
        const timer = setTimeout(() => setStatus(''), 3000)

        return () => clearTimeout(timer)
    }, [status])

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            if (!box.current?.contains(e.target as Node)) setOpen(false)
        }

        document.addEventListener('mousedown', away)

        // Focus the first destination. Opening a menu and leaving focus behind
        // on the button is what makes arrow keys scroll the page instead.
        items.current[0]?.focus()

        return () => document.removeEventListener('mousedown', away)
    }, [open])

    const message = text ? `${text}\n${url}` : url

    function close(returnFocus = true) {
        setOpen(false)
        if (returnFocus) trigger.current?.focus()
    }

    /**
     * Copy, and say what happened either way.
     *
     * `navigator.clipboard` is undefined outside a secure context — which is
     * every plain-http address, including the LAN one this gets tested on — and
     * it rejects when the page is not focused. Both used to throw into nothing:
     * the button did visibly nothing and the reader had no idea why.
     */
    async function copy(value: string) {
        try {
            await navigator.clipboard.writeText(value)
            setStatus(t('lists.copied'))
        } catch {
            setStatus(t('lists.copy_manual'))
        }

        close()
    }

    async function shareNatively() {
        // AbortError is the reader closing the sheet, which is not a failure
        // and must not be reported as one.
        await navigator.share({ text: message, url }).catch(() => undefined)
    }

    /** Roving focus, so the menu behaves the way its role promises. */
    function onKeyDown(e: React.KeyboardEvent) {
        const focusable = items.current.filter((el): el is HTMLElement => el !== null)
        const here = focusable.indexOf(document.activeElement as HTMLElement)

        if (e.key === 'Escape' || e.key === 'Tab') {
            close()

            return
        }

        const next = {
            ArrowDown: here + 1,
            ArrowUp: here - 1,
            Home: 0,
            End: focusable.length - 1,
        }[e.key]

        if (next === undefined) return

        e.preventDefault()
        // Wraps, because a menu this short has no "end" worth stopping at.
        focusable[(next + focusable.length) % focusable.length]?.focus()
    }

    const channels: { key: ShareIconKey; label: string; href: string }[] = [
        {
            key: 'whatsapp',
            label: 'WhatsApp',
            href: `https://wa.me/?text=${encodeURIComponent(message)}`,
        },
        {
            key: 'telegram',
            label: 'Telegram',
            href: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text ?? '')}`,
        },
        {
            key: 'facebook',
            label: 'Facebook',
            // URL only: Facebook drops prefilled text.
            href: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`,
        },
        {
            key: 'x',
            label: 'X',
            href: `https://x.com/intent/tweet?text=${encodeURIComponent(text ?? '')}&url=${encodeURIComponent(url)}`,
        },
        {
            key: 'email',
            label: t('lists.share_email'),
            href: `mailto:?subject=${encodeURIComponent(text ?? '')}&body=${encodeURIComponent(message)}`,
        },
    ]

    /*
     * The two chat apps first, and the reason is not popularity.
     *
     * A gift list is shared with the people who are buying the gift, and that
     * conversation is a group chat. Facebook and X are for a quiz score — the
     * one thing here anybody broadcasts.
     */
    const row = 'flex w-full items-center gap-3 rounded px-3 py-2 text-left text-sm hover:bg-line/40'

    return (
        <div ref={box} className="relative inline-block">
            <button
                ref={trigger}
                type="button"
                onClick={() => (native ? shareNatively() : setOpen((v) => !v))}
                // Only the menu version pops something up. Announcing a menu on
                // the phone, where pressing this opens the OS sheet instead,
                // would describe a control that is not there.
                aria-expanded={native ? undefined : open}
                aria-haspopup={native ? undefined : 'menu'}
                className="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm whitespace-nowrap hover:border-ink"
            >
                <ShareIcon name="share" />
                {label ?? t('lists.share')}
            </button>

            {open && (
                <div className="absolute right-0 z-50 mt-1 w-60 rounded-card border border-line bg-card p-1 shadow-xl">
                    <div role="menu" onKeyDown={onKeyDown}>
                        {channels.map((channel, i) => (
                            <a
                                key={channel.key}
                                ref={(el) => {
                                    items.current[i] = el
                                }}
                                role="menuitem"
                                href={channel.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => close(false)}
                                className={row}
                            >
                                <span aria-hidden className="shrink-0 text-ink-soft">
                                    <ShareIcon name={channel.key} />
                                </span>
                                {channel.label}
                            </a>
                        ))}

                        {/*
                          "Copy text and link", not "Copy link".

                          The row this menu sits in has its own copy button, and
                          that one copies the bare URL — which is what you paste
                          into an address bar. This one copies the message the
                          channels above would have sent. Two buttons a
                          centimetre apart, both saying "Copy link" and putting
                          different things on the clipboard, is what was there
                          before.
                        */}
                        <button
                            ref={(el) => {
                                items.current[channels.length] = el
                            }}
                            type="button"
                            role="menuitem"
                            onClick={() => copy(message)}
                            className={row}
                        >
                            <span aria-hidden className="shrink-0 text-ink-soft">
                                <ShareIcon name="copy" />
                            </span>
                            {text ? t('lists.copy_message') : t('lists.copy_link')}
                        </button>
                    </div>

                    {/*
                      Outside the `role="menu"`, deliberately. A paragraph is
                      not a menu item, and in menu mode a screen reader is
                      entitled to skip anything that is not one — which would
                      drop the one line here that exists to explain an absence.
                    */}
                    <p className="border-t border-line px-3 py-2 text-xs text-ink-soft">
                        {t('lists.share_instagram')}
                    </p>
                </div>
            )}

            {/*
              Spoken, not shown in place of the button's own label.

              "Copied" used to replace the word "Share" for two seconds, which
              changed the button's width and shuffled the row around it — and
              said nothing at all to a reader who cannot see it.
            */}
            <span role="status" aria-live="polite" className="sr-only">
                {status}
            </span>
        </div>
    )
}
