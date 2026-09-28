import { useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react'

/** How far a card has to travel before a swipe counts, in pixels. */
const SWIPE_DISTANCE = 90

/**
 * One card that can be swiped: right for yes, left for no.
 *
 * Lifted out of This or that (2026-09-28), which no longer has one-card
 * rounds; Swipe gifts in Find a gift is where it lives now. A swipe is a
 * shortcut for the buttons beside it, never the only way.
 *
 * `touch-action: pan-y` keeps the page scrolling vertically under a finger;
 * only a sideways drag moves the card. The card follows the finger either
 * way (that is the finger, not an animation), and only the fly-away at the
 * end is skipped when the visitor asked for reduced motion.
 */
export default function SwipeCard({
    label,
    onVerdict,
    yesLabel,
    noLabel,
    children,
}: {
    /** The accessible name of the card: the product's title. */
    label: string
    onVerdict: (v: 'yes' | 'no') => void
    yesLabel: string
    noLabel: string
    children: ReactNode
}) {
    const [dx, setDx] = useState(0)
    const [leaving, setLeaving] = useState<'yes' | 'no' | null>(null)
    const start = useRef<number | null>(null)

    const reduced =
        typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

    const release = () => {
        const moved = dx
        start.current = null

        if (Math.abs(moved) < SWIPE_DISTANCE) {
            setDx(0)
            return
        }

        const value = moved > 0 ? 'yes' : 'no'

        if (reduced) {
            onVerdict(value)
            return
        }

        setLeaving(value)
        window.setTimeout(() => onVerdict(value), 180)
    }

    const offset = leaving === null ? dx : leaving === 'yes' ? 600 : -600

    return (
        <div
            role="group"
            aria-label={label}
            onPointerDown={(e: ReactPointerEvent<HTMLDivElement>) => {
                start.current = e.clientX
                e.currentTarget.setPointerCapture(e.pointerId)
            }}
            onPointerMove={(e) => start.current !== null && setDx(e.clientX - start.current)}
            onPointerUp={release}
            onPointerCancel={() => {
                start.current = null
                setDx(0)
            }}
            style={{
                transform: `translateX(${offset}px) rotate(${offset / 25}deg)`,
                transition: start.current !== null || reduced ? 'none' : 'transform 180ms ease-out',
                touchAction: 'pan-y',
            }}
            className="relative flex w-full max-w-sm cursor-grab flex-col rounded-card border border-line bg-card p-4 select-none active:cursor-grabbing"
        >
            {children}
            <span
                aria-hidden
                style={{ opacity: Math.min(Math.max(dx, 0) / SWIPE_DISTANCE, 1) }}
                className="absolute top-3 left-3 rounded-full bg-sage px-3 py-1 text-sm font-medium text-white"
            >
                {yesLabel}
            </span>
            <span
                aria-hidden
                style={{ opacity: Math.min(Math.max(-dx, 0) / SWIPE_DISTANCE, 1) }}
                className="absolute top-3 right-3 rounded-full bg-ink px-3 py-1 text-sm font-medium text-cream"
            >
                {noLabel}
            </span>
        </div>
    )
}
