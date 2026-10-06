import { MenuItem, MenuSeparator } from './Menu'
import ToolIcon from './ToolIcon'

/** What the ⋯ of a list row needs to know about the list. */
export interface ListActionTarget {
    id: string
    url: string
    kind: string
    /** Has it a live link? The quiz exists only over a shared wish list. */
    shared: boolean
    /** The person a gift list or a group gift is about. */
    recipient: { id: string; name: string } | null
}

/**
 * The ⋯ menu of one of your own lists, per kind: one function for Mijn Coves
 * and a person's page, so the same list offers the same actions on both
 * (consistency review, round 2, 2026-09-27). Until then the person's page
 * offered two of the six.
 *
 * The list page's own ⋯ per kind (owner, 2026-09-27: "the ... menu for the
 * group lists should contain more actions, check the list page itself"),
 * under the conditions `ListToolsBar` uses; each item opens the list on that
 * tool (`?panel=`). Left out: This or that together and Secret Friend, which
 * the list page shows only while that list has one, which a row does not
 * know.
 *
 * In the list page's order (2026-10-06): settings, ask them, the quiz, ask
 * others, their page, delete. The two lists had the same items in two orders.
 *
 * `onPersonPage`: the row is on the page of the person the list is about, so
 * "Wat je :name gaf" (a link to that same page) is left out.
 *
 * Delete is last, apart and red, and asks first: the caller passes what to do,
 * which is a `ConfirmDialog`.
 */
export function listActionItems({
    list,
    base,
    t,
    onDelete,
    onPersonPage = false,
}: {
    list: ListActionTarget
    base: string
    t: (key: string, replacements?: Record<string, string | number>) => string
    onDelete: () => void
    onPersonPage?: boolean
}) {
    const aboutSomebody = list.kind === 'for_someone' || list.kind === 'group'
    const icon = (name: Parameters<typeof ToolIcon>[0]['name']) => <ToolIcon name={name} className="h-4 w-4" />

    return (
        <>
            <MenuItem href={`${list.url}?panel=settings`} icon={icon('settings')}>
                {t('lists.settings')}
            </MenuItem>
            {aboutSomebody && list.recipient && (
                <MenuItem href={`${list.url}?panel=ask`} icon={icon('suggestions')}>
                    {t('lists.ask_tab', { name: list.recipient.name })}
                </MenuItem>
            )}
            {list.kind === 'mine' && list.shared && (
                <MenuItem href={`${list.url}?panel=quiz`} icon={icon('quiz')}>
                    {t('quiz.badge')}
                </MenuItem>
            )}
            {aboutSomebody && (
                <MenuItem href={`${base}/ask?list=${list.id}`} icon={icon('board')}>
                    {t('lists.ask_others')}
                </MenuItem>
            )}
            {aboutSomebody && list.recipient && !onPersonPage && (
                <MenuItem href={`${base}/people/${list.recipient.id}`} icon={icon('people')}>
                    {t('gift_history.link', { name: list.recipient.name })}
                </MenuItem>
            )}
            <MenuSeparator />
            <MenuItem danger icon={icon('trash')} onSelect={onDelete}>
                {t('lists.delete')}
            </MenuItem>
        </>
    )
}
