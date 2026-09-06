import ListKindBadge, { type ListKind } from './ListKindBadge'
import { useTranslations } from '../useTranslations'

/**
 * Which of the three roles this reader has on this list.
 *
 * A list has exactly three: the **owner** who made it and is the only one who
 * can take things off it, a **contributor** who was let in to help and may add,
 * and the **recipient** the gifts are for. They are not a permission table —
 * that lives in `ListAccess` — they are what a reader needs to know before they
 * do anything, and each one reads the same page differently.
 */
export type ListRole = 'owner' | 'contributor' | 'recipient'

/**
 * The row of pills that says what a list is and what you are on it.
 *
 * ## Why one component and not three
 *
 * These pills were built on the index cards, where a card is read out of
 * context and has to explain itself. The list page and the shared page then
 * showed a subset, in a different order, some of it as prose instead — so the
 * same list said three different things about itself depending on which page
 * you reached it from, and "can I add something here?" was answered on one of
 * them and implied on the other two.
 *
 * One component, one order, everywhere: what kind of list, whose it is, what
 * you may do. Anything a page cannot know it simply omits.
 *
 * ## Why the sentence went away
 *
 * The shared page carried "Bvandoveren shared this list" as a line of prose
 * above the items. That is the *whose it is* pill, spelled out, in a second
 * place — and a fact stated twice on one screen in two shapes reads as two
 * facts. The pill keeps it, because a pill is what the other two pages already
 * used for it.
 */
export default function ListPills({
    kind,
    role,
    ownerName,
    canAdd = false,
    className = '',
}: {
    kind: ListKind
    /** Null when the page cannot say — an anonymous visitor to a shared link. */
    role: ListRole | null
    /** Whose list it is. Null for an anonymous owner, or when it is yours. */
    ownerName: string | null
    /** Whether somebody who is not the owner may put things on it. */
    canAdd?: boolean
    className?: string
}) {
    const { t } = useTranslations()

    return (
        <div className={`flex flex-wrap items-center gap-2 text-2xs ${className}`}>
            <ListKindBadge kind={kind} />

            {/*
              Whose it is, when it is not yours.

              Amber, and first after the kind: on any page that mixes your lists
              with lists you were let into, this is the fact that decides how to
              read everything else. Omitted on your own list, where saying "your
              list" on your list is furniture.
            */}
            {role !== 'owner' && (
                <span className="rounded-full border border-amber/60 bg-amber/15 px-2 py-0.5 font-medium text-ink">
                    {ownerName
                        ? t('lists.owned_by', { name: ownerName })
                        : t('lists.shared_with_me')}
                </span>
            )}

            {/*
              Adding, and only when it is on.

              There was a grey "Reading only" pill for the off case and it was
              simply wrong. Adding being off does not make a list read-only:
              anybody holding the link can still **claim** an item and still
              **suggest** one, which waits for the owner instead of going
              straight on. Naming the state "reading only" told people two
              things they could do were closed to them.

              So the pill appears when there is something extra to report and is
              absent otherwise — the ordinary state needs no label. Same for the
              owner, where it is the same fact from the other side: whether the
              people you sent it to can put things on the list.

              Only ever *adding*. Removing is the owner's, always: a contributor
              who could delete is a list that quietly loses items, including ones
              somebody has already claimed and bought.
              `WishlistItemController::destroy()` is the gate; this was never
              the sentence for it.
            */}
            {canAdd && (
                <span className="rounded-full border border-sage/40 bg-sage/10 px-2 py-0.5 font-medium text-ink">
                    {t('lists.adding_allowed')}
                </span>
            )}
        </div>
    )
}
