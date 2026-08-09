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
export default function SaveToList({
    groupId,
    compact = false,
}: {
    groupId: number
    compact?: boolean
}) {
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

    /*
     * `relative z-20` on the compact variant is load-bearing.
     *
     * A grid card is one big click target made from a stretched link at z-10.
     * Without lifting this above it, the overlay swallows the click and the
     * button is decoration — the same trap the brand link inside the card
     * already had to be rescued from.
     */
    if (compact) {
        return (
            <button
                onClick={save}
                disabled={busy}
                aria-pressed={saved}
                aria-label={saved ? t('lists.saved') : t('lists.save_to_list')}
                title={saved ? t('lists.saved') : t('lists.save_to_list')}
                className={`relative z-20 rounded-full border px-2.5 py-1 text-xs transition ${
                    saved
                        ? 'border-sage bg-sage/10 text-sage'
                        : 'border-line bg-card hover:border-ink disabled:opacity-60'
                }`}
            >
                {saved ? `✓ ${t('lists.saved')}` : `+ ${t('lists.save')}`}
            </button>
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
