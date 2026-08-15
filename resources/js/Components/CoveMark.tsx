import type { ReactNode } from 'react'

export type CoveMarkKey = 'mine' | 'shared' | 'group' | 'santa' | 'daily' | 'surprise' | 'idea'

/*
 * The brand palette, literally. Not tokens.
 *
 * These marks are the logo with one element swapped, so they have to be the
 * logo's colours — a themed version would be a different mark that happens to
 * look similar. `public/icons/giftcoves.svg` is the source of truth for all
 * three; see docs/features/brand-mark.md.
 */
const TILE = '#12232B'
const COVE = '#EFE6D6'
const BUOY = '#F2A93B'

/**
 * One section, drawn as a cove.
 *
 * The logo is a headland wrapping a sheltered bay with a buoy in its mouth.
 * That is a system rather than a picture, and this is the system applied: **the
 * tile and the arc never change, and the amber mark in the bay says which
 * section you are looking at.** Every surface on the site is a cove sheltering
 * something different.
 *
 * Why this replaced the line illustrations that were here first: those were
 * competent drawings in a visual language the brand does not otherwise speak.
 * Seven of them on one page made the homepage look like a stock icon set with a
 * logo bolted on top. These are recognisably the same object as the mark in the
 * header and the favicon, which is the entire point of having a mark.
 *
 * The amber element is always the *variable* and always the only amber on the
 * tile, so the eye goes straight to the one part that differs. Sizes are tuned
 * for 56–64px, which is where these are used; below about 40px the multi-dot
 * marks (group, shared) start to merge and `CoveIcon` is the better choice.
 *
 * `aria-hidden`, like every other mark on the site — the section name is always
 * in words beside it.
 */
const marks: Record<CoveMarkKey, ReactNode> = {
    // A heart. The only surface here about wanting rather than coordinating.
    mine: <path d="M32 40c-4-3.6-8-7-8-11.4a4.6 4.6 0 0 1 8-2.8 4.6 4.6 0 0 1 8 2.8C40 33 36 36.4 32 40Z" fill={BUOY} />,

    // Two buoys, one behind the other: somebody else's list, arriving.
    shared: (
        <>
            <circle cx="28" cy="28" r="6" fill={BUOY} opacity="0.55" />
            <circle cx="36" cy="36" r="6" fill={BUOY} />
        </>
    ),

    // Three, gathered around one point. Several people, one present.
    group: (
        <>
            <circle cx="32" cy="24" r="5" fill={BUOY} />
            <circle cx="25" cy="37" r="5" fill={BUOY} />
            <circle cx="39" cy="37" r="5" fill={BUOY} />
        </>
    ),

    // Crossing arrows — what the draw *is*, matching ToolIcon's santa glyph.
    // Not a hat: a hat would draw the season, and the season is exactly what
    // the rename to Secret Friend removed.
    santa: (
        <>
            <path d="M24 26h14l-4-4M40 38H26l4 4" stroke={BUOY} strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
        </>
    ),

    // The canonical buoy, alone. The Daily Cove is the site's heartbeat, so it
    // gets the mark the logo itself carries, unchanged.
    daily: <circle cx="32" cy="32" r="7" fill={BUOY} />,

    // A four-point sparkle: something you did not expect was there.
    surprise: (
        <path
            d="M32 22c1.2 6 2.8 7.6 8.8 8.8-6 1.2-7.6 2.8-8.8 8.8-1.2-6-2.8-7.6-8.8-8.8 6-1.2 7.6-2.8 8.8-8.8Z"
            fill={BUOY}
        />
    ),

    // Two leaves meeting at a spine — an open book, for the long reads.
    idea: (
        <>
            <path d="M32 25c-2.2-1.8-5-2.6-8.4-2.6v14c3.4 0 6.2.8 8.4 2.6" fill="none" stroke={BUOY} strokeWidth="3" strokeLinejoin="round" />
            <path d="M32 25c2.2-1.8 5-2.6 8.4-2.6v14c-3.4 0-6.2.8-8.4 2.6" fill="none" stroke={BUOY} strokeWidth="3" strokeLinejoin="round" />
        </>
    ),
}

export default function CoveMark({ name, className }: { name: CoveMarkKey; className?: string }) {
    return (
        <svg
            viewBox="0 0 64 64"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-14 w-14'}
        >
            {/* Whole tile, exactly as the logo draws it. */}
            <rect x="0" y="0" width="64" height="64" rx="14" fill={TILE} />

            {/*
              The cove: a headland wrapping a sheltered bay. Identical geometry
              and stroke to public/icons/giftcoves.svg — if that file changes,
              this arc changes with it or the family stops being a family.
            */}
            <path
                d="M40.6,19.71 A15,15 0 1 0 40.6,44.29"
                fill="none"
                stroke={COVE}
                strokeWidth="8.5"
                strokeLinecap="round"
            />

            {marks[name]}
        </svg>
    )
}
