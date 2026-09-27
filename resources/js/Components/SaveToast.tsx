import { Link, usePage } from '@inertiajs/react'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { send } from '../http'
import { markRemoved } from '../savedItems'
import { dismiss, snapshot, serverSnapshot, subscribe } from '../saveToast'
import type { SharedProps } from '../types'
import { rich, useTranslations } from '../useTranslations'
import ListName from './ListName'
import ToolIcon from './ToolIcon'

/**
 * "Saved to Camping · Undo · View list", where you are looking.
 *
 * Mounted once in the layout; raised from any product card on the page. See
 * `saveToast.ts` for why saves stopped using the flash banner.
 *
 * ## Why Undo is here and not on the card
 *
 * Undoing a save used to mean reopening the picker and finding the ticked row —
 * which works, and requires knowing that the picker reports membership at all.
 * The mistake this recovers from is saving to the list one line above the one
 * you meant, and the moment to catch it is the second after it happened, on the
 * confirmation that just named the wrong list. Anywhere else is a search.
 *
 * It needs the row's id, which is why `store()` answers with one. A flash
 * string can name a list and cannot name a row.
 */
export default function SaveToast() {
    const toast = useSyncExternalStore(subscribe, snapshot, serverSnapshot)
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const [undoing, setUndoing] = useState(false)
    const [paused, setPaused] = useState(false)

    const key = toast?.key

    useEffect(() => {
        if (key === undefined || paused) return

        /*
         * Six seconds. Long enough to read six words and decide to press Undo;
         * short enough that it is gone before it becomes furniture. It does not
         * auto-dismiss while hovered or focused, because a countdown running
         * under a pointer that is on its way to Undo is a race the person
         * loses.
         */
        const timer = window.setTimeout(() => dismiss(key), 6000)

        return () => window.clearTimeout(timer)
    }, [key, paused])

    // A new message arrives with the previous one's pause still set, which
    // would leave it on screen for ever if the pointer had wandered off.
    useEffect(() => setPaused(false), [key])

    if (!toast) return null

    const isError = toast.tone === 'error'

    async function undo(): Promise<void> {
        if (!toast?.undo || undoing) return

        setUndoing(true)

        try {
            await send(`/${market.key}/list-items/${toast.undo.itemId}`, 'DELETE')

            /*
             * The bookmark empties only if this product is not on some *other*
             * list. `markRemoved` is unconditional, so it is used here only for
             * the item this toast created, and the picker's own remove path —
             * which re-checks every list — stays the authority elsewhere.
             */
            if (toast.undo.groupId !== undefined) {
                // Named by list (2026-09-12): the bookmark empties only when no other
                // list still holds the product, which the store now knows.
                markRemoved(toast.undo.groupId, toast.listId ?? null)
            }

            dismiss(toast.key)
        } catch {
            // Saying "that did not work" beats a button that quietly does
            // nothing, which is what the whole control used to do on an error.
            setUndoing(false)
        }
    }

    return (
        <div
            // Fixed to the viewport, not to the document: the entire point is
            // that it is visible from wherever the person was scrolled to.
            className="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4 sm:justify-start sm:px-6"
        >
            <div
                role={isError ? 'alert' : 'status'}
                aria-live={isError ? 'assertive' : 'polite'}
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
                onFocus={() => setPaused(true)}
                onBlur={() => setPaused(false)}
                className={`pointer-events-auto flex max-w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-card border px-4 py-3 text-sm shadow-xl ${
                    isError ? 'border-accent/40 bg-card text-ink' : 'border-line bg-card text-ink'
                }`}
            >
                {/* The sentence gets its own line on a phone, with the buttons
                    under it, rather than being cut to fit beside them: there
                    the buttons left room for "Saved to …" and no more, and the
                    list's name (held to its icon, see ListName) is the part that
                    was cut, the one part of the sentence that is news. */}
                <span className="line-clamp-2 min-w-0 basis-full sm:basis-auto sm:flex-1">
                    {toast.list
                        ? rich(toast.list.template, { list: <ListName name={toast.list.name} kind={toast.list.kind} /> })
                        : toast.message}
                </span>

                {toast.undo && (
                    <button
                        type="button"
                        onClick={() => void undo()}
                        disabled={undoing}
                        className="shrink-0 font-medium text-accent-dark hover:text-ink disabled:opacity-50"
                    >
                        {t('lists.undo')}
                    </button>
                )}

                {toast.listId && (
                    <Link
                        href={`/${market.key}/lists/${toast.listId}`}
                        className="shrink-0 font-medium text-ink-soft hover:text-ink"
                    >
                        {t('lists.view_list')}
                    </Link>
                )}

                <button
                    type="button"
                    onClick={() => dismiss(toast.key)}
                    aria-label={t('nav.close')}
                    className="-my-1 -mr-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-ink-soft hover:text-ink"
                >
                    <ToolIcon name="close" className="h-4 w-4 shrink-0" />
                </button>
            </div>
        </div>
    )
}
