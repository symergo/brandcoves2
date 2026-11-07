import { router } from '@inertiajs/react'
import { useState, type FormEvent } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * Give a product on your list a name of your own (owner, 2026-11-07: "allow
 * everywhere to change the name of an item on a list"), and a note. The note
 * was written in the step before adding until one press began to add a
 * product as it is (the same day), so it is written here, afterwards.
 *
 * Opened under the card from the item's ⋯, where a hand-written item opens
 * `EditManualItem`. The same PATCH; on a product the server keeps the name
 * apart from what the shop said (`custom_title`), so the price history is
 * untouched. Shown on the list, the shared page and in emails; "the shop's
 * name" puts the product's own back.
 */
export default function RenameItem({
    action,
    title,
    productTitle,
    note,
    onDone,
}: {
    /** `/{market}/list-items/{item}`: PATCH. */
    action: string
    title: string
    productTitle: string
    note: string | null
    onDone: () => void
}) {
    const { t } = useTranslations()
    const [name, setName] = useState(title)
    const [noteText, setNoteText] = useState(note ?? '')
    const [busy, setBusy] = useState(false)
    const renamed = title !== productTitle

    const send = (value: string) => {
        setBusy(true)
        router.patch(action, { title: value, note: noteText.trim() || null }, { preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(false) })
    }

    return (
        <form
            onSubmit={(e: FormEvent) => {
                e.preventDefault()
                send(name.trim())
            }}
            className="mt-3 space-y-2 border-t border-line pt-3"
        >
            <label className="block text-xs font-medium">
                {t('lists.item_name')}
                <input
                    maxLength={500}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder={productTitle}
                    autoFocus
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>
            <label className="block text-xs font-medium">
                {t('suggestions.note_label')}
                <input
                    maxLength={500}
                    value={noteText}
                    onChange={(e) => setNoteText(e.target.value)}
                    placeholder={t('lists.add_note_placeholder')}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>
            {renamed && (
                <button type="button" disabled={busy} onClick={() => send('')} className="text-xs text-ink-soft underline hover:text-ink">
                    {t('lists.item_name_reset')}
                </button>
            )}
            <div className="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-lg bg-accent px-4 py-1.5 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                >
                    {t('lists.save_changes')}
                </button>
                <button type="button" onClick={onDone} className="text-xs text-ink-soft underline hover:text-ink">
                    {t('lists.cancel')}
                </button>
            </div>
        </form>
    )
}
