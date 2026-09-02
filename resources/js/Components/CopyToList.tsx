import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import ShareIcon from './ShareIcon'
import { markSaved } from '../savedItems'
import { useTranslations } from '../useTranslations'

export interface CopyTarget {
    id: string
    title: string
}

/**
 * "Put this on another list too."
 *
 * ## Why a picker rather than dragging
 *
 * Drag and drop was the obvious shape and is the wrong one. It is unusable with
 * a keyboard, invisible to a screen reader, and on a phone it fights the scroll
 * — on the device most of this happens on, a long press that sometimes scrolls
 * and sometimes lifts is worse than no feature at all. It also cannot say
 * *which* list without the destination already being on screen, which on a
 * phone it never is.
 *
 * A control on the row and a list of destinations by name. It works by touch, by
 * keyboard and by screen reader, and it names the target in words rather than
 * asking somebody to aim at it.
 *
 * ## Copy, never move
 *
 * The only verb, and deliberately: removal already exists on every row, so a
 * move would be a second way to do what the page can do in two presses — with a
 * failure mode the copy does not have. Pressed on the wrong row, a copy costs
 * one row somebody deletes; a move destroys the original. See
 * App\Services\Wishlist\ItemMover.
 *
 * The claim never travels, and that is enforced server-side rather than here:
 * carrying one would announce on another list's page that something has been
 * bought.
 */
export default function CopyToList({
    action,
    targets,
    label,
    groupId = null,
}: {
    /** `…/items/{item}/copy`, on either of the two sources. */
    action: string
    /** Lists this person may write to. The current one is filtered out by the caller. */
    targets: CopyTarget[]
    /** Overrides the default wording, for the Ask panel where "add to my list" reads better. */
    label?: string
    /**
     * The product behind the row, when there is one.
     *
     * Only used to keep the browser's saved-items cache honest — see `copy()`.
     * Null on a hand-written wish, which has no product to bookmark.
     */
    groupId?: number | null
}) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [sending, setSending] = useState(false)
    const box = useRef<HTMLDivElement>(null)

    /*
     * Close on a click away, and on Escape.
     *
     * A dropdown that closes only by picking something or pressing its own
     * trigger again is a dropdown people leave open — and this one sits at the
     * end of a row in a list of rows, so an abandoned panel covers the items
     * underneath it. `ShareMenu` learned this first; same shape here.
     */
    useEffect(() => {
        if (! open) return

        const away = (e: MouseEvent) => {
            if (! box.current?.contains(e.target as Node)) setOpen(false)
        }

        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
        }
    }, [open])

    // Nowhere to copy to is not a disabled button, it is no button: a control
    // that cannot do anything is a question the reader has to answer.
    if (targets.length === 0) {
        return null
    }

    const copy = (to: string) => {
        setSending(true)
        router.post(
            action,
            { to },
            {
                preserveScroll: true,
                /*
                 * Tell the browser's saved-items cache, exactly as `SaveToList`
                 * does after a save.
                 *
                 * That cache is what every product card on every surface reads
                 * to decide whether it is already bookmarked. Copying a product
                 * onto a list without saying so left the cache stale, so the
                 * product's own page went on reporting "not saved" for a list it
                 * was now on — the one visible symptom of writing a second
                 * saving path and forgetting the first one's bookkeeping.
                 */
                onSuccess: () => groupId != null && markSaved(groupId),
                onFinish: () => {
                    setSending(false)
                    setOpen(false)
                },
            },
        )
    }

    /*
     * One destination is not a choice, it is the action.
     *
     * The Ask panel passes exactly one — the list you asked from — and opening a
     * panel to reveal a single button asks somebody to choose between one thing.
     * Pressing "Zet op mijn lijstje" should put it on the list, which is what it
     * says.
     */
    const only = targets.length === 1 ? targets[0] : null

    return (
        <div ref={box} className="relative inline-block">
            {/*
              An icon by default, words when a caller asks for them.

              On a list row this sits beside the remove control at the end of
              every item, and a sentence there — "Kopieer naar een ander
              lijstje" — was longer than most of the product titles it was
              lined up against. Repeated down twenty rows it read as the page's
              main verb, which it is not: copying is occasional, and removing is
              the only other per-row action, already an icon.

              The Ask panel passes a `label` and keeps its words. There the
              control is the *point* of the row — "put what they asked for on my
              list" is the errand somebody opened the panel for — and it stands
              beside a text "Claim" button rather than in a column of glyphs.

              The name is spoken either way: `aria-label` and `title`, so it is a
              labelled control for a screen reader and a tooltip for everybody
              else. Same treatment the board's delete gets.
            */}
            <button
                type="button"
                onClick={() => (only ? copy(only.id) : setOpen((v) => !v))}
                // Only when it opens something. Announcing a collapsed panel on
                // a button that copies immediately describes a control that is
                // not there.
                aria-expanded={only ? undefined : open}
                aria-label={label ?? t('lists.copy_to')}
                title={label ?? t('lists.copy_to')}
                disabled={sending}
                className={
                    label === undefined
                        ? 'rounded p-2 text-ink-soft hover:text-accent disabled:opacity-50'
                        : 'text-xs text-ink-soft underline hover:text-ink disabled:opacity-50'
                }
            >
                {label ?? <ShareIcon name="copy" />}
            </button>

            {open && only === null && (
                <div className="absolute right-0 z-40 mt-1 w-56 rounded-card border border-line bg-card p-1 shadow-xl">
                    <p className="px-3 py-2 text-xs text-ink-soft">{t('lists.copy_to_which')}</p>

                    {/*
                      Plain buttons, one per list. Not a `role="menu"`: that
                      promises arrow-key navigation, and a role whose behaviour
                      is missing is worse than no role — a screen reader
                      announces a menu and then the arrow keys scroll the page.
                      Tab through them, as with any other stack of buttons.
                    */}
                    <ul>
                        {targets.map((target) => (
                            <li key={target.id}>
                                <button
                                    type="button"
                                    onClick={() => copy(target.id)}
                                    disabled={sending}
                                    className="block w-full truncate rounded px-3 py-2 text-left text-sm hover:bg-line/40 disabled:opacity-50"
                                >
                                    {target.title}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}
