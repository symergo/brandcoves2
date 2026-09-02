import type { ReactNode } from 'react'

export type ShareIconKey = 'share' | 'copy' | 'whatsapp' | 'facebook' | 'telegram' | 'x' | 'email'

/**
 * The share destinations, drawn.
 *
 * A share menu is the one place icons genuinely earn their keep: the reader is
 * not reading the list, they are looking for the one app they already had in
 * mind, and a mark is found faster than a word. Six text rows all the same
 * length is a list you have to read from the top every time.
 *
 * Line art in the site's own hand rather than the platforms' brand assets, for
 * the reasons `ToolIcon` sets out and one more. One stroke weight, one 24px
 * grid, `currentColor` throughout — which is what lets the same glyph sit in a
 * menu row, in a hover state and in a disabled one without a second copy of it
 * existing. Dropping in six official logos would bring six palettes into a
 * design with one accent, and would make the menu look like an advert for the
 * platforms rather than a control belonging to this page.
 *
 * Every icon is `aria-hidden`: the destination's name is in words immediately
 * beside it, and announcing "envelope" before "Email" adds a puzzle rather than
 * information.
 */
const paths: Record<ShareIconKey, ReactNode> = {
    // The standard share glyph: three nodes and the lines between them.
    share: (
        <>
            <circle cx="18" cy="5" r="2.5" />
            <circle cx="6" cy="12" r="2.5" />
            <circle cx="18" cy="19" r="2.5" />
            <path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4" />
        </>
    ),

    // Two sheets, one behind the other.
    copy: (
        <>
            <rect x="9" y="9" width="11" height="11" rx="2" />
            <path d="M5 15H4a1 1 0 0 1-1-1V5a2 2 0 0 1 2-2h9a1 1 0 0 1 1 1v1" />
        </>
    ),

    // A speech bubble with a handset in it — the shape people recognise before
    // they read the word beside it.
    whatsapp: (
        <>
            <path d="M20.5 11.7a8.4 8.4 0 0 1-12.4 7.4L3.5 20.5l1.4-4.6a8.4 8.4 0 1 1 15.6-4.2Z" />
            <path d="M9.3 9c.2-.5.5-.5.8-.5h.5c.2 0 .4 0 .6.5l.7 1.6c.1.3 0 .5-.1.7l-.4.4c-.2.2-.3.4-.1.7a6 6 0 0 0 2.7 2.3c.3.1.5 0 .7-.2l.5-.6c.2-.2.4-.2.6-.1l1.6.8c.3.1.4.3.4.5a1.8 1.8 0 0 1-1.9 1.6c-2 0-4-1.3-5.4-2.9C9.4 13.3 8.7 12 8.7 11c0-.9.3-1.5.6-2Z" />
        </>
    ),

    // The f, in the rounded square it always sits in.
    facebook: (
        <>
            <rect x="3.5" y="3.5" width="17" height="17" rx="4" />
            <path d="M14.8 8h-1.3c-.9 0-1.5.6-1.5 1.5V20" />
            <path d="M10 12.6h4" />
        </>
    ),

    // A paper plane.
    telegram: (
        <>
            <path d="M21 4 3 11.2l5.6 2.1L21 4Z" />
            <path d="M21 4 8.6 13.3l.5 5.4 2.9-3.6L21 4Z" />
        </>
    ),

    // Two crossed strokes. Deliberately not a close glyph: it is drawn to the
    // edges of the grid where a × is drawn small and centred, and it never
    // appears without the word "X" beside it.
    x: (
        <>
            <path d="M4 4 20 20" />
            <path d="M20 4 4 20" />
        </>
    ),

    // An envelope.
    email: (
        <>
            <rect x="2.5" y="5" width="19" height="14" rx="2" />
            <path d="m3 7 8.2 5.6a1.5 1.5 0 0 0 1.6 0L21 7" />
        </>
    ),
}

export default function ShareIcon({
    name,
    className = 'h-4 w-4',
}: {
    name: ShareIconKey
    className?: string
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
        >
            {paths[name]}
        </svg>
    )
}
