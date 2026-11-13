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
 * On a phone the actions, icons only, sit on the line of the name, at the
 * right (owner, 2026-11-13). Only the name keeps clear of them, with right
 * padding sized to how many there are (`slots`: 36px icons with a 6px gap);
 * the line under it, who the list is for and its badges, runs under the
 * icons to the edge of the row. Before, the whole text column kept clear, so
 * the badges wrapped in a narrow column while the space under the icons
 * stayed empty. From `sm` up the actions sit at the end of the row, in the
 * flow, with their words.
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
    /** How many row actions there are, for the room the name keeps for them on a phone. */
    slots?: 0 | 1 | 2 | 3
}) {
    // Static class names, so Tailwind sees them. The first child is the name.
    // On a phone the last line also keeps its distance from the icons above it.
    const air = 'max-sm:[&>*:last-child:not(:first-child)]:mt-3.5'
    const room = [
        '',
        `[&>*:first-child]:pr-10 sm:[&>*:first-child]:pr-0 ${air}`,
        `[&>*:first-child]:pr-20 sm:[&>*:first-child]:pr-0 ${air}`,
        `[&>*:first-child]:pr-31 sm:[&>*:first-child]:pr-0 ${air}`,
    ][actions ? slots : 0]
    const body = (
        <>
            {thumb}
            <span className={`min-w-0 flex-1 ${room}`}>{children}</span>
        </>
    )
    const linkClass = 'group flex min-w-0 flex-1 items-center gap-3'

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
            {actions && <div className="absolute top-2.5 right-4 flex items-center gap-1.5 sm:static sm:gap-2">{actions}</div>}
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

/** A list's picture is 48px. 64px, the size of a product on a list, was tried on 2026-11-13 and taken back the same day (owner). */
const pictureSize = 'h-12 w-12'

/**
 * The box a product picture sits in: white (owner, 2026-09-27: "background of
 * the image boxes can be white"). Product photos are nearly all shot on
 * white, so a cream box showed as a cream frame around a white square; on
 * white they sit flush and the thin border gives the box its edge.
 */
export const pictureBox = `${pictureSize} shrink-0 rounded-lg border border-line bg-white object-contain p-1`

/**
 * A list's picture, 48px: up to four
 * products as a 2×2 collage (what is in it at a glance, while every name starts at the same place), one product on its
 * own, or the kind's mark on a list with no pictures yet.
 */
export function ListThumb({
    covers = [],
    kind = null,
    count,
    countLabel,
}: {
    covers?: string[]
    kind?: ListKind | string | null
    /** How many things are on the list, shown in a circle on the picture's corner. */
    count?: number
    /** The count in words ("3 items"), for a screen reader and the tooltip. */
    countLabel?: string
}) {
    const picture = <ListPicture covers={covers} kind={kind} />

    if (count === undefined) {
        return picture
    }

    /*
     * The count sits on the picture (owner, 2026-11-13), not in the line under
     * the name: that line is then free for who the list is for and its
     * badges. Filled when there is something on the list, quiet when empty.
     */
    return (
        <span className="relative shrink-0">
            {picture}
            <span
                title={countLabel}
                className={`absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-2xs leading-none font-semibold ring-2 ring-card ${
                    count > 0 ? 'bg-accent text-white' : 'bg-line text-ink-soft'
                }`}
            >
                <span aria-hidden>{count > 99 ? '99+' : count}</span>
                {countLabel && <span className="sr-only">{countLabel}</span>}
            </span>
        </span>
    )
}

function ListPicture({ covers, kind }: { covers: string[]; kind: ListKind | string | null }) {
    if (covers.length > 1) {
        return (
            // White between the cells too: the hairline gap drew a grey cross
            // through four white product shots.
            <span className={`grid ${pictureSize} shrink-0 grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-white`}>
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
        <span aria-hidden className={`flex ${pictureSize} shrink-0 items-center justify-center rounded-lg text-accent`}>
            <ToolIcon name={kindIcons[(kind as ListKind) ?? 'mine'] ?? 'list'} className="h-7 w-7" duo />
        </span>
    )
}
