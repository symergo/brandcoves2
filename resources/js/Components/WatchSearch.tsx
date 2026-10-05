import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Button, { buttonClasses } from './Button'
import SignInLink from './SignInLink'
import ToolIcon from './ToolIcon'
import { formatBudget, type SharedProps } from '../types'
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
 *
 * `compact` (2026-10-05, owner: "make this less crowded"): a bell beside the
 * search page's title instead of a button on a row of its own. The words stay
 * beside the bell from `sm` up and for a screen reader always; the panel floats
 * under the bell so opening it moves nothing.
 */
export default function WatchSearch({ term, watch, compact = false }: { term: string; watch: WatchState; compact?: boolean }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [ceiling, setCeiling] = useState('')
    const [busy, setBusy] = useState(false)

    const label = compact ? (
        <>
            <ToolIcon name="bell" className="h-5 w-5 shrink-0" />
            <span className="sr-only sm:not-sr-only">{t('search.watch')}</span>
        </>
    ) : (
        t('search.watch')
    )
    const buttonClass = compact
        ? buttonClasses('secondary', 'sm', 'min-w-11 rounded-full sm:min-w-0')
        : buttonClasses('secondary', 'sm')

    if (watch.requiresAccount) {
        return (
            <SignInLink hint={t('search.watch_sign_in')} className={buttonClass}>
                {label}
            </SignInLink>
        )
    }

    if (watch.watching && watch.id !== null) {
        return (
            <div className="flex flex-wrap items-center gap-3 text-sm">
                {compact && <ToolIcon name="bell" className="h-5 w-5 shrink-0 fill-current text-accent" />}
                <span className={compact ? 'sr-only text-ink-soft sm:not-sr-only' : 'text-ink-soft'}>
                    {watch.maxPrice === null
                        ? t('search.watching')
                        : t('search.watching_under', { price: formatBudget(watch.maxPrice, market) })}
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
        <div className={compact ? 'relative' : 'space-y-2'}>
            <button
                type="button"
                className={buttonClass}
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                title={compact ? t('search.watch') : undefined}
            >
                {label}
            </button>

            {open && (
                <div
                    className={
                        compact
                            ? 'absolute top-full right-0 z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] space-y-2 rounded-card border border-line bg-card p-3 text-sm shadow-lg'
                            : 'max-w-md space-y-2 rounded-card border border-line bg-card p-3 text-sm'
                    }
                >
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
