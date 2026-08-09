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
 */
let saved: Set<number> | null = null
let inflight: Promise<void> | null = null

const listeners = new Set<() => void>()

function notify(): void {
    // A new Set each time: `useSyncExternalStore` compares snapshots by
    // identity, and mutating in place would leave every subscriber convinced
    // nothing had changed.
    saved = saved === null ? null : new Set(saved)
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

export function load(marketKey: string, signedIn: boolean): void {
    if (!signedIn || saved !== null || inflight !== null) return

    inflight = fetch(`/${marketKey}/saved-items`, { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data: { groupIds: number[] }) => {
            saved = new Set(data.groupIds ?? [])
            notify()
        })
        .catch(() => {
            // A failed lookup means bookmarks stay unfilled, which is the old
            // behaviour rather than a broken page.
            saved = new Set()
            notify()
        })
        .finally(() => {
            inflight = null
        })
}

export function markSaved(groupId: number): void {
    saved ??= new Set()
    saved.add(groupId)
    notify()
}

export function markRemoved(groupId: number): void {
    if (saved === null) return

    saved.delete(groupId)
    notify()
}

/** After something changed that this store cannot infer — a deleted list. */
export function invalidate(): void {
    saved = null
    inflight = null
    listeners.forEach((fn) => fn())
}
