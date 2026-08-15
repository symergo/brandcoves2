/**
 * The three flags on the market switcher, drawn rather than typed.
 *
 * **Not emoji.** 🇧🇪 is a regional-indicator pair, and Windows has never
 * shipped glyphs for those — Chrome and Edge on Windows render it as the
 * letters "BE", which is most desktop visitors seeing a broken control. Inline
 * SVG is the only version that looks the same everywhere, and these are three
 * rectangles and a ring of stars, so the cost is nothing.
 *
 * Deliberately unlike the rest of the site's icons: `CoveIcon` and the
 * illustrations take `currentColor` so a card can recolour them on hover, and a
 * flag cannot do that and stay a flag. These carry their own official colours
 * and are the only drawings on the site that ignore the palette.
 *
 * The hairline border is `currentColor` at low opacity rather than a fixed grey
 * so it survives on any background — without it the white band of the Dutch
 * flag dissolves into the page and the flag reads as two stripes.
 *
 * `aria-hidden`: the country's name is on the control itself, and a flag is a
 * famously bad label for a country anyway.
 */

/** A five-pointed star, upright, as a polygon centred on (cx, cy). */
function star(cx: number, cy: number, outer: number): string {
    const points: string[] = []

    for (let i = 0; i < 10; i++) {
        // Start at the top point, then alternate outer and inner vertices.
        const angle = (Math.PI / 5) * i - Math.PI / 2
        const radius = i % 2 === 0 ? outer : outer * 0.382

        points.push(`${(cx + radius * Math.cos(angle)).toFixed(2)},${(cy + radius * Math.sin(angle)).toFixed(2)}`)
    }

    return points.join(' ')
}

/*
 * Twelve stars on a circle of radius 6 about the centre of a 24×16 field —
 * the ratio the European flag specifies (a circle of 1/3 the hoist), kept so
 * this reads as the flag rather than as a blue rectangle with dots on it.
 * Twelve is fixed and has never referred to a number of member states.
 */
const EU_STARS = Array.from({ length: 12 }, (_, i) => {
    const angle = (Math.PI / 6) * i

    return star(12 + 6 * Math.sin(angle), 8 - 6 * Math.cos(angle), 1.25)
})

export type FlagCountry = 'BE' | 'NL' | 'INT' | 'ES'

const flags: Record<FlagCountry, React.ReactNode> = {
    // Vertical thirds: black, yellow, red.
    BE: (
        <>
            <rect x="0" y="0" width="8" height="16" fill="#000000" />
            <rect x="8" y="0" width="8" height="16" fill="#FAE042" />
            <rect x="16" y="0" width="8" height="16" fill="#ED2939" />
        </>
    ),

    // Horizontal thirds: red, white, blue.
    NL: (
        <>
            <rect x="0" y="0" width="24" height="5.34" fill="#AE1C28" />
            <rect x="0" y="5.34" width="24" height="5.33" fill="#FFFFFF" />
            <rect x="0" y="10.67" width="24" height="5.33" fill="#21468B" />
        </>
    ),

    /*
     * A globe, not the European flag.
     *
     * The English market is the one that is not a country: it is what a visitor
     * outside Belgium, the Netherlands and Spain lands on. The EU flag said
     * something specific and wrong — it is not the EU, it excludes the two EU
     * countries sitting next to it in the same row, and it reads as a claim
     * about jurisdiction on a site that has real ones.
     *
     * Drawn on the brand tile colour rather than a national palette, because
     * there is no nation to be faithful to. Meridian and equator only; at
     * 24×16 anything more becomes a smudge.
     */
    INT: (
        <>
            <rect x="0" y="0" width="24" height="16" fill="#12232B" />
            <circle cx="12" cy="8" r="5.5" fill="none" stroke="#EFE6D6" strokeWidth="1.1" />
            <ellipse cx="12" cy="8" rx="2.4" ry="5.5" fill="none" stroke="#EFE6D6" strokeWidth="1.1" />
            <path d="M6.5 8h11" stroke="#EFE6D6" strokeWidth="1.1" />
        </>
    ),

    // Horizontal bands 1:2:1 — the plain civil version, without the arms.
    ES: (
        <>
            <rect x="0" y="0" width="24" height="16" fill="#AA151B" />
            <rect x="0" y="4" width="24" height="8" fill="#F1BF00" />
        </>
    ),
}

export default function FlagIcon({ country, className }: { country: FlagCountry; className?: string }) {
    return (
        <svg
            viewBox="0 0 24 16"
            aria-hidden="true"
            focusable="false"
            className={className ?? 'h-4 w-6'}
        >
            {flags[country]}
            <rect x="0.25" y="0.25" width="23.5" height="15.5" fill="none" stroke="currentColor" strokeOpacity="0.25" strokeWidth="0.5" />
        </svg>
    )
}
