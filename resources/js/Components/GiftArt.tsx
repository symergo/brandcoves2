import HeroGift from './HeroGift'

/**
 * The wrapped present on its circles: the site's one illustration.
 *
 * It was drawn by hand in each place that shows it (the homepage hero and its
 * closing card, Find a gift, the Secret Friend start page and its draw card),
 * five copies of the same percentages (2026-11-12). Decoration only, so it is
 * hidden from assistive technology.
 *
 * - `scene`: the large one, on three circles, beside a page's opening words.
 * - `badge`: the small one on a single peach disc, in the corner of a card.
 */
export default function GiftArt({ variant = 'scene', className = '' }: { variant?: 'scene' | 'badge'; className?: string }) {
    if (variant === 'badge') {
        return (
            <div aria-hidden="true" className={`relative aspect-square shrink-0 ${className}`}>
                <span className="absolute inset-0 rounded-full bg-peach" />
                <HeroGift className="absolute top-[18%] left-[12%] h-auto w-[76%]" />
            </div>
        )
    }

    return (
        <div aria-hidden="true" className={`relative aspect-[5/4] w-full ${className}`}>
            <span className="absolute top-[4%] left-[6%] aspect-square w-[62%] rounded-full bg-tint/80" />
            <span className="absolute top-[6%] right-0 aspect-square w-[36%] rounded-full bg-peach" />
            <span className="absolute bottom-[2%] left-[16%] aspect-square w-[22%] rounded-full bg-peach/70" />
            <HeroGift className="absolute top-[12%] left-[16%] h-auto w-[68%]" />
        </div>
    )
}
