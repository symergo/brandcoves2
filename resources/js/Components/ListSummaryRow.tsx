import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AddProduct from './AddProduct'
import Badge from './Badge'
import { rowActionClasses } from './Button'
import { listActionItems } from './listActions'
import type { ListKind } from './ListKindBadge'
import ListPills from './ListPills'
import ListRow, { ListRowBadges, ListRowMeta, ListRowTitle, ListThumb } from './ListRow'
import Menu, { MoreButtonContent } from './Menu'
import Modal, { useConfirm } from './Modal'
import ShareSettings, { type ShareableList } from './ShareSettings'
import ToolIcon from './ToolIcon'
import { invalidate } from '../savedItems'
import { formatDay, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * A list as a row of Mijn Coves, from `summarise()` plus the overview's own
 * fields. A person's page sends the same shape for the lists you make for
 * them (`PersonProfile::listsForThem()`), so both pages draw one row.
 */
export interface ListSummary {
    id: string
    title: string
    kind: string
    isDefault: boolean
    visibility: string
    itemCount: number
    covers: string[]
    url: string
    recipient: { id: string; name: string } | null
    /**
     * Suggestions waiting on a decision. Null on a list somebody else owns —
     * that message is addressed to them, not to me.
     */
    suggestions: number | null
    /**
     * Somebody else's list that I have been let into, rather than one of mine.
     * What I may do with the two is not the same, and a list I merely have
     * access to can be changed out from under me by the person who owns it.
     */
    sharedWithMe: boolean
    /** May somebody who is not the owner put things on it? From `summarise()`. */
    linkCanAdd: boolean
    /** Who owns it. Null on my own rows, where the answer is me. */
    ownerName: string | null
    /** `viewer` or `editor`, on a list shared with me. */
    role: string | null
    /** Your own list's link once it is shared; null while private or not yours. */
    shareUrl?: string | null
    /** A wish list of yours shown to your people; null on other kinds. From `summarise()`. */
    visibleToFriends?: boolean | null
    pledgersVisible?: boolean
    votingEnabled?: boolean
    /** On the site as a Community Cove (published, not hidden). From `summarise()`. */
    published?: boolean
    /** The occasion's day, `YYYY-MM-DD`, when the list has one. */
    eventDate?: string | null
}

/** A row's action; the recipe and why it is never filled are in `rowActionClasses`. */
const rowAction = rowActionClasses()

/**
 * One list, as one row: the way My people draws a person (owner, 2026-09-27:
 * "design the My Coves list in the same way as the My People list").
 *
 * The picture, then the name, how many items and for whom, and the pills; on
 * the right what you do to it: add, share (a popup), and ⋯ with the list's
 * tools per kind (`listActionItems`).
 *
 * "Shared" means two different things here and must not be confused: `shared`
 * is *I have published this outward*, `theirs` is *this is not mine at all*.
 * Share and ⋯ are for your own lists only; somebody else's is theirs to share
 * and set up. Adding needs a list you may add to.
 */
export default function ListSummaryRow({
    list,
    friends,
    onPersonPage = false,
}: {
    list: ListSummary
    /** The owner's friends, named under "Visible to my people" in the share popup. */
    friends?: { name: string }[]
    /** On the page of the person the list is about (see `listActionItems`). */
    onPersonPage?: boolean
}) {
    const { t, n } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const base = `/${market.key}`
    const shared = list.visibility !== 'private'
    const theirs = list.sharedWithMe
    const [adding, setAdding] = useState(false)
    const [sharing, setSharing] = useState(false)
    const [confirm, confirmDialog] = useConfirm()
    const canAdd = !theirs || list.role === 'editor'
    const slots = ((canAdd ? 1 : 0) + (theirs ? 0 : 2)) as 0 | 1 | 2 | 3

    const remove = async () => {
        if (await confirm({ message: t('lists.delete_confirm'), confirmLabel: t('lists.delete'), danger: true })) {
            // `stay`: back to the page the row is on (a person's page), not to
            // Mijn Coves, which is where a delete from the list page itself goes.
            // The save buttons elsewhere must stop reporting products as saved
            // into a list that is gone, hence `invalidate()`.
            router.delete(`${base}/lists/${list.id}`, {
                data: { stay: true },
                preserveScroll: true,
                onSuccess: () => invalidate(),
            })
        }
    }

    return (
        <>
            <ListRow
                href={list.url}
                thumb={<ListThumb covers={list.covers} kind={list.kind} />}
                slots={slots}
                actions={
                    canAdd || !theirs ? (
                        <>
                            {canAdd && (
                                <button
                                    type="button"
                                    onClick={() => setAdding(true)}
                                    aria-label={t('lists.add_product')}
                                    title={t('lists.add_product')}
                                    className={rowAction}
                                >
                                    <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                                    <span className="hidden sm:inline">{t('people.add')}</span>
                                </button>
                            )}
                            {!theirs && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setSharing(true)}
                                        aria-label={`${t('lists.share')}: ${list.title}`}
                                        title={t('lists.share')}
                                        className={rowAction}
                                    >
                                        <ToolIcon name="shared" className="h-4 w-4 shrink-0" />
                                        <span className="hidden sm:inline">{t('lists.share')}</span>
                                    </button>
                                    <Menu
                                        label={t('people.list_actions', { name: list.title })}
                                        button={<MoreButtonContent word={t('people.more')} />}
                                        buttonClassName={rowAction}
                                    >
                                        {(close) =>
                                            listActionItems({
                                                list: { ...list, shared, recipient: list.recipient },
                                                base,
                                                t,
                                                onPersonPage,
                                                onDelete: () => {
                                                    close()
                                                    void remove()
                                                },
                                            })
                                        }
                                    </Menu>
                                </>
                            )}
                        </>
                    ) : undefined
                }
            >
                <ListRowTitle>{list.title}</ListRowTitle>
                <ListRowMeta>
                    {list.itemCount === 1 ? t('lists.one_item') : t('lists.items', { count: n(list.itemCount) })}
                    {/*
                      Who the list is for: on a list about somebody, the
                      recipient (not on that person's own page, which says so
                      in its title); on a wish list somebody shared with me,
                      its owner, because that is the person I shop for.
                    */}
                    {list.kind !== 'mine' && list.recipient && !onPersonPage && ` · ${list.recipient.name}`}
                    {theirs && list.kind === 'mine' && list.ownerName && ` · ${list.ownerName}`}
                    {/* The occasion's day: a person's page showed it, Mijn Coves did not. */}
                    {list.eventDate && ` · ${formatDay(list.eventDate, market, { year: 'auto' })}`}
                </ListRowMeta>
                {/* Somebody else's wish list is how I shop for them: say so. */}
                {theirs && list.kind === 'mine' && list.ownerName && (
                    <span className="mt-0.5 block text-sm text-accent-dark">{t('lists.shop_for', { name: list.ownerName })}</span>
                )}
                <ListRowBadges>
                    <ListPills
                        kind={list.kind as ListKind}
                        role={theirs ? 'contributor' : 'owner'}
                        ownerName={theirs ? list.ownerName : null}
                        canAdd={list.visibility !== 'private' && list.linkCanAdd}
                    />
                    {list.isDefault && <Badge size="xs">{t('lists.default_badge')}</Badge>}
                    {/*
                      Publishing to the community is not the share link: a list
                      can have either, both or neither (community-coves.md). So
                      "Privé" only when it has neither; a published list says
                      so, and keeps "Gedeeld" beside it when it also has a link.
                    */}
                    {!theirs && list.published && (
                        <Badge size="xs" tone="sage">
                            {t('lists.published_short')}
                        </Badge>
                    )}
                    {!theirs && (shared || !list.published) && (
                        <Badge size="xs" tone={shared ? 'sage' : 'neutral'}>
                            {shared ? t('lists.shared_short') : t('lists.private_short')}
                        </Badge>
                    )}
                    {/* Somebody put something forward and it is waiting on you. */}
                    {list.suggestions !== null && list.suggestions > 0 && (
                        <Badge size="xs" tone="accent">
                            {list.suggestions === 1 ? t('suggestions.one_waiting') : t('suggestions.waiting', { count: n(list.suggestions) })}
                        </Badge>
                    )}
                </ListRowBadges>
            </ListRow>
            {adding && <AddToListDialog list={list} onClose={() => setAdding(false)} />}
            {sharing && <ShareListDialog list={list} friends={friends} onClose={() => setSharing(false)} />}
            {confirmDialog}
        </>
    )
}

