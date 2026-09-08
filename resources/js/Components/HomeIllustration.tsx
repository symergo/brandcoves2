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
 */

/* The logo's own three values. See docs/features/brand-mark.md. */
const TILE = '#12232B'
const COVE = '#EFE6D6'
const BUOY = '#F2A93B'

export default function HomeIllustration({ className }: { className?: string }) {
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
