import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

export interface AlertState {
    eligible: boolean
    /** Shop names this alert will NOT watch, so the copy can say so. */
    excluded: string[]
    requiresAccount: boolean
    price: boolean
    restock: boolean
}

interface Props {
    groupId: number
    alert: AlertState
    currentPrice: Cents | null
    inStock: boolean
}

/**
 * Watch a product for a price drop, or for it coming back in stock.
 *
 * Which of the two is offered depends on the product's state: telling someone
 * they can be notified about a drop on something nobody currently sells is
 * an offer we cannot keep.
 */
export default function AlertButton({ groupId, alert, currentPrice, inStock }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [target, setTarget] = useState('')

    // Not eligible means every offer comes from a source whose programme rules
    // forbid a price-tracking feature. Rendering nothing is better than a
    // disabled control nobody can explain.
    if (!alert.eligible) {
        return null
    }

    const watching = alert.price || alert.restock

    if (alert.requiresAccount) {
        return (
            <a
                href={`/${market.key}/login`}
                className="inline-flex items-center gap-2 rounded border border-line px-3 py-2 text-sm hover:bg-card"
            >
                {inStock ? t('alerts.watch_price') : t('alerts.watch_restock')}
            </a>
        )
    }

    if (watching) {
        return (
            <div className="flex items-center gap-3 text-sm">
                <span className="text-ink-soft">
                    {alert.price ? t('alerts.watching_price') : t('alerts.watching_restock')}
                </span>
                <button
                    type="button"
                    className="underline hover:text-ink"
                    onClick={() => router.delete(`/${market.key}/alerts/${groupId}`, { preserveScroll: true })}
                >
                    {t('alerts.stop')}
                </button>
            </div>
        )
    }

    const submit = (type: 'price' | 'restock') => {
        router.post(
            `/${market.key}/alerts`,
            {
                group_id: groupId,
                type,
                // Blank means "any drop". Sent as a decimal amount because that
                // is what the person typed; the server converts to cents.
                target_price: type === 'price' && target !== '' ? target : null,
            },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        )
    }

    if (!inStock) {
        return (
            <button
                type="button"
                className="inline-flex items-center gap-2 rounded border border-line px-3 py-2 text-sm hover:bg-card"
                onClick={() => submit('restock')}
            >
                {t('alerts.watch_restock')}
            </button>
        )
    }

    return (
        <div className="space-y-2">
            <button
                type="button"
                className="inline-flex items-center gap-2 rounded border border-line px-3 py-2 text-sm hover:bg-card"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
            >
                {t('alerts.watch_price')}
            </button>

            {open && (
                <div className="space-y-2 rounded border border-line bg-card p-3 text-sm">
                    <label className="block" htmlFor={`target-${groupId}`}>
                        {t('alerts.target_label')}
                    </label>
                    <div className="flex gap-2">
                        <input
                            id={`target-${groupId}`}
                            type="number"
                            min="0"
                            step="0.01"
                            className="w-32 rounded border border-line px-2 py-1"
                            placeholder={
                                currentPrice === null ? '' : formatPrice(currentPrice, market)
                            }
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                        />
                        <button
                            type="button"
                            className="rounded bg-accent px-3 py-1 text-white"
                            onClick={() => submit('price')}
                        >
                            {t('alerts.confirm')}
                        </button>
                    </div>
                    <p className="text-ink-soft">{t('alerts.any_drop_hint')}</p>

                    {/*
                      Disclosure, not a footnote. If we watch three of four
                      shops, saying "we'll tell you when it drops" without
                      naming the exception is a promise we would quietly break.
                    */}
                    {alert.excluded.length > 0 && (
                        <p className="text-ink-soft">
                            {t('alerts.excluded', { shops: alert.excluded.join(', ') })}
                        </p>
                    )}
                </div>
            )}
        </div>
    )
}
