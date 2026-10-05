import { Link } from '@inertiajs/react'
import type { ReactNode } from 'react'
import { kindIcons, type ListKind } from './ListKindBadge'
import ToolIcon from './ToolIcon'

/**
 * One row of a list of lists: a picture, the name and what it is, and on the
 * right what you do to it.
 *
 * ## Why a component (consistency review, round 2, 2026-09-27)
 *
 * Mijn Coves drew a list as a row with a picture, a count, pills and outlined
 * actions; a person's page drew the same list as a name, a kind badge and two
 * borderless icons; the saved Coves under Mijn Coves were big picture cards,
 * three to a row. Three drawings of "a list you can open" on two pages the
 * owner moves between. This is the one row, and each caller fills its slots.
 *
 * ## The phone layout
 *
 * On a phone the actions sit in the row's top-right corner, icons only, and
 * the text column keeps clear of them with right padding sized to how many
 * there are (`actions`: 36px icons with a 6px gap). From `sm` up they sit at
 * the end of the row, in the flow, with their words.
 */
export default function ListRow({
    href,
    external = false,
    thumb,
    children,
    actions,
    slots = 0,
}: {
    href: string
    /** A real anchor: somebody else's list opens by share link, a page people keep in a tab. */
    external?: boolean
    thumb: ReactNode
    /** The text column: the name first, then a meta line and badges. */
    children: ReactNode
    actions?: ReactNode
    /** How many row actions there are, for the room kept for them on a phone. */
    slots?: 0 | 1 | 2 | 3
}) {
    // Static class names, so Tailwind sees them.
    const room = ['', 'pr-12 sm:pr-0', 'pr-24 sm:pr-0', 'pr-32 sm:pr-0'][actions ? slots : 0]
    const body = (
        <>
            {thumb}
            <span className="min-w-0">{children}</span>
        </>
    )
    const linkClass = `group flex min-w-0 flex-1 items-center gap-3 ${room}`

    return (
        <div className="relative flex gap-3 p-4 sm:items-center">
            {external ? (
                <a href={href} className={linkClass}>
                    {body}
                </a>
            ) : (
                <Link href={href} className={linkClass}>
                    {body}
                </Link>
            )}
            {actions && <div className="absolute top-3 right-3 flex items-center gap-1.5 sm:static sm:gap-2">{actions}</div>}
        </div>
    )
}

/** The name in a row: underlined on hover, as the whole left part is the link. */
export function ListRowTitle({ children }: { children: ReactNode }) {
    return <span className="block font-medium group-hover:underline">{children}</span>
}

/** The line under the name: how many, for whom, when. */
export function ListRowMeta({ children }: { children: ReactNode }) {
    return <span className="mt-0.5 block text-sm text-ink-soft">{children}</span>
}

/** The badges under the meta line. */
export function ListRowBadges({ children }: { children: ReactNode }) {
    return <span className="mt-1.5 flex flex-wrap items-center gap-1.5 text-2xs">{children}</span>
}

const hide = (e: { currentTarget: HTMLImageElement }) => {
    e.currentTarget.style.visibility = 'hidden'
}

/**
 * The box a product picture sits in: white (owner, 2026-09-27: "background of
 * the image boxes can be white"). Product photos are nearly all shot on
 * white, so a cream box showed as a cream frame around a white square; on
 * white they sit flush and the thin border gives the box its edge.
 */
export const pictureBox = 'h-12 w-12 shrink-0 rounded-lg border border-line bg-white object-contain p-1'

/**
 * A list's picture, 48px: up to four products as a 2×2 collage (what is in it
 * at a glance, while every name starts at the same place), one product on its
 * own, or the kind's mark on a list with no pictures yet.
 */
export function ListThumb({ covers = [], kind = null }: { covers?: string[]; kind?: ListKind | string | null }) {
    if (covers.length > 1) {
        return (
            // White between the cells too: the hairline gap drew a grey cross
            // through four white product shots.
            <span className="grid h-12 w-12 shrink-0 grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-white">
                {covers.slice(0, 4).map((src, i) => (
                    <img key={i} src={src} alt="" loading="lazy" className="h-full w-full bg-white object-contain" onError={hide} />
                ))}
            </span>
        )
    }

    if (covers.length === 1) {
        return (
            <img
                src={covers[0]}
                alt=""
                loading="lazy"
                className={pictureBox}
                onError={hide}
            />
        )
    }

    return (
        <span aria-hidden className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-accent">
            <ToolIcon name={kindIcons[(kind as ListKind) ?? 'mine'] ?? 'list'} className="h-7 w-7" duo />
        </span>
    )
}
