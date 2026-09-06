import { router } from '@inertiajs/react'
import { useState, type FormEvent } from 'react'
import type { Cents } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Correcting something you typed onto a list yourself.
 *
 * ## Why only a hand-written item
 *
 * On an item saved from the catalogue, `snapshot_title`, `snapshot_price` and
 * `snapshot_url` are a **record of what the feed said when it was saved** —
 * they are what "you saved it at €329, it is €279 now" is measured against.
 * Rewriting them would turn a price history into a free-text field.
 *
 * On an item somebody typed, those same columns are not a snapshot of anything.
 * They *are* the item. So a typo in one was uncorrectable: the only fix was to
 * delete the row and type it again, which loses the note, the position, and any
 * claim somebody had already made on it.
 *
 * `WishlistItemController::update()` asks the same question server-side and
 * silently drops these fields on a catalogue item — this control is not the
 * gate, it is the reason the gate is never reached by the page.
 *
 * ## Why the price is a text field
 *
 * It is stored as integer cents (invariant #7) and typed by a person in euros.
 * The conversion happens here, once, on the way out — `number` with a step of
 * 0.01 invites a browser to hand back `39.989999999999995` and a decimal
 * separator that differs by locale.
 */
export default function EditManualItem({
    action,
    title,
    url,
    price,
    onDone,
}: {
    /** `/{market}/list-items/{item}` — PATCH. */
    action: string
    title: string
    url: string | null
    price: Cents | null
    onDone: () => void
}) {
    const { t } = useTranslations()

    const [form, setForm] = useState({
        title,
        url: url ?? '',
        // Cents to a plain decimal string. Empty stays empty, which is how the
        // price is cleared rather than set to zero — "free" and "no price" are
        // different things and a list holds plenty of the second.
        price: price === null ? '' : (price / 100).toFixed(2),
    })

    function submit(e: FormEvent) {
        e.preventDefault()

        const typed = form.price.trim().replace(',', '.')
        const euros = Number.parseFloat(typed)

        router.patch(
            action,
            {
                title: form.title.trim(),
                // Null, not '': the column is nullable and an empty string
                // would be a URL that fails every safety check on the way back
                // out rather than an absent one.
                url: form.url.trim() === '' ? null : form.url.trim(),
                price: typed === '' || Number.isNaN(euros) ? null : Math.round(euros * 100),
            },
            { preserveScroll: true, onSuccess: onDone },
        )
    }

    return (
        <form onSubmit={submit} className="mt-3 space-y-2 border-t border-line pt-3">
            <label className="block text-xs font-medium">
                {t('lists.manual_title')}
                <input
                    required
                    maxLength={500}
                    value={form.title}
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>

            <label className="block text-xs font-medium">
                {t('lists.manual_url')}
                <input
                    type="url"
                    maxLength={2048}
                    value={form.url}
                    onChange={(e) => setForm({ ...form, url: e.target.value })}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>

            <label className="block text-xs font-medium">
                {t('lists.manual_price')}
                <input
                    inputMode="decimal"
                    value={form.price}
                    onChange={(e) => setForm({ ...form, price: e.target.value })}
                    className="mt-1 w-32 rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>

            <div className="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    className="rounded-lg bg-accent px-4 py-1.5 text-sm font-medium text-white hover:bg-accent-dark"
                >
                    {t('lists.save_changes')}
                </button>

                <button
                    type="button"
                    onClick={onDone}
                    className="text-xs text-ink-soft underline hover:text-ink"
                >
                    {t('lists.cancel')}
                </button>
            </div>
        </form>
    )
}
