import type { ReactNode } from 'react'

export type ToolKey =
    | 'wishlist'
    | 'giftlist'
    | 'shared'
    | 'collab'
    | 'handover'
    | 'santa'
    | 'registry'
    | 'quiz'
    | 'suggestions'
    | 'whisperer'
    | 'search'
    | 'alerts'
    | 'friends'
    | 'guides'
    | 'split'
    | 'build'
    | 'board'
    | 'menu'
    | 'close'

/**
 * The Gift Cove tools, drawn — the nine of them, plus `shared`.
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
    /*
     * The two chrome glyphs. They were the characters ☰ and ✕, which render
     * at the font's metrics and sat off the baseline beside the real icons
     * in the same header. Same grid, same stroke as everything else here.
     */
    menu: <path d="M4 7h16M4 12h16M4 17h16" />,

    close: <path d="m6 6 12 12M18 6 6 18" />,

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

    /*
     * The share glyph: one node, two lines, two nodes.
     *
     * Not a tool — the tenth key here is for the *Shared lists* view, which is
     * a question about where a list came from rather than something you do to
     * one. It lives in this set anyway because the alternative was a second
     * icon style on the same menu, and the whole argument for `ToolIcon` is
     * that one grid and one stroke weight is what makes a row of these read as
     * a set instead of a collection.
     *
     * Deliberately not two people: `collab` is two people and means co-givers
     * on one list, which is the row directly below this one.
     */
    shared: (
        <>
            <circle cx="6" cy="12" r="2.5" />
            <circle cx="17.5" cy="6" r="2.5" />
            <circle cx="17.5" cy="18" r="2.5" />
            <path d="m8.2 10.8 7.1-3.6M8.2 13.2l7.1 3.6" />
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

    /*
     * The four that are not list tools.
     *
     * The Gift Cove used to show the nine list tools and nothing else, which
     * described a third of the site. These are the rest of it — finding a
     * product, being told when its price moves, the people you share with, and
     * the guides — drawn in the same set so the page reads as one map rather
     * than a toolbox with a footer stapled on. The Coves themselves (daily,
     * surprise, ideas) keep their own `CoveIcon` drawings: those are the
     * vocabulary readers already met in the nav, and a second version here
     * would be two pictures for one thing.
     */

    // A magnifier. Search and the barcode scanner share one card and one glyph:
    // both answer "is this sold here, and for how much".
    search: (
        <>
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m20.5 20.5-5.2-5.2" />
        </>
    ),

    // A bell — the one tool that comes to you instead of waiting to be opened.
    alerts: (
        <>
            <path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z" />
            <path d="M10 20.5a2 2 0 0 0 4 0" />
        </>
    ),

    // One person and a plus: a connection you make, as opposed to `collab`'s
    // two people already on one list.
    friends: (
        <>
            <circle cx="9.5" cy="8" r="3.5" />
            <path d="M3 20v-1.5a4.5 4.5 0 0 1 4.5-4.5h4a4.5 4.5 0 0 1 4.5 4.5V20" />
            <path d="M19 8v6M16 11h6" />
        </>
    ),

    // An open book: the guides are the one thing here you read rather than use.
    guides: (
        <>
            <path d="M12 6.5c-1.6-1.4-4-2-7.5-2v13c3.5 0 5.9.6 7.5 2 1.6-1.4 4-2 7.5-2v-13c-3.5 0-5.9.6-7.5 2z" />
            <path d="M12 6.5v13" />
        </>
    ),

    // Two boxes, one ticked: several givers, each marking what they take.
    split: (
        <>
            <rect x="3.5" y="4.5" width="7" height="7" rx="1.5" />
            <rect x="13.5" y="12.5" width="7" height="7" rx="1.5" />
            <path d="m5.5 8 1.5 1.5L10 6.5" />
        </>
    ),

    // A list with a plus at the corner: other people adding to it.
    build: (
        <>
            <path d="M5 5.5h9M5 10h9M5 14.5h6" />
            <path d="M17 13v6M14 16h6" />
        </>
    ),

    // Two speech bubbles: a conversation about the list, not on it.
    board: (
        <>
            <path d="M4 5.5h10v7H8l-3 2.5v-2.5H4z" />
            <path d="M14 9.5h6v6h-1v2.5l-3-2.5h-2v-2" />
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
