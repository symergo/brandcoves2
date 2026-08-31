import { router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { countAdded, countRemoved } from '../addingMode'
import { HttpError, send } from '../http'
import {
    forget as forgetLastList,
    remember as rememberLastList,
    serverSnapshot as lastListOnServer,
    snapshot as lastListFor,
    subscribe as subscribeLastList,
} from '../lastList'
import {
    activeSnapshot,
    holderSnapshot,
    load,
    serverHolders,
    markRemoved,
    markSaved,
    snapshot,
    serverSnapshot,
    subscribe,
} from '../savedItems'
import { show as showToast } from '../saveToast'
import { useSignIn } from '../signIn'
import type { ListOption, SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface SaveResult {
    itemId: number
    listId: string
    listTitle: string
    message: string
}

/** Where the panel goes, and which way up it is. */
interface Placement {
    top: number
    left: number
    maxHeight: number
    /** Anchored by its bottom edge, because there was no room below the trigger. */
    flipped: boolean
}

const PANEL_WIDTH = 288
const EDGE = 8

/**
 * Save a product — to a list for yourself, or to one about somebody else.
 *
 * ## The save no longer navigates
 *
 * This used to `router.post`, which rebuilt the whole page: a forty-result
 * search re-run on the server to move one row, and the confirmation delivered
 * through `flash` — drawn by `FlashMessage` at the top of the document, where
 * somebody saving from the bottom of a grid could not see it, and where
 * inserting it shoved the grid down under their cursor at the moment of a
 * successful tap.
 *
 * It now posts directly and reports through `SaveToast`. That is also what
 * makes Undo possible at all: the endpoint answers with the row's id, and a
 * flash string can name a list but never a row.
 *
 * ## Why the menu is a portal
 *
 * A product card is `overflow-hidden` (rounded corners, and the image scales on
 * hover), so an absolutely positioned panel inside it is simply clipped away —
 * the menu opened correctly and was invisible. Rendering it into `document.body`
 * escapes every `overflow-hidden` ancestor and every stacking context at once,
 * which no amount of z-index on the panel can do.
 *
 * ## One rule for both variants
 *
 * Not saved → save. Saved → open the picker, which is the only place it can be
 * taken off again.
 *
 * The card variant used to open the picker in both cases, on the stated grounds
 * that saving somewhere unnamed and making people undo it is worse than asking
 * first. The confirmation now *names* the list and carries an Undo, which
 * removes the premise. Two behaviours from one component was the greater cost:
 * the same bookmark meaning different things on a grid and on a product page is
 * not something anybody learns, it is something they get wrong.
 */
export default function SaveToList({
    groupId,
    source,
    externalId,
    title,
    imageUrl,
    price,
    compact = false,
}: {
    groupId?: number
    source?: string
    externalId?: string
    title?: string
    imageUrl?: string | null
    price?: number | null
    compact?: boolean
}) {
    const { market, auth, savingTo, lists } = usePage<SharedProps>().props
    const signIn = useSignIn()
    const { t } = useTranslations()

    const [busy, setBusy] = useState(false)

    /*
     * Shared across every card on the page.
     *
     * Local state only knew about its own clicks, so anything saved on a
     * previous visit rendered as unsaved — the control lied about the one thing
     * it exists to report.
     */
    const savedIds = useSyncExternalStore(subscribe, snapshot, serverSnapshot)
    const activeIds = useSyncExternalStore(subscribe, activeSnapshot, serverSnapshot)

    /*
     * Which list holds each saved product — the other half of the same fetch,
     * and the reason this panel opens without asking the server anything.
     */
    const holders = useSyncExternalStore(subscribe, holderSnapshot, serverHolders)

    /*
     * While a list is being filled, the bookmark reports membership of *that*
     * list. "It is on one of your lists somewhere" is the right answer to the
     * question somebody browsing has, and the wrong answer to the question
     * somebody filling Camping has.
     */
    const relevant = savingTo ? activeIds : savedIds
    const saved = groupId !== undefined && relevant !== null && relevant.has(groupId)

    /*
     * Where a bookmark press lands, when nothing else has said.
     *
     * See `lastList.ts`. Read through `useSyncExternalStore` for the same
     * reason `savedItems` is: a save on one card has to update the label on the
     * other thirty-nine, and per-component state cannot do that. Null in the
     * server pass, so the first client paint matches the markup it hydrates.
     */
    const userId = auth.user?.id ?? null
    const lastList = useSyncExternalStore(
        subscribeLastList,
        useCallback(() => lastListFor(userId), [userId]),
        lastListOnServer,
    )

    const [open, setOpen] = useState(false)
    const [creating, setCreating] = useState<null | 'mine' | 'for_someone' | 'group'>(null)
    const [name, setName] = useState('')
    const [place, setPlace] = useState<Placement | null>(null)
    const [sheet, setSheet] = useState(false)

    const trigger = useRef<HTMLButtonElement>(null)
    const menu = useRef<HTMLDivElement>(null)

    // The product, however it is identified. A live bol result and an Amazon
    // product have no stored group; the server decides what may be kept.
    const payload = groupId
        ? { group_id: groupId }
        : { source, external_id: externalId, title, image_url: imageUrl, price }

    /*
     * Named, in the order the save itself resolves: the list being filled, then
     * the one you used last, then "a list" when neither is known. A bookmark
     * that files things somewhere without saying where would be worse than the
     * default it replaces, and this label plus the toast are where it says.
     */
    const destination = savingTo
        ? t('lists.save_to', { list: savingTo.title })
        : lastList
          ? t('lists.save_to', { list: lastList.title })
          : t('lists.save_to_list')

    /*
     * A phone gets a sheet, not a popover.
     *
     * A 288px panel anchored to a 36px button is a desktop shape. On a phone
     * every card is at both edges of the viewport at once, the panel is most of
     * the screen anyway, and anchoring it just makes it land somewhere
     * arbitrary. Matched once and on change rather than read per render, and
     * defaulted to false so the server-rendered pass agrees with the first
     * client paint.
     */
    useEffect(() => {
        const query = window.matchMedia('(max-width: 639px)')
        const apply = () => setSheet(query.matches)

        apply()
        query.addEventListener('change', apply)

        return () => query.removeEventListener('change', apply)
    }, [])

    const position = useCallback(() => {
        const button = trigger.current

        if (!button || sheet) return

        const box = button.getBoundingClientRect()

        // Keep it on screen: a card at the right edge of the grid would push the
        // panel off the viewport, and on a phone every card is at both edges.
        const left = Math.min(
            Math.max(EDGE, box.right - PANEL_WIDTH),
            window.innerWidth - PANEL_WIDTH - EDGE,
        )

        /*
         * And vertically, which it never did.
         *
         * `top` was `box.bottom + 6` with no bound at all, so a card in the
         * bottom row of a grid — an entirely ordinary place to save from —
         * opened a three-section panel below the fold, where it could be
         * neither read nor reached. It now flips above the trigger when there
         * is more room there, and is capped either way so somebody with twenty
         * lists gets a panel that scrolls rather than one that runs off the
         * screen.
         */
        const below = window.innerHeight - box.bottom - EDGE - 6
        const above = box.top - EDGE - 6
        const flipped = below < 240 && above > below

        setPlace({
            top: flipped ? box.top - 6 : box.bottom + 6,
            left,
            maxHeight: Math.max(160, flipped ? above : below),
            flipped,
        })
    }, [sheet])

    useLayoutEffect(() => {
        if (open) position()
    }, [open, position])

    // Lazily, and never for a visitor who cannot save anything.
    useEffect(() => {
        load(market.key, Boolean(auth.user), savingTo?.id ?? null)
    }, [market.key, auth.user, savingTo?.id])

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            const target = e.target as Node
            if (menu.current?.contains(target) || trigger.current?.contains(target)) return
            setOpen(false)
        }
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)
        // Fixed positioning does not follow the page, so it is repositioned
        // rather than left floating over the wrong card.
        window.addEventListener('scroll', position, true)
        window.addEventListener('resize', position)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
            window.removeEventListener('scroll', position, true)
            window.removeEventListener('resize', position)
        }
    }, [open, position])

    /*
     * Sign in first — but not empty-handed, and without leaving the product.
     *
     * Enforced on the route too; done here as well so the visitor gets asked
     * rather than meeting a silent 302 swallowed by an XHR. It opens the dialog
     * over the page they are on: the thing they wanted to save is on that page,
     * and a navigation to the login form takes it away at the exact moment they
     * were reaching for it.
     *
     * The intent is still stashed server-side first, and still matters — a
     * magic link goes out by email, so the round trip happens in another tab or
     * another hour, and `PendingSave` is what finishes the save when they come
     * back. The dialog shortens the journey; it does not remove it. See
     * App\Services\Wishlist\PendingSave.
     */
    async function requireAccount(): Promise<boolean> {
        if (auth.user) return true

        try {
            await send(`/${market.key}/save-intent`, 'POST', {
                ...payload,
                return_to: window.location.pathname + window.location.search,
            })
        } catch {
            // Losing the intent makes for a worse sign-in, not a broken one.
        }

        signIn.open(t('lists.sign_in_hint'))

        return false
    }

    function openPicker(): void {
        void requireAccount().then((ok) => ok && setOpen((v) => !v))
    }

    /**
     * @param close Whether to dismiss the picker afterwards. A row is a toggle,
     *              so it stays open and shows the tick it just earned; naming a
     *              new list is a completed errand, so that one closes.
     */
    /**
     * @returns whether the item is now on the list this call aimed at. `move()`
     *          deletes the old row only on a `true`, because the alternative —
     *          a failed save followed by a successful delete — takes the
     *          product off every list in response to a press that meant "put it
     *          over there".
     */
    async function save(extra: Record<string, unknown> = {}, close = true): Promise<boolean> {
        if (busy || !(await requireAccount())) return false

        setBusy(true)

        /*
         * Filled before the request, not after it.
         *
         * A bookmark that waits for a round trip reads as a button that did
         * nothing. The rollback below is what keeps that honest when the
         * request does not in fact succeed.
         */
        /*
         * Nobody named a list, so one is guessed: adding mode if it is on,
         * otherwise wherever the last save went. An explicit `wishlist_id` or a
         * `new_list` is not a guess and is left alone.
         */
        const unqualified = extra.wishlist_id === undefined && extra.new_list === undefined
        const guess = !unqualified ? undefined : (savingTo?.id ?? lastList?.id)
        const chosen = (extra.wishlist_id as string | undefined) ?? guess
        const ontoActive = !savingTo || chosen === savingTo.id

        if (groupId !== undefined) {
            markSaved(groupId, ontoActive)
        }

        try {
            const body = (target?: string) => ({
                ...payload,
                ...extra,
                ...(target === undefined ? {} : { wishlist_id: target }),
            })

            let result: SaveResult

            try {
                result = await send<SaveResult>(`/${market.key}/list-items`, 'POST', body(guess))
            } catch (error) {
                /*
                 * The remembered list has been deleted. That is a 404 on a
                 * request the reader did not know was being made, so it is
                 * ours to recover from: forget it and let the save land in the
                 * default list, which is where it would have gone anyway
                 * before any of this existed.
                 *
                 * Only for a guess. A list the reader picked by name is a
                 * different failure and must be reported.
                 */
                if (
                    guess !== undefined &&
                    guess === lastList?.id &&
                    guess !== savingTo?.id &&
                    error instanceof HttpError &&
                    // Gone (404), or no longer ours to write to — a collaborator
                    // demoted to viewer keeps the list in `ListAccess::scope()`
                    // and gets a 403 from `canEdit`, which would otherwise stick
                    // to this browser until they saved somewhere by hand.
                    (error.status === 404 || error.status === 403)
                ) {
                    forgetLastList(userId)
                    result = await send<SaveResult>(`/${market.key}/list-items`, 'POST', body())
                } else {
                    throw error
                }
            }

            // Whatever it landed in — picked, guessed or just created — is what
            // the next unqualified save aims at.
            rememberLastList(userId, { id: result.listId, title: result.listTitle })

            /*
             * And where the product now is, which is what moves the marker in
             * an open picker.
             *
             * The optimistic `markSaved` above runs before the request and can
             * only say *that* it is saved; the row it landed in is not known
             * until the response names it. Without this second call the panel
             * kept marking the list the product was on before the press, and
             * picking a different one did nothing you could see.
             */
            if (groupId !== undefined) {
                markSaved(groupId, ontoActive, { listId: result.listId, itemId: result.itemId })
            }

            setCreating(null)
            setName('')

            if (savingTo && result.listId === savingTo.id) {
                countAdded(result.listId)
            }

            showToast({
                message: result.message,
                tone: 'ok',
                undo: { itemId: result.itemId, groupId },
                listId: result.listId,
            })

            if (close) {
                setOpen(false)
            }

            /*
             * A list this page has never heard of.
             *
             * The rows come from the shared `lists` prop, which was serialised
             * before this list existed — so a list named here would be missing
             * from the next picker on the same page. One partial reload of that
             * one prop, only on the rare press that creates a list, rather than
             * a fetch on every open to cover it.
             */
            if (extra.new_list !== undefined) {
                router.reload({ only: ['lists'] })
            }

            return true
        } catch (error) {
            if (groupId !== undefined) {
                markRemoved(groupId, ontoActive)
            }

            /*
             * Said out loud. Both `save` and `remove` had an `onSuccess` and no
             * error branch at all, so a 403 or a validation failure looked
             * exactly like a control that does not work — and this is a control
             * people press at the moment they have decided something.
             */
            showToast({
                message:
                    error instanceof HttpError && error.status === 422
                        ? error.message
                        : t('lists.save_failed'),
                tone: 'error',
            })

            return false
        } finally {
            setBusy(false)
        }
    }

    /**
     * One list at a time: put it here, and take it off wherever it was.
     *
     * The picker was a checklist, and a product could sit on four lists at once.
     * That answered "where have I kept this?" and asked the wrong question of
     * the person using it — the reason to open this menu is almost always *this
     * one, not that one*, and expressing a move as an add plus a hunt for the
     * old row is how a product ends up on two lists neither of which is the one
     * you meant.
     *
     * Sequential rather than parallel, and the delete comes second. The window
     * where the product is on both lists is the safe order to fail in; the other
     * one loses it entirely.
     */
    async function move(
        extra: Record<string, unknown>,
        close = false,
        keep?: string,
    ): Promise<void> {
        // One holder, so one delete — read before the save, which changes it.
        const previous = held !== null && held.listId !== keep ? held : null

        if (!(await save(extra, close))) return

        if (previous !== null) {
            await remove(previous.itemId, previous.listId, true)
        }
    }

    /**
     * Take it off a list, from the same row that put it there.
     *
     * The menu stays open: removing from the wrong list is the mistake this
     * whole path exists to make recoverable, and closing the menu would make it
     * unrecoverable in the same click.
     *
     * @param kept `true` when this delete is the second half of a move, so the
     *             product is still on the list it went to. Without it the
     *             bookmark would go hollow in the middle of putting something
     *             somewhere.
     *
     * This used to refetch every list and recompute membership across all of
     * them. One list holds a product now, so removing from it means it is on
     * none — an answer already in hand, and not worth a round trip.
     */
    async function remove(itemId: number, listId: string, kept = false): Promise<void> {
        if (busy) return

        setBusy(true)

        try {
            await send(`/${market.key}/list-items/${itemId}`, 'DELETE')

            if (savingTo?.id === listId) {
                countRemoved(listId)
            }

            if (groupId !== undefined) {
                if (!kept) {
                    markRemoved(groupId, true)
                } else if (savingTo?.id === listId) {
                    // `false` for the holder: the move has already written the
                    // new one, and forgetting it here would blank the marker in
                    // the open picker mid-move.
                    // Moved off the list being filled and onto another one: the
                    // bookmark answers "is it on Camping?" during a run, so it
                    // goes hollow for that question and stays filled for the
                    // general one.
                    markRemoved(groupId, true, false)
                    markSaved(groupId, false)
                }
            }

        } catch {
            showToast({ message: t('lists.save_failed'), tone: 'error' })
        } finally {
            setBusy(false)
        }
    }

    /*
     * The one list holding it, if any.
     *
     * Singular by design — see `move()`. It decides three things: whether the
     * other rows read "save" or "move", which row is marked as the current
     * answer, and whether the menu offers a way off the lists at all.
     *
     * Null until `savedItems` has answered, and null forever for a product with
     * no group of its own — a live bol result, an Amazon product — which is the
     * same as it was: nothing to match on, so nothing is claimed.
     */
    const held = groupId === undefined ? null : (holders?.[groupId] ?? null)
    const holder = held === null ? null : (lists.find((l) => l.id === held.listId) ?? null)

    const mine = lists.filter((l) => l.kind === 'mine')
    const forOthers = lists.filter((l) => l.kind === 'for_someone')
    const groups = lists.filter((l) => l.kind === 'group')

    /**
     * A row is an option, not a selection.
     *
     * It has been both. As a **checklist** it let a product sit on four lists at
     * once, which asked the wrong question — the reason to open this menu is
     * almost always *this one, not that one*. As a **radio group** it stopped
     * that, but it still put a control box in front of every list and made the
     * chosen row mean something different from all the others: press it and the
     * product left every list, from a widget whose whole grammar says it selects.
     *
     * So: no boxes. Every row is one option — *put it here* — and pressing any
     * of them moves it. Where it currently is, is reported rather than offered:
     * marked, `aria-current`, and inert, because "put it where it already is"
     * is not an action. Taking it off the lists altogether is its own named
     * option at the foot of the menu, where a destructive thing belongs.
     */
    function row(list: ListOption, label: string) {
        const on = held?.listId === list.id

        return (
            <button
                key={list.id}
                type="button"
                role="menuitem"
                aria-current={on ? 'true' : undefined}
                disabled={busy || on}
                onClick={() => void move({ wishlist_id: list.id }, false, list.id)}
                title={
                    on
                        ? t('lists.saved')
                        : holder
                          ? t('lists.move_to', { list: label })
                          : t('lists.save_to', { list: label })
                }
                className={`flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm ${
                    on
                        ? 'border border-sage bg-sage/15 font-semibold text-sage'
                        : 'border border-transparent hover:bg-line/40 disabled:opacity-60'
                }`}
            >
                <span className="min-w-0 flex-1 truncate">{label}</span>
                {/*
                  Where it is, said loudly.

                  A grey tick at the trailing edge of one row in a list of eight
                  is not a state anybody reads — it was the same size and weight
                  as the text beside it. The row itself carries the answer now:
                  a sage tint, a sage border and a filled disc, so the one line
                  that is different looks different from across the panel. Still
                  at the trailing edge and still not a box, because it reports
                  rather than offers.
                */}
                {on && (
                    <span
                        aria-hidden
                        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-sage text-xs font-bold text-white"
                    >
                        ✓
                    </span>
                )}
            </button>
        )
    }

    /*
     * No loading state, and no failure state, because there is nothing to wait
     * for: the rows arrived with the page. What went with them is the retry
     * button and `lists.options_failed`, which existed because a dropped fetch
     * would otherwise have been indistinguishable from "you have no lists" —
     * and the second of those invites somebody to duplicate a list they own.
     */
    const body = creating ? (
            <form
                className="p-2"
                onSubmit={(e) => {
                    e.preventDefault()
                    /*
                     * Both "for someone" shapes name a person and title the list
                     * after them; a group list adds `together`, which is the
                     * single bit that separates the two on the server.
                     *
                     * Through `move` rather than `save`: naming a new list for
                     * this product is the same intention as picking an existing
                     * one, and leaving the old row behind here would be the one
                     * way back to two lists holding it.
                     */
                    void move(
                        creating === 'mine'
                            ? { new_list: name }
                            : {
                                  new_list: t('lists.for_person', { name }),
                                  new_recipient: name,
                                  together: creating === 'group',
                              },
                        true,
                    )
                }}
            >
                <label className="block text-xs font-medium">
                    {creating === 'mine' ? t('lists.list_name') : t('lists.person_name')}
                </label>
                <input
                    autoFocus
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    maxLength={80}
                    className="mt-1 w-full rounded border border-line px-2 py-1.5 text-sm"
                />
                <div className="mt-2 flex gap-2">
                    <button
                        type="submit"
                        disabled={busy}
                        className="rounded bg-accent px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60"
                    >
                        {t('lists.save')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setCreating(null)}
                        className="rounded border border-line px-3 py-1.5 text-xs"
                    >
                        {t('lists.cancel')}
                    </button>
                </div>
            </form>
        ) : (
            <>
                {/*
                  The way off the lists, first.

                  It used to be the marked row itself: press the thing that says
                  "it is on Camping" and it stops being on Camping. That is fine
                  in a checklist and wrong in a menu of options, where every
                  other row puts the product somewhere and one of them silently
                  did the opposite.

                  At the top rather than the foot, because it is the answer to a
                  question you arrive with — "get this off my list" — and a
                  panel of eight lists would otherwise make you read all of them
                  to find out it was possible. One word: which list it leaves is
                  the marked one, directly below, and the tooltip names it.
                */}
                {holder && held && (
                    <>
                        <button
                            type="button"
                            role="menuitem"
                            disabled={busy}
                            onClick={() => void remove(held.itemId, holder.id)}
                            title={t('lists.remove_from', {
                                list: holder.kind === 'mine' ? holder.title : (holder.recipient ?? holder.title),
                            })}
                            className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-ink-soft hover:bg-line/40 hover:text-accent disabled:opacity-60"
                        >
                            <span aria-hidden className="text-xs">
                                ✕
                            </span>
                            {t('lists.remove')}
                        </button>
                        <div className="my-1 border-t border-line" />
                    </>
                )}

                <p className="px-2 pt-1 pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase">
                    {t('lists.for_me')}
                </p>
                {mine.map((l) => row(l, l.title))}
                <button
                    type="button"
                    onClick={() => setCreating('mine')}
                    className="block w-full rounded px-2 py-1.5 text-left text-sm text-accent hover:bg-line/40"
                >
                    + {t('lists.new_list')}
                </button>

                <p className="mt-2 border-t border-line px-2 pt-2 pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase">
                    {t('lists.for_someone_else')}
                </p>
                {forOthers.map((l) => row(l, l.recipient ?? l.title))}
                <button
                    type="button"
                    onClick={() => setCreating('for_someone')}
                    className="block w-full rounded px-2 py-1.5 text-left text-sm text-accent hover:bg-line/40"
                >
                    + {t('lists.add_person')}
                </button>

                {/*
                  A third section, because a group gift is a third answer to
                  "who is this for?" — several of us, for one person. Its own
                  heading rather than a badge inside "for someone else": the two
                  carry different mechanisms, and a shortlist you are all
                  putting money into is not private research.
                */}
                <p className="mt-2 border-t border-line px-2 pt-2 pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase">
                    {t('lists.group_gift')}
                </p>
                {groups.map((l) => row(l, l.recipient ?? l.title))}
                <button
                    type="button"
                    onClick={() => setCreating('group')}
                    className="block w-full rounded px-2 py-1.5 text-left text-sm text-accent hover:bg-line/40"
                >
                    + {t('lists.start_group_gift')}
                </button>
            </>
        )

    const panel = !open ? null : sheet ? (
        createPortal(
            <div className="fixed inset-0 z-50 flex items-end" role="dialog" aria-modal="true">
                <button
                    type="button"
                    aria-label={t('lists.cancel')}
                    onClick={() => setOpen(false)}
                    className="absolute inset-0 bg-ink/40"
                />
                <div
                    ref={menu}
                    role="menu"
                    className="relative max-h-[75vh] w-full overflow-y-auto rounded-t-card border-t border-line bg-card p-2 pb-6 text-left shadow-xl"
                >
                    {body}
                </div>
            </div>,
            document.body,
        )
    ) : place ? (
        createPortal(
            <div
                ref={menu}
                role="menu"
                style={{
                    position: 'fixed',
                    left: place.left,
                    width: PANEL_WIDTH,
                    maxHeight: place.maxHeight,
                    // Anchored by whichever edge meets the trigger, so a flipped
                    // panel grows upwards instead of covering the button.
                    ...(place.flipped
                        ? { bottom: window.innerHeight - place.top }
                        : { top: place.top }),
                }}
                className="z-50 overflow-y-auto rounded-card border border-line bg-card p-2 text-left shadow-xl"
            >
                {body}
            </div>,
            document.body,
        )
    ) : null

    /*
     * `relative z-20` on the card variant is load-bearing: a card is one big
     * click target made from a stretched link at z-10, and without lifting the
     * control above it the overlay swallows every click here.
     */
    if (compact) {
        return (
            <>
                <span className="relative z-20 inline-flex items-stretch">
                    <button
                        type="button"
                        onClick={() => (saved ? openPicker() : void save())}
                        disabled={busy}
                        aria-pressed={saved}
                        aria-label={saved ? t('lists.saved') : destination}
                        title={saved ? t('lists.saved') : destination}
                        className={`flex h-9 w-9 items-center justify-center rounded-l-full border shadow-sm backdrop-blur transition disabled:opacity-60 ${
                            saved
                                ? 'border-sage bg-sage text-white'
                                : 'border-line bg-card/90 text-ink hover:border-ink hover:bg-card'
                        }`}
                    >
                        {/* A bookmark, filled once saved. Recognisable at 16px in
                            a way a word is not, and it does not compete with the
                            product title for attention. */}
                        <svg viewBox="0 0 24 24" className="h-4 w-4" aria-hidden>
                            <path
                                d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"
                                fill={saved ? 'currentColor' : 'none'}
                                stroke="currentColor"
                                strokeWidth="1.8"
                                strokeLinejoin="round"
                            />
                        </svg>
                    </button>

                    {/*
                      The chevron is kept even here, narrow.

                      Without it, filing a card straight into a named list would
                      cost a save into the default one, a second press to
                      reopen, and a move — so adding the fast path would have
                      made the deliberate path slower. It is 20px because the
                      bookmark is the right answer almost every time.
                    */}
                    <button
                        ref={trigger}
                        type="button"
                        onClick={openPicker}
                        aria-expanded={open}
                        aria-haspopup="menu"
                        aria-label={t('lists.save_to_list')}
                        className="-ml-px flex h-9 w-5 items-center justify-center rounded-r-full border border-line bg-card/90 text-[10px] text-ink-soft shadow-sm backdrop-blur hover:border-ink hover:text-ink"
                    >
                        ▾
                    </button>
                </span>
                {panel}
            </>
        )
    }

    return (
        <div className="relative inline-flex items-stretch">
            <button
                type="button"
                // Already saved? Then the useful action is not saving it twice
                // but seeing where it went — and that is the only screen with a
                // way to take it off again.
                onClick={() => (saved ? openPicker() : void save())}
                disabled={busy}
                aria-pressed={saved}
                className={`rounded-l-lg border px-4 py-2 text-sm font-medium transition disabled:opacity-60 ${
                    saved ? 'border-sage bg-sage/10 text-sage' : 'border-line hover:border-ink'
                }`}
            >
                {saved
                    ? `✓ ${t('lists.saved')}`
                    : savingTo
                      ? t('lists.add_to_this', { list: savingTo.title })
                      : t('lists.save')}
            </button>

            <button
                ref={trigger}
                type="button"
                onClick={openPicker}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label={t('lists.save_to_list')}
                className="-ml-px rounded-r-lg border border-line px-2.5 py-2 text-sm hover:border-ink"
            >
                ▾
            </button>
            {panel}
        </div>
    )
}
