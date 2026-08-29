import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Shop {
    id: number
    name: string
    domain: string | null
    logo: string | null
    isNew: boolean
    url: string
}

interface Cove {
    title: string
    intro: string | null
    url: string
}

interface Props {
    coves: Cove[]
    newShops: Shop[]
    shops: Shop[]
}

/**
 * One shop, as a card.
 *
 * The logo is `onError`-hidden rather than guarded before render: a favicon URL
 * is derived from a domain and is a guess until the browser tries it, so the
 * only place that knows whether it exists is the browser. An empty box where a
 * mark should be reads as a broken shop; the name alone reads as a shop.
 */
function ShopCard({ shop, newLabel }: { shop: Shop; newLabel: string }) {
    return (
        <li className="rounded-lg border border-line bg-card p-4">
            <Link href={shop.url} className="flex items-center gap-3 group">
                {shop.logo && (
                    <img
                        src={shop.logo}
                        alt=""
                        loading="lazy"
                        width={24}
                        height={24}
                        className="h-6 w-6 shrink-0 rounded object-contain"
                        onError={(e) => {
                            e.currentTarget.hidden = true
                        }}
                    />
                )}

                <span className="min-w-0">
                    <span className="block truncate font-medium group-hover:underline">
                        {shop.name}
                    </span>
                    {shop.domain && (
                        <span className="block truncate text-xs text-ink-soft">{shop.domain}</span>
                    )}
                </span>

                {shop.isNew && (
                    <span className="ml-auto shrink-0 rounded-full bg-accent/10 px-2 py-0.5 text-[11px] font-medium text-accent">
                        {newLabel}
                    </span>
                )}
            </Link>
        </li>
    )
}

/**
 * Shop Coves — the shops this market's prices are compared across.
 *
 * The writing first, then new arrivals, then the whole directory A–Z with the
 * new ones repeated inside it. The Coves lead because they are the reason to
 * *read* this page; the directory is the reason to scroll it. Repetition is the point: the spotlight is a band, not a filter,
 * and a shop missing from the alphabet because it happens to be new is a shop
 * somebody scrolling for it cannot find.
 *
 * No counts anywhere. Same rule as the Discover hub — a page that totals the
 * catalogue is making a claim a visitor cannot check, and it is the number most
 * likely to be wrong.
 */
export default function ShopsIndex({ coves, newShops, shops }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('shops.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('shops.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('shops.intro')}</p>
            </header>

            {coves.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-lg font-medium">{t('shops.coves_heading')}</h2>
                    <p className="mt-1 max-w-2xl text-sm text-ink-soft">{t('shops.coves_what')}</p>

                    <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url} className="rounded-lg border border-line bg-card p-5">
                                <Link href={cove.url} className="font-medium hover:underline">
                                    {cove.title}
                                </Link>
                                {cove.intro && (
                                    <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {shops.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('shops.empty')}</p>
            ) : (
                <>
                    {newShops.length > 0 && (
                        <section className="mt-10">
                            <h2 className="text-lg font-medium">{t('shops.new_heading')}</h2>
                            <p className="mt-1 max-w-2xl text-sm text-ink-soft">
                                {t('shops.new_what')}
                            </p>

                            <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {newShops.map((shop) => (
                                    <ShopCard key={shop.id} shop={shop} newLabel={t('shops.new_badge')} />
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="mt-12">
                        <h2 className="text-lg font-medium">{t('shops.all_heading')}</h2>

                        <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {shops.map((shop) => (
                                <ShopCard key={shop.id} shop={shop} newLabel={t('shops.new_badge')} />
                            ))}
                        </ul>
                    </section>
                </>
            )}
        </>
    )
}
