import type { ReactNode } from 'react'

export type ToolKey =
    | 'wishlist'
    | 'list'
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
    | 'help'
    | 'signin'
    | 'signout'
    | 'admin'
    | 'info'
    | 'settings'
    | 'trash'
    | 'more'
    | 'chevron'
    | 'edit'
    | 'copy'
    | 'link'
    | 'barcode'
    | 'picture'
    | 'people'
    | 'taste'
    | 'swipe'
    | 'cake'
    | 'plus'
    | 'vote'
    | 'thumbsUp'
    | 'thumbsDown'
    | 'check'
    | 'bell'
    | 'package'
    | 'gift'
    | 'heart'
    | 'mailAccount'

/*
 * Two drawings with two names each, drawn once so the pairs cannot drift
 * apart: the alerts tool and the header's bell, the wish list and the vote's
 * heart (2026-09-27).
 */
const BELL = (
    <>
        <path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z" />
        <path d="M10 20.5a2 2 0 0 0 4 0" />
    </>
)
const HEART = <path d="M18.3 13.8c1.34-1.31 2.7-2.89 2.7-4.95A4.95 4.95 0 0 0 16.05 3.9c-1.58 0-2.7.45-4.05 1.8-1.35-1.35-2.47-1.8-4.05-1.8A4.95 4.95 0 0 0 3 8.85c0 2.07 1.35 3.65 2.7 4.95l6.3 6.3Z" />

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

    /*
     * The glyphs that stood in for icons until 2026-09-27: ✓ beside "saved"
     * and "on this list", 🔔 in the header, 📦 and 🎁 on the notifications
     * page, ♥ ♡ on a group list's vote. Each rendered in the font's or the
     * operating system's drawing, at its metrics, off the baseline of the line
     * icons beside it. Same grid and stroke as everything here.
     *
     * `heart` is the wish list's outline under a second name, for the vote,
     * where it means "this is the one we want" rather than "a wish list". The
     * filled state is `className="fill-current"`: CSS outranks the `fill`
     * attribute on the `<svg>`, so no second drawing is needed.
     */
    check: <path d="M5 12.5 10 17.5 19 7" />,
    // Start something new: "Nieuwe lijst voor …" on a person's page (2026-09-27).
    plus: <path d="M12 5v14M5 12h14" />,
    // A vote on the contribute page's board (2026-09-27): an arrow up, the
    // mark every voting board uses, so it needs no explaining. Not the heart
    // a group list's vote wears: that one says "this is the gift we want".
    vote: <path d="M12 19V6M6.5 11.5 12 6l5.5 5.5" />,

    /*
     * Thumbs on Find a gift's ideas (2026-09-27): "more like this" and "not
     * this, something else". A cuff and a hand, drawn once; the thumb down is
     * the same drawing turned half a circle, so the two can never drift into
     * different weights. Not the emoji the daily picks use: those render in
     * the reader's own colours beside a price, these take the text colour,
     * and the button around them shows the pressed state.
     */
    thumbsUp: (
        <>
            <rect x="3.5" y="10.5" width="3.5" height="9" rx="1" />
            <path d="M7 11.5 10.6 5a1.6 1.6 0 0 1 2.9 1.2l-.8 3.8H18a2 2 0 0 1 2 2.3l-1.1 5.9a2 2 0 0 1-2 1.8H7" />
        </>
    ),
    thumbsDown: (
        <g transform="rotate(180 12 12)">
            <rect x="3.5" y="10.5" width="3.5" height="9" rx="1" />
            <path d="M7 11.5 10.6 5a1.6 1.6 0 0 1 2.9 1.2l-.8 3.8H18a2 2 0 0 1 2 2.3l-1.1 5.9a2 2 0 0 1-2 1.8H7" />
        </g>
    ),

    /*
     * The header's own rows: search and help beside the two menus, and the
     * account block. Drawn here so the desktop links, the desktop account
     * menu and the phone sheet all carry the same marks as the section
     * items — a row with an icon beside a row without one reads as two
     * different kinds of thing, and they are not.
     */
    help: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7" />
            <path d="M12 17h.01" />
        </>
    ),

    signin: (
        <>
            <circle cx="12" cy="8" r="3.5" />
            <path d="M4.5 20.5a7.5 7.5 0 0 1 15 0" />
        </>
    ),

    // An envelope with a person at its corner: "your sign-in link is in the
    // mail", in the sign-in dialog's sent notice (2026-10-04).
    mailAccount: (
        <>
            <rect x="2.5" y="4.5" width="13.5" height="10" rx="1.5" />
            <path d="m3 5.5 6.25 4.5L15.5 5.5" />
            <circle cx="18.5" cy="15.5" r="2.2" />
            <path d="M14.5 21.5a4 4 0 0 1 8 0" />
        </>
    ),

    signout: (
        <>
            <path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4" />
            <path d="M14 8l4 4-4 4M18 12H9" />
        </>
    ),

    admin: (
        <>
            <path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z" />
            <path d="m9.5 12 1.8 1.8L15 10" />
        </>
    ),

    // The (i) that InfoTip draws beside a label.
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 8h.01" />
        </>
    ),

    // Three sliders with a knob each: the list's own settings, as distinct
    // from who may see it (`shared`). The knobs are filled so the rail does
    // not show through them.
    settings: (
        <>
            <path d="M4 7h16M4 12h16M4 17h16" />
            <circle cx="15.5" cy="7" r="2.2" fill="currentColor" />
            <circle cx="8.5" cy="12" r="2.2" fill="currentColor" />
            <circle cx="13.5" cy="17" r="2.2" fill="currentColor" />
        </>
    ),

    // The bin: the one destructive control in the list tools row.
    trash: (
        <>
            <path d="M4 7h16" />
            <path d="M9.5 7V5a1.5 1.5 0 0 1 1.5-1.5h2A1.5 1.5 0 0 1 14.5 5v2" />
            <path d="M7 7v11.5A1.5 1.5 0 0 0 8.5 20h7a1.5 1.5 0 0 0 1.5-1.5V7" />
            <path d="M10 11v5M14 11v5" />
        </>
    ),

    /*
     * The list page's menus (2026-09-26): three dots for "more things you can
     * do", a chevron for "this opens a choice", a pencil and two sheets for the
     * item menu. The chevron replaces a ▾ character that rendered at 10px and
     * read as a minus sign beside the bookmark.
     */
    more: (
        <>
            <circle cx="5.5" cy="12" r="1.3" fill="currentColor" />
            <circle cx="12" cy="12" r="1.3" fill="currentColor" />
            <circle cx="18.5" cy="12" r="1.3" fill="currentColor" />
        </>
    ),

    chevron: <path d="m6 9.5 6 6 6-6" />,

    edit: (
        <>
            <path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3z" />
            <path d="m14 8 3 3" />
        </>
    ),

    copy: (
        <>
            <rect x="8.5" y="8.5" width="11" height="11" rx="2" />
            <path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5" />
        </>
    ),

    /*
     * A heart — the one thing on this page that is about wanting rather than
     * organising.
     *
     * Redrawn 2026-09-26 at nine tenths of its old size, around the centre.
     * The three list kinds (this, `giftlist`, `collab`) now sit beside a
     * list's name inside sentences (`ListName`), where they are seen side by
     * side at text size, and the old heart ran from edge to edge of the grid
     * (x 2–22) while the clipboard and the two figures keep a margin of about
     * three units. Next to them it read a size larger and heavier. Same
     * stroke, same round joins; only the outline moved in.
     */
    wishlist: HEART,
    heart: HEART,

    /*
     * A list whose kind is unknown or does not apply: a Cove somebody
     * bookmarked, named in a sentence by `ListName`. Three rows with a mark
     * before each, in the same grid and stroke as the three kinds beside it.
     */
    list: (
        <>
            <path d="M9.5 6.5H20M9.5 12H20M9.5 17.5H20" />
            <path d="M4.5 6.5h.5M4.5 12h.5M4.5 17.5h.5" />
        </>
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
    alerts: BELL,
    bell: BELL,

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

    /*
     * The ways something gets onto GiftCoves, beside `search`: paste a link,
     * scan a barcode, add a picture. With `people` for sharing they make the
     * homepage's "Search anything · Add anything · Share anything" line
     * (owner-approved 2026-09-26; see docs/features/homepage.md).
     */
    link: (
        <>
            <rect x="2.5" y="8" width="11" height="8" rx="4" />
            <rect x="10.5" y="8" width="11" height="8" rx="4" />
        </>
    ),

    barcode: <path d="M4 5v14M7.5 5v14M10 5v14M13.5 5v14M16.5 5v14M19 5v14" />,

    picture: (
        <>
            <rect x="3" y="4.5" width="18" height="15" rx="2.5" />
            <path d="m3 17 5-5 4 4 3-3 6 6" />
            <circle cx="16" cy="9" r="1.6" />
        </>
    ),

    people: (
        <>
            <circle cx="8.5" cy="8" r="3" />
            <path d="M3 19a5.5 5.5 0 0 1 11 0" />
            <circle cx="16.5" cy="9" r="2.5" />
            <path d="M15.5 14a5 5 0 0 1 6 5" />
        </>
    ),

    // Two cards leaning apart: this or that, the taste discovery tool.
    taste: (
        <>
            <rect x="2.8" y="6" width="8" height="12" rx="1.5" transform="rotate(-8 6.8 12)" />
            <rect x="13.2" y="6" width="8" height="12" rx="1.5" transform="rotate(8 17.2 12)" />
            <path d="M12 3.5v2M12 18.5v2" />
        </>
    ),

    /* Swipe gifts: one card, tilted as it leaves, and the way it goes. */
    swipe: (
        <>
            <rect x="5" y="4.5" width="9" height="14" rx="1.5" transform="rotate(10 9.5 11.5)" />
            <path d="M16.5 12h5M19.5 10l2 2-2 2" />
        </>
    ),

    /*
     * A cake with one candle: a birthday, on My people and a person's page.
     * It replaced the 🎂 emoji (2026-09-27) for the reason at the top of this
     * file: the emoji is the reader's operating system's picture, in its
     * colours, beside line icons in ours.
     */
    cake: (
        <>
            <path d="M4 20h16" />
            <path d="M5.5 20v-6A1.5 1.5 0 0 1 7 12.5h10a1.5 1.5 0 0 1 1.5 1.5v6" />
            <path d="M5.5 16c1.1.9 2.2.9 3.25 0s2.15-.9 3.25 0 2.15.9 3.25 0 2.15-.9 3.25 0" />
            <path d="M12 12.5V9" />
            <path d="M12 4c.8.9 1.2 1.6 1.2 2.2a1.2 1.2 0 0 1-2.4 0c0-.6.4-1.3 1.2-2.2z" />
        </>
    ),

    // A box: something back in stock (was 📦 on the notifications page).
    package: (
        <>
            <path d="M3.5 7.5 12 3.5l8.5 4v9L12 20.5l-8.5-4z" />
            <path d="M3.5 7.5 12 11.5l8.5-4M12 11.5v9" />
        </>
    ),

    // A wrapped present: an occasion coming up (was 🎁 on the notifications page).
    gift: (
        <>
            <rect x="4" y="9" width="16" height="11" rx="1" />
            <path d="M3 9h18M12 9v11" />
            <path d="M12 9c-1.5-3.5-5.5-4-5.5-1.5S10 9 12 9zm0 0c1.5-3.5 5.5-4 5.5-1.5S14 9 12 9z" />
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
