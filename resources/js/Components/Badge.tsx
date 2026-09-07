import { type ReactNode } from 'react'

/**
 * A small label: a discount, a kind, a count, a state.
 *
 * Sixteen distinct pill signatures existed before this, and the discount alone
 * was drawn four ways — a solid accent block over the image, a bare accent
 * suffix, an accent-wash pill and an emerald pill from a palette the site does
 * not use. The same fact should make the same visual claim wherever it turns
 * up, so the tones are named here and a call site picks one.
 *
 * `accent` is a wash, not a fill: solid accent is reserved for the one primary
 * action on a view, and a badge that shouts as loudly as the button it sits
 * beside dilutes both.
 *
 * `discount` is the one solid tone, and it is green (2026-09-07). A discount
 * is good news, and the accent wash it wore was the colour of the buy button
 * — a price cut reading as a call to action rather than as a fact. Solid
 * rather than a wash because it sits over product photographs, where a
 * translucent pill takes on whatever is behind it.
 */
export type BadgeTone = 'accent' | 'sage' | 'neutral' | 'amber' | 'discount'
export type BadgeSize = 'xs' | 'sm'

const tones: Record<BadgeTone, string> = {
    accent: 'bg-accent/10 text-accent-dark',
    sage: 'bg-sage/10 text-sage',
    neutral: 'bg-cream text-ink-soft',
    amber: 'bg-amber/15 text-ink',
    discount: 'bg-sage text-white',
}

const sizes: Record<BadgeSize, string> = {
    xs: 'px-1.5 py-px text-2xs',
    sm: 'px-2 py-0.5 text-xs',
}

export default function Badge({
    tone = 'neutral',
    size = 'sm',
    uppercase = false,
    className = '',
    children,
}: {
    tone?: BadgeTone
    size?: BadgeSize
    uppercase?: boolean
    className?: string
    children: ReactNode
}) {
    return (
        <span
            className={[
                'inline-flex items-center rounded-full font-medium whitespace-nowrap',
                uppercase ? 'uppercase tracking-wide' : '',
                tones[tone],
                sizes[size],
                className,
            ]
                .filter(Boolean)
                .join(' ')}
        >
            {children}
        </span>
    )
}
