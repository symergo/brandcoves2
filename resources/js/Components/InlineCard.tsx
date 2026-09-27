import { Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import SaveToList from './SaveToList'

/** What a card needs; a guide's item and a brand page's example both carry it. */
export interface InlineCardItem {
    groupId: number
    title: string
    image: string | null
    price: Cents | null
    merchantCount: number
    verdict: string | null
    unavailable: boolean
    url: string
}

/**
 * A product drawn under the paragraph that names it.
 *
 * Moved out of the guide page when brand pages started carrying example
 * products (2026-09-27), so the two draw one card rather than two that drift.
 */
export default function InlineCard({ item }: { item: InlineCardItem }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    return (
        <figure
            className={`my-5 flex flex-col gap-4 rounded-card border border-line bg-card p-4 sm:flex-row ${
                item.unavailable ? 'opacity-60' : ''
            }`}
        >
            {item.image && (
                <Link href={item.url} className="shrink-0">
                    <img
                        src={item.image}
                        alt=""
                        loading="lazy"
                        className="mx-auto h-32 w-32 object-contain"
                    />
                </Link>
            )}

            <figcaption className="min-w-0 flex-1">
                {item.verdict && (
                    <p className="text-xs font-medium tracking-wide text-accent uppercase">
                        {item.verdict}
                    </p>
                )}

                <Link href={item.url} className="mt-1 block font-medium hover:underline">
                    {item.title}
                </Link>

                <div className="mt-3 flex flex-wrap items-center gap-4">
                    {/*
                      Live from the group, never written into the copy. A price
                      baked into editorial is wrong within a week.
                    */}
                    <span className="font-semibold">
                        {item.unavailable
                            ? t('guides.unavailable')
                            : item.price === null
                              ? '—'
                              : formatPrice(item.price, market)}
                    </span>

                    {item.merchantCount > 1 && (
                        <span className="text-sm text-ink-soft">
                            {t('guides.shops', { count: n(item.merchantCount) })}
                        </span>
                    )}

                    <SaveToList groupId={item.groupId} />
                </div>
            </figcaption>
        </figure>
    )
}
