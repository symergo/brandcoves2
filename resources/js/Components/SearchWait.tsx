import { useEffect, useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * What a search shows while it waits (owner, 2026-10-25).
 *
 * The page used to keep the previous results on screen behind a thin moving
 * line, which on a 1–3 s wait read as nothing happening. Now: placeholder
 * cards where the products will come, a line that says what is going on, and
 * after a few seconds a tip, changing every few seconds, so a long wait has
 * something in it. See docs/features/search.md, "While a search waits".
 */

/** Milliseconds since `active` became true; 0 while it is false. */
export function useElapsed(active: boolean): number {
    const [elapsed, setElapsed] = useState(0)

    useEffect(() => {
        if (!active) {
            setElapsed(0)
            return
        }

        const started = Date.now()
        const id = window.setInterval(() => setElapsed(Date.now() - started), 250)

        return () => window.clearInterval(id)
    }, [active])

    return elapsed
}

/** Before this, nothing changes: a fast search should not flash placeholders. */
export const PLACEHOLDER_AFTER = 300

const LONGER_AFTER = 2500
const TIPS_AFTER = 3500
const TIP_EVERY = 5000
const TIPS = ['search.wait_tip_1', 'search.wait_tip_2', 'search.wait_tip_3', 'search.wait_tip_4']

/** Card-shaped placeholders in the grid the products will fill. */
export function SkeletonGrid({ count = 10 }: { count?: number }) {
    return (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" aria-hidden>
            {Array.from({ length: count }, (_, i) => (
                <div key={i} className="overflow-hidden rounded-card border border-line bg-card">
                    <div className="aspect-square animate-pulse bg-line/40" />
                    <div className="space-y-2 p-3">
                        <div className="h-3 w-4/5 animate-pulse rounded bg-line/50" />
                        <div className="h-3 w-1/2 animate-pulse rounded bg-line/50" />
                        <div className="mt-3 h-4 w-1/3 animate-pulse rounded bg-line/60" />
                    </div>
                </div>
            ))}
        </div>
    )
}

/**
 * The line under a waiting search. `elapsed` drives the stages: "Even
 * zoeken…", then (or at once, when the shops are named) who else is being
 * asked, then a tip that changes every few seconds.
 *
 * Announced to screen readers once per stage (`role="status"`); the tips are
 * not, a rotating sentence read aloud every five seconds is noise.
 */
export function WaitLine({ elapsed, shops = [] }: { elapsed: number; shops?: string[] }) {
    const { t } = useTranslations()

    const message =
        shops.length > 0
            ? t('search.live_looking', { shops: shops.join(', ') })
            : elapsed >= LONGER_AFTER
              ? t('search.wait_longer')
              : t('search.wait_searching')

    const tip = elapsed >= TIPS_AFTER ? TIPS[Math.floor((elapsed - TIPS_AFTER) / TIP_EVERY) % TIPS.length] : null

    return (
        <div className="my-4 text-sm">
            <p role="status" className="flex items-center gap-2 text-ink-soft">
                <span className="relative flex h-2 w-2 shrink-0" aria-hidden>
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent/60" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-accent" />
                </span>
                {message}
            </p>
            {tip && (
                <p key={tip} className="mt-2 text-ink-soft">
                    <span className="font-medium text-ink">{t('search.wait_tip')}:</span> {t(tip)}
                </p>
            )}
        </div>
    )
}
