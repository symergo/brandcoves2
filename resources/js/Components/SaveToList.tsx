import { usePage } from '@inertiajs/react'
import { useCallback, useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { countAdded, countRemoved } from '../addingMode'
import { HttpError, send } from '../http'
import {
    activeSnapshot,
    load,
    markRemoved,
    markSaved,
    snapshot,
    serverSnapshot,
    subscribe,
} from '../savedItems'
import { show as showToast } from '../saveToast'
import { useSignIn } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface ListOption {
    id: string
    title: string
    /**
     * All three kinds. This was typed as the two the picker knew about, and the
     * filters below matched on them exactly — so a `group` list existed, was
     * returned by `/list-options`, and was invisible in the one control that
     * saves to a list.
     */
    kind: 'mine' | 'for_someone' | 'group'
    recipient: string | null
    items: number
    /** The row this product already occupies on that list, if it is on it. */
    itemId: number | null
}

interface Options {
    lists: ListOption[]
    recipients: { id: string; name: string }[]
}

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
    const { market, auth, savingTo } = usePage<SharedProps>().props
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
     * While a list is being filled, the bookmark reports membership of *that*
     * list. "It is on one of your lists somewhere" is the right answer to the
     * question somebody browsing has, and the wrong answer to the question
     * somebody filling Camping has.
     */
    const relevant = savingTo ? activeIds : savedIds
    const saved = groupId !== undefined && relevant !== null && relevant.has(groupId)

    const [open, setOpen] = useState(false)
    const [options, setOptions] = useState<Options | null>(null)
    const [failed, setFailed] = useState(false)
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

    const destination = savingTo ? t('lists.save_to', { list: savingTo.title }) : t('lists.save_to_list')

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

    /*
     * Asked for with the product, so each row can say whether it already holds
     * it. Without that the picker is a one-way door: every list looks equally
     * empty, and saving to the wrong one — they are a line apart — can only be
     * undone by going and finding that list.
     */
    const refresh = useCallback(async (): Promise<Options> => {
        const query = groupId === undefined ? '' : `?group_id=${groupId}`

        try {
            const fresh: Options = await fetch(`/${market.key}/list-options${query}`, {
                headers: { Accept: 'application/json' },
            }).then((r) => r.json())

            setOptions(fresh)
            setFailed(false)

            return fresh
        } catch {
            /*
             * An empty picker used to be the fallback here, which made a
             * dropped connection indistinguishable from "you have no lists" —
             * and the second of those invites somebody to create a duplicate of
             * a list they already own.
             */
            setFailed(true)

            return { lists: [], recipients: [] }
        }
    }, [groupId, market.key])

    useEffect(() => {
        if (!open || options || failed) return

        void refresh()
    }, [open, options, failed, refresh])

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
    async function save(extra: Record<string, unknown> = {}, close = true): Promise<void> {
        if (busy || !(await requireAccount())) return

        setBusy(true)

        /*
         * Filled before the request, not after it.
         *
         * A bookmark that waits for a round trip reads as a button that did
         * nothing. The rollback below is what keeps that honest when the
         * request does not in fact succeed.
         */
        const chosen = (extra.wishlist_id as string | undefined) ?? savingTo?.id
        const ontoActive = !savingTo || chosen === savingTo.id

        if (groupId !== undefined) {
            markSaved(groupId, ontoActive)
        }

        try {
            const result = await send<SaveResult>(`/${market.key}/list-items`, 'POST', {
                ...payload,
                ...extra,
                // In adding mode an unqualified save means "onto the list I am
                // filling", which is the whole point of the mode.
                ...(savingTo && extra.wishlist_id === undefined && extra.new_list === undefined
                    ? { wishlist_id: savingTo.id }
                    : {}),
            })

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
                // The list set may have changed, so the next open refetches.
                setOptions(null)
            } else {
                void refresh()
            }
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
        } finally {
            setBusy(false)
        }
    }

    /**
     * Take it off a list, from the same row that put it there.
     *
     * The menu stays open: removing from the wrong list is the mistake this
     * whole path exists to make recoverable, and closing the menu would make it
     * unrecoverable in the same click. Outside adding mode the bookmark only
     * goes hollow once no list holds the product — it is on your lists or it is
     * not, and one of three lists letting go does not change that answer.
     */
    async function remove(itemId: number, listId: string): Promise<void> {
        if (busy) return

        setBusy(true)

        try {
            await send(`/${market.key}/list-items/${itemId}`, 'DELETE')

            const fresh = await refresh()

            if (savingTo?.id === listId) {
                countRemoved(listId)
            }

            if (groupId !== undefined) {
                const onAny = fresh.lists.some((l) => l.itemId !== null)
                const onActive =
                    savingTo !== null &&
                    fresh.lists.some((l) => l.id === savingTo.id && l.itemId !== null)

                if (!onAny) {
                    markRemoved(groupId, true)
                } else if (savingTo && !onActive) {
                    // Still kept somewhere, just no longer on the list being
                    // filled — so the general set keeps it and the active one
                    // does not.
                    markRemoved(groupId, true)
                    markSaved(groupId, false)
                }
            }
        } catch {
            showToast({ message: t('lists.save_failed'), tone: 'error' })
        } finally {
            setBusy(false)
        }
    }

    const mine = options?.lists.filter((l) => l.kind === 'mine') ?? []
    const forOthers = options?.lists.filter((l) => l.kind === 'for_someone') ?? []
    const groups = options?.lists.filter((l) => l.kind === 'group') ?? []

    /*
     * One row, both directions. A tick means it is on that list and pressing it
     * takes it off — the same control reporting the state and changing it, which
     * is the only arrangement where "which lists is this on?" can be answered by
     * looking rather than by remembering.
     */
    function row(list: ListOption, label: string) {
        const on = list.itemId !== null

        return (
            <button
                key={list.id}
                type="button"
                role="menuitemcheckbox"
                aria-checked={on}
                disabled={busy}
                onClick={() =>
                    on
                        ? void remove(list.itemId as number, list.id)
                        : void save({ wishlist_id: list.id }, false)
                }
                title={on ? t('lists.remove_from', { list: label }) : t('lists.save_to', { list: label })}
                className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-line/40 disabled:opacity-60"
            >
                <span
                    aria-hidden
                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border text-[10px] ${
                        on ? 'border-sage bg-sage text-white' : 'border-line'
                    }`}
                >
                    {on ? '✓' : ''}
                </span>
                <span className="min-w-0 flex-1 truncate">{label}</span>
            </button>
        )
    }

    const body =
        failed && options === null ? (
            <div className="p-3 text-sm">
                <p className="text-ink-soft">{t('lists.options_failed')}</p>
                <button
                    type="button"
                    onClick={() => void refresh()}
                    className="mt-2 rounded border border-line px-3 py-1.5 text-xs hover:border-ink"
                >
                    {t('lists.retry')}
                </button>
            </div>
        ) : options === null ? (
            <p className="px-2 py-3 text-sm text-ink-soft">{t('lists.loading_lists')}</p>
        ) : creating ? (
            <form
                className="p-2"
                onSubmit={(e) => {
                    e.preventDefault()
                    /*
                     * Both "for someone" shapes name a person and title the list
                     * after them; a group list adds `together`, which is the
                     * single bit that separates the two on the server.
                     */
                    void save(
                        creating === 'mine'
                            ? { new_list: name }
                            : {
                                  new_list: t('lists.for_person', { name }),
                                  new_recipient: name,
                                  together: creating === 'group',
                              },
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
