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
export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'destructive' | 'dark' | 'light'
export type ButtonSize = 'sm' | 'md' | 'lg'

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-accent text-white hover:bg-accent-dark',
    secondary: 'border border-line bg-card text-ink hover:border-ink',
    ghost: 'text-ink-soft hover:text-ink',
    danger: 'border border-line text-danger hover:border-danger',
    /*
     * Filled red: only the button that carries out a deletion in a
     * confirmation (`ConfirmDialog`), where it is the one action of the popup.
     * Anywhere else a destructive action is `danger`, outlined, so it never
     * outshouts the page's primary action (2026-09-27).
     */
    destructive: 'bg-danger text-white hover:opacity-90',
    /*
     * The primary action on the coral hero band (Koraal, 2026-10-04): a
     * brick-red button on coral all but disappears, so on the band it is ink.
     */
    dark: 'bg-ink text-white hover:bg-ink/85',
    /*
     * The primary action on the red hero band (Rood + oranje, 2026-10-05):
     * a red button on a red band disappears, so there it is white with red
     * type. `accent-dark` on white is 6.6:1.
     */
    light: 'bg-white text-accent-dark hover:bg-white/90',
}

/*
 * 44px tall below `sm`, whatever the size: the floor `app.css` gives fields
 * on a phone, applied to the thing a finger presses most. The desktop keeps
 * the compact heights — 38px at `md` is right beside a 14px label there.
 */
const sizes: Record<ButtonSize, string> = {
    sm: 'min-h-11 px-3 py-1.5 text-sm sm:min-h-0',
    md: 'min-h-11 px-4 py-2 text-sm sm:min-h-0',
    lg: 'min-h-11 px-5 py-3 sm:min-h-0',
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

/**
 * A row's action on a list of rows (Mijn Coves, Mijn mensen): outlined, the
 * words beside the icon on a wide screen and the icon alone on a phone (owner,
 * 2026-09-27: "on mobile, replace the buttons with icons"). Callers keep the
 * words for screen readers with `aria-label`, and wrap them in
 * `hidden sm:inline`.
 *
 * Never filled: on a page of twenty rows a filled button on each is twenty
 * primary actions, and the page's own one in the header ("Maak een Cove",
 * "Iemand toevoegen") stops standing out (2026-09-27 review).
 *
 * 36px square on a phone rather than Button's 44px: two or three of these sit
 * in a row's corner beside the name, and the row itself is the larger target.
 */
export function rowActionClasses(className = ''): string {
    return [
        'inline-flex h-9 min-w-9 items-center justify-center gap-1.5 rounded-lg border border-line bg-card px-2 text-sm text-ink transition',
        'hover:border-ink sm:h-auto sm:min-w-0 sm:px-3 sm:py-1.5',
        className,
    ]
        .filter(Boolean)
        .join(' ')
}

/**
 * A text field, select or textarea in a form: the one input recipe the
 * people, person and Santa forms repeated by hand (2026-09-27). `block
 * w-full` is the default because almost every field fills its label;
 * `inline` drops it for a field that sits in a row.
 */
export function fieldClasses(className = '', { inline = false }: { inline?: boolean } = {}): string {
    return [
        'rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal',
        inline ? '' : 'mt-1 block w-full',
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
