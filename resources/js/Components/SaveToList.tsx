import { router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface ListOption {
    id: string
    title: string
    kind: 'mine' | 'for_someone'
    recipient: string | null
    items: number
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
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const [saved, setSaved] = useState(false)
    const [busy, setBusy] = useState(false)
    const [open, setOpen] = useState(false)
    const [options, setOptions] = useState<Options | null>(null)
    const [creating, setCreating] = useState<null | 'mine' | 'for_someone'>(null)
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

    useEffect(() => {
        if (!open || options) return

        fetch(`/${market.key}/list-options`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then(setOptions)
            .catch(() => setOptions({ lists: [], recipients: [] }))
    }, [open, options, market.key])

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

    function save(extra: Record<string, unknown> = {}) {
        if (busy) return
        setBusy(true)

        router.post(
            `/${market.key}/list-items`,
            { ...payload, ...extra },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSaved(true)
                    setOpen(false)
                    setCreating(null)
                    setName('')
                    // The list set may have changed, so the next open refetches.
                    setOptions(null)
                },
                onFinish: () => setBusy(false),
            },
        )
    }

    const mine = options?.lists.filter((l) => l.kind === 'mine') ?? []
    const forOthers = options?.lists.filter((l) => l.kind === 'for_someone') ?? []

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
                                  save(
                                      creating === 'mine'
                                          ? { new_list: name }
                                          : {
                                                new_list: t('lists.for_person', { name }),
                                                new_recipient: name,
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
                              {mine.map((l) => (
                                  <button
                                      key={l.id}
                                      type="button"
                                      role="menuitem"
                                      onClick={() => save({ wishlist_id: l.id })}
                                      className="block w-full truncate rounded px-2 py-1.5 text-left text-sm hover:bg-line/40"
                                  >
                                      {l.title}
                                  </button>
                              ))}
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
                              {forOthers.map((l) => (
                                  <button
                                      key={l.id}
                                      type="button"
                                      role="menuitem"
                                      onClick={() => save({ wishlist_id: l.id })}
                                      className="block w-full truncate rounded px-2 py-1.5 text-left text-sm hover:bg-line/40"
                                  >
                                      {l.recipient ?? l.title}
                                  </button>
                              ))}
                              <button
                                  type="button"
                                  onClick={() => setCreating('for_someone')}
                                  className="block w-full rounded px-2 py-1.5 text-left text-sm text-accent hover:bg-line/40"
                              >
                                  + {t('lists.add_person')}
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
                    onClick={() => setOpen((v) => !v)}
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
                onClick={() => save()}
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
                onClick={() => setOpen((v) => !v)}
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
