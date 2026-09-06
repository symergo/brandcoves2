import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Button, { buttonClasses } from './Button'
import SignInLink from './SignInLink'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export interface WatchState {
    watching: boolean
    id: number | null
    /** Cents, or null for any price. */
    maxPrice: number | null
    requiresAccount: boolean
}

/**
 * "Tell me about new finds for this search."
 *
 * The price alert's machinery, pointed at a query: the search page is where
 * the intent is expressed, and until 2026-09-06 the only thing that could be
 * watched was one product at a time. Same shape as AlertButton — a button, a
 * small panel with an optional ceiling, a sign-in link for a guest.
 */
export default function WatchSearch({ term, watch }: { term: string; watch: WatchState }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [ceiling, setCeiling] = useState('')
    const [busy, setBusy] = useState(false)

    if (watch.requiresAccount) {
        return (
            <SignInLink hint={t('search.watch_sign_in')} className={buttonClasses('secondary', 'sm')}>
                {t('search.watch')}
            </SignInLink>
        )
    }

    if (watch.watching && watch.id !== null) {
        return (
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <span className="text-ink-soft">
                    {watch.maxPrice === null
                        ? t('search.watching')
                        : t('search.watching_under', { price: formatPrice(watch.maxPrice, market) })}
                </span>
                <button
                    type="button"
                    className="underline hover:text-ink"
                    onClick={() =>
                        router.delete(`/${market.key}/search-alerts/${watch.id}`, { preserveScroll: true })
                    }
                >
                    {t('search.stop_watching')}
                </button>
            </div>
        )
    }

    const submit = () => {
        router.post(
            `/${market.key}/search-alerts`,
            {
                term,
                // Blank means any price. A decimal keyboard writes 19,99 on
                // half the site's keyboards; normalised as the price alert is.
                max_price: ceiling.trim() === '' ? null : ceiling.trim().replace(',', '.'),
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => setOpen(false),
            },
        )
    }

    return (
        <div className="space-y-2">
            <button
                type="button"
                className={buttonClasses('secondary', 'sm')}
                onClick={() => setOpen(!open)}
                aria-expanded={open}
            >
                {t('search.watch')}
            </button>

            {open && (
                <div className="max-w-md space-y-2 rounded-card border border-line bg-card p-3 text-sm">
                    <p className="text-ink-soft">{t('search.watch_hint')}</p>
                    <label className="block" htmlFor="watch-ceiling">
                        {t('search.watch_price_label')}
                    </label>
                    <div className="flex gap-2">
                        <input
                            id="watch-ceiling"
                            inputMode="decimal"
                            className="w-32 rounded border border-line px-2 py-1"
                            placeholder="€"
                            value={ceiling}
                            onChange={(e) => setCeiling(e.target.value)}
                        />
                        <Button size="sm" busy={busy} onClick={submit}>
                            {t('search.watch_confirm')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    )
}
