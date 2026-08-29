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
let saved: Set<number> | null = null
let active: Set<number> | null = null
let activeListId: string | null = null
let inflight: Promise<void> | null = null

const listeners = new Set<() => void>()

function notify(): void {
    // New Sets each time: `useSyncExternalStore` compares snapshots by
    // identity, and mutating in place would leave every subscriber convinced
    // nothing had changed.
    saved = saved === null ? null : new Set(saved)
    active = active === null ? null : new Set(active)
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

/** Server-rendered markup shows the unsaved state; the client corrects it. */
export function serverSnapshot(): Set<number> | null {
    return null
}

/** What is on the list currently being filled, or null when not in that mode. */
export function activeSnapshot(): Set<number> | null {
    return active
}

export function load(marketKey: string, signedIn: boolean, listId: string | null = null): void {
    if (!signedIn) return

    // Entering or leaving adding mode changes the question, so the answer is
    // refetched rather than left describing the previous list.
    if (listId !== activeListId) {
        activeListId = listId
        saved = null
        active = null
        inflight = null
    }

    if (saved !== null || inflight !== null) return

    const query = listId === null ? '' : `?list=${encodeURIComponent(listId)}`

    inflight = fetch(`/${marketKey}/saved-items${query}`, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data: { groupIds: number[]; listGroupIds?: number[] }) => {
            saved = new Set(data.groupIds ?? [])
            active = listId === null ? null : new Set(data.listGroupIds ?? [])
            notify()
        })
        .catch(() => {
            // A failed lookup means bookmarks stay unfilled, which is the old
            // behaviour rather than a broken page.
            saved = new Set()
            active = listId === null ? null : new Set()
            notify()
        })
        .finally(() => {
            inflight = null
        })
}

/**
 * @param onActiveList Whether this save landed in the list being filled. False
 *                     for a save made from the picker into some other list
 *                     while a run is in progress — which must not tick the
 *                     bookmark, because the item is still not on Camping.
 */
export function markSaved(groupId: number, onActiveList = true): void {
    saved ??= new Set()
    saved.add(groupId)

    if (active !== null && onActiveList) {
        active.add(groupId)
    }

    notify()
}

export function markRemoved(groupId: number, fromActiveList = true): void {
    if (saved !== null) {
        saved.delete(groupId)
    }

    if (active !== null && fromActiveList) {
        active.delete(groupId)
    }

    notify()
}

/** After something changed that this store cannot infer — a deleted list. */
export function invalidate(): void {
    saved = null
    active = null
    inflight = null
    listeners.forEach((fn) => fn())
}
