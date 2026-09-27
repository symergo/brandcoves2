import { type ReactNode, useState } from 'react'
import { useTranslations } from '../useTranslations'
import { fieldClasses } from './Button'
import { kindIcons, type ListKind } from './ListKindBadge'
import ToolIcon from './ToolIcon'

/** One of your lists, as a picker row needs it. */
export interface PickableList {
    id: string
    /** What the row says: the list's name, or for a list about somebody their name. */
    title: string
    /** Draws the kind's icon; none known (an older payload) draws the plain list mark. */
    kind?: ListKind | null
    /** Your default list: first in its section. */
    isDefault?: boolean
}

/** One group of rows: the save picker has three (for me, for somebody, together), a copy menu one. */
export interface ListPickerSection {
    key: string
    heading?: ReactNode
    lists: PickableList[]
    /** The "+ …" row under the section, e.g. "Maak een Cove". */
    create?: { label: ReactNode; onClick: () => void }
}

/**
 * The default list first, or `first` when the page has a better guess (the
 * list a press on Save goes to). Otherwise the order it arrived in, which the
 * server fixed so a menu never reorders itself under a pointer
 * (App\Services\Wishlist\ListOptions).
 */
export function defaultFirst<T extends PickableList>(lists: T[], first: string | null = null): T[] {
    const rank = (list: T) => (first !== null ? Number(list.id === first) * 2 : 0) + Number(list.isDefault === true)

    return [...lists].sort((a, b) => rank(b) - rank(a))
}

const rowClass =
    'flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm outline-none hover:bg-line/40 focus-visible:bg-line/60 focus-visible:ring-2 focus-visible:ring-accent/40 disabled:cursor-not-allowed disabled:opacity-50'

/**
 * "Pick one of your lists, or make a new one", drawn once (consistency
 * review, round 3, 2026-09-27).
 *
 * Three controls asked it: the Save button's sheet (`SaveToList`), the copy
 * menu on a shared list's hand-written items (`CopyToList`) and "Kopieer naar"
 * in the ⋯ on an item of your own list (`OwnItemMenu`). They had drifted: a
 * tick box and the list's name in one, a bare title in the second, a menu
 * item in the third; "+ Maak een Cove" in accent in two and a different form
 * behind it in each. The same question now reads the same: the kind's icon
 * before each name (the icon `ListName` uses in a sentence, so a list looks
 * like a list everywhere), the default list first, and "Maak een Cove" to
 * make one on the spot.
 *
 * Only the body is shared. What a press does stays with each caller, with
 * its own endpoint: a save or an untick (`/list-items`), a copy of a row
 * (`…/items/{item}/copy`). So does the shell: a sheet, a portal dropdown or a
 * `Menu`. Inside a `Menu` the rows are `menuitem`s (`inMenu`), so the arrow
 * keys move through them as through the menu's other items.
 *
 * `isChecked` turns each row into a tick box (the save sheet: a product may
 * sit on several lists, and a row is a toggle). Without it a row is an action.
 */
export default function ListPicker({
    hint,
    sections,
    onPick,
    isChecked,
    rowTitle,
    inMenu = false,
    disabled = false,
}: {
    /** One line above the rows: what a press does ("Naar welke lijst?"). */
    hint?: ReactNode
    sections: ListPickerSection[]
    onPick: (list: PickableList) => void
    isChecked?: (list: PickableList) => boolean
    /** A tooltip per row, e.g. "Bewaar in …" / "Haal van …". */
    rowTitle?: (list: PickableList, on: boolean) => string
    inMenu?: boolean
    disabled?: boolean
}) {
    const itemRole = inMenu ? { role: 'menuitem', tabIndex: -1 } : {}

    return (
        <>
            {hint && <p className="px-2 pt-1 pb-2 text-sm text-ink-soft">{hint}</p>}
            {sections.map((section, index) => (
                <div key={section.key}>
                    {section.heading && (
                        <p
                            className={`${index > 0 || hint ? 'border-t border-line' : ''} ${
                                index > 0 ? 'mt-2' : ''
                            } px-2 pt-2 pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase`}
                        >
                            {section.heading}
                        </p>
                    )}
                    {section.lists.map((list) => {
                        const on = isChecked?.(list) ?? null

                        return (
                            <button
                                key={list.id}
                                type="button"
                                // A tick box when `isChecked` is given (the save sheet, never in a `Menu`).
                                {...(on === null ? itemRole : { role: 'checkbox', 'aria-checked': on })}
                                disabled={disabled}
                                onClick={() => onPick(list)}
                                title={rowTitle?.(list, on === true)}
                                className={`${rowClass} ${
                                    on ? 'border border-sage bg-sage/15 font-medium text-sage' : 'border border-transparent'
                                }`}
                            >
                                {on !== null && (
                                    /*
                                      A real-looking box: the row is a selection,
                                      and a box is the one shape everybody reads
                                      as "tick me". Sage when on, like the filled
                                      bookmark, so the state is said more than
                                      once for a reader who catches one.
                                    */
                                    <span
                                        aria-hidden
                                        className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${
                                            on ? 'border-sage bg-sage text-white' : 'border-line bg-card'
                                        }`}
                                    >
                                        {on && <ToolIcon name="check" className="h-3 w-3" />}
                                    </span>
                                )}
                                <ToolIcon
                                    name={list.kind ? kindIcons[list.kind] : 'list'}
                                    className={`h-4 w-4 shrink-0 ${on ? '' : 'text-accent'}`}
                                />
                                <span className="min-w-0 flex-1 truncate">{list.title}</span>
                            </button>
                        )
                    })}
                    {section.create && (
                        <button
                            type="button"
                            {...itemRole}
                            disabled={disabled}
                            onClick={section.create.onClick}
                            className={`${rowClass} border border-transparent font-medium text-accent-dark`}
                        >
                            <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                            <span className="min-w-0 flex-1">{section.create.label}</span>
                        </button>
                    )}
                </div>
            ))}
        </>
    )
}

/**
 * Naming the new list (or the person it is for), inside the picker. One form
 * for the three: a label, the field, the action, and a way back to the rows.
 * Enter submits; an empty name does nothing.
 */
export function ListPickerNameForm({
    label,
    submitLabel,
    onSubmit,
    onCancel,
    busy = false,
    maxLength = 120,
}: {
    label: ReactNode
    submitLabel: ReactNode
    onSubmit: (name: string) => void
    onCancel?: () => void
    busy?: boolean
    maxLength?: number
}) {
    const { t } = useTranslations()
    const [name, setName] = useState('')

    return (
        <form
            className="p-2"
            onSubmit={(e) => {
                e.preventDefault()

                if (name.trim() !== '') onSubmit(name.trim())
            }}
        >
            <label className="block text-xs font-medium">
                {label}
                <input
                    autoFocus
                    required
                    maxLength={maxLength}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={fieldClasses()}
                />
            </label>
            <div className="mt-2 flex gap-2">
                <button
                    type="submit"
                    disabled={busy || name.trim() === ''}
                    className="flex-1 rounded bg-accent px-3 py-1.5 text-sm font-medium text-white hover:bg-accent-dark disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {submitLabel}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className="rounded border border-line px-3 py-1.5 text-sm hover:border-ink">
                        {t('lists.cancel')}
                    </button>
                )}
            </div>
        </form>
    )
}
