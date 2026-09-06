import { router } from '@inertiajs/react'
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
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
 *
 * ## Why this is not just `SaveToList`
 *
 * It nearly is, and for a product from the catalogue it **is**: both pages use
 * the save picker for those, because saving a product to one of your lists is
 * exactly the errand and that control already does it well.
 *
 * A **hand-written** item is the exception, and the reason this exists. There is
 * no `group_id` behind it — somebody typed a title, maybe a link, maybe a price
 * — so there is no product to save. The row itself has to be copied, which is
 * what `ItemTransferController` does and what `SaveToList` has no way to ask
 * for.
 *
 * So: same bookmark, same menu, same "+ new list" — a different endpoint
 * underneath, for the one kind of item that needs it. If a reader can tell
 * which of the two they are looking at, something has drifted again.
 *
 * ## Why the menu is a portal
 *
 * The same reason `SaveToList`'s is, found the same way — by it being invisible.
 * The items on a list page sit in a `<ul>` with `overflow-hidden` on it, for the
 * rounded corners, so an absolutely positioned panel inside a row is clipped at
 * the edge of that box. On a long list the clipping is off-screen and nobody
 * notices; on a **short** one the menu is taller than the remaining rows and
 * most of it is simply cut away.
 *
 * Rendering into `document.body` escapes every `overflow-hidden` ancestor and
 * every stacking context at once, which no amount of z-index can do. The cost
 * is that fixed positioning does not follow the page, so it is recomputed on
 * scroll and resize and closed on neither.
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

    // Naming a new list, inside the menu. Closed again whenever the menu is.
    const [creating, setCreating] = useState(false)
    const [name, setName] = useState('')
    const box = useRef<HTMLDivElement>(null)
    const trigger = useRef<HTMLButtonElement>(null)
    const panel = useRef<HTMLDivElement>(null)

    // Where the portal puts the menu, in viewport coordinates. Null until it
    // has been measured, which is also what stops it flashing at 0,0.
    const [place, setPlace] = useState<{ top: number; left: number; maxHeight: number } | null>(null)

    /*
     * Measure from the button, and keep the panel on screen.
     *
     * The same arithmetic `SaveToList` does, and for the same two failures: a
     * control at the right edge pushes a fixed panel off the viewport, and one
     * near the bottom opens below the fold. The last row of a short list is
     * both at once, which is exactly where this was reported.
     */
    const position = useCallback(() => {
        const button = trigger.current

        if (! button) return

        const rect = button.getBoundingClientRect()
        const width = 224 // w-56
        const edge = 8

        const left = Math.min(
            Math.max(edge, rect.right - width),
            window.innerWidth - width - edge,
        )

        const below = window.innerHeight - rect.bottom - edge - 6
        const above = rect.top - edge - 6

        // Flip above the trigger when there is more room there, and cap either
        // way so somebody with twenty lists gets a panel that scrolls rather
        // than one that runs off the screen.
        const flipped = below < 200 && above > below

        setPlace({
            top: flipped ? Math.max(edge, rect.top - 6 - Math.min(above, 320)) : rect.bottom + 6,
            left,
            maxHeight: Math.max(160, Math.min(flipped ? above : below, 320)),
        })
    }, [])

    useLayoutEffect(() => {
        if (open) position()
    }, [open, position])

    /*
     * Fixed positioning does not follow the page, so it is recomputed rather
     * than left behind. `true` on the scroll listener catches scrolling inside
     * any container, not just the window.
     */
    useEffect(() => {
        if (! open) return

        window.addEventListener('scroll', position, true)
        window.addEventListener('resize', position)

        return () => {
            window.removeEventListener('scroll', position, true)
            window.removeEventListener('resize', position)
        }
    }, [open, position])

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
            // Both, because the menu is no longer inside `box`: it is rendered
            // into `document.body`, so a click on it is a click outside the
            // wrapper and would otherwise close the panel before the button
            // inside it could fire.
            if (box.current?.contains(e.target as Node)) return
            if (panel.current?.contains(e.target as Node)) return

            setOpen(false)
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

    /**
     * @param to       an existing list, or null when one is being named
     * @param newList  a list to create and copy into, as the save picker does
     */
    const copy = (to: string | null, newList?: string) => {
        setSending(true)
        router.post(
            action,
            newList === undefined ? { to } : { new_list: newList },
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
                    setCreating(false)
                    setName('')
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
                ref={trigger}
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
                {label ?? (
                    /*
                      The same bookmark `SaveToList` draws, at the same size.

                      It was a copy glyph, which made the two controls look like
                      two different offers sitting in the same corner of two
                      pages that show the same list. The verb underneath differs
                      — this copies a row, that saves a product — and the person
                      pressing it wants the identical thing either way: put this
                      on one of my lists.
                    */
                    <svg viewBox="0 0 24 24" className="h-4 w-4" aria-hidden>
                        <path
                            d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.8"
                            strokeLinejoin="round"
                        />
                    </svg>
                )}
            </button>

            {open && only === null && place !== null && createPortal(
                <div
                    ref={panel}
                    style={{
                        position: 'fixed',
                        top: place.top,
                        left: place.left,
                        maxHeight: place.maxHeight,
                    }}
                    className="z-50 w-56 overflow-y-auto rounded-card border border-line bg-card p-1 shadow-xl">
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

                    {/*
                      A list named on the spot, exactly as the save picker
                      offers.

                      Without it this menu could only file into a list that
                      already existed, so somebody with none met a panel with
                      nothing in it — and that, rather than any deliberate
                      choice, is why the shared page had to draw a different
                      control for a visitor who had not started a list yet.
                    */}
                    {creating ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault()

                                if (name.trim() === '') {
                                    return
                                }

                                copy(null, name.trim())
                            }}
                            className="p-1"
                        >
                            <input
                                autoFocus
                                required
                                maxLength={120}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={t('lists.list_name')}
                                className="w-full rounded border border-line bg-cream px-2 py-1.5 text-sm"
                            />
                            <button
                                type="submit"
                                disabled={sending}
                                className="mt-1 w-full rounded bg-accent px-2 py-1.5 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                            >
                                {t('lists.create')}
                            </button>
                        </form>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setCreating(true)}
                            className="block w-full rounded px-3 py-2 text-left text-sm text-accent hover:bg-line/40"
                        >
                            + {t('lists.new_list')}
                        </button>
                    )}
                </div>,
                document.body,
            )}
        </div>
    )
}
