import type { ReactNode } from 'react'
import type { CoveKey } from './CoveIcon'

/**
 * The three discovery Coves at card size.
 *
 * Not the `CoveIcon` scaled up. A 24px glyph enlarged to 160px is a thin
 * outline in a lot of empty space — the stroke stays hairline while everything
 * around it grows, which reads as a rendering fault rather than a drawing. These
 * are separate compositions with a foreground shape, a supporting one and a
 * tinted wash, sized for the space they actually occupy.
 *
 * They stay in the same visual language: one stroke weight, `currentColor` for
 * every line, and the single accent used only as a translucent fill. That is
 * what lets a card change its text colour on hover and take the drawing with
 * it, and it is why these survive a palette change without being redrawn.
 *
 * Each shows the *promise* of its Cove rather than its mechanism:
 *
 * - **Daily** is a stack, not a single page. The front card carries today's
 *   grid and the ones behind it are the archive — the reason to come back is
 *   that this happened yesterday too, and a lone calendar page cannot say that.
 * - **Surprise** is a box already open with something leaving it. A closed box
 *   depicts a gift; an open one depicts the moment the feature exists for.
 * - **Idea** is an open book against a shelf. The shelf is what makes it an
 *   archive rather than an article, which is the half of the Coves that earns
 *   traffic over years.
 *
 * Decorative throughout: `aria-hidden`, because the Cove's name and its
 * sentence sit directly beside every one of these.
 */
const scenes: Record<CoveKey, ReactNode> = {
    daily: (
        <>
            {/* The archive, behind. */}
            <rect x="26" y="30" width="86" height="66" rx="6" className="fill-accent/10" />
            <rect x="34" y="24" width="86" height="66" rx="6" className="fill-accent/10" />
            {/* Today, in front. */}
            <rect x="42" y="18" width="86" height="66" rx="6" fill="none" />
            <path d="M42 36h86" />
            <path d="M62 12v12M108 12v12" />
            <rect x="54" y="48" width="16" height="14" rx="3" className="fill-accent/25" />
            <path d="M80 52h34M80 62h22" />
        </>
    ),

    surprise: (
        <>
            {/* The lid, lifted and tilted. */}
            <path d="M34 44h94v14H34z" className="fill-accent/15" />
            <path d="M30 40l100-6 4 12-100 6z" fill="none" />
            {/* The box. */}
            <path d="M40 58h84v40a4 4 0 0 1-4 4H44a4 4 0 0 1-4-4z" fill="none" />
            <path d="M82 58v44" />
            {/* Ribbon loops, and something leaving. */}
            <path d="M82 34c-6-12-12-16-19-14a8 8 0 0 0 2 15" fill="none" />
            <path d="M84 34c7-13 13-17 20-15a8 8 0 0 1-2 15" fill="none" />
            <path d="M104 26l6-8M116 34l10-5M112 16l2-9" />
        </>
    ),

    idea: (
        <>
            {/* The shelf behind: the archive this belongs to. */}
            <rect x="28" y="14" width="12" height="34" rx="2" className="fill-accent/10" />
            <rect x="44" y="18" width="10" height="30" rx="2" className="fill-accent/10" />
            <rect x="58" y="12" width="12" height="36" rx="2" className="fill-accent/10" />
            <path d="M24 50h54" />
            {/* The open book. */}
            <path d="M80 66c-8-8-19-11-33-11H36v42h11c12 0 22 3 29 9" fill="none" />
            <path d="M82 66c8-8 19-11 33-11h11v42h-11c-12 0-22 3-29 9" fill="none" />
            <path d="M81 66v40" />
            <path d="M48 70h20M48 80h16M96 70h20M96 80h16" />
        </>
    ),
}

export default function CoveIllustration({ name, className }: { name: CoveKey; className?: string }) {
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
