import { Link } from '@inertiajs/react'
import { type ReactNode, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

/**
 * A button that opens a short menu of actions: the "More" menu on a list and
 * the "⋯" on each item of your own list.
 *
 * ## Why a component of its own
 *
 * Three menus already existed (`AccountMenu`, `SaveToList`, `CopyToList`) and
 * each wrote its own open state, outside click and Escape. None of them moves
 * with the arrow keys, and `CopyToList` says in a comment that it therefore
 * does not claim `role="menu"`. The list page's two menus are exactly the case
 * where the role is right — a handful of actions behind one button — so this
 * one does the whole job: arrow keys, Home and End, Escape back to the button,
 * Tab out closes it, and focus goes to the first item on opening.
 *
 * ## Why a portal
 *
 * The same reason as `SaveToList`: a list item card is `overflow-hidden`, so a
 * panel positioned inside it is clipped away. Rendered into `document.body`
 * with fixed coordinates, measured from the button and kept on screen (it flips
 * above the button when there is more room there).
 */
export default function Menu({
    label,
    button,
    buttonClassName,
    width = 240,
    view,
    onClose,
    children,
}: {
    /** The button's spoken name, and its tooltip. */
    label: string
    /** What the button shows. */
    button: ReactNode
    buttonClassName: string
    width?: number
    /**
     * Which screen of the menu is showing, when it has more than one (the item
     * menu's "copy to which list"). A change moves focus to its first item.
     */
    view?: string
    /** Told whenever the menu closes, so a caller can reset its screen. */
    onClose?: () => void
    /** The items. `close` shuts the menu, for an item that acts. */
    children: (close: () => void) => ReactNode
}) {
    const [open, setOpenState] = useState(false)
    const [place, setPlace] = useState<{ top: number; left: number; maxHeight: number } | null>(null)
    const trigger = useRef<HTMLButtonElement>(null)
    const panel = useRef<HTMLDivElement>(null)

    const setOpen = useCallback(
        (next: boolean) => {
            setOpenState(next)

            if (!next) onClose?.()
        },
        [onClose],
    )

    const close = useCallback(
        (focusButton = false) => {
            setOpen(false)

            if (focusButton) trigger.current?.focus()
        },
        [setOpen],
    )

    const position = useCallback(() => {
        const box = trigger.current?.getBoundingClientRect()

        if (!box) return

        const edge = 8
        const left = Math.min(Math.max(edge, box.right - width), window.innerWidth - width - edge)
        const below = window.innerHeight - box.bottom - edge - 6
        const above = box.top - edge - 6
        // 220px is about five rows: less than that below, and more above, and
        // the menu opens upwards rather than under the fold.
        const flipped = below < 220 && above > below
        const maxHeight = Math.max(160, Math.min(flipped ? above : below, 420))

        setPlace({
            top: flipped ? Math.max(edge, box.top - 6 - maxHeight) : box.bottom + 6,
            left,
            maxHeight,
        })
    }, [width])

    useLayoutEffect(() => {
        if (open) position()
    }, [open, position])

    // Radio items too: the market button's rows are a choice of one
    // (`menuitemradio`, 2026-09-26), and they move with the arrows like any other.
    const items = () =>
        Array.from(
            panel.current?.querySelectorAll<HTMLElement>(
                '[role="menuitem"]:not([disabled]), [role="menuitemradio"]:not([disabled])',
            ) ?? [],
        )

    const placed = place !== null

    // Focus the first item when the menu opens, and again when it changes screen;
    // in a choice of one, the item already chosen.
    useEffect(() => {
        if (!open || !placed) return

        const all = items()
        ;(all.find((item) => item.getAttribute('aria-checked') === 'true') ?? all[0])?.focus()
    }, [open, placed, view])

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            const target = e.target as Node

            if (panel.current?.contains(target) || trigger.current?.contains(target)) return

            setOpen(false)
        }

        const keys = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault()
                close(true)

                return
            }

            // The arrow press that opened the menu from its button reaches this
            // listener too, after focus has already moved into the menu, and
            // would move it one row further. It belongs to the button.
            if (trigger.current?.contains(e.target as Node) && e.key !== 'Tab') return

            const active = document.activeElement as HTMLElement | null
            const inside = panel.current?.contains(active) ?? false
            const role = active?.getAttribute('role')
            const onItem = inside && (role === 'menuitem' || role === 'menuitemradio')

            // Tab leaves the menu, as it leaves any other popup. Not from a
            // form inside it (naming a new list), where Tab reaches its button.
            if (e.key === 'Tab') {
                if (onItem) {
                    e.preventDefault()
                    close(true)
                } else if (!inside) {
                    setOpen(false)
                }

                return
            }

            // Arrows only between items: inside a text field they belong to the field.
            if (!onItem) return

            const all = items()
            const at = all.indexOf(active as HTMLElement)
            const go = (i: number) => {
                e.preventDefault()
                all[(i + all.length) % all.length]?.focus()
            }

            if (e.key === 'ArrowDown') go(at + 1)
            else if (e.key === 'ArrowUp') go(at - 1)
            else if (e.key === 'Home') go(0)
            else if (e.key === 'End') go(all.length - 1)
        }

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', keys)
        window.addEventListener('scroll', position, true)
        window.addEventListener('resize', position)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', keys)
            window.removeEventListener('scroll', position, true)
            window.removeEventListener('resize', position)
        }
    }, [open, close, position, setOpen])

    return (
        <>
            <button
                ref={trigger}
                type="button"
                onClick={() => setOpen(!open)}
                onKeyDown={(e) => {
                    // The arrow keys open a menu button too, as on any desktop menu.
                    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !open) {
                        e.preventDefault()
                        setOpen(true)
                    }
                }}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                title={label}
                className={buttonClassName}
            >
                {button}
            </button>

            {open &&
                placed &&
                createPortal(
                    <div
                        ref={panel}
                        role="menu"
                        aria-label={label}
                        style={{ position: 'fixed', top: place.top, left: place.left, width, maxHeight: place.maxHeight }}
                        className="z-50 overflow-y-auto rounded-card border border-line bg-card p-1 text-left shadow-xl"
                    >
                        {children(() => close(true))}
                    </div>,
                    document.body,
                )}
        </>
    )
}

