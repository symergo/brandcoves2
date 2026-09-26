/**
 * The homepage hero drawing since 2026-09-26: many shores, one cove, shared.
 *
 * Three places things come from (a web shop, something handmade, the shop
 * round the corner) drift into the mouth of one cove, become a gift, and go
 * out the back to two people. It is the strategy in one line of drawing:
 * from anywhere, into a Cove, to the people who matter. Chosen by the owner
 * from five sketches ("A2, round 3"); see docs/features/homepage.md.
 *
 * Same visual language as `SceneIllustration` (and the Home, Cove and List
 * illustrations it replaced, removed 2026-09-26 once nothing drew them): one
 * stroke weight, `currentColor` for every line, the
 * accent only ever as a translucent wash, and the logo's orange buoy in the
 * cove's mouth. The cove's arc is the logo's arc (the same opening angle,
 * 15 → 50 units of radius), turned to face the things arriving.
 *
 * The sources are generic on purpose, never other companies' names or marks
 * (owner's decision, 2026-09-26).
 *
 * `aria-hidden`, like every other drawing on the site: the headline beside it
 * says what the page is.
 */

/* The logo's buoy. See docs/features/brand-mark.md. */
const BUOY = '#F2A93B'

export default function SharedCoveIllustration({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 290 176"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-auto w-full'}
        >
            {/* A web shop. */}
            <rect x="10" y="18" width="56" height="40" rx="5" />
            <path d="M10 28H66" />
            <circle cx="16" cy="23" r="1.2" fill="currentColor" stroke="none" />
            <circle cx="21" cy="23" r="1.2" fill="currentColor" stroke="none" />
            <circle cx="26" cy="23" r="1.2" fill="currentColor" stroke="none" />
            <rect x="30" y="37" width="16" height="14" rx="2" className="fill-accent/20" />
            <path d="M34 37v-2a4 4 0 0 1 8 0v2" />

            {/* Something handmade. */}
            <path d="M18 78h24v14a6 6 0 0 1-6 6h-12a6 6 0 0 1-6-6z" className="fill-accent/20" />
            <path d="M42 82h3a4 4 0 0 1 0 8h-3" />
            <path d="M25 72c-2-3 2-4 0-7" />
            <path d="M33 72c-2-3 2-4 0-7" />

            {/* The shop round the corner. */}
            <rect x="12" y="120" width="52" height="10" rx="2" className="fill-accent/20" />
            <path d="M25 120v10M38 120v10M51 120v10" />
            <rect x="16" y="130" width="44" height="30" rx="1" />
            <rect x="32" y="141" width="12" height="19" />
            <rect x="20" y="136" width="8" height="8" />

            {/* Drifting in. */}
            <g className="stroke-ink-soft" strokeDasharray="2 5">
                <path d="M70 38C100 38 108 78 128 85" />
                <path d="M52 86C80 86 100 88 128 88" />
                <path d="M68 140C100 140 108 98 128 91" />
            </g>

            {/*
              The cove. Centre (180,88), radius 50; the mouth opens to the left
              at the logo's angle, so its ends sit at ±35° from the horizontal.
            */}
            <circle cx="180" cy="88" r="38" className="fill-accent/10" stroke="none" />
            <path d="M139.04 59.32A50 50 0 1 1 139.04 116.68" />
            <circle cx="135" cy="88" r="5" fill={BUOY} stroke="none" />

            {/* What it becomes. */}
            <rect x="168" y="92" width="30" height="24" rx="3" />
            <rect x="165" y="83" width="36" height="10" rx="2" className="fill-accent/20" />
            <path d="M183 93v23" />
            <path d="M182 83c-4-7-8-9-12-7a4.5 4.5 0 0 0 2 8" />
            <path d="M184 83c4-8 8-10 12-8a4.5 4.5 0 0 1-2 8" />

            {/* And who it goes to. */}
            <g className="stroke-ink-soft" strokeDasharray="2 5">
                <path d="M232 76C246 66 252 58 260 54" />
                <path d="M232 100C246 110 252 118 260 122" />
            </g>
            <circle cx="274" cy="44" r="7" />
            <path d="M261 68a13 13 0 0 1 26 0" />
            <circle cx="274" cy="110" r="7" />
            <path d="M261 134a13 13 0 0 1 26 0" />
        </svg>
    )
}
