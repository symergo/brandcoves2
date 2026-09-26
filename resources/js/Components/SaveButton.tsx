import { forwardRef } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * The bookmark: outlined to save, filled once saved.
 *
 * The one icon for "keep this", on a product and on a Cove, so the two read as
 * one idea. Recognisable at 16px in a way a word is not.
 */
export function BookmarkIcon({ filled }: { filled: boolean }) {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" aria-hidden>
            <path
                d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"
                fill={filled ? 'currentColor' : 'none'}
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinejoin="round"
            />
        </svg>
    )
}

/**
 * The Save button: one word, one icon, everywhere something can be kept.
 *
 * "Bewaar" before, "Bewaard" after, in all four languages from
 * `save_button.*`. Two shapes of the same button: a round icon-only one that
 * sits in a product photo (`compact`), and a labelled one for a product page
 * or a Cove. What pressing it does is up to the caller (SaveToList, SaveCove);
 * this is only how it looks, so the two cannot drift apart again. See
 * docs/features/save-button.md.
 */
const SaveButton = forwardRef<
    HTMLButtonElement,
    {
        saved: boolean
        onClick: () => void
        compact?: boolean
        busy?: boolean
        /** Says what a press will do: "Save to Camping", "Saved. Choose lists". */
        label?: string
        /** Set when the press opens the sheet, so it is announced as a popup. */
        expanded?: boolean
        opensSheet?: boolean
    }
>(function SaveButton({ saved, onClick, compact = false, busy = false, label, expanded, opensSheet = false }, ref) {
    const { t } = useTranslations()
    const word = saved ? t('save_button.saved') : t('save_button.save')

    const popup = opensSheet ? { 'aria-haspopup': 'dialog' as const, 'aria-expanded': expanded ?? false } : {}

    if (compact) {
        return (
            <button
                ref={ref}
                type="button"
                onClick={onClick}
                disabled={busy}
                aria-label={label ?? word}
                title={label ?? word}
                {...popup}
                className={`flex h-10 w-10 items-center justify-center rounded-full border shadow-sm backdrop-blur transition disabled:opacity-50 lg:h-9 lg:w-9 ${
                    saved
                        ? 'border-sage bg-sage text-white'
                        : 'border-line bg-card/90 text-ink hover:border-ink hover:bg-card'
                }`}
            >
                <BookmarkIcon filled={saved} />
            </button>
        )
    }

    return (
        <button
            ref={ref}
            type="button"
            onClick={onClick}
            disabled={busy}
            // The visible word stays short; the label says where it goes, and
            // starts with that same word in every language, so a voice user
            // saying "Save" still hits it.
            aria-label={label}
            title={label}
            {...popup}
            className={`inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 text-sm font-medium transition disabled:opacity-50 ${
                saved ? 'border-sage bg-sage/10 text-sage' : 'border-line bg-card text-ink hover:border-ink'
            }`}
        >
            <BookmarkIcon filled={saved} />
            {word}
        </button>
    )
})

export default SaveButton
