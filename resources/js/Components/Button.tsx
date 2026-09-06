import { type ButtonHTMLAttributes, type ReactNode } from 'react'

/**
 * The button, once.
 *
 * Before this there were fifty-six accent buttons in forty-one distinct class
 * strings: twelve padding pairs, three radii, thirty-two with no hover and
 * fifty with no transition — so pressing around the site, some primary buttons
 * responded and some were inert. The recipe lives here and call sites say what
 * kind of button they want, not how to draw one.
 *
 * `busy` is the loading affordance the site did not have: the label dims and a
 * small spinner takes its place, so a press on a slow request reads as "working"
 * rather than "did nothing" — the state that gets a button clicked twice.
 *
 * `buttonClasses()` is exported for the anchors that look like buttons (an
 * outbound shop link, a sign-in link), which cannot be a `<button>`.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-accent text-white hover:bg-accent-dark',
    secondary: 'border border-line bg-card text-ink hover:border-ink',
    ghost: 'text-ink-soft hover:text-ink',
    danger: 'border border-line text-danger hover:border-danger',
}

const sizes: Record<ButtonSize, string> = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-3',
}

export function buttonClasses(
    variant: ButtonVariant = 'primary',
    size: ButtonSize = 'md',
    className = '',
): string {
    return [
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition',
        'disabled:cursor-not-allowed disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
    ]
        .filter(Boolean)
        .join(' ')
}

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant
    size?: ButtonSize
    /** In flight: disabled, with a spinner over a dimmed label. */
    busy?: boolean
    children: ReactNode
}

export default function Button({
    variant = 'primary',
    size = 'md',
    busy = false,
    disabled,
    className = '',
    children,
    type = 'button',
    ...rest
}: Props) {
    return (
        <button
            type={type}
            disabled={disabled || busy}
            aria-busy={busy || undefined}
            className={buttonClasses(variant, size, `relative ${className}`)}
            {...rest}
        >
            <span className={busy ? 'invisible' : undefined}>{children}</span>
            {busy && (
                <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                    <Spinner />
                </span>
            )}
        </button>
    )
}

/**
 * A ring with a gap, in the current text colour, so it sits on any variant.
 * Reduced motion gets a still ring: the spinner's presence carries the message.
 */
export function Spinner({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg
            className={`${className} animate-spin motion-reduce:animate-none`}
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2.5" opacity="0.25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
        </svg>
    )
}
