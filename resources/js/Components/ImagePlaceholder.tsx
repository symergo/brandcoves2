/**
 * What stands where a product picture should be.
 *
 * Feed images 404 constantly, and a few products never had one. The card used
 * to hide the broken image and leave its square blank, which on a phone reads
 * as a card that failed to load rather than a product with no picture. A gift
 * outline in the line colour says "no picture" without pretending to be one.
 *
 * Decorative: the title beside it names the product, so it is hidden from
 * assistive technology.
 */
export default function ImagePlaceholder({ className = '' }: { className?: string }) {
    return (
        <div className={`flex h-full w-full items-center justify-center text-line ${className}`} aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" className="h-12 w-12">
                <path d="M3 11h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-9Z" />
                <path d="M12 11v10" />
                <path d="M2.5 7.5h19v3.5h-19z" />
                <path d="M12 7.5C10.5 4 9 3 7.5 3a2.25 2.25 0 0 0 0 4.5" />
                <path d="M12 7.5C13.5 4 15 3 16.5 3a2.25 2.25 0 0 1 0 4.5" />
            </svg>
        </div>
    )
}
