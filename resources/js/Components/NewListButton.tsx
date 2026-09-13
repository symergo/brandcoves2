import { useTranslations } from '../useTranslations'

/**
 * The "Make a new list" button, as it looks on the home page.
 *
 * One component for the two places it stands — the Organise band on the home
 * page and the header of My Lists — so a reader who learnt it on one page
 * finds the same button on the other. Before 2026-09-12 My Lists had its own,
 * smaller "New list" button with no glyph, and the two looked like two
 * different things that turned out to open the same wizard.
 *
 * It is a disclosure toggle rather than a link: what opens under it is the
 * caller's business (the kind chooser on the home page, the wizard on My
 * Lists), which is why it takes `open`, `onToggle` and the id of what it
 * controls, and nothing else.
 *
 * Outlined rather than filled, on both pages: the accent button on the home
 * page is the search, and on My Lists the page under the button is the point.
 *
 * Full width only below `sm`: at 390px an inline button beside nothing looks
 * like it fell off a toolbar, and everything else in those bands is full
 * width anyway. `py-2.5` at 16px keeps the tap target at 44px.
 */
export default function NewListButton({
    open,
    onToggle,
    controls,
}: {
    open: boolean
    onToggle: () => void
    /** The id of the region the button shows and hides. */
    controls: string
}) {
    const { t } = useTranslations()

    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            aria-controls={controls}
            className="flex w-full items-center justify-center gap-2 rounded-card border border-line bg-card px-4 py-2.5 font-medium text-ink transition hover:border-ink sm:inline-flex sm:w-auto"
        >
            <svg
                viewBox="0 0 20 20"
                aria-hidden="true"
                className="h-4 w-4 shrink-0"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
            >
                <path d="M10 4v12M4 10h12" />
            </svg>
            {t('lists.make_new')}
            <svg
                viewBox="0 0 20 20"
                aria-hidden="true"
                className={`h-4 w-4 shrink-0 text-ink-soft transition-transform ${open ? 'rotate-180' : ''}`}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="m5 8 5 5 5-5" />
            </svg>
        </button>
    )
}
