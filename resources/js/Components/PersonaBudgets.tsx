import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, CurrentMarket, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import SaveToList from './SaveToList'

export interface BudgetBand {
    /** The price point the tab is named after, in cents. */
    around: Cents
    items: {
        groupId: number
        title: string
        image: string | null
        price: Cents
        url: string
    }[]
}

/**
 * "Around 15, 40 or 100": a persona at three budgets, under its curated
 * shelf and never instead of it.
 *
 * The server sends only the bands it could fill (three products or more), so
 * a tab is never half empty; no bands, no section. The products are the
 * suggestion engine's answer to the persona's brief at that budget, which is
 * why the explanation says they were not hand-picked.
 * See docs/features/persona-budgets.md.
 */
export default function PersonaBudgets({ bands }: { bands: BudgetBand[] }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [active, setActive] = useState(0)

    if (bands.length === 0) {
        return null
    }

    const band = bands[Math.min(active, bands.length - 1)]

    return (
        <section className="mt-10" aria-labelledby="persona-budgets">
            <h2 id="persona-budgets" className="flex items-center gap-1.5 text-sm font-medium text-ink-soft">
                {t('gift_ideas.budgets_title')}
                <InfoTip>{t('gift_ideas.budgets_hint')}</InfoTip>
            </h2>

            <div role="tablist" className="mt-3 flex flex-wrap gap-2">
                {bands.map((b, i) => (
                    <button
                        key={b.around}
                        type="button"
                        role="tab"
                        aria-selected={i === active}
                        onClick={() => setActive(i)}
                        className={`rounded-full border px-4 py-1.5 text-sm transition ${
                            i === active ? 'border-accent bg-accent text-white' : 'border-line hover:border-ink'
                        }`}
                    >
                        {t('gift_ideas.budget_around', { price: wholePrice(b.around, market) })}
                    </button>
                ))}
            </div>

            <ul role="tabpanel" className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {band.items.map((item) => (
                    <li key={item.groupId} className="flex flex-col rounded-card border border-line bg-card p-4">
                        <Link href={item.url}>
                            {item.image && (
                                <img src={item.image} alt="" className="mx-auto h-36 object-contain" loading="lazy" />
                            )}
                            <h3 className="mt-3 line-clamp-2 font-medium">{item.title}</h3>
                        </Link>

                        <div className="mt-auto flex items-center justify-between pt-4">
                            <span className="font-semibold">{formatPrice(item.price, market)}</span>
                            <SaveToList groupId={item.groupId} />
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    )
}

/**
 * "€ 15" rather than "€ 15,00": a tab names a budget, not a price. Guarded
 * like formatPrice, whose note explains why a throw here would blank the page.
 */
function wholePrice(cents: Cents, market: CurrentMarket): string {
    try {
        return new Intl.NumberFormat(market.hrefLang, {
            style: 'currency',
            currency: market.currency,
            maximumFractionDigits: 0,
        }).format(cents / 100)
    } catch {
        return formatPrice(cents, market)
    }
}
