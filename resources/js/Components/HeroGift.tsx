/**
 * The homepage hero drawing since 2026-10-05: one wrapped present.
 *
 * Taken from the Koraal proposal the owner chose (the palettes page), where it
 * stood on the coral band between a blush and a peach circle, and asked for in
 * place of the line drawing of shops, a cove and two people
 * (`SharedCoveIllustration`, 2026-09-26 to 2026-10-05). That drawing told the
 * strategy in one line; this one is a picture of the thing itself, in colour,
 * which is what the livelier palette wanted from the first thing on the page.
 *
 * Filled from the tokens (`accent`, `accent-dark`, `peach`, `ink`), so it
 * follows the palette rather than carrying its own colours. The box was white
 * until the owner asked for a colour fill (2026-10-05): a red box, a darker
 * lid, and a light ribbon and bow.
 *
 * `aria-hidden`, like every drawing on the site: the headline beside it says
 * what the page is.
 */
export default function HeroGift({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 100 90"
            fill="none"
            strokeWidth={3}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            className={`stroke-ink ${className ?? 'h-auto w-full'}`}
        >
            {/* The box. */}
            <rect x="14" y="38" width="72" height="46" rx="5" className="fill-accent" />
            {/* The lid. */}
            <rect x="8" y="26" width="84" height="14" rx="4" className="fill-accent-dark" />
            {/* The ribbon, down the lid and the box. */}
            <rect x="45" y="26" width="10" height="58" className="fill-peach" />
            {/* The bow. */}
            <path d="M50 26c-6-14-22-18-24-8s14 8 24 8zm0 0c6-14 22-18 24-8s-14 8-24 8z" className="fill-peach" />
        </svg>
    )
}
