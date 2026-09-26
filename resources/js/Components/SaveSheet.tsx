import { type ReactNode, type RefObject, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslations } from '../useTranslations'

/** Where the panel goes, and which way up it is. */
interface Placement {
    top: number
    left: number
    maxHeight: number
    /** Anchored by its bottom edge, because there was no room below the trigger. */
    flipped: boolean
}

const PANEL_WIDTH = 288
const EDGE = 8

/**
 * The small panel the Save button opens, for a product and for a Cove alike.
 *
 * One shell for both, because "save" used to be four controls that each
 * opened (or did not open) something of their own; the audit of 2026-09-26
 * made it one button and one sheet. What goes inside differs (lists for a
 * product, "keep it" and "make it my list" for a Cove); how it opens, where it
 * sits and how it closes does not. See docs/features/save-button.md.
 *
 * ## Why a portal
 *
 * A product card is `overflow-hidden` (rounded corners, and the image scales on
 * hover), so an absolutely positioned panel inside it is clipped away: it
 * opened correctly and was invisible. Rendering into `document.body` escapes
 * every `overflow-hidden` ancestor and every stacking context at once.
 *
 * ## A sheet on a phone, a popover on a desktop
 *
 * A 288px panel anchored to a 36px button is a desktop shape. On a phone every
 * card touches both edges of the screen, the panel is most of the screen
 * anyway, and anchoring it only makes it land somewhere arbitrary. Below 640px
 * it is a bottom sheet over a dimmed page.
 *
 * ## Keyboard
 *
 * Opening moves focus into the panel; Escape closes it and puts focus back on
 * the button that opened it, so a keyboard user is never left on `<body>`.
 * A press outside closes it too.
 */
export default function SaveSheet({
    open,
    onClose,
    anchor,
    label,
    children,
}: {
    open: boolean
    onClose: () => void
    /** The button the panel belongs to: it is positioned against it and focus returns to it. */
    anchor: RefObject<HTMLElement | null>
    /** What a screen reader announces the panel as. */
    label: string
    children: ReactNode
}) {
    const { t } = useTranslations()
    const panel = useRef<HTMLDivElement>(null)
    const [place, setPlace] = useState<Placement | null>(null)
    const [sheet, setSheet] = useState(false)

    // Matched once and on change rather than per render, and false by default
    // so the server-rendered pass agrees with the first client paint.
    useEffect(() => {
        const query = window.matchMedia('(max-width: 639px)')
        const apply = () => setSheet(query.matches)

        apply()
        query.addEventListener('change', apply)

        return () => query.removeEventListener('change', apply)
    }, [])

    const position = useCallback(() => {
        const button = anchor.current

        if (!button || sheet) return

        const box = button.getBoundingClientRect()

        /*
         * Under the button, starting at its left edge, so it reads as coming
         * out of it; right-aligned to it instead when that would run off the
         * screen, as it does for a card at the right edge of a grid. Clamped
         * either way.
         */
        const fits = box.left + PANEL_WIDTH <= window.innerWidth - EDGE
        const left = Math.min(
            Math.max(EDGE, fits ? box.left : box.right - PANEL_WIDTH),
            window.innerWidth - PANEL_WIDTH - EDGE,
        )

        /*
         * And vertically. A card in the bottom row of a grid is an ordinary
         * place to save from, and a panel opened below the fold there can be
         * neither read nor reached. It flips above the button when there is
         * more room there, and is capped either way so somebody with twenty
         * lists gets a panel that scrolls rather than one that runs off screen.
         */
        const below = window.innerHeight - box.bottom - EDGE - 6
        const above = box.top - EDGE - 6
        const flipped = below < 240 && above > below

        setPlace({
            top: flipped ? box.top - 6 : box.bottom + 6,
            left,
            maxHeight: Math.max(160, flipped ? above : below),
            flipped,
        })
    }, [anchor, sheet])

    useLayoutEffect(() => {
        if (open) position()
    }, [open, position])

    // Focus in on open, once. The first control rather than the panel itself,
    // so a keyboard user can act straight away. Once, because `place` changes
    // on every scroll and focus must not jump back to the top each time.
    const focused = useRef(false)

    useEffect(() => {
        if (!open) {
            focused.current = false

            return
        }

        if (focused.current || !panel.current) return

        focused.current = true
        panel.current.querySelector<HTMLElement>('button, a[href], input')?.focus({ preventScroll: true })
    }, [open, place, sheet])

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            const target = e.target as Node
            if (panel.current?.contains(target) || anchor.current?.contains(target)) return
            onClose()
        }
        const escape = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return
            onClose()

            const home = anchor.current
            const button = home instanceof HTMLButtonElement ? home : home?.querySelector<HTMLElement>('button')
            button?.focus()
        }

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)
        // Fixed positioning does not follow the page, so it is repositioned
        // rather than left floating over the wrong card.
        window.addEventListener('scroll', position, true)
        window.addEventListener('resize', position)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
            window.removeEventListener('scroll', position, true)
            window.removeEventListener('resize', position)
        }
    }, [open, position, onClose, anchor])

    if (!open) return null

    if (sheet) {
        return createPortal(
            <div className="fixed inset-0 z-50 flex items-end">
                <button type="button" aria-label={t('nav.close')} onClick={onClose} className="absolute inset-0 bg-ink/40" />
                <div
                    ref={panel}
                    role="dialog"
                    aria-label={label}
                    className="relative max-h-[75vh] w-full overflow-y-auto rounded-t-card border-t border-line bg-card p-2 pb-6 text-left shadow-xl"
                >
                    {/* A grab handle: the one shape everybody reads as "this slides away". */}
                    <div aria-hidden className="mx-auto mb-2 h-1 w-10 rounded-full bg-line" />
                    {children}
                </div>
            </div>,
            document.body,
        )
    }

    if (!place) return null

    return createPortal(
        <div
            ref={panel}
            role="dialog"
            aria-label={label}
            style={{
                position: 'fixed',
                left: place.left,
                width: PANEL_WIDTH,
                maxHeight: place.maxHeight,
                // Anchored by whichever edge meets the button, so a flipped
                // panel grows upwards instead of covering it.
                ...(place.flipped ? { bottom: window.innerHeight - place.top } : { top: place.top }),
            }}
            className="z-50 overflow-y-auto rounded-card border border-line bg-card p-2 text-left shadow-xl"
        >
            {children}
        </div>,
        document.body,
    )
}
