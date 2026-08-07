import type { CurrentMarket } from '../types'
import { formatPrice } from '../types'

/**
 * Daily low across all shops, over 90 days.
 *
 * Inline SVG rather than a charting library: this is one path and two labels,
 * and pulling in 40 KB of chart code for it would cost more than it renders.
 *
 * The line is the *minimum* across offers, because the question a price chart
 * answers is "what would this have cost me", not "what did one particular shop
 * charge".
 */
export default function Sparkline({
    points,
    market,
}: {
    points: { date: string; price: number }[]
    market: CurrentMarket
}) {
    if (points.length < 2) return null

    const width = 480
    const height = 90
    const pad = 4

    const prices = points.map((p) => p.price)
    const lo = Math.min(...prices)
    const hi = Math.max(...prices)
    // A flat price would divide by zero and collapse the line onto an edge.
    const span = hi - lo || 1

    const coords = points.map((p, i) => {
        const x = pad + (i / (points.length - 1)) * (width - pad * 2)
        const y = pad + (1 - (p.price - lo) / span) * (height - pad * 2)
        return `${x.toFixed(1)},${y.toFixed(1)}`
    })

    const first = points[0]
    const last = points[points.length - 1]
    const fell = last.price < first.price

    return (
        <figure className="rounded-card border border-line bg-card p-4">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-24 w-full"
                role="img"
                aria-label={`${formatPrice(lo, market)} – ${formatPrice(hi, market)}`}
                preserveAspectRatio="none"
            >
                {/* Fill first so the stroke sits on top of it. */}
                <polygon
                    points={`${pad},${height - pad} ${coords.join(' ')} ${width - pad},${height - pad}`}
                    fill={fell ? 'var(--color-sage)' : 'var(--color-accent)'}
                    opacity="0.08"
                />
                <polyline
                    points={coords.join(' ')}
                    fill="none"
                    stroke={fell ? 'var(--color-sage)' : 'var(--color-accent)'}
                    strokeWidth="2"
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    vectorEffect="non-scaling-stroke"
                />
            </svg>

            <figcaption className="mt-2 flex justify-between text-xs text-ink-soft">
                <span>{formatPrice(lo, market)}</span>
                <span>{formatPrice(hi, market)}</span>
            </figcaption>
        </figure>
    )
}
