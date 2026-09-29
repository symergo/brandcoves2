import Button from './Button'
import ToolIcon from './ToolIcon'
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
 * point. Below `sm` it is the icon alone, beside the title; `py-2.5` keeps
 * the tap target at 44px.
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

    // The same button as Mijn mensen's "Iemand toevoegen" (owner, 2026-09-27):
    // filled while closed, outlined while its form is open, with its icon.
    // Only the icon on a phone (owner, 2026-09-29), so it fits beside the title.
    const label = t('lists.make_new')

    return (
        <Button
            variant={open ? 'secondary' : 'primary'}
            onClick={onToggle}
            aria-expanded={open}
            aria-controls={controls}
            aria-label={label}
            title={label}
        >
            <span className="inline-flex items-center gap-2">
                <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                <span className="hidden sm:inline">{label}</span>
            </span>
        </Button>
    )
}
