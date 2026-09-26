import { useTranslations } from '../useTranslations'

/**
 * The "Create a Cove" button: one name for the one way a list is made.
 *
 * The home page's hero said "Create a Cove" and this button said "Make a new
 * list", and both led to the same screen, so a reader met one thing under two
 * names (UX audit, 2026-09-26). It now says what the hero says, from the same
 * words in every language (`lists.make_new` = `home.cta_create`).
 *
 * A plain button since the one-step create: it had a chevron and read as a
 * dropdown, a menu of kinds. The kind is now chosen on the screen it opens,
 * in one question, so there is nothing to drop down. It still says whether
 * that screen is open (`aria-expanded`), because on My Lists it shows the
 * screen in place rather than going somewhere.
 *
 * Outlined rather than filled: on My Lists the page under the button is the
 * point. Full width only below `sm`, where an inline button beside nothing
 * looks like it fell off a toolbar; `py-2.5` keeps the tap target at 44px.
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
        </button>
    )
}
