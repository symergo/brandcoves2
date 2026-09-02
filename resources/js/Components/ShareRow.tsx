import { useEffect, useRef, useState } from 'react'
import ShareIcon from './ShareIcon'
import ShareMenu from './ShareMenu'
import { useTranslations } from '../useTranslations'

/**
 * The link, a copy button and a share button — the whole of "give this to
 * somebody".
 *
 * Extracted because the Secret Santa invite was a lone "copy" button with the
 * URL nowhere in sight, while a wishlist showed the link, offered the share
 * sheet and confirmed the copy. Two ways of doing the same thing on one site is
 * a bug in the interface even when both work — and the sparser one was on the
 * screen whose entire purpose is getting a link to other people.
 *
 * ## Why the link is a field and not a label
 *
 * It was a truncated `<code>`: readable, and nothing else. People check a URL
 * before they paste it into a group chat, and the ones whose clipboard is
 * blocked — any plain-http address, a locked-down work browser — had no way to
 * get it at all, because you cannot reliably select text that is `text-overflow:
 * ellipsis` on a phone. A read-only input selects its whole contents on focus,
 * so the manual route is one tap and then the browser's own copy.
 *
 * ## Why copy is the primary button and share is not
 *
 * Both are here because they are different jobs. Copy is for the destination
 * this page cannot know about — a work chat, a note to self, a text message —
 * and it is the one people reach for most, so it is the solid button. Share is
 * the one that knows about WhatsApp.
 *
 * The two used to be equally weighted and, worse, both said "Copy link" while
 * putting different things on the clipboard: this one the bare URL, the one
 * inside the menu the URL with a sentence in front of it. Same words, one
 * centimetre apart, two results. Now this copies the link, the menu copies the
 * message, and each says which.
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
    const [status, setStatus] = useState('')
    const field = useRef<HTMLInputElement>(null)

    // Say it once, then stop. A confirmation that never leaves stops being read
    // as a confirmation of the press that just happened.
    useEffect(() => {
        if (status === '') return
        const timer = setTimeout(() => setStatus(''), 3000)

        return () => clearTimeout(timer)
    }, [status])

    /**
     * Copy the URL, and when that is not allowed, hand the reader the next best
     * thing rather than nothing.
     *
     * `navigator.clipboard` is undefined outside a secure context and rejects
     * when the document is not focused. Both used to throw into an empty catch
     * that did not exist: the button did visibly nothing, with no explanation.
     * Now the field is selected and the status line says to copy it — which is
     * one keystroke, and is at least true.
     */
    async function copy() {
        try {
            await navigator.clipboard.writeText(url)
            setStatus(t('lists.copied'))
        } catch {
            field.current?.select()
            setStatus(t('lists.copy_manual'))
        }
    }

    return (
        <div>
            {label && <h3 className="text-sm font-medium">{label}</h3>}
            {hint && <p className="mt-1 text-xs text-ink-soft">{hint}</p>}

            {/*
              The field takes the whole first row on a phone and the buttons sit
              under it. Wrapping a 200-pixel URL, a Copy and a Share onto one
              line at 360px left the link four characters wide — a field you
              cannot read is not a field, it is a spacer.
            */}
            <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                <input
                    ref={field}
                    type="text"
                    value={url}
                    readOnly
                    aria-label={t('lists.share_link')}
                    // Selecting on focus is the whole point of showing it: one
                    // tap and the browser's own copy is available.
                    onFocus={(e) => e.currentTarget.select()}
                    className="min-w-0 flex-1 truncate rounded-lg border border-line bg-cream px-3 py-2 font-mono text-xs"
                />

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={copy}
                        className="inline-flex items-center gap-2 rounded-lg bg-ink px-3 py-2 text-sm whitespace-nowrap text-cream"
                    >
                        <ShareIcon name="copy" />
                        {t('lists.copy_link')}
                    </button>

                    <ShareMenu url={url} text={text} />
                </div>
            </div>

            {/*
              One line, in place, under the control that caused it — and it
              holds its height whether or not there is anything to say, so
              confirming a copy never nudges the page.

              It replaced swapping the button's own label to "Copied" for two
              seconds, which changed the button's width, shuffled the row, and
              left a reader who cannot see it with no confirmation at all. This
              is announced.
            */}
            <p role="status" aria-live="polite" className="mt-1.5 h-4 text-xs text-ink-soft">
                {status}
            </p>
        </div>
    )
}
