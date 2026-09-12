/**
 * What is already on one of your lists.
 *
 * The save control appears on every product card on every surface — search,
 * brand pages, guides, the daily edition, the wizard — and each instance used
 * to know only about its own clicks. So a product you saved last week showed an
 * empty bookmark, and the only way to discover it was already there was to save
 * it again.
 *
 * A module-level store rather than page props, for two reasons. Passing the ids
 * through every page would mean touching seven controllers and putting a query
 * on pages that render no cards at all. And a store lets every bookmark on a
 * grid of forty update the moment one of them is clicked, which per-component
 * state cannot do.
 *
 * Fetched once per page load, lazily: nothing happens until a card actually
 * mounts, and nothing happens at all for signed-out visitors, who cannot save.
 *
 * ## Two sets, because the bookmark answers two different questions
 *
 * Ordinarily it answers *"have I kept this anywhere?"* — any list of yours
 * counts, because a thing on your research list for your mother is still a
 * thing you have already found.
 *
 * While you are filling one named list, that is the wrong question. During a
 * run the thing you need to know is *"is this one on Camping yet?"*, and
 * answering it with "well, it is on your Books list" would tick items you have
 * not added and hide the ones you have. So the active list gets its own set,
 * filled by the same request.
 */

/**
 * Which lists hold a product, for the products this person has saved.
 *
 * The same rows that answer "is this saved?" also know *where* it is saved, and
 * the picker used to go and ask a second time — `/list-options?group_id=`, per
 * product, on every open, with the wait sitting between the press and the rows.
 *
 * A list of holders per product, not one. Between 2026-08-31 and 2026-09-12 a
 * product lived on exactly one list and the picker moved it between them; the
 * owner asked for the checklist back, so a product may sit on the birthday
 * list and the Christmas list at once, and each row of the picker is a tick
 * for one list. The store therefore keeps every row that holds the product,
 * and the bookmark stays filled until the last of them is gone.
 */
export interface Holder {
    listId: string
    itemId: number
}

let saved: Set<number> | null = null
let active: Set<number> | null = null
let holding: Record<number, Holder[]> | null = null
let activeListId: string | null = null
let inflight: Promise<void> | null = null

const listeners = new Set<() => void>()

function notify(): void {
    // New references, so `useSyncExternalStore` sees a change.
    saved = saved === null ? null : new Set(saved)
    active = active === null ? null : new Set(active)
    holding = holding === null ? null : { ...holding }
    listeners.forEach((fn) => fn())
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener)

    return () => {
        listeners.delete(listener)
    }
}

export function snapshot(): Set<number> | null {
    return saved
}

export function serverSnapshot(): Set<number> | null {
    return null
}

export function holderSnapshot(): Record<number, Holder[]> | null {
    return holding
}

export function serverHolders(): Record<number, Holder[]> | null {
    return null
}

export function activeSnapshot(): Set<number> | null {
    return active
}

export function load(marketKey: string, signedIn: boolean, listId: string | null = null): void {
    if (!signedIn) return

    // A different list is being filled: what was known about the old one is
    // the wrong answer for the new one.
    if (listId !== activeListId) {
        activeListId = listId
        saved = null
        active = null
        holding = null
        inflight = null
    }

    if (saved !== null || inflight !== null) return

    const query = listId === null ? '' : `?list=${encodeURIComponent(listId)}`

    inflight = fetch(`/${marketKey}/saved-items${query}`, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data: { groupIds: number[]; listGroupIds?: number[]; holders?: Record<number, Holder[]> }) => {
            saved = new Set(data.groupIds ?? [])
            active = listId === null ? null : new Set(data.listGroupIds ?? [])
            holding = { ...(data.holders ?? {}) }
            notify()
        })
        .catch(() => {
            // Failing closed: nothing is marked saved, and a save still works.
            saved = new Set()
            active = listId === null ? null : new Set()
            holding = {}
            notify()
        })
        .finally(() => {
            inflight = null
        })
}

/**
 * The product went onto a list.
 *
 * `listId` is which one, when known — the optimistic call before a request
 * may not know yet, and passes null; the call after the response names the
 * row too, so the picker can tick it and later untick it. The active set is
 * touched only when the list is the one being filled.
 */
export function markSaved(groupId: number, listId: string | null = null, itemId: number | null = null): void {
    saved ??= new Set()
    saved.add(groupId)

    if (listId !== null && itemId !== null) {
        const rest = (holding?.[groupId] ?? []).filter((h) => h.listId !== listId)
        holding = { ...(holding ?? {}), [groupId]: [...rest, { listId, itemId }] }
    }

    if (active !== null && listId !== null && listId === activeListId) {
        active.add(groupId)
    }

    notify()
}

/**
 * The product came off a list — one list, when `listId` says which, or every
 * list when it does not (a deleted list, an undo whose list is unknown).
 *
 * The bookmark empties only when no other list still holds the product: a
 * thing taken off the Christmas list is still a thing you have kept, if it is
 * on your own list too.
 */
export function markRemoved(groupId: number, listId: string | null = null): void {
    if (holding !== null && groupId in holding) {
        const left = listId === null ? [] : holding[groupId].filter((h) => h.listId !== listId)
        holding = { ...holding }

        if (left.length === 0) {
            delete holding[groupId]
        } else {
            holding[groupId] = left
        }
    }

    const elsewhere = holding !== null && groupId in holding

    if (saved !== null && !elsewhere) {
        saved.delete(groupId)
    }

    if (active !== null && (listId === null || listId === activeListId)) {
        active.delete(groupId)
    }

    notify()
}

/** After something changed that this store cannot infer — a deleted list. */
export function invalidate(): void {
    saved = null
    active = null
    holding = null
    inflight = null
    listeners.forEach((fn) => fn())
}
