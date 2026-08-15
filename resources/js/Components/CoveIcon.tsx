import type { ReactNode } from 'react'

export type CoveKey = 'daily' | 'surprise' | 'idea'

/**
 * The three discovery Coves, drawn.
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
 *
 * All three are `aria-hidden`: the Cove's name sits in words immediately beside
 * the icon everywhere this is used, and announcing "book" before "Idea Cove"
 * adds a riddle rather than information.
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
