import { useCallback, useEffect, useRef, useState, type KeyboardEvent, type PointerEvent, type ReactNode } from 'react'
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
 * press, and between them a slider whose thumb is as wide as the part of the
 * row that is in view ("the slider should be proportional to the visible part
 * of the screen", the same day). Four of six columns in view is a thumb two
 * thirds of the track. Drag the thumb, or press the track to jump there.
 *
 * Drawn rather than a range input, which cannot size its thumb. It keeps what
 * the range input gave: `role="slider"` with its value, and the arrow keys,
 * Page Up/Down, Home and End. The native scrollbar is hidden, since the bar
 * does its job; a swipe on a phone or a trackpad still scrolls.
 *
 * The bar shows only when the columns do not all fit.
 */
export default function LaneScroller({ children }: { children: ReactNode }) {
    const { t } = useTranslations()
    const row = useRef<HTMLDivElement>(null)
    const bar = useRef<HTMLDivElement>(null)
    // The visible share of the row (0-1) and how far along it is scrolled (0-1).
    const [view, setView] = useState({ share: 1, at: 0 })
    // Snapping leaves the columns a few pixels off either end, so "at the start" has a margin.
    const [atStart, setAtStart] = useState(true)
    const [atEnd, setAtEnd] = useState(false)
    // Where on the thumb the pointer took hold, so a drag does not jump the thumb to the pointer.
    const grip = useRef<number | null>(null)

    const measure = useCallback(() => {
        const el = row.current
        if (!el) return

        const room = el.scrollWidth - el.clientWidth
        setView({ share: el.scrollWidth > 0 ? el.clientWidth / el.scrollWidth : 1, at: room > 0 ? el.scrollLeft / room : 0 })
        setAtStart(el.scrollLeft <= 8)
        setAtEnd(el.scrollLeft >= room - 8)
    }, [])

    useEffect(() => {
        const el = row.current
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

    const overflows = view.share < 0.995

    // One column per press: the first column's width plus the gap.
    const step = (direction: -1 | 1) => {
        const el = row.current
        if (!el) return

        const first = el.firstElementChild as HTMLElement | null
        const width = first ? first.getBoundingClientRect().width + 16 : el.clientWidth * 0.8
        el.scrollBy({ left: direction * width, behavior: 'smooth' })
    }

    /** Scroll the row so the thumb's left edge sits at `left` px along the bar. */
    const moveThumbTo = (left: number) => {
        const el = row.current
        const track = bar.current
        if (!el || !track) return

        const free = track.clientWidth * (1 - view.share)
        const at = free > 0 ? Math.min(1, Math.max(0, left / free)) : 0
        el.scrollLeft = at * (el.scrollWidth - el.clientWidth)
    }

    const onPointerDown = (e: PointerEvent<HTMLDivElement>) => {
        const track = bar.current
        if (!track) return

        const rect = track.getBoundingClientRect()
        const thumbWidth = rect.width * view.share
        const thumbLeft = (rect.width - thumbWidth) * view.at
        const x = e.clientX - rect.left

        // On the thumb: hold it where it was taken. On the track: centre it there.
        grip.current = x >= thumbLeft && x <= thumbLeft + thumbWidth ? x - thumbLeft : thumbWidth / 2
        moveThumbTo(x - grip.current)
        track.setPointerCapture(e.pointerId)
    }

    const onPointerMove = (e: PointerEvent<HTMLDivElement>) => {
        const track = bar.current
        if (grip.current === null || !track) return

        moveThumbTo(e.clientX - track.getBoundingClientRect().left - grip.current)
    }

    const onPointerUp = () => {
        grip.current = null
    }

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        const el = row.current
        if (!el) return

        const keys: Record<string, () => void> = {
            ArrowLeft: () => step(-1),
            ArrowRight: () => step(1),
            PageUp: () => el.scrollBy({ left: -el.clientWidth, behavior: 'smooth' }),
            PageDown: () => el.scrollBy({ left: el.clientWidth, behavior: 'smooth' }),
            Home: () => el.scrollTo({ left: 0, behavior: 'smooth' }),
            End: () => el.scrollTo({ left: el.scrollWidth, behavior: 'smooth' }),
        }

        if (keys[e.key]) {
            e.preventDefault()
            keys[e.key]()
        }
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
                    <div
                        ref={bar}
                        role="slider"
                        tabIndex={0}
                        aria-label={t('search.lanes_slider')}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={Math.round(view.at * 100)}
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerCancel={onPointerUp}
                        onKeyDown={onKeyDown}
                        // touch-none: a drag on the bar moves the bar, not the page.
                        className="relative h-3 min-w-0 flex-1 cursor-pointer touch-none rounded-full bg-line outline-offset-4 select-none"
                    >
                        <span
                            aria-hidden
                            className="absolute inset-y-0 rounded-full bg-accent shadow-sm transition-[background-color] hover:bg-accent-dark"
                            style={{
                                width: `${view.share * 100}%`,
                                left: `${(1 - view.share) * view.at * 100}%`,
                            }}
                        />
                    </div>
                    <button type="button" onClick={() => step(1)} disabled={atEnd} aria-label={t('search.lanes_next')} className={arrow}>
                        <ToolIcon name="chevron" className="h-4 w-4 -rotate-90" />
                    </button>
                </div>
            )}
            <div
                ref={row}
                className="-mx-1 flex snap-x gap-4 overflow-x-auto px-1 pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                {children}
            </div>
        </>
    )
}
