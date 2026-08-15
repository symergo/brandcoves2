import type { ReactNode } from 'react'

export type ListSceneKey = 'mine' | 'shared' | 'group' | 'santa'

/**
 * The four Organise surfaces at card size, matched to `CoveIllustration`.
 *
 * Same viewBox, same stroke weight, same `currentColor`-plus-one-accent-wash
 * rule — the two sections of the homepage sit directly above and below each
 * other, and two illustration styles on one page reads as two websites.
 *
 * Each shows the *relationship* rather than the object, because that is what
 * distinguishes these four. All four are, at bottom, a list:
 *
 * - **Mine** — one card with a heart. The only one of the four that is about
 *   wanting rather than coordinating.
 * - **Shared** — two cards, one passing behind the other, with the arrow
 *   pointing *at* the viewer. It is a list arriving from somebody else, and the
 *   direction is the whole distinction from a list you shared outward.
 * - **Group** — three marks converging on one box. Several people, one present;
 *   the convergence is what makes it a group list rather than a shared one.
 * - **Santa** — crossing arrows, matching `ToolIcon`'s santa glyph exactly.
 *   What the feature *is* is the draw, not the season, which is also why the
 *   name no longer mentions December.
 *
 * `aria-hidden` throughout: every one of these sits beside its own name.
 */
const scenes: Record<ListSceneKey, ReactNode> = {
    mine: (
        <>
            <rect x="46" y="16" width="68" height="86" rx="6" className="fill-accent/10" />
            <path d="M60 40h40M60 54h30M60 68h34" />
            <path d="M96 84c3-3 6-6.5 6-11a5 5 0 0 0-9-3 5 5 0 0 0-9 3c0 4.5 3 8 6 11l3 3z" />
        </>
    ),

    shared: (
        <>
            <rect x="22" y="26" width="56" height="72" rx="6" className="fill-accent/10" />
            <rect x="40" y="14" width="56" height="72" rx="6" fill="none" />
            <path d="M54 34h28M54 46h20M54 58h24" />
            <path d="M112 74H86M96 62l-12 12 12 12" />
        </>
    ),

    group: (
        <>
            <path d="M56 64h48v34a4 4 0 0 1-4 4H60a4 4 0 0 1-4-4z" className="fill-accent/10" />
            <path d="M52 52h56v12H52z" fill="none" />
            <path d="M80 52v50" />
            <circle cx="34" cy="26" r="8" />
            <circle cx="80" cy="20" r="8" />
            <circle cx="126" cy="26" r="8" />
            <path d="M40 34l14 12M80 30v14M120 34l-14 12" />
        </>
    ),

    santa: (
        <>
            <circle cx="80" cy="58" r="40" className="fill-accent/10" />
            <path d="M44 44h48l-12-12M116 74H68l12 12" />
            <circle cx="34" cy="44" r="5" />
            <circle cx="126" cy="74" r="5" />
        </>
    ),
}

export default function ListIllustration({ name, className }: { name: ListSceneKey; className?: string }) {
    return (
        <svg
            viewBox="0 0 160 116"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-28 w-full'}
        >
            {scenes[name]}
        </svg>
    )
}
