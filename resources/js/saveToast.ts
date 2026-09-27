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
import type { ListKind } from './Components/ListKindBadge'

export interface SaveToast {
    /**
     * Identity, not order. Saving the same product to the same list twice in a
     * row produces the same text, and without a changing key the toast would
     * sit there unchanged and the second press would look like it did nothing.
     */
    key: number
    message: string
    /**
     * The message again in pieces when it names a list ("Saved to :list"), so
     * the toast draws the name as a list's name. See `ListName`.
     */
    list?: { template: string; name: string; kind: ListKind | null }
    tone: 'ok' | 'error'
    /** Present only when there is a row to take back out again. */
    undo?: { itemId: number; groupId?: number }
    /**
     * A removal that has not happened yet (`pendingRemovals.ts`): `commit`
     * sends it when the toast goes (its six seconds run out, it is closed,
     * another message replaces it, the page is left), `revert` puts the item
     * back when Undo is pressed instead. Exactly one of the two runs.
     */
    pending?: { commit: () => void; revert: () => void }
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

/**
 * The `list` of a toast, from what `POST /list-items` answers: the name, its
 * kind and the sentence with `:list` left in. Undefined when the answer came
 * without them, and the toast then shows `message` as it is.
 */
export function listFrom(result: { listTitle: string; listKind?: ListKind; messageTemplate?: string }): SaveToast['list'] {
    return result.messageTemplate === undefined
        ? undefined
        : { template: result.messageTemplate, name: result.listTitle, kind: result.listKind ?? null }
}

export function show(toast: Omit<SaveToast, 'key'>): void {
    // A removal waiting on the message being replaced goes through now.
    current?.pending?.commit()
    current = { ...toast, key: nextKey++ }
    notify()
}

/** Undo on a pending removal: put it back, and the removal never happens. */
export function revert(key: number): void {
    if (current?.key !== key || current.pending === undefined) return

    current.pending.revert()
    current = null
    notify()
}

/*
 * Leaving the page (a closed tab, a reload) commits a pending removal: the
 * request is sent with `keepalive`, which outlives the page. If it is lost
 * anyway the item is simply still on the list, the safe way to fail.
 */
if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => {
        current?.pending?.commit()

        if (current?.pending) {
            current = { ...current, pending: undefined }
        }
    })
}

/**
 * @param key Dismiss only if this is still the message on screen. A timer
 *            belonging to a toast that has already been replaced must not close
 *            its successor early.
 */
export function dismiss(key?: number): void {
    if (key !== undefined && current?.key !== key) return

    current?.pending?.commit()
    current = null
    notify()
}

