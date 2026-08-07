import { Head, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import Sparkline from '../Components/Sparkline'

interface Offer {
    id: number
    merchant: string
    merchantLogo: string | null
    price: number | null
    currency: string
    availability: string
    isBuyable: boolean
    title: string
    url: string
}

interface Props {
    product: {
        id: number
        title: string
        brand: string | null
        image: string | null
        category: string | null
        minPrice: number | null
        maxPrice: number | null
        medianPrice: number | null
        discountPercent: number | null
        inStock: boolean
        merchantCount: number
        identityKind: string | null
        ean: string | null
    }
    offers: Offer[]
    history: { date: string; price: number }[]
}

export default function Product({ product, offers, history }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const buyable = offers.filter((o) => o.isBuyable)
    const cheapestId = buyable[0]?.id

    return (
        <>
            <Head title={product.title} />

            <div className="grid gap-10 lg:grid-cols-2">
                <div className="rounded-card border border-line bg-card p-8">
                    {product.image && (
                        <img
                            src={product.image}
                            alt={product.title}
                            className="mx-auto max-h-96 w-full object-contain"
                        />
                    )}
                </div>

                <div>
                    {product.brand && (
                        <div className="text-sm tracking-wide text-ink-soft uppercase">{product.brand}</div>
                    )}
                    <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{product.title}</h1>

                    {product.minPrice !== null && (
                        <div className="mt-5 flex flex-wrap items-baseline gap-3">
                            <span className="text-3xl font-semibold">{formatPrice(product.minPrice, market)}</span>

                            {product.discountPercent !== null && product.medianPrice && (
                                <>
                                    <span className="rounded bg-accent px-2 py-1 text-sm font-medium text-white">
                                        {t('product.off', { percent: product.discountPercent })}
                                    </span>
                                    {/*
                                      Against our own 30-day median, never a
                                      merchant's "was" price — those are
                                      frequently fiction.
                                    */}
                                    <span className="text-sm text-ink-soft">
                                        {t('product.typical_price', {
                                            price: formatPrice(product.medianPrice, market),
                                        })}
                                    </span>
                                </>
                            )}
                        </div>
                    )}

                    <p className="mt-2 text-sm text-ink-soft">
                        {product.merchantCount > 1
                            ? t('product.compare', { count: n(offers.length) })
                            : t('product.one_shop')}
                    </p>

                    {history.length > 2 && (
                        <div className="mt-8">
                            <h2 className="mb-2 text-sm font-medium">{t('product.price_history')}</h2>
                            <Sparkline points={history} market={market} />
                        </div>
                    )}

                    {product.ean && (
                        <p className="mt-6 text-xs text-ink-soft">
                            {t('product.barcode')}: <code>{product.ean}</code>
                        </p>
                    )}
                </div>
            </div>

            {/*
              The offer table IS the product. Everything above gives it context.
            */}
            <section className="mt-12">
                <h2 className="mb-4 text-xl font-semibold">{t('product.all_offers')}</h2>

                {offers.length === 0 ? (
                    <p className="rounded-card border border-line bg-card p-6 text-ink-soft">
                        {t('product.unavailable')}
                    </p>
                ) : (
                    <ul className="divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                        {offers.map((offer) => (
                            <li key={offer.id} className="flex flex-wrap items-center gap-4 p-4">
                                {offer.merchantLogo && (
                                    <img
                                        src={offer.merchantLogo}
                                        alt=""
                                        width={20}
                                        height={20}
                                        className="h-5 w-5 rounded"
                                        onError={(e) => { e.currentTarget.style.display = 'none' }}
                                    />
                                )}

                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">{offer.merchant}</div>
                                    <div className="truncate text-xs text-ink-soft">{offer.title}</div>
                                </div>

                                {offer.id === cheapestId && (
                                    <span className="rounded bg-sage/15 px-2 py-1 text-xs font-medium text-sage">
                                        {t('product.cheapest')}
                                    </span>
                                )}

                                <div className="text-right">
                                    {offer.price !== null && (
                                        <div className="text-lg font-semibold">
                                            {formatPrice(offer.price, market)}
                                        </div>
                                    )}
                                    <div className={`text-xs ${offer.isBuyable ? 'text-sage' : 'text-ink-soft'}`}>
                                        {offer.isBuyable ? t('product.in_stock') : t('product.out_of_stock')}
                                    </div>
                                </div>

                                <a
                                    href={offer.url}
                                    // Outbound affiliate link: sponsored is the
                                    // correct rel, and noopener is mandatory on
                                    // any target=_blank to a third party.
                                    rel="sponsored noopener nofollow"
                                    target="_blank"
                                    className={`rounded-lg px-4 py-2 text-sm font-medium ${
                                        offer.isBuyable
                                            ? 'bg-accent text-white hover:bg-accent-dark'
                                            : 'pointer-events-none border border-line text-ink-soft opacity-50'
                                    }`}
                                >
                                    {t('product.go_to_shop')}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                <p className="mt-3 text-xs text-ink-soft">{t('product.disclosure')}</p>
            </section>
        </>
    )
}
