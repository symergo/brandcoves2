import { router, usePage } from '@inertiajs/react'
import SignInLink from './SignInLink'
import type { SharedProps } from '../types'
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
    inStock: boolean
}

/**
 * Watch a product for it coming back in stock.
 *
 * Until 2026-09-08 this also offered a price-drop watch, with a target price
 * and a disclosure of the shops it could not follow. The owner took that
 * offer off the page. A list already shows a drop on everything saved to it
 * ("Nu €X, was €Y"), so saving is the way to follow a price here. The
 * endpoint and the mail still exist, and a watch somebody set before that
 * date keeps running and can still be stopped here; only the way to start a
 * new one is gone. Restock stays, because nothing else says "it is back".
 */
export default function AlertButton({ groupId, alert, inStock }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    // Not eligible means every offer comes from a source whose programme rules
    // forbid a price-tracking feature. Rendering nothing is better than a
    // disabled control nobody can explain.
    if (!alert.eligible) {
        return null
    }

    if (alert.price || alert.restock) {
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

    // In stock and not watched: nothing to offer. Telling someone they can be
    // notified about a restock on something every shop has is an offer with
    // no moment to keep it.
    if (inStock) {
        return null
    }

    if (alert.requiresAccount) {
        return (
            /*
              The dialog, not the login page. This control sits on a product
              somebody is looking at, and the whole point of the alert is that
              they are looking at *this* product — sending them to a form on
              another page loses the one thing the alert is about.
            */
            <SignInLink
                hint={t('alerts.watch_restock')}
                className="inline-flex items-center gap-2 rounded border border-line px-3 py-2 text-sm hover:bg-card"
            >
                {t('alerts.watch_restock')}
            </SignInLink>
        )
    }

    return (
        <button
            type="button"
            className="inline-flex items-center gap-2 rounded border border-line px-3 py-2 text-sm hover:bg-card"
            onClick={() =>
                router.post(
                    `/${market.key}/alerts`,
                    { group_id: groupId, type: 'restock', target_price: null },
                    { preserveScroll: true },
                )
            }
        >
            {t('alerts.watch_restock')}
        </button>
    )
}