/**
 * Share, as a popup over the row (owner, 2026-09-27: "share button should show
 * popup interface"), rather than a trip to the list page: whether it is
 * shared, the link to copy or send, and what the link allows. The switches are
 * the list page's own (`ShareSettings`), so the popup and the list page's
 * share panel say the same thing. Who gets the link by name, publishing and
 * handing a list over stay on the list page's share panel. Each switch is the
 * list page's PATCH; the page's props come back with the new state and the
 * popup stays open over them.
 */
function ShareListDialog({ list, friends, onClose }: { list: ListSummary; friends?: { name: string }[]; onClose: () => void }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const shareable: ShareableList = {
        title: list.title,
        kind: list.kind,
        shareUrl: list.shareUrl ?? null,
        visibleToFriends: list.visibleToFriends ?? null,
        linkCanAdd: list.linkCanAdd,
        pledgersVisible: Boolean(list.pledgersVisible),
        votingEnabled: Boolean(list.votingEnabled),
    }

    return (
        <Modal title={list.title} label={`${t('lists.share')}: ${list.title}`} onClose={onClose}>
            <div className="mt-3">
                <ShareSettings
                    list={shareable}
                    friends={friends}
                    onSetting={(data, done) =>
                        router.patch(`/${market.key}/lists/${list.id}`, data, {
                            preserveScroll: true,
                            preserveState: true,
                            onFinish: done,
                        })
                    }
                />
            </div>
        </Modal>
    )
}

/**
 * The add panel of a list page, over the row, for one list. Adding or
 * cancelling closes the popup, and the server answers with a toast naming the
 * list (`onListPage={false}`), since the list is not on screen.
 */
function AddToListDialog({ list, onClose }: { list: ListSummary; onClose: () => void }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Swiping as another way to fill this list (owner, 2026-10-01): right
     * swipes go straight into it (`?list=`), and Stop comes back to it. A list
     * about somebody starts from what is known about them (`&person=`).
     */
    const swipe = `/${market.key}/gift/swipe?list=${encodeURIComponent(list.id)}${
        list.recipient ? `&person=${encodeURIComponent(list.recipient.id)}` : ''
    }`

    return (
        <Modal title={list.title} onClose={onClose} width="lg">
            <div className="mt-3">
                <AddProduct base={`/${market.key}`} listId={list.id} market={market} defaultOpen onListPage={false} onClose={onClose} />
            </div>
            <Link
                href={swipe}
                className="mt-4 flex items-center gap-3 rounded-card border border-line bg-card p-3 transition hover:border-ink"
            >
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                    <ToolIcon name="swipe" className="h-5 w-5" />
                </span>
                <span className="min-w-0">
                    <span className="block font-medium">{t('lists.add_swipe')}</span>
                    <span className="block text-sm text-ink-soft">{t('lists.add_swipe_hint')}</span>
                </span>
                <span className="ml-auto shrink-0 text-accent-dark" aria-hidden>
                    →
                </span>
            </Link>
        </Modal>
    )
}
