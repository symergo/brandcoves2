import ToolIcon, { type ToolKey } from './ToolIcon'
import { useTranslations } from '../useTranslations'

export type ListKind = 'mine' | 'for_someone' | 'group'

/**
 * A mark per kind, and all three already existed.
 *
 * The badge was three words in three identically grey pills, which is exactly
 * the shape you cannot tell apart at a glance — and a card is read at a glance,
 * on an index where every row carries one. A mark is recognised before a word
 * is read, and a colour before a mark; the badge now carries all three. See
 * {@link kindColours} for which colour and why the word is not the coloured
 * part.
 *
 * Drawn from `ToolIcon` rather than newly: the heart, the clipboard and the two
 * figures are what the Gift Cove's tool grid already calls a wish list, a list
 * for somebody else and buying together. A kind that looked one way on the
 * manual and another way on the badge would be two vocabularies for one idea —
 * and the drawings are the vocabulary people meet first.
 */
export const kindIcons: Record<ListKind, ToolKey> = {
    mine: 'wishlist',
    for_someone: 'giftlist',
    group: 'collab',
}

/**
 * A colour per kind, from the three the palette already has.
 *
 * Three grey pills with three different drawings in them still take a moment to
 * tell apart on an index where every row carries one — the mark is recognised
 * before the word, and the colour before the mark. `sage`, `amber` and `accent`
 * are the only three that are defined once and left alone by the dark theme, so
 * a badge means the same thing in both.
 *
 * The assignment is not arbitrary: sage for your own list, the calm one that is
 * simply yours; amber for a list about somebody else, warmer because it is about
 * a person; accent for a group gift, the loudest, because it is the one with
 * other people and their money in it.
 *
 * ## Why the tint and the border carry it, and not the text
 *
 * `amber` is #c4956a — about 2.4:1 against white, which is unreadable as body
 * text and would have made one badge of three fail on contrast while the other
 * two passed. So the word stays `text-ink`, which is near-black and flips with
 * the theme, and the colour lives in the background wash, the border and the
 * icon. Colour is then a *second* signal rather than the only one, which is
 * also what keeps the badge legible to somebody who cannot separate these three
 * hues.
 */
export const kindColours: Record<ListKind, { pill: string; icon: string }> = {
    mine: { pill: 'border-sage/40 bg-sage/10', icon: 'text-sage' },
    for_someone: { pill: 'border-amber/60 bg-amber/15', icon: 'text-amber' },
    group: { pill: 'border-accent/40 bg-accent/10', icon: 'text-accent' },
}

/**
 * What kind of list this is, and what that means you can do with it.
 *
 * The taxonomy decided everything — who may claim, who may vote, who sees the
 * money — and appeared on no screen. `Lists/Show` showed a title, a recipient
 * and a shared/private badge; `Lists/Index` carried the kind only in the
 * *section heading*, so a card read out of context (which is how a card is
 * read) said nothing at all.
 *
 * ## Two axes, and the sentence reads both
 *
 * The **badge names the kind** and never moves: a list does not change what it
 * is because somebody was invited to it, and a label that shifted underneath
 * people would undo the whole reason `ListKind` is chosen at creation rather
 * than derived.
 *
 * The **sentence reads kind *and* whether anybody else is on the list**, because
 * most lists are private and a private list of any kind offers none of the
 * mechanisms. Telling somebody with a personal list of saved things that
 * "people can claim these" describes an audience that does not exist.
 *
 * So a private list says what it is now, and then what sharing would do. That
 * second half is the only place these mechanisms are ever taught — a settings
 * panel is a worse teacher, because you have to already suspect the feature
 * exists to go and open it.
 */
export function useListKindWords() {
    const { t } = useTranslations()

    return {
        label: (kind: ListKind): string =>
            ({
                mine: t('lists.kind_mine'),
                for_someone: t('lists.kind_for_someone'),
                group: t('lists.kind_group'),
            })[kind],

        /*
         * Deliberately free of the recipient's name.
         *
         * Every surface that renders this already names the person a line or
         * two above — `Lists/Show` under the title, `Lists/Shared` in the
         * heading — so interpolating it here would say it twice. It also keeps
         * the four languages honest: a name dropped into a sentence needs
         * different grammar in each of them, and the two that would need it
         * most are the two where it is already on screen.
         */
        sentence: (kind: ListKind, shared: boolean): string =>
            t(`lists.about_${kind}_${shared ? 'shared' : 'private'}`),
    }
}

export default function ListKindBadge({
    kind,
    className = '',
}: {
    kind: ListKind
    className?: string
}) {
    const { label } = useListKindWords()
    const colours = kindColours[kind]

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium text-ink ${colours.pill} ${className}`}
        >
            {/*
              The mark, then the word. Both, not one: the icon is what makes a
              row of cards scannable, and the word is what makes the icon mean
              something the first time somebody sees it. `ToolIcon` is
              `aria-hidden` throughout, so a screen reader hears the label alone
              — which is the whole of the information.
            */}
            <ToolIcon name={kindIcons[kind]} className={`h-3.5 w-3.5 ${colours.icon}`} />
            {label(kind)}
        </span>
    )
}
