import { router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { load, markRemoved, markSaved, snapshot, serverSnapshot, subscribe } from '../savedItems'
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

/**
 * Save a product — to a list for yourself, or to one about somebody else.
 *
 * ## Why the menu is a portal
 *
 * A product card is `overflow-hidden` (rounded corners, and the image scales on
 * hover), so an absolutely positioned panel inside it is simply clipped away —
 * the menu opened correctly and was invisible. Rendering it into `document.body`
 * escapes every `overflow-hidden` ancestor and every stacking context at once,
 * which no amount of z-index on the panel can do.
 *
 * ## Why the card variant is an icon
 *
 * On a grid card there is no room for a labelled split button, and one crammed
 * in reads as clutter over the product. A bookmark in the corner is the
 * convention people already know from every shopping site, and it opens the
 * picker directly rather than saving somewhere unstated and making them undo it.
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
    const { market, auth } = usePage<SharedProps>().props
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
    const saved = groupId !== undefined && savedIds !== null && savedIds.has(groupId)
    const [open, setOpen] = useState(false)
    const [options, setOptions] = useState<Options | null>(null)
    const [creating, setCreating] = useState<null | 'mine' | 'for_someone' | 'group'>(null)
    const [name, setName] = useState('')
    const [rect, setRect] = useState<{ top: number; left: number } | null>(null)

    const trigger = useRef<HTMLButtonElement>(null)
    const menu = useRef<HTMLDivElement>(null)

    // The product, however it is identified. A live bol result and an Amazon
    // product have no stored group; the server decides what may be kept.
    const payload = groupId
        ? { group_id: groupId }
        : { source, external_id: externalId, title, image_url: imageUrl, price }

    const place = useCallback(() => {
        const button = trigger.current
        if (!button) return

        const box = button.getBoundingClientRect()
        const width = 288
        // Keep it on screen: a card at the right edge of the grid would push the
        // panel off the viewport, and on a phone every card is at both edges.
        const left = Math.min(Math.max(8, box.right - width), window.innerWidth - width - 8)

        setRect({ top: box.bottom + 6, left })
    }, [])

    useLayoutEffect(() => {
        if (open) place()
    }, [open, place])

    // Lazily, and never for a visitor who cannot save anything.
    useEffect(() => {
        load(market.key, Boolean(auth.user))
    }, [market.key, auth.user])

    /*
     * Asked for with the product, so each row can say whether it already holds
     * it. Without that the picker is a one-way door: every list looks equally
     * empty, and saving to the wrong one — they are a line apart — can only be
     * undone by going and finding that list.
     */
    const refresh = useCallback(async (): Promise<Options> => {
        const query = groupId === undefined ? '' : `?group_id=${groupId}`

        const fresh: Options = await fetch(`/${market.key}/list-options${query}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .catch(() => ({ lists: [], recipients: [] }))

        setOptions(fresh)

        return fresh
    }, [groupId, market.key])

    useEffect(() => {
        if (!open || options) return

        void refresh()
    }, [open, options, refresh])

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
        window.addEventListener('scroll', place, true)
        window.addEventListener('resize', place)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
            window.removeEventListener('scroll', place, true)
            window.removeEventListener('resize', place)
        }
    }, [open, place])

    /*
     * Sign in first.
     *
     * Enforced on the route too; done here as well so the visitor gets the
     * login page rather than a silent 302 swallowed by an Inertia POST.
     */
    function requireAccount(): boolean {
        if (auth.user) {
            return true
        }

        router.get(`/${market.key}/login`)

        return false
    }

    function openPicker() {
        if (requireAccount()) {
            setOpen((v) => !v)
        }
    }

    /**
     * @param close Whether to dismiss the picker afterwards. A row is a toggle,
     *              so it stays open and shows the tick it just earned; naming a
     *              new list is a completed errand, so that one closes.
     */
    function save(extra: Record<string, unknown> = {}, close = true) {
        if (busy || !requireAccount()) return

        setBusy(true)

        router.post(
            `/${market.key}/list-items`,
            { ...payload, ...extra },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    if (groupId !== undefined) {
                        markSaved(groupId)
                    }

                    setCreating(null)
                    setName('')

                    if (close) {
                        setOpen(false)
                        // The list set may have changed, so the next open refetches.
                        setOptions(null)
                    } else {
                        void refresh()
                    }
                },
                onFinish: () => setBusy(false),
            },
        )
    }

    /**
     * Take it off a list, from the same row that put it there.
     *
     * The menu stays open: removing from the wrong list is the mistake this
     * whole path exists to make recoverable, and closing the menu would make it
     * unrecoverable in the same click. The bookmark only goes hollow once no
     * list holds the product any more — it is on your lists or it is not, and
     * one of three lists letting go does not change that answer.
     */
    function remove(itemId: number) {
        if (busy) return

        setBusy(true)

        router.delete(`/${market.key}/list-items/${itemId}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                const fresh = await refresh()

                if (groupId !== undefined && !fresh.lists.some((l) => l.itemId !== null)) {
                    markRemoved(groupId)
                }
            },
            onFinish: () => setBusy(false),
        })
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
                onClick={() => (on ? remove(list.itemId as number) : save({ wishlist_id: list.id }, false))}
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

    const panel =
        open && rect
            ? createPortal(
                  <div
                      ref={menu}
                      role="menu"
                      style={{ position: 'fixed', top: rect.top, left: rect.left, width: 288 }}
                      className="z-50 rounded-card border border-line bg-card p-2 text-left shadow-xl"
                  >
                      {options === null ? (
                          <p className="px-2 py-3 text-sm text-ink-soft">{t('lists.save_to_list')}…</p>
                      ) : creating ? (
                          <form
                              className="p-2"
                              onSubmit={(e) => {
                                  e.preventDefault()
                                  /*
                                   * Both "for someone" shapes name a person and
                                   * title the list after them; a group list adds
                                   * `together`, which is the single bit that
                                   * separates the two on the server.
                                   */
                                  save(
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
                                A third section, because a group gift is a third
                                answer to "who is this for?" — several of us, for
                                one person. Its own heading rather than a badge
                                inside "for someone else": the two carry
                                different mechanisms, and a shortlist you are all
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
                      )}
                  </div>,
                  document.body,
              )
            : null

    /*
     * `relative z-20` on the card variant is load-bearing: a card is one big
     * click target made from a stretched link at z-10, and without lifting the
     * button above it the overlay swallows every click here.
     */
    if (compact) {
        return (
            <>
                <button
                    ref={trigger}
                    type="button"
                    onClick={openPicker}
                    aria-expanded={open}
                    aria-haspopup="menu"
                    aria-label={saved ? t('lists.saved') : t('lists.save_to_list')}
                    title={saved ? t('lists.saved') : t('lists.save_to_list')}
                    className={`relative z-20 flex h-9 w-9 items-center justify-center rounded-full border shadow-sm backdrop-blur transition ${
                        saved
                            ? 'border-sage bg-sage text-white'
                            : 'border-line bg-card/90 text-ink hover:border-ink hover:bg-card'
                    }`}
                >
                    {/* A bookmark, filled once saved. Recognisable at 16px in a
                        way a word is not, and it does not compete with the
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
                onClick={() => (saved ? openPicker() : save())}
                disabled={busy}
                aria-pressed={saved}
                className={`rounded-l-lg border px-4 py-2 text-sm font-medium transition ${
                    saved ? 'border-sage bg-sage/10 text-sage' : 'border-line hover:border-ink'
                }`}
            >
                {saved ? `✓ ${t('lists.saved')}` : t('lists.save')}
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
