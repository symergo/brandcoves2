import type { ReactNode } from 'react'

export type ToolKey =
    | 'wishlist'
    | 'giftlist'
    | 'collab'
    | 'handover'
    | 'santa'
    | 'registry'
    | 'quiz'
    | 'suggestions'
    | 'whisperer'

/**
 * The nine Gift Cove tools, drawn.
 *
 * Line art rather than emoji, for three reasons that all bite at once. An emoji
 * is rendered by the reader's operating system, so 🎁 is a different picture on
 * Windows, Android and iOS and none of them is ours. It arrives with its own
 * colours into a palette that has exactly one accent. And half of these tools
 * have no emoji at all — there is no glyph for "a list you hand over to
 * somebody" or "invite a co-giver", so the set would have ended up part
 * pictogram and part shrug.
 *
 * One stroke weight, one 24px grid, `currentColor` throughout. Inheriting the
 * text colour is what lets the same icon sit on a tinted card, in a hover state
 * and inside the manual without a second copy of it existing.
 *
 * Every icon is `aria-hidden`: the tool's name is in words immediately beside
 * it in both places these are used, and announcing "clipboard" before "a list
 * for someone else" adds a puzzle rather than information.
 */
const paths: Record<ToolKey, ReactNode> = {
    // A heart — the one thing on this page that is about wanting rather than
    // organising.
    wishlist: (
        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
    ),

    // A clipboard. A list *for* somebody is research you keep about them, not a
    // present — a gift box here would promise the wrong thing.
    giftlist: (
        <>
            <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2" />
            <rect x="9" y="2" width="6" height="4" rx="1" />
            <path d="M9 11h6M9 15h4" />
        </>
    ),

    // Two people, the second half-behind the first: co-givers, one list.
    collab: (
        <>
            <circle cx="9.5" cy="7.5" r="3.2" />
            <path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20" />
            <path d="M16 3.6a4 4 0 0 1 0 7.8" />
            <path d="M21 20v-1.5a4 4 0 0 0-3-3.87" />
        </>
    ),

    // Out of the tray, upward. Handing over is the one action here that ends
    // with you no longer holding the thing.
    handover: (
        <>
            <path d="M4 14v4.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V14" />
            <path d="M12 15V4" />
            <path d="m8 8 4-4 4 4" />
        </>
    ),

    // Crossing arrows. What a Secret Santa *is* is the draw; a hat would be a
    // picture of the season instead of the mechanism.
    santa: (
        <>
            <path d="M16 3h5v5" />
            <path d="M4 20 21 3" />
            <path d="M21 16v5h-5" />
            <path d="m15 15 6 6" />
            <path d="m4 4 5 5" />
        </>
    ),

    // A calendar: an occasion and a date are exactly what turn a wishlist into
    // a registry.
    registry: (
        <>
            <rect x="3.5" y="5" width="17" height="15" rx="2" />
            <path d="M8 3v4M16 3v4M3.5 10h17" />
        </>
    ),

    // Four tiles, one ticked — the shape of a single round.
    quiz: (
        <>
            <rect x="3.5" y="3.5" width="7.5" height="7.5" rx="1.5" />
            <rect x="13" y="3.5" width="7.5" height="7.5" rx="1.5" />
            <rect x="3.5" y="13" width="7.5" height="7.5" rx="1.5" />
            <rect x="13" y="13" width="7.5" height="7.5" rx="1.5" />
            <path d="m15 16.7 1.6 1.6 3.2-3.5" />
        </>
    ),

    // A speech bubble with a plus in it. A suggestion is somebody talking to
    // you, not an item appearing.
    suggestions: (
        <>
            <path d="M20 14.5a2.5 2.5 0 0 1-2.5 2.5H9l-4.5 3.5V6.5A2.5 2.5 0 0 1 7 4h10.5A2.5 2.5 0 0 1 20 6.5z" />
            <path d="M12.2 7.5v6M9.2 10.5h6" />
        </>
    ),

    // Sparkles: the only tool on the page that thinks of the answer for you.
    whisperer: (
        <>
            <path d="M11 4.5C11 8 13.1 10 16.5 10.6 13.1 11.2 11 13.3 11 16.7 11 13.3 8.9 11.2 5.5 10.6 8.9 10 11 8 11 4.5z" />
            <path d="M18.5 15.2C18.5 16.9 19.5 17.9 21.2 18.2 19.5 18.5 18.5 19.5 18.5 21.2 18.5 19.5 17.5 18.5 15.8 18.2 17.5 17.9 18.5 16.9 18.5 15.2z" />
        </>
    ),
}

export default function ToolIcon({ name, className = 'h-5 w-5' }: { name: ToolKey; className?: string }) {
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
