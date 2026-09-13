import { usePage } from '@inertiajs/react'
import SaveToList from './SaveToList'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/** A live offer as the server presents it; see App\Services\Search\LiveOfferCard. */
export interface LiveOffer {
    title: string
    url: string
    image: string | null
    price: number | null
    merchant: string
    inStock: boolean
    needsPriceTimestamp: boolean
    directLink: boolean
    /** What it takes to keep one: the external-source save path. */
    source: string
    externalId: string
}

/**
 * A card for an offer that lives at a shop and not in the catalogue.
 *
 * It was the brand page's own markup until 2026-09-13, when the search
 * landing started showing live offers too. There is no group behind such an
 * offer, so the card cannot claim an offer count, a shop count or a discount;
 * it shows the one shop, the one price, and the bookmark that keeps it.
 *
 * The snapshot fields handed to the bookmark are hints: the server stores
 * them only for a source it is allowed to mirror, so an Amazon offer keeps the
 * decision and nothing else (invariant #6).
 */
export default function LiveOfferCard({ offer }: { offer: LiveOffer }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    return (
        <li className="flex flex-col overflow-hidden rounded-card border border-line bg-card transition hover:border-ink/30">
            <div className="relative aspect-square overflow-hidden bg-cream">
                {offer.image && (
                    <img
                        src={offer.image}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-contain p-4"
                        onError={(e) => {
                            e.currentTarget.style.visibility = 'hidden'
                        }}
                    />
                )}

                <div className="absolute right-2 bottom-2">
                    <SaveToList
                        source={offer.source}
                        externalId={offer.externalId}
                        title={offer.title}
                        imageUrl={offer.image}
                        price={offer.price}
                        compact
                    />
                </div>
            </div>

            <div className="flex flex-1 flex-col p-4">
                <div className="text-xs tracking-wide text-ink-soft uppercase">{offer.merchant}</div>

                <h3 className="mt-1 line-clamp-2 text-sm font-medium">
                    <a
                        href={offer.url}
                        // Unobscured, as the programme requires; sponsored +
                        // noopener, as any outbound affiliate link needs.
                        rel="sponsored noopener nofollow"
                        target="_blank"
                        className="hover:text-accent"
                    >
                        {offer.title}
                    </a>
                </h3>

                <div className="mt-auto pt-3">
                    {offer.price !== null && (
                        <div className="text-lg font-semibold">{formatPrice(offer.price, market)}</div>
                    )}
                    <div className={`mt-1 text-xs ${offer.inStock ? 'text-sage' : 'text-ink-soft'}`}>
                        {offer.inStock ? t('product.in_stock') : t('product.out_of_stock')}
                    </div>
                    {offer.needsPriceTimestamp && (
                        <div className="mt-0.5 text-2xs text-ink-soft">{t('product.price_as_of')}</div>
                    )}
                </div>
            </div>
        </li>
    )
}
