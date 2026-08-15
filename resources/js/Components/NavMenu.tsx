import { Link } from '@inertiajs/react'
import { type ReactNode, useEffect, useId, useRef, useState } from 'react'

export type NavMenuItem = {
    href: string
    label: string
    hint?: string
    icon?: ReactNode
}

/**
 * A header section that is both a destination and a menu.
 *
 * Organise and Discover each have a landing page that explains what is behind
 * them, and three-to-nine surfaces behind that. Making the section *only* a
 * menu hides the explanation, which is the page that exists because these tools
 * are not self-evident. Making it *only* a link hides the surfaces, and puts the
 * Daily Cove — the one thing that brings somebody back tomorrow — two clicks
 * deep.
 *
 * So the label is a `Link` and the chevron is a separate `button`. Pressing the
 * word goes to the hub; pressing the chevron opens the list. A single control
 * cannot do both without guessing, and the usual guess — link on click, menu on
 * hover — has no keyboard or touch equivalent at all.
 *
 * The button carries its own accessible name (`nav.submenu`, interpolated with
 * the section) rather than inheriting the label, because "Discover, link" and
 * "Discover, button" one after another describes two controls by one word and
 * says nothing about what the second does.
 */
export default function NavMenu({
    href,
    label,
    items,
    current,
    isCurrent,
    submenuLabel,
}: {
    href: string
    label: string
    items: NavMenuItem[]
    current: boolean
    isCurrent: (href: string) => boolean
    submenuLabel: string
}) {
    const [open, setOpen] = useState(false)
    const id = useId()
    const wrapper = useRef<HTMLDivElement>(null)
    const toggle = useRef<HTMLButtonElement>(null)

    useEffect(() => {
        if (! open) {
            return
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') {
                return
            }

            // Focus goes back to the control that opened it. Closing a menu and
            // dropping focus to the top of the document is how a keyboard user
            // loses their place in the header entirely.
            setOpen(false)
            toggle.current?.focus()
        }

        const onPointerDown = (e: MouseEvent) => {
            if (! wrapper.current?.contains(e.target as Node)) {
                setOpen(false)
            }
        }

        document.addEventListener('keydown', onKey)
        document.addEventListener('mousedown', onPointerDown)

        return () => {
            document.removeEventListener('keydown', onKey)
            document.removeEventListener('mousedown', onPointerDown)
        }
    }, [open])

    return (
        <div ref={wrapper} className="relative flex items-center gap-1">
            <Link
                href={href}
                aria-current={current ? 'page' : undefined}
                className={
                    current
                        ? 'font-medium text-ink underline decoration-accent decoration-2 underline-offset-8'
                        : 'hover:text-ink'
                }
            >
                {label}
            </Link>

            <button
                ref={toggle}
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen(! open)}
                className="rounded p-0.5 text-ink-soft hover:text-ink"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                    focusable="false"
                    className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
                <span className="sr-only">{submenuLabel}</span>
            </button>

            {open && (
                <ul
                    id={id}
                    className="absolute top-full left-0 z-40 mt-2 w-72 rounded-lg border border-line bg-white p-2 shadow-lg"
                >
                    {items.map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                aria-current={isCurrent(item.href) ? 'page' : undefined}
                                onClick={() => setOpen(false)}
                                className="flex gap-3 rounded-md px-3 py-2 hover:bg-sand"
                            >
                                {item.icon ? <span className="mt-0.5 shrink-0 text-accent">{item.icon}</span> : null}
                                <span>
                                    <span className="block font-medium text-ink">{item.label}</span>
                                    {item.hint ? (
                                        <span className="block text-xs text-ink-soft">{item.hint}</span>
                                    ) : null}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}