const itemClass = (danger: boolean) =>
    `flex w-full items-center gap-2.5 rounded px-3 py-2 text-left text-sm outline-none hover:bg-line/40 focus-visible:bg-line/60 focus-visible:ring-2 focus-visible:ring-accent/40 disabled:opacity-50 ${
        danger ? 'text-danger' : ''
    }`

/** One action in a `Menu`: a button, or a link when `href` is given. */
export function MenuItem({
    onSelect,
    href,
    icon,
    danger = false,
    disabled = false,
    children,
}: {
    onSelect?: () => void
    href?: string
    icon?: ReactNode
    danger?: boolean
    disabled?: boolean
    children: ReactNode
}) {
    const body = (
        <>
            {icon && <span className={`shrink-0 ${danger ? '' : 'text-ink-soft'}`}>{icon}</span>}
            <span className="min-w-0 flex-1">{children}</span>
        </>
    )

    if (href !== undefined) {
        return (
            <Link href={href} role="menuitem" tabIndex={-1} onClick={onSelect} className={itemClass(danger)}>
                {body}
            </Link>
        )
    }

    return (
        <button
            type="button"
            role="menuitem"
            tabIndex={-1}
            disabled={disabled}
            onClick={onSelect}
            className={itemClass(danger)}
        >
            {body}
        </button>
    )
}

/** A thin rule between groups of items, e.g. before the destructive one. */
export function MenuSeparator() {
    return <div role="separator" className="my-1 border-t border-line" />
}
