import { kindIcons, type ListKind } from './ListKindBadge'
import ToolIcon from './ToolIcon'

/** A list named in a sentence: its name, and its kind when the sentence knows it. */
export interface ListRef {
    name: string
    kind?: ListKind | null
}

/**
 * A list's name inside running text, drawn so it reads as a list's name.
 *
 * The owner's rule (2026-09-26): "When you name a list in text, make clear it
 * is a list name by styling it consistently as a list name." Until then a name
 * sat in a sentence as plain text in one place, in quotes in another and bold
 * in a third — and nothing constrains what somebody calls a list, so "Bewaard
 * in voor mama" left the reader to work out where the name began.
 *
 * ## What it looks like, and why
 *
 * - **The kind's icon first**, the same drawing `ListKindBadge` and the Gift
 *   Cove's tool grid use (heart: your wish list; clipboard: a list for
 *   somebody; two figures: giving together). A list named without a kind — a
 *   Cove somebody bookmarked — gets the plain `list` mark, so every named list
 *   still carries a mark and the style never goes missing.
 * - **One colour for every kind** (`accent`), unlike the badge. The badge sits
 *   on a card and the colour helps tell three kinds apart at a glance; inside
 *   a sentence three colours would be three different-looking kinds of words,
 *   which is the inconsistency this component exists to remove.
 * - **Sized in `em`**, so the icon is as tall as the text it sits in —
 *   `h-3.5` beside `text-sm`, smaller in a `text-xs` hint — and nudged onto the
 *   baseline.
 * - **Medium weight, in `ink`**, so the name stands out of a `text-ink-soft`
 *   hint as well as out of body text, in both themes.
 * - **The icon and the first word never part.** A line break between them
 *   leaves a lone icon at the end of a line, pointing at nothing. Only the
 *   first word is held to it: a long name must still wrap.
 *
 * Headings and cards that *are* a list's title keep their own styling; this is
 * for a name inside a sentence. Quotes around the name in a translation are
 * dropped where it is placed (`rich()` in `useTranslations.ts`), because the
 * style now does what the quotes were there for.
 */
export default function ListName({ name, kind = null, className = '' }: ListRef & { className?: string }) {
    const space = name.search(/\s/)
    const first = space === -1 ? name : name.slice(0, space)
    const rest = space === -1 ? '' : name.slice(space)

    return (
        <span className={`font-medium text-ink ${className}`}>
            <span className="whitespace-nowrap">
                <ToolIcon
                    name={kind ? kindIcons[kind] : 'list'}
                    className="mr-[0.25em] inline-block h-[1em] w-[1em] shrink-0 align-[-0.125em] text-accent"
                />
                {first}
            </span>
            {rest}
        </span>
    )
}
