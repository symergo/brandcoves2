/**
 * The homepage hero drawing: the mark, at scene size.
 *
 * Same visual language as `CoveIllustration` and `ListIllustration` — one
 * stroke weight, `currentColor` for every line, the accent only ever as a
 * translucent wash — because this sits two bands above both of them and a
 * second illustration style on one page reads as two websites.
 *
 * What is different is that this one contains the actual logo. The card
 * drawings are scenes *about* a surface; this one is the brand's own shape
 * drawn large, so the headland arc here is the logo's arc — same 15:40.6
 * proportions, scaled from r=15 to r=60 — and the real mark, in its real
 * colours, is moored in the mouth of it where the buoy belongs.
 *
 * **The tile keeps the logo's palette and does not take `currentColor`.** A
 * recoloured logo is not the logo, so the three brand values are literal here
 * exactly as they are in `public/icons/giftcoves.svg`; if that file's geometry
 * changes, the arc below changes with it. Everything *around* the tile is
 * `currentColor`, which is what lets the drawing sit on any background and
 * still belong to the text beside it.
 *
 * The gift in the bay is the object the whole site is about, and it is drawn
 * inside the shelter rather than beside it — the arc is doing the sentence the
 * headline is making. One object, not three: at this size a crowd of small
 * shapes turns into texture, and the mark stops being the thing you see first.
 *
 * `aria-hidden`, like every other drawing on the site. The headline next to it
 * says what the page is; a description here would only repeat it.
 *
 * **`compact` is the phone's version, and it is the icon's own idea drawn
 * once more.** At 128px the scene above turns into texture: the arc is a
 * hairline, the gift a smudge and the tile a dot, and the owner asked for
 * something that reads as the mark (2026-09-08). So the compact drawing
 * keeps the icon's composition rather than the scene's: the headland arc at
 * the icon's own stroke weight, and in the mouth of the cove, where the icon
 * moors its buoy, the gift in the buoy's orange. The owner's own reading of
 * the mark, in four words: "the buoy is the gift". No miniature tile; at
 * this size the drawing *is* the tile's idea, and a logo inside a logo is
 * noise.
 */

/* The logo's own three values. See docs/features/brand-mark.md. */
const TILE = '#12232B'
const COVE = '#EFE6D6'
const BUOY = '#F2A93B'

export default function HomeIllustration({ className, compact = false }: { className?: string; compact?: boolean }) {
    if (compact) {
        return (
            <svg
                viewBox="0 0 200 176"
                fill="none"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                focusable="false"
                className={className ?? 'h-auto w-full'}
            >
                {/* The sheltered water. */}
                <circle cx="100" cy="88" r="52" className="fill-accent/10" stroke="none" />

                {/*
                  The headland at the icon's weight: 8.5 of a 15 radius there,
                  scaled to a 60 radius here and then eased, because a stroke
                  that is a third of the radius reads as a ring at 128px.
                */}
                <path d="M134.4 38.85A60 60 0 1 0 134.4 137.15" strokeWidth={14} />

                {/*
                  The gift, moored where the icon keeps its buoy: 12 of 15 units
                  right of centre there, 48 of 60 here. Filled in the buoy's
                  orange, so the eye that knows the icon finds the same dot of
                  warmth in the same place.
                */}
                <g transform="translate(148 88)" strokeWidth={3}>
                    <rect x="-20" y="-6" width="40" height="30" rx="4" fill={BUOY} fillOpacity={0.9} />
                    <rect x="-24" y="-18" width="48" height="13" rx="3" fill={BUOY} />
                    <path d="M0 -5v29" stroke={TILE} strokeOpacity={0.5} />
                    <path d="M-1 -18c-5-9-10-12-15-10a6 6 0 0 0 2 11" />
                    <path d="M1 -18c5-10 10-13 15-11a6 6 0 0 1-2 11" />
                </g>
            </svg>
        )
    }

    return (
        <svg
            viewBox="0 0 200 176"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-auto w-full'}
        >
            {/* The sheltered water, and the only accent fill in the drawing. */}
            <circle cx="100" cy="88" r="50" className="fill-accent/10" stroke="none" />

            {/*
              The headland. The logo draws this as M40.6,19.71 A15,15 0 1 0
              40.6,44.29 about a centre at (32,32); this is that arc at four
              times the radius, so the mouth opens at the same angle.
            */}
            <path d="M134.4 38.85A60 60 0 1 0 134.4 137.15" />

            {/* Something worth giving, sheltered by it. */}
            <rect x="66" y="84" width="44" height="34" rx="4" />
            <rect x="62" y="72" width="52" height="14" rx="3" className="fill-accent/20" />
            <path d="M88 86v32" />
            <path d="M87 72c-5-9-10-12-15-10a6 6 0 0 0 2 11" />
            <path d="M89 72c5-10 10-13 15-11a6 6 0 0 1-2 11" />

            {/*
              The mark itself, moored in the mouth — 44 of the logo's 64 units,
              so its stroke scales with it rather than being redrawn thinner.
            */}
            <g transform="translate(116 66) scale(0.6875)">
                <rect x="0" y="0" width="64" height="64" rx="14" fill={TILE} stroke="none" />
                <path
                    d="M40.6,19.71 A15,15 0 1 0 40.6,44.29"
                    fill="none"
                    stroke={COVE}
                    strokeWidth="8.5"
                    strokeLinecap="round"
                />
                <circle cx="44" cy="32" r="5" fill={BUOY} stroke="none" />
            </g>
        </svg>
    )
}
