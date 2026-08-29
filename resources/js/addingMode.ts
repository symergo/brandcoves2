/**
 * How many things this run has added.
 *
 * Deliberately client-side and deliberately not persisted. The server knows how
 * many items the list holds; it does not know — and should not have to record —
 * how many of them arrived since the person pressed "Find things to add". The
 * number that answers "am I getting anywhere?" is the second one, and it is
 * worth nothing after the session that produced it.
 *
 * Reset when the mode changes rather than on navigation: Inertia keeps the
 * module alive across pages, which is exactly what lets a count survive
 * searching for something else halfway through.
 */
let count = 0
let forList: string | null = null

const listeners = new Set<() => void>()

export function subscribe(listener: () => void): () => void {
    listeners.add(listener)

    return () => {
        listeners.delete(listener)
    }
}

export function addedCount(): number {
    return count
}

/** Called by the save control after a save that landed in the active list. */
export function countAdded(listId: string): void {
    if (forList !== listId) {
        forList = listId
        count = 0
    }

    count += 1
    listeners.forEach((fn) => fn())
}

/** An Undo should not leave the tally claiming something that is no longer there. */
export function countRemoved(listId: string): void {
    if (forList !== listId || count === 0) return

    count -= 1
    listeners.forEach((fn) => fn())
}
