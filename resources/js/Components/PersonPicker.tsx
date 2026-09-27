import { usePage } from '@inertiajs/react'
import type { ReactNode } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatCountdown, formatDay } from '../types'
import { useTranslations } from '../useTranslations'
import Badge from './Badge'
import ToolIcon from './ToolIcon'

/**
 * Somebody you can choose, in the shape a card needs.
 *
 * The same row `App\Services\Social\MyPeople` sends (Find a gift receives it
 * whole); the list wizard and This or that build it from what their pages
 * already carry. Everything past `name` is optional: a card shows what it is
 * given and leaves the rest out, so a page that knows less draws a shorter
 * card, never a different one.
 */
export interface PickablePerson {
    /** Stable per row: `p:<id>` for a saved person, `f:<id>` for a friend nobody saved. */
    key: string
    name: string
    /** "Mama", or what was typed. Hidden when it only repeats the name. */
    relationship?: string | null
    /** The saved person behind the row; null for a friend nobody saved yet. */
    personId: string | null
    /** An account you are connected to: drawn with "op GiftCoves". */
    friend: { id: number } | null
    /** The nearest date: their birthday, or an occasion on a list about them. */
    next?: { date: string; days: number; kind: 'birthday' | 'occasion'; title: string | null } | null
    known?: { interests: string[]; budgetMin?: Cents | null; budgetMax?: Cents | null } | null
}

/**
 * "Who is it for?", drawn once (consistency review, round 3, 2026-09-27).
 *
 * The owner asked for "the cards of the people you know instead of just the
 * name" on Find a gift, and that page got them; the list wizard still showed a
 * row of name chips and This or that a row of "Bewaar bij …" buttons. Three
 * ways to choose the same people from the same list read as three different
 * lists. Now one card everywhere: the initial, the name, the relationship, "op
 * GiftCoves" for a friend, the next date with its countdown, and what you know
 * of their interests.
 *
 * - `cards` (default): Find a gift's full cards, three to a row on a wide
 *   screen.
 * - `compact`: the same card with a smaller mark and one line of facts, for a
 *   form or a panel inside a card (the list wizard, This or that's result).
 *
 * The picker only draws and reports. What a choice *does* stays with the page:
 * Find a gift saves a friend as a person first, the list wizard fills its form,
 * This or that saves the result. A card is a button with `aria-pressed`, so the
 * chosen one is announced as well as outlined.
 *
 * Nothing here reads claim state or anything about a list's contents; the
 * facts are the ones My people already shows the same person (invariant 4).
 */
export default function PersonPicker({
    people,
    isChosen,
    onChoose,
    busyKey = null,
    disabled = false,
    variant = 'cards',
    label,
    trailing,
}: {
    people: PickablePerson[]
    isChosen: (person: PickablePerson) => boolean
    onChoose: (person: PickablePerson) => void
    /** The card whose choice is being saved: disabled and dimmed until it lands. */
    busyKey?: string | null
    /** Every card, while something else is in flight. */
    disabled?: boolean
    variant?: 'cards' | 'compact'
    /** A line above the cards ("Iemand die je kent"). */
    label?: ReactNode
    /** Words appended to each card's accessible name, e.g. what pressing it does. */
    trailing?: (person: PickablePerson) => string | undefined
}) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const compact = variant === 'compact'

    if (people.length === 0) {
        return null
    }

    return (
        <div>
            {label && <p className="text-sm text-ink-soft">{label}</p>}
            <ul className={`${label ? 'mt-2' : ''} grid gap-2 sm:grid-cols-2 lg:grid-cols-3 ${compact ? '' : 'sm:gap-3'}`}>
                {people.map((person) => {
                    const on = isChosen(person)
                    const interests = person.known?.interests ?? []
                    const relationship =
                        person.relationship && person.relationship.toLowerCase() !== person.name.toLowerCase()
                            ? person.relationship
                            : null
                    const extra = trailing?.(person)

                    // The date line, or on a compact card with no date, what you know.
                    const dateLine = person.next ? (
                        <span className="mt-0.5 flex flex-wrap items-center text-sm text-ink-soft">
                            {person.next.kind === 'birthday' ? (
                                <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />
                            ) : (
                                person.next.title && <span className="mr-1">{person.next.title} ·</span>
                            )}
                            {formatDay(person.next.date, market)}
                            {' · '}
                            <span className={person.next.days <= 14 ? 'font-medium text-ink' : ''}>
                                {formatCountdown(person.next.days, t)}
                            </span>
                        </span>
                    ) : null
                    const interestLine =
                        interests.length > 0 ? (
                            <span className="mt-0.5 block truncate text-sm text-ink-soft">{interests.slice(0, 3).join(', ')}</span>
                        ) : null

                    return (
                        <li key={person.key}>
                            <button
                                type="button"
                                aria-pressed={on}
                                aria-label={extra ? `${person.name}, ${extra}` : undefined}
                                disabled={disabled || busyKey === person.key}
                                onClick={() => onChoose(person)}
                                className={`flex h-full w-full items-start rounded-card border text-left transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                    compact ? 'gap-2.5 px-3 py-2.5' : 'gap-3 p-4'
                                } ${on ? 'border-accent bg-accent/5' : 'border-line bg-card hover:border-ink'}`}
                            >
                                <span
                                    aria-hidden
                                    className={`flex shrink-0 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent ${
                                        compact ? 'h-8 w-8 text-sm' : 'h-10 w-10'
                                    }`}
                                >
                                    {person.name.slice(0, 1).toUpperCase()}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-x-2">
                                        <span className="font-medium text-ink">{person.name}</span>
                                        {relationship !== null && <span className="text-sm text-ink-soft">{relationship}</span>}
                                        {person.friend !== null && (
                                            <Badge tone="accent" size="xs">
                                                {t('people.on_giftcoves')}
                                            </Badge>
                                        )}
                                    </span>
                                    {/* A compact card has room for one line of facts: the date if there is one. */}
                                    {compact ? (dateLine ?? interestLine) : (
                                        <>
                                            {dateLine}
                                            {interestLine}
                                        </>
                                    )}
                                </span>
                                {on && <ToolIcon name="check" className="mt-0.5 h-4 w-4 shrink-0 text-accent" />}
                            </button>
                        </li>
                    )
                })}
            </ul>
        </div>
    )
}
