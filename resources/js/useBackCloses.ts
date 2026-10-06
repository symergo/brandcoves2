import { useEffect, useRef } from 'react'

/**
 * The phone's back button closes the popup on top, instead of leaving the page.
 *
 * The owner's rule (2026-10-06): popups are full screen on a phone, and a full
 * screen is something people leave with back. Without this, back on Android
 * (or the back swipe) left the whole page with the popup still open on it.
 *
 * ## How
 *
 * Opening a popup adds one history entry (a copy of the page's own state, with
 * a marker). Back then pops that entry, and the popup closes. Closing it any
 * other way (its ×, Escape, the backdrop, a saved form) takes the entry off
 * again with `history.back()`, so the history is as it was before it opened.
 *
 * ## Why our listener runs first and stops the event
 *
 * Inertia answers every `popstate` by putting the page from the history entry
 * back, with `preserveState: false` (core's `handlePopstateEvent`): the page
 * would be mounted afresh, losing whatever was typed, and a list page opened
 * with `?panel=share` would open its popup again. A capture listener on
 * `window` runs before Inertia's ordinary one, and `stopImmediatePropagation`
 * keeps Inertia out of a back that only closed a popup.
 *
 * ## Popups on popups
 *
 * An "are you sure?" over a form is two popups and two entries. One listener
 * for all of them, with a stack: a back closes the top one only.
 *
 * Known leftover: a popup closed by a navigation to another page (a link
 * inside it) cannot take its entry off, because by then the entry is the new
 * page's; and when Inertia replaces the entry after a form saved, the marker
 * is gone with it. Each leaves one extra step back to the same page. Harmless,
 * and better than a `history.back()` that would leave the page.
 */
const KEY = 'giftcovesPopup'
const stack: { marker: string; close: () => void }[] = []
let ownBacks = 0
let listening = false

function onPopstate(event: PopStateEvent) {
    // The back we made ourselves, taking a closed popup's entry off.
    if (ownBacks > 0) {
        ownBacks--
        event.stopImmediatePropagation()

        return
    }

    const top = stack.pop()

    if (top === undefined) {
        return
    }

    event.stopImmediatePropagation()
    top.close()
}

/** `active`: for a popup that stays mounted and opens and closes (SignInDialog). */
export function useBackCloses(onClose: () => void, active = true): void {
    const close = useRef(onClose)
    close.current = onClose

    useEffect(() => {
        if (!active || typeof window === 'undefined') return

        if (!listening) {
            window.addEventListener('popstate', onPopstate, true)
            listening = true
        }

        const marker = Math.random().toString(36).slice(2)
        const entry = { marker, close: () => close.current() }

        window.history.pushState({ ...(window.history.state ?? {}), [KEY]: marker }, '')
        stack.push(entry)

        return () => {
            const at = stack.indexOf(entry)

            // Still ours: closed by its ×, Escape or a save, not by back.
            if (at !== -1) {
                stack.splice(at, 1)

                if ((window.history.state as Record<string, unknown> | null)?.[KEY] === marker) {
                    ownBacks++
                    window.history.back()
                }
            }
        }
    }, [active])
}
