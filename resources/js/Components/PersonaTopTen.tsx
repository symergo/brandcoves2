import { Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../types'
import { formatDay, formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import SaveToList from './SaveToList'

export interface TopTen {
    /** The Monday of the week the list is for, YYYY-MM-DD. */
    week: string
    /** The day it was worked out, YYYY-MM-DD: what the page shows. */
    updated: string
    items: {
        rank: number
        groupId: number
        title: string
        image: string | null
        price: Cents
        url: string
    }[]
}

/**
 * This week's top 10 for a persona, at the end of its page.
 *
 * Ranked by bestseller charts and by how many wish lists on this site hold a
 * product, worked out every Monday; the hint says so, because a numbered list
 * reads as a claim and the reader should know whose. Numbered rows rather
 * than a grid: the order is the point. The server sends nothing when the
 * persona cannot fill a list, and then there is no section.
 * See docs/features/persona-top-ten.md.
 */
export default function PersonaTopTen({ top }: { top: TopTen | null }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    if (top === null || top.items.length === 0) {
        return null
    }

    return (
        <section className="mt-10" aria-labelledby="persona-top-ten">
            <h2 id="persona-top-ten" className="flex items-center gap-1.5 text-sm font-medium text-ink-soft">
                {t('gift_ideas.top_title')}
                <InfoTip>{t('gift_ideas.top_hint')}</InfoTip>
            </h2>
            <p className="mt-1 text-xs text-ink-soft">
                {t('gift_ideas.top_updated', { date: formatDay(top.updated, market) })}
            </p>

            <ol className="mt-4 divide-y divide-line rounded-card border border-line bg-card">
                {top.items.map((item) => (
                    <li key={item.groupId} className="flex items-start gap-3 p-3 sm:items-center sm:gap-4">
                        <span className="w-5 shrink-0 pt-1 text-center text-lg font-semibold text-accent sm:w-6 sm:pt-0">
                            {item.rank}
                        </span>

                        <Link href={item.url} className="shrink-0">
                            {item.image ? (
                                <img src={item.image} alt="" className="h-14 w-14 object-contain" loading="lazy" />
                            ) : (
                                <span className="block h-14 w-14" />
                            )}
                        </Link>

                        {/*
                          On a phone the title gets the whole width beside the
                          picture, with price and save on a line under it: side
                          by side they left the title a few characters a line.
                          From `sm` up there is room for one row.
                        */}
                        <div className="min-w-0 flex-1 sm:flex sm:items-center sm:gap-4">
                            <Link
                                href={item.url}
                                className="line-clamp-3 text-sm font-medium hover:underline sm:line-clamp-2 sm:flex-1 sm:text-base"
                            >
                                {item.title}
                            </Link>

                            <div className="mt-2 flex items-center gap-3 sm:mt-0 sm:shrink-0">
                                <span className="font-semibold">{formatPrice(item.price, market)}</span>
                                <SaveToList groupId={item.groupId} />
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    )
}
