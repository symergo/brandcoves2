import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Save a product to a list, from anywhere.
 *
 * No account required and no list required: the controller creates a default
 * list on first save. Asking someone to sign in or to name a list before they
 * can keep a product is how you lose the save.
 */
export default function SaveToList({ groupId }: { groupId: number }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [saved, setSaved] = useState(false)
    const [busy, setBusy] = useState(false)

    function save() {
        if (saved || busy) return
        setBusy(true)

        router.post(
            `/${market.key}/list-items`,
            { group_id: groupId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSaved(true),
                onFinish: () => setBusy(false),
            },
        )
    }

    return (
        <button
            onClick={save}
            disabled={busy}
            aria-pressed={saved}
            className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${
                saved
                    ? 'border-sage bg-sage/10 text-sage'
                    : 'border-line hover:border-ink disabled:opacity-60'
            }`}
        >
            {saved ? `✓ ${t('lists.saved')}` : t('lists.save')}
        </button>
    )
}
