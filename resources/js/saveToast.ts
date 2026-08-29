/**
 * What just happened to your list, said where you are looking.
 *
 * ## Why this is not the flash banner
 *
 * `FlashMessage` renders `flash.success` in the layout, in the normal flow,
 * above the page. That is right for an outcome the page itself is about — a
 * claim somebody else won, a quiz refused for being too short. It is wrong for
 * a save, for two reasons that only show up in the place saves actually happen:
 *
 * - Saving from the bottom of a results grid put the confirmation off-screen.
 *   The one line of copy written specifically to make the default destination
 *   trustworthy — "Saved to Camping" — was the line nobody could read.
 * - Being in the flow, it *inserted* a block at the top of the page while the
 *   scroll position was deliberately preserved, so the grid jumped down under
 *   the cursor at the exact moment of a successful tap.
 *
 * ## Why a store rather than component state
 *
 * The same reason `savedItems` is a store: any of forty cards on a grid can
 * raise a message, and the thing that draws it is mounted once in the layout.
 * A card that unmounts mid-request — a filter changed, a page turned — must not
 * take the confirmation with it.
 */
export interface SaveToast {
    /**
     * Identity, not order. Saving the same product to the same list twice in a
     * row produces the same text, and without a changing key the toast would
     * sit there unchanged and the second press would look like it did nothing.
     */
    key: number
    message: string
    tone: 'ok' | 'error'
    /** Present only when there is a row to take back out again. */
    undo?: { itemId: number; groupId?: number }
    /** Where the thing went, for a "View list" link. */
    listId?: string
}

let current: SaveToast | null = null
let nextKey = 1

const listeners = new Set<() => void>()

function notify(): void {
    listeners.forEach((fn) => fn())
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener)

    return () => {
        listeners.delete(listener)
    }
}

export function snapshot(): SaveToast | null {
    return current
}

/** Nothing on the server-rendered pass; toasts are always a response to a click. */
export function serverSnapshot(): SaveToast | null {
    return null
}

export function show(toast: Omit<SaveToast, 'key'>): void {
    current = { ...toast, key: nextKey++ }
    notify()
}

/**
 * @param key Dismiss only if this is still the message on screen. A timer
 *            belonging to a toast that has already been replaced must not close
 *            its successor early.
 */
export function dismiss(key?: number): void {
    if (key !== undefined && current?.key !== key) return

    current = null
    notify()
}

