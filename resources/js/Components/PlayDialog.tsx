import { useEffect, type ReactNode } from 'react'
import ToolIcon, { type ToolKey } from './ToolIcon'

/**
 * The popup the choosing games play in: Swipe gifts and This or that.
 *
 * The owner, 2026-09-29: "bigger pictures, no scrolling... maybe a popup?",
 * then "same layout for Dit of dat". As ordinary pages, the site's header and
 * footer left a small card in a page that scrolled on a phone. Here it is the
 * full screen on a phone (clear of the notch and the home bar) and a tall
 * panel over a dimmed page from `sm` up, with one slim top bar and a close
 * button; the caller fills the rest, which is a column of fixed height: a
 * `flex-1 min-h-0` child gets every pixel left.
 *
 * The page behind does not scroll while it is open, and Escape closes it.
 */
export default function PlayDialog({
    icon,
    title,
    subtitle,
    onClose,
    closeLabel,
    aside,
    wide = false,
    children,
}: {
    icon: ToolKey
    title: string
    /** One line under the title: for whom, a count, a round. */
    subtitle?: string | null
    onClose: () => void
    closeLabel: string
    /** Beside the close button, such as a link to the list. */
    aside?: ReactNode
    /** Room for two cards side by side from `sm` up. */
    wide?: boolean
    children: ReactNode
}) {
    useEffect(() => {
        const before = document.body.style.overflow
        document.body.style.overflow = 'hidden'

        return () => {
            document.body.style.overflow = before
        }
    }, [])

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault()
                onClose()
            }
        }

        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    }, [onClose])

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="play-dialog-title"
            className="fixed inset-0 z-40 flex items-stretch justify-center bg-ink/60 sm:items-center sm:p-6"
        >
            <div
                className={`flex h-dvh w-full flex-col bg-cream sm:h-[min(52rem,92dvh)] sm:rounded-card sm:shadow-xl ${
                    wide ? 'sm:max-w-3xl' : 'sm:max-w-md'
                }`}
                style={{ paddingTop: 'env(safe-area-inset-top)', paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                <div className="flex items-center gap-3 px-4 pt-3 pb-2">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-accent">
                        <ToolIcon name={icon} className="h-7 w-7" duo />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 id="play-dialog-title" className="truncate text-base font-semibold">
                            {title}
                        </h1>
                        {subtitle && <p className="truncate text-xs text-ink-soft">{subtitle}</p>}
                    </div>
                    {aside}
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label={closeLabel}
                        title={closeLabel}
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-card hover:border-ink"
                    >
                        <ToolIcon name="close" className="h-5 w-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    )
}
