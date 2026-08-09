import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'

/**
 * What the server just said.
 *
 * `HandleInertiaRequests` has shared `flash` since Phase 3 and nothing ever
 * rendered it, so every `->with('success', …)` and `->with('error', …)` in the
 * codebase was written to a channel with no receiver. The visible effect is
 * worse than a missing confirmation: an action that *failed* — a claim somebody
 * else got to first, a quiz refused because the list is too short — looked
 * exactly like a button that does nothing.
 *
 * Rendered in the layout, so a controller can report an outcome from anywhere
 * without the page it redirects back to knowing anything about it.
 */
export default function FlashMessage() {
    const { flash } = usePage<SharedProps>().props
    const message = flash.error ?? flash.success ?? flash.status
    const isError = Boolean(flash.error)

    const [dismissed, setDismissed] = useState(false)

    // A new message re-opens the banner: without this, dismissing one hides the
    // next one too, and the second is usually the one that mattered.
    useEffect(() => setDismissed(false), [message])

    if (!message || dismissed) return null

    return (
        <div
            // Errors interrupt; confirmations do not. A polite live region for a
            // failed claim would be announced after whatever the user did next.
            role={isError ? 'alert' : 'status'}
            aria-live={isError ? 'assertive' : 'polite'}
            className={`mb-6 flex items-start gap-3 rounded-card border p-4 text-sm ${
                isError
                    ? 'border-accent/40 bg-accent/5 text-ink'
                    : 'border-sage/40 bg-sage/10 text-ink'
            }`}
        >
            <span className="flex-1">{message}</span>
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="×"
                className="shrink-0 text-ink-soft hover:text-ink"
            >
                ×
            </button>
        </div>
    )
}
