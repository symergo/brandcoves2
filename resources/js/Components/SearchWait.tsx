import { useEffect, useRef, useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * What a search shows while it waits (owner, 2026-10-25).
 *
 * The page used to keep the previous results on screen behind a thin moving
 * line, which on a 1–3 s wait read as nothing happening. Now: placeholder
 * cards where the products will come, and under the search bar one line,
 * "Even zoeken…" and then tips about what this page can do, so a long wait
 * has something in it. See docs/features/search.md, "While a search waits".
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

/** "Even zoeken…" this long, then the funny lines, each this long. */
const LINES_AFTER = 1000
const LINE_EVERY = 2500

/**
 * `search.wait_line_1` … `_10` in the language files: what the search is up
 * to, with a little fun, naming a real shop (`:shop`) and sometimes a second
 * one (`:other`).
 */
const LINE_COUNT = 10
const LINES = Array.from({ length: LINE_COUNT }, (_, i) => `search.wait_line_${i + 1}`)

/** A random order (Fisher–Yates). */
function shuffled<T>(items: readonly T[]): T[] {
    const out = [...items]
    for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1))
        ;[out[i], out[j]] = [out[j], out[i]]
    }
    return out
}

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

/** The pulsing dot that says "working". */
function Pulse() {
    return (
        <span className="relative flex h-2 w-2 shrink-0" aria-hidden>
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent/60" />
            <span className="relative inline-flex h-2 w-2 rounded-full bg-accent" />
        </span>
    )
}

/**
 * The line under the search bar while a search loads (owner, 2026-10-25):
 * "Even zoeken…", then funny lines about how the search is going, naming
 * the shops it looks through ("Even rondneuzen bij Coolblue…"), changing
 * every few seconds. One line, never two.
 *
 * Lines and shops are shuffled for every search, so each starts somewhere
 * else and no line repeats before all have been shown. `shops` are the
 * market's biggest shops (WaitShops on the server); with none, only
 * "Even zoeken…", and with one, only the lines that name one shop.
 *
 * Laid over the margin under the bar (`absolute`), so it takes no room: it
 * appears and disappears without moving anything on the page. The parent
 * must be `relative`. One line, truncated, so it cannot spill onto what is
 * below. Only "Even zoeken…" is for screen readers; a line read aloud every
 * few seconds is noise, so the rest is `aria-hidden`.
 */
export function SearchStatus({ elapsed, shops = [] }: { elapsed: number; shops?: string[] }) {
    const { t } = useTranslations()
    const lineOrder = useRef<string[]>(LINES)
    const shopOrder = useRef<string[]>(shops)
    const idle = elapsed === 0

    // Shuffled while no search runs, ready for the next one. Never during
    // server rendering: the line is only drawn while a search runs.
    useEffect(() => {
        if (idle) {
            lineOrder.current = shuffled(LINES)
            shopOrder.current = shuffled(shops)
        }
    }, [idle, shops])

    if (elapsed < PLACEHOLDER_AFTER) {
        return null
    }

    const names = shopOrder.current.length > 0 ? shopOrder.current : shops
    const lines = names.length > 1 ? lineOrder.current : lineOrder.current.filter((key) => !t(key).includes(':other'))
    const step = Math.floor((elapsed - LINES_AFTER) / LINE_EVERY)
    const key = names.length > 0 && elapsed >= LINES_AFTER && lines.length > 0 ? lines[step % lines.length] : null

    return (
        <p className="pointer-events-none absolute inset-x-0 top-full mt-1.5 flex items-center gap-2 text-xs text-ink-soft sm:text-sm">
            <Pulse />
            {key ? (
                <span key={key} className="truncate" aria-hidden>
                    {t(key, { shop: names[step % names.length], other: names[(step + 1) % names.length] })}
                </span>
            ) : (
                <span className="truncate">{t('search.wait_searching')}</span>
            )}
        </p>
    )
}

/**
 * The line under the grid while the live shops are asked after the page was
 * shown: "We kijken ook bij bol.com…". Under the grid, so neither it nor the
 * notice that replaces it moves a product the visitor is looking at.
 */
export function WaitLine({ shops }: { shops: string[] }) {
    const { t } = useTranslations()

    return (
        <p role="status" className="my-4 flex items-center gap-2 text-sm text-ink-soft">
            <Pulse />
            {t('search.live_looking', { shops: shops.join(', ') })}
        </p>
    )
}
