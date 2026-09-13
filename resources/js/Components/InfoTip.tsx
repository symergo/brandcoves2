import { type ReactNode, useId, useState } from 'react'
import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

/**
 * An explanation behind an (i), the site's standard since 2026-09-07.
 *
 * A form used to carry its reasoning inline: a sentence under every label, a
 * paragraph in every choice card, a note at the foot. Each was true and each
 * cost a line, and on a phone a step of the list wizard was a screen of
 * explanation with the controls between the paragraphs. The rule now is that
 * a control shows its label and the (i) beside it; the words are one tap
 * away for whoever wants them and cost nothing for whoever does not.
 *
 * Revealed in the flow rather than as a floating tooltip: a popover needs
 * room it does not have on a phone, and a hover is not a thing a thumb does.
 * The button is 32px on its own so it can sit inside a label or a legend
 * without turning the whole line into a target; it is real interactive
 * content, so a click on it inside a `<label>` does not toggle the label's
 * control.
 *
 * ## The note goes under the title, whatever the title sits in
 *
 * The wrapper is `display: contents`, so the button and the note take part
 * in the parent's layout directly, and the note is a full-width block. In
 * running text that is what a block already did: it breaks below the line
 * the icon is on. In a flex row it was not — the wrapper was one flex item,
 * so the note opened beside the heading, hanging off the icon, instead of
 * under the heading it explains (the lists page, 2026-09-13). A parent flex
 * row needs `flex-wrap` for the full-width note to drop to the next line;
 * `className` lands on the button, since a contents box has no margin.
 */
export default function InfoTip({ children, className = '' }: { children: ReactNode; className?: string }) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const id = useId()

    return (
        <span className="contents">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                aria-label={t('nav.info')}
                onClick={() => setOpen((v) => !v)}
                className={`-my-1 inline-flex h-8 w-8 items-center justify-center rounded-full align-middle transition hover:text-ink ${
                    open ? 'text-accent' : 'text-ink-soft'
                } ${className}`}
            >
                <ToolIcon name="info" className="h-4 w-4" />
            </button>
            {open && (
                <span
                    id={id}
                    role="note"
                    className="mt-1 block w-full rounded-lg border border-line bg-card p-3 text-sm font-normal text-ink-soft normal-case tracking-normal"
                >
                    {children}
                </span>
            )}
        </span>
    )
}
