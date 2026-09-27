import type { ReactNode } from 'react'
import InfoTip from './InfoTip'

/**
 * One choice, as a card you press rather than a dot you aim at.
 *
 * The sharing settings were bare inputs with their label and a grey hint
 * running the full width of the panel — about 1,100px on a laptop, so a
 * two-line explanation became one very long line and the eye had to travel back
 * across the whole page to find the next option. Four of them stacked like that
 * read as a form to fill in rather than a question to answer, which is the
 * opposite of what these are: nothing here is typed, every one is a choice
 * between two stated outcomes.
 *
 * So each option is a bordered card, the whole of it is the hit target, and the
 * selected one is tinted. That gives the group a shape you can take in without
 * reading it, and makes the target a finger rather than a 13px circle.
 *
 * ## The explanation is behind an (i) (2026-09-27)
 *
 * `tip` is the explanation, one tap away, as the site standard has it since
 * 2026-09-07; the card itself carries only its label. Until round 2 of the
 * consistency review the same switch drew its explanation on screen in the
 * share popup and behind an (i) in the list page's share panel. `note` is for
 * a line that is a fact rather than an explanation (who can see it right now),
 * and stays visible. A press on the (i) does not tick the box: InfoTip's button
 * is interactive content inside the label.
 *
 * Moved out of `ListTools` so the share popup and the share panel can both use
 * it through `ShareSettings` without importing each other.
 */
export default function Option({
    type,
    name,
    checked,
    onChange,
    label,
    tip,
    note,
}: {
    type: 'radio' | 'checkbox'
    name?: string
    checked: boolean
    onChange: () => void
    label: string
    tip?: ReactNode
    note?: ReactNode
}) {
    return (
        <label
            className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${
                checked ? 'border-accent bg-accent/5' : 'border-line hover:border-ink/30'
            }`}
        >
            <input type={type} name={name} checked={checked} onChange={onChange} className="mt-0.5 shrink-0" />
            <span
                className="flex min-w-0 flex-1 flex-wrap items-center"
                onClick={(e) => {
                    // Reading the opened explanation is not an answer: a press
                    // on its text would otherwise reach the label and flip the
                    // switch (a privacy switch, here). A link inside it still works.
                    const target = e.target as HTMLElement

                    if (target.closest('[data-infotip-note]') && !target.closest('a')) {
                        e.preventDefault()
                    }
                }}
            >
                <span className="text-sm font-medium">{label}</span>
                {tip && <InfoTip>{tip}</InfoTip>}
                {note && <span className="block w-full text-xs text-ink-soft">{note}</span>}
            </span>
        </label>
    )
}
