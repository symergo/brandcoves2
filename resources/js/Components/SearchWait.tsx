import { type ReactNode, useEffect, useRef, useState } from 'react'
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

/** How long "20 producten van bol.com toegevoegd" stays before it goes. */
const NOTICE_FOR = 6000

/**
 * The one line under the search bar (owner, 2026-10-25). In order of what
 * it says:
 *
 * 1. While a search loads: "Even zoeken…", then funny lines about how the
 *    search is going, naming the shops it looks through ("Even rondneuzen bij
 *    Coolblue…"), changing every few seconds. Lines and shops are shuffled
 *    for every search, so each starts somewhere else and no line repeats
 *    before all have been shown. `shops` are the market's biggest shops
 *    (WaitShops on the server); with none, only "Even zoeken…", and with one,
 *    only the lines that name one shop.
 * 2. While the live shops are asked after the page was shown: "We kijken
 *    ook bij bol.com…" (`liveShops`).
 * 3. When they answered: how many products they added ("20 producten van
 *    bol.com, eBay toegevoegd", `notice`), for six seconds, then nothing.
 *
 * Laid over the margin under the bar (`absolute`), so it takes no room: it
 * appears, changes and disappears without moving anything on the page. The
 * parent must be `relative`. One line, truncated, so it cannot spill onto
 * what is below. The funny lines are `aria-hidden` (a line read aloud every
 * few seconds is noise); the rest is announced.
 */
export function SearchStatus({
    elapsed,
    shops = [],
    liveShops = [],
    notice = null,
}: {
    elapsed: number
    shops?: string[]
    liveShops?: string[]
    notice?: string | null
}) {
    const { t } = useTranslations()
    const lineOrder = useRef<string[]>(LINES)
    const shopOrder = useRef<string[]>(shops)
    const idle = elapsed === 0

    // Shuffled while no search runs, ready for the next one. Never during
    // server rendering: the funny lines are only drawn while a search runs.
    useEffect(() => {
        if (idle) {
            lineOrder.current = shuffled(LINES)
            shopOrder.current = shuffled(shops)
        }
    }, [idle, shops])

    // The notice shows for NOTICE_FOR, from the moment it arrives.
    const [noticeShown, setNoticeShown] = useState<string | null>(null)
    useEffect(() => {
        if (!notice) {
            setNoticeShown(null)
            return
        }
        setNoticeShown(notice)
        const id = window.setTimeout(() => setNoticeShown(null), NOTICE_FOR)

        return () => window.clearTimeout(id)
    }, [notice])

    const line = (content: ReactNode, pulse = true) => (
        <p className="pointer-events-none absolute inset-x-0 top-full mt-1.5 flex items-center gap-2 text-xs text-ink-soft sm:text-sm">
            {pulse && <Pulse />}
            {content}
        </p>
    )

    if (elapsed >= PLACEHOLDER_AFTER) {
        const names = shopOrder.current.length > 0 ? shopOrder.current : shops
        const lines = names.length > 1 ? lineOrder.current : lineOrder.current.filter((key) => !t(key).includes(':other'))
        const step = Math.floor((elapsed - LINES_AFTER) / LINE_EVERY)
        const key = names.length > 0 && elapsed >= LINES_AFTER && lines.length > 0 ? lines[step % lines.length] : null

        return line(
            key ? (
                <span key={key} className="truncate" aria-hidden>
                    {t(key, { shop: names[step % names.length], other: names[(step + 1) % names.length] })}
                </span>
            ) : (
                <span className="truncate">{t('search.wait_searching')}</span>
            ),
        )
    }

    if (elapsed === 0 && liveShops.length > 0) {
        return line(
            <span role="status" className="truncate">
                {t('search.live_looking', { shops: liveShops.join(', ') })}
            </span>,
        )
    }

    if (elapsed === 0 && noticeShown) {
        return line(
            <span role="status" className="truncate">
                {noticeShown}
            </span>,
            false,
        )
    }

    return null
}
