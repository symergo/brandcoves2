import { Link, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { read, type Viewed } from '../recentlyViewed'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * "You looked at", as a band.
 *
 * Read after mount, never during render: the list lives in the browser's
 * storage, the SSR container has none, and a band that rendered on the server
 * and not in the browser (or the other way round) is a hydration mismatch.
 * The first paint has no band; it appears a frame later, below everything the
 * server sent, so nothing above it moves.
 *
 * Renders nothing at all with nothing to show. A heading over an empty row
 * would announce a feature to somebody who has not used it yet.
 */
export default function RecentlyViewed({
    excludeId = null,
    className = '',
}: {
    /** The product being read, which is not "recently viewed" while it is open. */
    excludeId?: number | null
    className?: string
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [items, setItems] = useState<Viewed[]>([])

    useEffect(() => {
        setItems(read(market.key).filter((v) => v.id !== excludeId).slice(0, 6))
    }, [market.key, excludeId])

    if (items.length === 0) return null

    return (
        <section className={className} aria-labelledby="recently-viewed-heading">
            <h2 id="recently-viewed-heading" className="text-xl font-semibold tracking-tight sm:text-2xl">
                {t('home.recently_viewed')}
            </h2>

            <ul className="mt-4 flex snap-x gap-4 overflow-x-auto pb-2">
                {items.map((item) => (
                    <li key={item.id} className="w-40 shrink-0 snap-start">
                        <Link href={item.url} className="group block">
                            <div className="aspect-square overflow-hidden rounded-card border border-line bg-card p-3">
                                {item.image && (
                                    <img
                                        src={item.image}
                                        alt=""
                                        loading="lazy"
                                        className="h-full w-full object-contain transition group-hover:scale-105"
                                    />
                                )}
                            </div>
                            <p className="mt-2 line-clamp-2 text-sm text-ink group-hover:underline">{item.title}</p>
                            {item.price !== null && (
                                <p className="mt-1 text-sm font-medium tabular-nums">{formatPrice(item.price, market)}</p>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    )
}
