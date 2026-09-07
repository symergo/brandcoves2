import { Head, Link, usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import AmazonSearchCta, { type AmazonSearch } from '../Components/AmazonSearchCta'
import Badge from '../Components/Badge'
import { buttonClasses } from '../Components/Button'
import RecentlyViewed from '../Components/RecentlyViewed'
import ShareMenu from '../Components/ShareMenu'
import { record as recordView } from '../recentlyViewed'
import SaveToList from '../Components/SaveToList'
import AlertButton from '../Components/AlertButton'
import type { AlertState } from '../Components/AlertButton'

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
    /** True where the programme requires an unobscured link (Amazon). */
    direct: boolean
    /** Set only on direct links, which bypass the click-recording redirector. */
    beacon: string | null
    needsPriceTimestamp: boolean
}

interface Props {
    product: {
        id: number
        title: string
        brand: string | null
        /** The brand's own page, when it has one. Null for most brands. */
        brandUrl: string | null
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
    alert: AlertState
    /**
     * The tagged Amazon search for this product's barcode. Null when the group
     * has no EAN, or the market has no Associates tag — see AmazonSearchLink.
     */
    amazonSearch: AmazonSearch | null
    /**
     * The shop's own description, in paragraphs, plus whose it is. Null when no
     * offer on this group carries one worth a section — see ProductDescription.
     */
    description: { paragraphs: string[]; merchant: string } | null
}

/**
 * Report a click on a direct link.
 *
 * Links that go through our redirector are recorded server-side. Links that
 * must be direct anchors — Amazon requires unobscured Associates links — have
 * no server hop, so the browser reports the click instead.
 *
 * sendBeacon rather than fetch: it survives the page being replaced by the
 * navigation that fires it, which a normal request often does not. Fired on
 * mousedown rather than click so it is queued before the browser starts
 * unloading. Failure loses one analytics row and never the sale.
 */
function reportClick(offer: Offer): void {
    if (!offer.direct || !offer.beacon) return

    try {
        navigator.sendBeacon?.(
            offer.beacon,
            new Blob([JSON.stringify({ offer: offer.id })], { type: 'application/json' }),
        )
    } catch {
        // Analytics must never break an outbound click.
    }
}

export default function Product({ product, offers, alert, amazonSearch, description }: Props) {
    const { market, seoTitle, canonical } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    // Remembered on this device, for the "you looked at" band. After mount,
    // because storage does not exist on the SSR container.
    useEffect(() => {
        recordView(market.key, {
            id: product.id,
            title: product.title,
            image: product.image,
            price: product.minPrice,
            url: `/${market.key}/p/${product.id}`,
        })
    }, [market.key, product.id, product.title, product.image, product.minPrice])

    const buyable = offers.filter((o) => o.isBuyable)

    return (
        <>
            {/*
              The listing title, which is not the heading.

              `product.title` is the cleaned <h1> — full length, no shop count.
              The tab and the search listing get the server's cut-to-fit version
              with "at N shops" on the end, so this tag and the og:title Blade
              rendered are the same string. See ProductTitle.
            */}
            <Head title={seoTitle ?? product.title} />

            <div className="grid gap-10 lg:grid-cols-2">
                <div className="rounded-card border border-line bg-card p-8">
                    {product.image && (
                        <img
                            src={product.image}
                            alt={product.title}
                            /*
                              The largest thing on the page and the first the
                              eye lands on, so it is fetched first and given
                              its box up front: without a size the layout
                              shifted as it landed, on the site's highest-intent
                              template. 4:3, capped at the same 24rem as
                              before; `object-contain` keeps any feed image
                              inside it.
                            */
                            width={512}
                            height={384}
                            fetchPriority="high"
                            className="mx-auto aspect-[4/3] max-h-96 w-full object-contain"
                        />
                    )}
                </div>

                <div>
                    {/*
                      A link only where the brand has a page. Slugifying the
                      name here would link every product confidently to a 404,
                      because most brands never reach `pageworthy()` — so the
                      server answers that question and sends a URL or a null.
                    */}
                    {product.brand && (
                        <div className="text-sm tracking-wide text-ink-soft uppercase">
                            {product.brandUrl ? (
                                <Link href={product.brandUrl} className="hover:text-accent">
                                    {product.brand}
                                </Link>
                            ) : (
                                product.brand
                            )}
                        </div>
                    )}
                    <h1 className="mt-1 text-xl font-semibold sm:text-2xl">{product.title}</h1>

                    {/*
                      The price is the fact, so it is the biggest thing here.
                      It used to share the title's size and weight exactly, on
                      a page whose whole job is to answer "what does it cost" —
                      one step up, one step down, and tabular figures so a
                      price does not jog when it changes.
                    */}
                    {product.minPrice !== null && (
                        <div className="mt-5 flex flex-wrap items-baseline gap-3">
                            <span className="text-3xl font-semibold tabular-nums sm:text-4xl">{formatPrice(product.minPrice, market)}</span>

                            {product.discountPercent !== null && product.medianPrice && (
                                <>
                                    <Badge tone="discount">
                                        {t('product.off', { percent: product.discountPercent })}
                                    </Badge>
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

                    <div className="mt-5 flex flex-wrap items-start gap-3">
                        <SaveToList groupId={product.id} />
                        <AlertButton
                            groupId={product.id}
                            alert={alert}
                            currentPrice={product.minPrice}
                            inStock={product.inStock}
                        />
                        {/*
                          The share sheet, on the page people actually send to
                          a group chat. It existed on the quiz alone; the
                          canonical URL is what gets shared, never the address
                          bar, so a retitled product's link still resolves.
                        */}
                        <ShareMenu url={canonical} text={product.title} label={t('nav.share')} />
                    </div>

                    {product.ean && (
                        <p className="mt-6 text-xs text-ink-soft">
                            {t('product.barcode')}: <code>{product.ean}</code>
                        </p>
                    )}

                    {/*
                      Directly under the barcode, because the barcode is what it
                      searches for — and because this belongs below the offer
                      table's own context, not above it. Placed among the buy
                      buttons it would compete with the shops we actually
                      carry, which are the ones a click here should be worth
                      less than.
                    */}
                    {amazonSearch && (
                        <div className="mt-6">
                            <AmazonSearchCta
                                link={amazonSearch}
                                label={t('product.amazon_search')}
                                detail={
                                    product.ean
                                        ? t('product.amazon_search_barcode', { ean: product.ean })
                                        : null
                                }
                            />
                        </div>
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

                                <div className="text-right">
                                    {offer.price !== null && (
                                        <div className="text-lg font-semibold">
                                            {formatPrice(offer.price, market)}
                                        </div>
                                    )}
                                    <div className={`text-xs ${offer.isBuyable ? 'text-sage' : 'text-ink-soft'}`}>
                                        {offer.isBuyable ? t('product.in_stock') : t('product.out_of_stock')}
                                    </div>
                                    {/*
                                      Required where the programme mandates it
                                      (Amazon): the price may have moved since we
                                      fetched it, and saying so is the condition
                                      of being allowed to show it at all.
                                    */}
                                    {offer.needsPriceTimestamp && (
                                        <div className="mt-0.5 text-2xs text-ink-soft">
                                            {t('product.price_as_of')}
                                        </div>
                                    )}
                                </div>

                                <a
                                    href={offer.url}
                                    // Outbound affiliate link: sponsored is the
                                    // correct rel, and noopener is mandatory on
                                    // any target=_blank to a third party.
                                    rel="sponsored noopener nofollow"
                                    target="_blank"
                                    onMouseDown={() => reportClick(offer)}
                                    className={
                                        offer.isBuyable
                                            ? buttonClasses('primary', 'md')
                                            : buttonClasses('secondary', 'md', 'pointer-events-none opacity-50')
                                    }
                                >
                                    {t('product.go_to_shop')}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                <p className="mt-3 text-xs text-ink-soft">{t('product.disclosure')}</p>
            </section>

            {/*
              The shop's own words, below the offers.

              Above them it would push the one thing this page exists for — who
              sells it and for how much — off the first screen, behind several
              hundred words of somebody else's marketing copy. Below, it is
              where a shopper who has seen the prices and wants to know what the
              thing actually *is* goes looking.

              Rendered as text, never as HTML. The paragraphs arrive already
              stripped: this column comes from third-party feeds, and one Awin
              advertiser ships unbalanced tags in it.
            */}
            {description && (
                <section className="mt-12" aria-labelledby="product-description">
                    <h2 id="product-description" className="mb-4 text-xl font-semibold">
                        {t('product.description_heading')}
                    </h2>

                    <div className="rounded-card border border-line bg-card p-6">
                        {/*
                          A measure. Every editorial page caps its prose near
                          seventy characters and this one ran the full column
                          — about 140 a line on a desktop, for up to 1800
                          characters of a shop's marketing copy.
                        */}
                        <div className="max-w-2xl space-y-3 leading-relaxed text-ink-soft">
                            {description.paragraphs.map((paragraph, i) => (
                                <p key={i}>{paragraph}</p>
                            ))}
                        </div>

                        {/*
                          Named, because it is a quotation. Unattributed it
                          reads as our own editorial voice — a claim we cannot
                          stand behind, since "the best headphones you will ever
                          own" is the shop's opinion and not ours.
                        */}
                        <p className="mt-4 text-xs text-ink-soft/80">
                            {t('product.description_source', { shop: description.merchant })}
                        </p>
                    </div>
                </section>
            )}

            {/* What this visitor looked at before this one. Client-side only. */}
            <RecentlyViewed excludeId={product.id} className="mt-12" />
        </>
    )
}
