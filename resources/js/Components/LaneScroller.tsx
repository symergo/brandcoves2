import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

/**
 * The search page's shop columns, with a bar above them to move along
 * (owner, 2026-11-07: "make the UX better (maybe add a slider above the
 * columns that facilitates the sliding?)").
 *
 * The columns scroll sideways, and the only way along was the browser's thin
 * scrollbar under them, a screen down on a long column, or a sideways swipe a
 * mouse cannot make. Above them now: an arrow either side, one column per
 * press, and between them a slider showing where you are among the shops,
 * which drags the columns as it moves. A real range input, so the keyboard
 * and a screen reader can use it too. The native scrollbar is hidden, since
 * the bar does its job; a swipe on a phone or a trackpad still scrolls.
 *
 * The bar shows only when the columns do not all fit.
 */
export default function LaneScroller({ children }: { children: ReactNode }) {
    const { t } = useTranslations()
    const track = useRef<HTMLDivElement>(null)
    // Where the columns are scrolled to, 0 to 1000, and whether there is anywhere to go.
    const [position, setPosition] = useState(0)
    const [overflows, setOverflows] = useState(false)
    // Snapping leaves the columns a few pixels off either end, so "at the start" has a margin.
    const [atStart, setAtStart] = useState(true)
    const [atEnd, setAtEnd] = useState(false)

    const measure = useCallback(() => {
        const el = track.current
        if (!el) return

        const room = el.scrollWidth - el.clientWidth
        setOverflows(room > 4)
        setPosition(room > 0 ? Math.round((el.scrollLeft / room) * 1000) : 0)
        setAtStart(el.scrollLeft <= 8)
        setAtEnd(el.scrollLeft >= room - 8)
    }, [])

    useEffect(() => {
        const el = track.current
        if (!el) return

        measure()
        el.addEventListener('scroll', measure, { passive: true })
        // Columns come and go (a shop hidden, the live shops answering).
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure)
        observer?.observe(el)
        Array.from(el.children).forEach((child) => observer?.observe(child))
        window.addEventListener('resize', measure)

        return () => {
            el.removeEventListener('scroll', measure)
            observer?.disconnect()
            window.removeEventListener('resize', measure)
        }
    }, [measure, children])

    // One column per press: the first column's width plus the gap.
    const step = (direction: -1 | 1) => {
        const el = track.current
        if (!el) return

        const first = el.firstElementChild as HTMLElement | null
        const width = first ? first.getBoundingClientRect().width + 16 : el.clientWidth * 0.8
        el.scrollBy({ left: direction * width, behavior: 'smooth' })
    }

    const slide = (value: number) => {
        const el = track.current
        if (!el) return

        setPosition(value)
        el.scrollLeft = (value / 1000) * (el.scrollWidth - el.clientWidth)
    }

    const arrow =
        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-card text-ink shadow-sm transition hover:border-ink disabled:cursor-default disabled:opacity-40 disabled:hover:border-line lg:h-9 lg:w-9'

    return (
        <>
            {overflows && (
                <div className="mb-3 flex items-center gap-3">
                    <button type="button" onClick={() => step(-1)} disabled={atStart} aria-label={t('search.lanes_previous')} className={arrow}>
                        <ToolIcon name="chevron" className="h-4 w-4 rotate-90" />
                    </button>
                    <input
                        type="range"
                        min={0}
                        max={1000}
                        value={position}
                        onChange={(e) => slide(Number(e.target.value))}
                        aria-label={t('search.lanes_slider')}
                        className="h-2 min-w-0 flex-1 cursor-pointer appearance-none rounded-full bg-line accent-accent [&::-moz-range-thumb]:h-5 [&::-moz-range-thumb]:w-12 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-accent [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:w-12 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-accent [&::-webkit-slider-thumb]:shadow-sm"
                    />
                    <button type="button" onClick={() => step(1)} disabled={atEnd} aria-label={t('search.lanes_next')} className={arrow}>
                        <ToolIcon name="chevron" className="h-4 w-4 -rotate-90" />
                    </button>
                </div>
            )}
            <div
                ref={track}
                className="-mx-1 flex snap-x gap-4 overflow-x-auto px-1 pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                {children}
            </div>
        </>
    )
}
