import { useState } from 'react'
import { useTranslations } from '../useTranslations'

/** One of Amazon's own products for a search (App\Services\Amazon\AmazonSearch). */
export interface AmazonItem {
    asin: string
    title: string
    image: string | null
    url: string
}

/**
 * An Amazon product among ours in the search grid (owner, 2026-11-23: "mix the
 * amazon products with the other", mixed by relevance in every order).
 *
 * Built like ProductCard so the grid reads as one list: the square picture, the
 * title, and the line where our cards show the price and the shop. That line says
 * where instead of how much: Amazon's price may not be shown without Amazon's own
 * API. The whole card is one plain anchor to the tagged product page (never our
 * redirector, Amazon's rule), sponsored, in a new tab. No save button: an Amazon
 * product is not in our catalogue, so there is nothing to save it as.
 *
 * The picture is Amazon's own address, shown as it is; our image proxy refuses
 * Amazon. A picture that fails leaves the cream square rather than a broken glyph.
 */
export default function AmazonProductCard({ item, host }: { item: AmazonItem; host: string }) {
    const { t } = useTranslations()
    const [broken, setBroken] = useState(false)

    return (
        <article className="group relative flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30">
            <div className="relative aspect-square overflow-hidden bg-white">
                {item.image && !broken && (
                    <img
                        src={item.image}
                        alt=""
                        loading="lazy"
                        // Contain, not cover as ours: Amazon's pictures are packshots
                        // with text on the box, and a crop cuts the words.
                        className="h-full w-full object-contain p-2 transition group-hover:scale-[1.02]"
                        onError={() => setBroken(true)}
                    />
                )}
            </div>

            <div className="flex flex-1 flex-col p-4">
                <h3 className="mt-1 line-clamp-2 text-sm font-medium">
                    <a href={item.url} target="_blank" rel="sponsored noopener nofollow">
                        {item.title}
                        <span className="absolute inset-0 z-10" aria-hidden />
                    </a>
                </h3>

                <div className="mt-auto pt-3">
                    <div className="flex items-baseline gap-2">
                        <span className="shrink-0 text-sm font-semibold">{t('product.check_on_amazon')}</span>
                        <span className="min-w-0 truncate text-sm text-ink-soft">{host}</span>
                    </div>
                </div>
            </div>
        </article>
    )
}
