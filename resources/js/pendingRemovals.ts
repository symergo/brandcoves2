/**
 * Taking an item off your own list, with Undo.
 *
 * ## Why the request waits (consistency review, round 2, 2026-09-27)
 *
 * Saving has had Undo on its toast since the toast existed; removing had
 * nothing, and a removal is the one of the two that loses something: the note
 * on the item, and on a shared list the claims and votes people made on it.
 * The server deletes the row outright, so an Undo that re-adds the product
 * would bring back a different row with none of that. Rather than teach the
 * server a soft delete, the removal waits: the item leaves the page at once,
 * the toast says "Van Camping gehaald · Ongedaan maken", and the DELETE is
 * sent only when the toast goes. Undo within those six seconds means the
 * server never heard of it.
 *
 * Failing safe: if the request never arrives (the tab was killed before
 * `pagehide`), the item is still on the list, which is the direction to be
 * wrong in. If it is refused, the item comes back and an error toast says so.
 */
import { router } from '@inertiajs/react'
import { useSyncExternalStore } from 'react'
import { csrfToken } from './http'
import { markRemoved } from './savedItems'
import { show } from './saveToast'

let hidden: ReadonlySet<number> = new Set()
const listeners = new Set<() => void>()

function set(next: ReadonlySet<number>): void {
    hidden = next
    listeners.forEach((fn) => fn())
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener)

    return () => {
        listeners.delete(listener)
    }
}

const empty: ReadonlySet<number> = new Set()

/** The item ids removed on this page and not (yet) put back: leave them out. */
export function useHiddenItems(): ReadonlySet<number> {
    return useSyncExternalStore(
        subscribe,
        () => hidden,
        () => empty,
    )
}

export function removeWithUndo({
    base,
    listId,
    listTitle,
    itemId,
    groupId,
    t,
}: {
    base: string
    listId: string
    listTitle: string
    itemId: number
    groupId: number | null
    t: (key: string, replacements?: Record<string, string | number>) => string
}): void {
    set(new Set([...hidden, itemId]))

    const putBack = () => {
        const next = new Set(hidden)
        next.delete(itemId)
        set(next)
    }

    let settled = false
    const template = t('lists.removed_from')

    show({
        message: template.replaceAll(':list', listTitle),
        list: { template, name: listTitle, kind: null },
        tone: 'ok',
        pending: {
            commit: () => {
                if (settled) return
                settled = true

                // `keepalive`, so the request outlives a page being closed.
                fetch(`${base}/list-items/${itemId}`, {
                    method: 'DELETE',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                })
                    .then((response) => {
                        if (!response.ok) throw new Error(String(response.status))

                        if (groupId !== null) markRemoved(groupId, listId)

                        // Still on that list: bring the counts on the page up to date.
                        if (window.location.pathname === `${base}/lists/${listId}`) {
                            router.reload()
                        }
                    })
                    .catch(() => {
                        putBack()
                        show({ message: t('lists.remove_failed'), tone: 'error' })
                    })
            },
            revert: () => {
                if (settled) return
                settled = true
                putBack()
            },
        },
    })
}
