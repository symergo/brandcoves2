import { router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'
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
    type Holder,
    holderSnapshot,
    load,
    serverHolders,
    markRemoved,
    markSaved,
    snapshot,
    serverSnapshot,
    subscribe,
} from '../savedItems'
import { listFrom, show as showToast } from '../saveToast'
import { useSignIn } from '../signIn'
import type { ListOption, SavingTo, SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import type { ListKind } from './ListKindBadge'
import ListName from './ListName'
import SaveButton from './SaveButton'
import SaveSheet from './SaveSheet'

interface SaveResult {
    itemId: number
    listId: string
    listTitle: string
    /** For drawing the list's name in the toast; see App\Support\ListName. */
    listKind?: ListKind
    messageTemplate?: string
    message: string
}

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
 * ## One button, one sheet (2026-09-26)
 *
 * This used to be a bookmark with a narrow "▾" beside it that opened the list
 * picker; at card size the chevron read as a minus sign, and a Cove, a product
 * page and This or that each had a save control of their own. It is now the
 * site's one Save button (`SaveButton`) and the one panel it opens
 * (`SaveSheet`), shared with `SaveCove`. The panel is a portal because a
 * product card clips anything positioned inside it. See
 * docs/features/save-button.md.
 *
 * ## One rule for both variants
 *
 * Not saved → save, straight to the list named in the button's label. Saved →
 * open the sheet, which says where it is and is the only place it can be taken
 * off again or put on another list. So the sheet is always one press away: the
 * press after the save.
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
    into,
    url,
    onSaved,
}: {
    /**
     * Told which list it landed in, for a page that shows that list beside the
     * button (`/for/{token}`'s ideas, above the person's own list) and has to
     * redraw it. Everywhere else the toast is the confirmation.
     */
    onSaved?: (listId: string) => void
    groupId?: number
    source?: string
    externalId?: string
    /**
     * A pasted link nothing we hold matched (Find a gift's search without a
     * list, 2026-09-27): saved as a hand-written item carrying the link, as a
     * list's own search does, and its page is read afterwards.
     */
    url?: string
    title?: string
    imageUrl?: string | null
    price?: number | null
    compact?: boolean
    /**
     * A list the page chose for this product, ahead of every guess.
     *
     * The Gift Whisperer resolves the chosen person's list server-side and
     * names it here, so a pick lands on "For Mum" rather than wherever the
     * last save went. More specific than adding mode (a person, not a list
     * being filled) and than the remembered last list, so it outranks both.
     */
    into?: SavingTo
}) {
    const { market, auth, savingTo, lists } = usePage<SharedProps>().props
    const signIn = useSignIn()
    const { t, tRich } = useTranslations()

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
    // The Save button itself: the sheet is placed against it, and focus goes
    // back to it when the sheet closes.
    const trigger = useRef<HTMLButtonElement>(null)
    const close = useCallback(() => setOpen(false), [])

    // The product, however it is identified. A live bol result and an Amazon
    // product have no stored group; the server decides what may be kept.
    const payload = groupId
        ? { group_id: groupId }
        : url !== undefined
          ? { source: 'manual', url }
          : { source, external_id: externalId, title, image_url: imageUrl, price }

    /*
     * Named, in the order the save itself resolves: the list being filled, then
     * the one you used last, then "a list" when neither is known. A bookmark
     * that files things somewhere without saying where would be worse than the
     * default it replaces, and this label plus the toast are where it says.
     */
    /*
     * The remembered list's title is looked up on this page, not read from
     * memory. `lastList.ts` stores the title as it was shown at the save,
     * in the language of the market at that moment, and keeps it across
     * markets: a save on the English site left "My wishlist" behind, and a
     * Dutch page then read "Bewaar in My wishlist" (owner, 2026-09-13). The
     * `lists` prop carries every list of yours titled in this page's
     * language, so it is the source; the stored title is only the fallback
     * for a list this page does not know, which the save then re-checks.
     */
    const lastListTitle = lastList
        ? (lists.find((l) => l.id === lastList.id)?.title ?? lastList.title)
        : null

    const destination = into
        ? t('lists.save_to', { list: into.title })
        : savingTo
        ? t('lists.save_to', { list: savingTo.title })
        : lastListTitle !== null
          ? t('lists.save_to', { list: lastListTitle })
          : t('lists.save_to_list')

    // Lazily, and never for a visitor who cannot save anything.
    useEffect(() => {
        load(market.key, Boolean(auth.user), savingTo?.id ?? null)
    }, [market.key, auth.user, savingTo?.id])

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
         * Nobody named a list, so one is guessed: the list the page chose for
         * this product (`into`) if there is one, else adding mode if it is on,
         * otherwise wherever the last save went. An explicit `wishlist_id` or a
         * `new_list` is not a guess and is left alone.
         */
        const unqualified = extra.wishlist_id === undefined && extra.new_list === undefined
        const guess = !unqualified ? undefined : (into?.id ?? savingTo?.id ?? lastList?.id)
        const chosen = (extra.wishlist_id as string | undefined) ?? guess

        if (groupId !== undefined) {
            markSaved(groupId, chosen ?? null)
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
                markSaved(groupId, result.listId, result.itemId)
            }

            setCreating(null)
            setName('')

            if (savingTo && result.listId === savingTo.id) {
                countAdded(result.listId)
            }

            showToast({
                message: result.message,
                list: listFrom(result),
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

            onSaved?.(result.listId)

            return true
        } catch (error) {
            if (groupId !== undefined) {
                markRemoved(groupId, chosen ?? null)
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
     * Take it off one list, from the same row that put it there.
     *
     * The menu stays open: unticking the wrong list is the mistake this path
     * exists to make recoverable, and closing the menu would make it
     * unrecoverable in the same click. The store decides whether the bookmark
     * empties — only when no other list still holds the product.
     */
    async function remove(itemId: number, listId: string): Promise<void> {
        if (busy) return

        setBusy(true)

        try {
            await send(`/${market.key}/list-items/${itemId}`, 'DELETE')

            if (savingTo?.id === listId) {
                countRemoved(listId)
            }

            if (groupId !== undefined) {
                markRemoved(groupId, listId)
            }
        } catch {
            showToast({ message: t('lists.save_failed'), tone: 'error' })
        } finally {
            setBusy(false)
        }
    }

    /*
     * Every list holding it. Empty until `savedItems` has answered, and empty
     * forever for a product with no group of its own — a live bol result, an
     * Amazon product — which is the same as it was: nothing to match on, so
     * nothing is ticked.
     */
    const held: Holder[] = groupId === undefined ? [] : (holders?.[groupId] ?? [])

    /*
     * Where one press on Save goes, first in its section.
     *
     * The same order the save itself resolves in (the page's choice, the list
     * being filled, the last list used). Otherwise the server's order stands,
     * which already puts the default list first. The row is what the sheet's
     * hint line names, so "one press puts it here" and the row it means sit
     * next to each other.
     */
    const quick = into ?? savingTo ?? (lastList ? { id: lastList.id, title: lastListTitle ?? lastList.title } : null)
    // The kind, for drawing the name (ListName): from the page's own rows,
    // which know every list of yours, else from `into` or adding mode.
    const quickKind = (lists.find((l) => l.id === quick?.id)?.kind ?? (quick && 'kind' in quick ? quick.kind : undefined)) ?? null
    const quickFirst = (a: ListOption, b: ListOption) => Number(b.id === quick?.id) - Number(a.id === quick?.id)

    const mine = lists.filter((l) => l.kind === 'mine').sort(quickFirst)
    const forOthers = lists.filter((l) => l.kind === 'for_someone').sort(quickFirst)
    const groups = lists.filter((l) => l.kind === 'group').sort(quickFirst)

    /**
     * A row is a tick: on, the product is on that list; off, it is not.
     *
     * The row has been three things. A **checklist** first. Then, from
     * 2026-08-31, a **menu of options** where one list held a product and
     * picking another row moved it there, on the argument that the reason to
     * open the menu is almost always "this one, not that one". Then a
     * checklist again from 2026-09-12, because the owner wanted a product to
     * be able to sit on the birthday list and the Christmas list at once, and
     * copying it across was a workaround for a capability the picker had
     * taken away. The move's one surprise — choosing a second list silently
     * emptied the first — goes with it.
     *
     * So: a box in front of every list, ticked where the product is. Ticking
     * saves to that list and leaves the others alone; unticking takes it off
     * that list only. "Get this off my lists" is unticking the rows that are
     * on, so the separate remove option at the top of the menu is gone.
     */
    function row(list: ListOption, label: string) {
        const holder = held.find((h) => h.listId === list.id) ?? null
        const on = holder !== null

        return (
            <button
                key={list.id}
                type="button"
                role="checkbox"
                aria-checked={on}
                disabled={busy}
                onClick={() => {
                    if (holder !== null) {
                        void remove(holder.itemId, list.id)
                    } else {
                        void save({ wishlist_id: list.id }, false)
                    }
                }}
                title={on ? t('lists.remove_from', { list: label }) : t('lists.save_to', { list: label })}
                className={`flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm disabled:opacity-50 ${
                    on
                        ? 'border border-sage bg-sage/15 font-medium text-sage'
                        : 'border border-transparent hover:bg-line/40'
                }`}
            >
                {/*
                  A real-looking box, because the row is a selection again and
                  a box is the one shape everybody reads as "tick me". Sage
                  when on, matching the filled bookmark and the tinted row, so
                  the state is said three times for a reader who catches one.
                */}
                <span
                    aria-hidden
                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border text-2xs font-bold ${
                        on ? 'border-sage bg-sage text-white' : 'border-line bg-card'
                    }`}
                >
                    {on ? '✓' : ''}
                </span>
                <span className="min-w-0 flex-1 truncate">{label}</span>
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
                     * Through `save`, like a tick on an existing row: naming a
                     * new list for this product puts it there and leaves the
                     * other lists alone, now that a product may sit on several.
                     */
                    void save(
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
                    {creating === 'mine' ? t('lists.list_name') : t('lists.recipient_label')}
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
                        className="rounded bg-accent px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
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
                  Says, in one line, what the button did or will do, so the
                  one-press save is never a mystery: where a press goes before
                  it is saved, and how to take it off after.
                */}
                <p className="px-2 pt-1 pb-2 text-sm text-ink-soft">
                    {saved
                        ? t('save_button.saved_hint')
                        : quick
                          ? tRich('save_button.quick_hint', { list: <ListName name={quick.title} kind={quickKind} /> })
                          : t('save_button.pick_hint')}
                </p>
                <p className="border-t border-line px-2 pt-2 pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase">
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

    const panel = (
        <SaveSheet open={open} onClose={close} anchor={trigger} label={t('lists.save_to_list')}>
            {body}
        </SaveSheet>
    )

    /*
     * `relative z-20` on the card variant is load-bearing: a card is one big
     * click target made from a stretched link at z-10, and without lifting the
     * control above it the overlay swallows every click here.
     *
     * No chevron any more, on either shape. It was a second, 20px target that
     * opened the sheet before a save; at card size it read as a minus sign,
     * and on a phone it was already gone (2026-09-08). Choosing a list now
     * costs the press that saves and the press that opens the sheet, and the
     * sheet opens with the list just used on top.
     */
    const button = (
        <SaveButton
            ref={trigger}
            compact={compact}
            saved={saved}
            busy={busy}
            onClick={() => (saved ? openPicker() : void save())}
            label={saved ? t('save_button.saved_label') : destination}
            opensSheet={saved}
            expanded={open}
        />
    )

    if (compact) {
        return (
            <>
                <span className="relative z-20 inline-flex">{button}</span>
                {panel}
            </>
        )
    }

    return (
        <>
            {button}
            {panel}
        </>
    )
}
