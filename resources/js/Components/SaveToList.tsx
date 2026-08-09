import { router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
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
 * The two are different acts and the previous version could only do the first:
 * every save landed in a default `mine` list with no way to choose, so "keep
 * this idea for my sister" had no path at all through the interface.
 *
 * Still one tap for the common case. The picker opens on the caret, never on
 * the main button — asking someone to make a filing decision before they can
 * keep a product is how you lose the save, which is the same reason no account
 * is required.
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
    const panel = useRef<HTMLDivElement>(null)

    // The product, however it is identified. A live bol result and an Amazon
    // product have no stored group; the server decides what may be kept.
    const payload = groupId
        ? { group_id: groupId }
        : { source, external_id: externalId, title, image_url: imageUrl, price }

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
            if (panel.current && !panel.current.contains(e.target as Node)) setOpen(false)
        }
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
        }
    }, [open])

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

    const buttonClass = compact
        ? `relative z-20 rounded-l-full border px-2.5 py-1 text-xs transition ${
              saved ? 'border-sage bg-sage/10 text-sage' : 'border-line bg-card hover:border-ink'
          }`
        : `rounded-l-lg border px-4 py-2 text-sm font-medium transition ${
              saved ? 'border-sage bg-sage/10 text-sage' : 'border-line hover:border-ink'
          }`

    const caretClass = compact
        ? 'relative z-20 -ml-px rounded-r-full border border-line bg-card px-2 py-1 text-xs hover:border-ink'
        : '-ml-px rounded-r-lg border border-line px-2.5 py-2 text-sm hover:border-ink'

    return (
        /*
         * `relative z-20` is load-bearing on a grid card: the card is one big
         * click target made from a stretched link at z-10, and without lifting
         * this above it the overlay swallows every click here.
         */
        <div ref={panel} className="relative z-20 inline-flex items-stretch">
            <button
                type="button"
                onClick={() => save()}
                disabled={busy}
                aria-pressed={saved}
                className={buttonClass}
            >
                {saved ? `✓ ${t('lists.saved')}` : t('lists.save')}
            </button>

            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label={t('lists.save_to_list')}
                className={caretClass}
            >
                ▾
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute top-full right-0 z-30 mt-1 w-72 rounded-card border border-line bg-card p-2 text-left shadow-lg"
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
                                        : { new_list: t('lists.for_person', { name }), new_recipient: name },
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
                </div>
            )}
        </div>
    )
}
