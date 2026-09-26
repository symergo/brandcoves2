import { Link, usePage } from '@inertiajs/react'
import type { Cents, SavingTo, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import SaveToList from './SaveToList'

export interface NextStepCard {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    url: string
    /** Why it follows on: `often_together`, `goes_with` or `same_brand`. */
    reason: 'often_together' | 'goes_with' | 'same_brand'
    /** The title of the past gift it follows. */
    after: string
}

/**
 * "The next step": products that follow on from what somebody was given.
 *
 * A small row on a saved person's page and under the Gift Finder's results
 * when that person is chosen. Each card says which past gift it follows and
 * why, because an idea that names what it follows is one a giver can judge
 * at a glance. See docs/features/gift-history.md.
 */
export default function NextSteps({
    steps,
    name,
    into,
    className = 'mt-10',
}: {
    steps: NextStepCard[]
    name: string
    into?: SavingTo | null
    className?: string
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    if (steps.length === 0) {
        return null
    }

    return (
        <section className={className}>
            <h2 className="flex items-center gap-1.5 text-sm font-medium text-ink-soft">
                {t('gift_history.next_title')}
                <InfoTip>{t('gift_history.next_hint', { name })}</InfoTip>
            </h2>
            <ul className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {steps.map((step) => (
                    <li key={step.id} className="flex flex-col rounded-card border border-line bg-card p-4">
                        <Link href={step.url}>
                            {step.image && (
                                <img src={step.image} alt="" className="mx-auto h-28 object-contain" loading="lazy" />
                            )}
                            <h3 className="mt-3 line-clamp-2 text-sm font-medium">{step.title}</h3>
                        </Link>
                        <p className="mt-1 text-xs text-sage">
                            {t(`gift_history.reason_${step.reason}`, { after: step.after })}
                        </p>
                        <div className="mt-auto flex items-center justify-between gap-3 pt-3">
                            <span className="text-sm font-semibold">
                                {step.price === null ? '' : formatPrice(step.price, market)}
                            </span>
                            <SaveToList groupId={step.id} into={into ?? undefined} />
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    )
}
