import type { ReactNode } from 'react'

export type CoveKey = 'daily' | 'surprise' | 'idea' | 'persona' | 'brand' | 'shop' | 'all' | 'ask'

/**
 * The discovery surfaces, drawn.
 *
 * Same grid, same stroke weight and the same `currentColor` rule as
 * `ToolIcon` — deliberately the same set rather than a second icon style that
 * happens to sit next to the first. The reasoning for line art over emoji is
 * written out there and applies unchanged: an emoji is drawn by the reader's
 * operating system, arrives with its own colours into a palette with one
 * accent, and does not exist at all for half of what we need to depict.
 *
 * What each one shows is a decision, not decoration:
 *
 * - **Daily** is a calendar with one day marked, not a clock. The point is that
 *   there is a *new edition* and that past ones keep their date — a clock would
 *   depict "now", which is the half of it that does not bring anyone back.
 * - **Surprise** is a gift box seen corner-on with its lid lifting, not a
 *   question mark. A question mark says "we do not know"; the feature's promise
 *   is that we do know and you do not yet.
 * - **Idea** is an open book rather than a lightbulb. The Coves are long reads
 *   with a shortlist inside them; a lightbulb would promise a tip, which sets
 *   up the wrong expectation about how much there is to read.
 * - **Persona** is one person, head and shoulders. A Gift Cove is built around
 *   somebody rather than a date, and that is the single fact separating it from
 *   the book beside it. Not a gift box — that is Surprise, and it would depict
 *   the output where the distinction is the *input*.
 * - **Brand** is a tag with its hole, the mark a maker puts on a thing. Not a
 *   label on a shelf — a Brand Cove is about who made it, and a shelf label is
 *   about where it is sold, which is the shop beside it.
 * - **Shop** is a storefront: an awning over a door. Not a shopping bag or a
 *   trolley, both of which depict *buying*; this page is about the shops
 *   themselves, and every other icon in the set would then have to explain why
 *   it is not also about buying.
 * - **All** is a two-by-two grid, the only icon here that depicts an
 *   arrangement rather than a thing. It is the one entry that is not a kind of
 *   Cove but the set of them, and a fifth object in the row would read as a
 *   fifth kind.
 * - **Ask** is two speech bubbles, the second answering the first. Not a
 *   question mark — that is Surprise's rejected drawing, and it would say "we
 *   do not know" about a feature whose whole premise is that somebody else
 *   does. Two bubbles say the thing that matters: a person replies.
 *
 * All of them are `aria-hidden`: the name sits in words immediately beside the
 * icon everywhere this is used, and announcing "book" before "Inspiration Coves" adds
 * a riddle rather than information.
 */
const paths: Record<CoveKey, ReactNode> = {
    daily: (
        <>
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M3 10h18M8 3v4M16 3v4" />
            <rect x="7" y="13" width="4" height="4" rx="1" />
        </>
    ),

    surprise: (
        <>
            <path d="M3 11h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-9Z" />
            <path d="M12 11v10" />
            <path d="M2.5 7.5h19v3.5h-19z" />
            <path d="M12 7.5C10.5 4 9 3 7.5 3a2.25 2.25 0 0 0 0 4.5" />
            <path d="M12 7.5C13.5 4 15 3 16.5 3a2.25 2.25 0 0 1 0 4.5" />
        </>
    ),

    idea: (
        <>
            <path d="M12 6.5C10.5 5 8.5 4.5 6 4.5H3v13h3.5c2.2 0 4.1.5 5.5 1.7" />
            <path d="M12 6.5C13.5 5 15.5 4.5 18 4.5h3v13h-3.5c-2.2 0-4.1.5-5.5 1.7" />
            <path d="M12 6.5v12.7" />
        </>
    ),

    persona: (
        <>
            <circle cx="12" cy="8" r="3.5" />
            <path d="M4.5 20.5a7.5 7.5 0 0 1 15 0" />
        </>
    ),

    brand: (
        <>
            <path d="M12.5 3H20a1 1 0 0 1 1 1v7.5a1 1 0 0 1-.3.7l-8.5 8.5a1 1 0 0 1-1.4 0l-7.5-7.5a1 1 0 0 1 0-1.4l8.5-8.5a1 1 0 0 1 .7-.3Z" />
            <circle cx="16.5" cy="7.5" r="1.5" />
        </>
    ),

    shop: (
        <>
            {/* The awning, and the door under it. */}
            <path d="M3.5 9.5h17v10a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1v-10Z" />
            <path d="M2.5 9.5 5 4h14l2.5 5.5" />
            <path d="M9 20.5v-6h6v6" />
        </>
    ),

    all: (
        <>
            <rect x="3" y="3" width="7.5" height="7.5" rx="1.5" />
            <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5" />
            <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5" />
            <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5" />
        </>
    ),

    ask: (
        <>
            {/* The question, with its tail down-left. */}
            <path d="M2.5 4.5h11a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H6l-3.5 3v-3a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1Z" />
            {/* The answer, overlapping and tailed the other way: a reply. */}
            <path d="M17.5 8.5h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1v3l-3.5-3h-4a1 1 0 0 1-1-1" />
        </>
    ),
}

export default function CoveIcon({ name, className }: { name: CoveKey; className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-6 w-6'}
        >
            {paths[name]}
        </svg>
    )
}
