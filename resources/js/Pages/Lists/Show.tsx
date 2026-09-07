import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import AddProduct from '../../Components/AddProduct'
import Pledge, { type Contributions } from '../../Components/Pledge'
import type { SharedProps } from '../../types'
import ListTools, { type Panel } from '../../Components/ListTools'
import { type ListKind } from '../../Components/ListKindBadge'
import EditManualItem from '../../Components/EditManualItem'
import SaveToList from '../../Components/SaveToList'
import ListItemCard from '../../Components/ListItemCard'
import ListPills, { type ListRole } from '../../Components/ListPills'
import ListBoard, { type BoardState } from '../../Components/ListBoard'
import CopyToList, { type CopyTarget } from '../../Components/CopyToList'
import { invalidate, markRemoved } from '../../savedItems'
import { useTranslations } from '../../useTranslations'

interface Item {
    id: number
    title: string
    image: string | null
    price: number | null
    currentPrice: number | null
    note: string | null
    groupId: number | null
    /** Off-site, for a hand-written item. Never an Inertia visit. */
    externalUrl: string | null
    /** Typed by somebody rather than saved from the catalogue, so its title,
     *  link and price are theirs to correct. */
    manual: boolean
    /**
     * Our page for it — already carrying the market the *product* is in, which
     * is not necessarily the one this page is being read in. A list is not
     * scoped to a market, so it is built server-side by
     * `WishlistItem::productPath()` rather than from `base` here.
     */
    url: string | null
    merchantCount: number
    inStock: boolean
}

interface Asked {
    id: number
    token: string
    listTitle: string
    title: string
    image: string | null
    price: number | null
    note: string | null
    live: boolean
    url: string | null
    claimed: boolean
    claimedByMe: boolean
    sent: boolean | null
}

interface Collaborator {
    id: number
    name: string | null
    role: string
}

interface Membership {
    groupId: string
    title: string
    attached: boolean
}

interface Suggestion {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    from: string | null
}

interface Props {
    /**
     * The owner's friends, for "Share with friends".
     *
     * Empty for anybody who is not the owner. Sharing is a row per person you
     * picked, not a switch. See the ListSharer service.
     */
    friends: { id: number; name: string }[]
    /** Which of the three roles this reader has. Decided on the server. */
    role: ListRole
    /** Whose list it is, when it is not yours. Null for an anonymous owner. */
    ownerName: string | null
    access: { isOwner: boolean; canEdit: boolean }
    suggestions: Suggestion[]
    canHandOver: boolean
    handoverEmail: string | null
    registryOptions: { value: string; label: string }[]
    deliveryAddress: string | null
    collaborators: Collaborator[]
    quizUrl: string | null
    quizPlays: number
    santaMemberships: Membership[]
    target: { name: string; isLinked: boolean; askUrl: string | null } | null
    asked: Asked[]
    list: {
        id: string
        title: string
        kind: string
        claimable: boolean
        visibility: string
        shareUrl: string | null
        recipient: { name: string } | null
        isDefault: boolean
        handedOver: boolean
        eventType: string | null
        eventDate: string | null
        hasCoGivers: boolean
        claimVisibility: string
        ownerSeesClaims: boolean
        linkCanAdd: boolean
        /** Who this list has already been shared with, by id. */
        sharedWith: number[]
        pledgersVisible: boolean
        /** Cents per person on a group gift, or null for "everyone names their own". */
        pledgeAmount: number | null
        votingEnabled: boolean
        /** The owner's own words, under the title. Null when they wrote none. */
        description: string | null
    }
    items: Item[]
    /** The pot on a group list, for the organiser's own page. */
    pot: Contributions | null
    /**
     * The discussion beside the list. Null for anybody who may not see one —
     * on a wish list of your own that is you, because a board is claim state in
     * prose. Its absence is the privacy rule, not a loading state.
     */
    board: BoardState | null
    /**
     * Every list this person may write to, minus this one.
     *
     * Empty is the ordinary case for somebody with a single list, and the
     * control renders nothing rather than a dead button.
     */
    copyTargets: CopyTarget[]
}

export default function ListShow({
    list,
    friends,
    role,
    ownerName,
    items,
    pot,
    target,
    asked,
    access,
    collaborators,
    suggestions,
    canHandOver,
    handoverEmail,
    registryOptions,
    deliveryAddress,
    quizUrl,
    quizPlays,
    santaMemberships,
    board,
    copyTargets,
}: Props) {
    const { market, flash } = usePage<SharedProps>().props
    const { t } = useTranslations()

    // Which hand-written item has its correction form open. One at a time: it
    // is a small fix, not a mode.
    const [editingItem, setEditingItem] = useState<number | null>(null)
    const base = `/${market.key}`

    /*
     * The row that just arrived, tinted for a few seconds.
     *
     * Adding from the panel above answered with "Saved to Camping" in a banner
     * at the top of the page — the name of the list you are already reading,
     * for a row that is now on it. The server sends the id instead (see
     * `WishlistItemController::report()`) and the change is shown where it
     * happened. The tint fades out on its own; nothing about the row depends on
     * it, so missing it costs nothing.
     *
     * The row is also brought into view. New items sort to the top, directly
     * under the add panel, so it is normally already there — but a list can be
     * scrolled anywhere, and a confirmation on a part of the page nobody is
     * looking at is the problem the banner had.
     */
    const [fresh, setFresh] = useState<number | null>(null)
    const freshRow = useRef<HTMLLIElement | null>(null)

    const savedItem = flash.savedItem ?? null

    useEffect(() => {
        if (savedItem === null) return

        setFresh(savedItem)

        const timer = window.setTimeout(() => setFresh(null), 4000)

        return () => window.clearTimeout(timer)
    }, [savedItem])

    useEffect(() => {
        if (fresh === null) return

        freshRow.current?.scrollIntoView({
            block: 'nearest',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'auto'
                : 'smooth',
        })
    }, [fresh])

    const shared = list.visibility !== 'private'
    const [panel, setPanel] = useState<Panel | null>(null)

    // The note under the title, and whether it is being written.
    const [noting, setNoting] = useState(false)
    const [note, setNote] = useState(list.description ?? '')

    return (
        <>
            <Head title={list.title} />

                <header className="flex items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                        <Link href={`${base}/lists`} className="text-sm text-ink-soft hover:text-ink">
                            ← {t('lists.title')}
                        </Link>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <h1 className="text-xl sm:text-2xl font-semibold">{list.title}</h1>
                            {/*
                              What kind of list this is — the fact that decides who
                              may claim, who may vote and who sees the money, and
                              which this page has never said out loud.
                            */}
                            <ListPills
                                kind={list.kind as ListKind}
                                role={role}
                                ownerName={ownerName}
                                canAdd={list.shareUrl !== null && list.linkCanAdd}
                            />
                            {/*
                              Shared or private, as a chip rather than the two
                              sentences that used to sit under the title — one
                              naming the state, one explaining what the state lets
                              you do. The row of tools below now lights up per
                              option, so the explanation had become a caption for
                              controls that say it themselves. Same shape as the
                              badge on the index card, so a list reads the same in
                              both places.
                            */}
                            <span
                                className={
                                    shared
                                        ? 'rounded-full bg-sage/15 px-2 py-0.5 text-2xs text-sage'
                                        : 'rounded-full bg-line/60 px-2 py-0.5 text-2xs text-ink-soft'
                                }
                            >
                                {shared ? t('lists.shared_short') : t('lists.private_short')}
                            </span>
                        </div>
                        {/*
                          A note, under the name, in the owner's own words.

                          The column existed and was rendered on the shared page,
                          and nothing anywhere could write it — so every list that
                          had one had got it from an import. "Bring it to the office
                          Friday", "she has enough candles" is the context a bare
                          list of products cannot carry, and the shared page is
                          where somebody arrives with none.

                          Inline rather than behind a chip: it is a property of the
                          list itself, like its title, not a thing you do with the
                          list. The row of chips is for the latter.

                          **The owner's, not an editor's.** `canEdit` is about the
                          *items* — a collaborator may add and remove presents, and
                          that is what the role was granted for. This is the owner
                          speaking to the people they sent the link to, in their own
                          voice, under their own title; an editor rewriting it would
                          be putting words in somebody's mouth on a page addressed
                          to their friends. The same reasoning that keeps a guest
                          out of the sharing panel.
                        */}
                        {noting ? (
                            <div className="mt-2 max-w-prose">
                                <textarea
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    rows={3}
                                    maxLength={2000}
                                    autoFocus
                                    placeholder={t('lists.note_placeholder')}
                                    className="w-full rounded-lg border border-line bg-cream p-3 text-sm"
                                />
                                <div className="mt-2 flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.patch(
                                                `${base}/lists/${list.id}`,
                                                // Empty is no note, not an empty
                                                // one: the column is nullable and a
                                                // blank string would render as a
                                                // gap under the title.
                                                { description: note.trim() === '' ? null : note.trim() },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => setNoting(false),
                                                },
                                            )
                                        }
                                        className="rounded-lg bg-ink px-3 py-1.5 text-sm text-cream"
                                    >
                                        {t('lists.save')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setNote(list.description ?? '')
                                            setNoting(false)
                                        }}
                                        className="rounded-lg border border-line px-3 py-1.5 text-sm"
                                    >
                                        {t('lists.cancel')}
                                    </button>
                                </div>
                            </div>
                        ) : list.description ? (
                            <p className="mt-2 max-w-prose text-ink-soft">
                                {list.description}
                                {access.isOwner && (
                                    <button
                                        type="button"
                                        onClick={() => setNoting(true)}
                                        className="ml-2 text-xs text-ink-soft underline hover:text-ink"
                                    >
                                        {t('lists.note_edit')}
                                    </button>
                                )}
                            </p>
                        ) : (
                            access.isOwner && (
                                <button
                                    type="button"
                                    onClick={() => setNoting(true)}
                                    className="mt-2 text-sm text-ink-soft underline hover:text-ink"
                                >
                                    {t('lists.note_add')}
                                </button>
                            )
                        )}
                        {/*
                          The quiz, named on the one list it cannot appear on.

                          `ListTools` gates the tab on `shared && claimable`, and
                          rightly — a quiz publishes what is on the list, so it must
                          not exist over a private one. The consequence was that the
                          feature invented to solve "nobody fills in a wishlist" was
                          invisible on exactly the wishlist nobody had filled in. The
                          gate does not move; the sentence is how you learn the tab
                          is there to be earned.
                        */}
                        {!shared && list.kind === 'mine' && (
                            <p className="mt-1 max-w-prose text-sm text-ink-soft">
                                {t('lists.quiz_unlocks')}
                            </p>
                        )}
                    </div>

                    {/*
                      All this header still holds is getting rid of the list.

                      Share moved down into `ListTools`, next to the other things
                      you can do with one — two copies of a control on one screen is
                      not twice as findable, and that row is where somebody looks
                      for what a list can do.
                    */}
                    {access.isOwner && (
                        <button
                            onClick={() => {
                                if (confirm(t('lists.delete_confirm'))) {
                                    // The store cannot infer a deleted list: every
                                    // bookmark on the next page would still report
                                    // its products as saved, into a list that is
                                    // gone. This is the caller `invalidate()` was
                                    // written for.
                                    router.delete(`${base}/lists/${list.id}`, { onSuccess: () => invalidate() })
                                }
                            }}
                            aria-label={t('lists.delete_list')}
                            title={t('lists.delete_list')}
                            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-line text-ink-soft transition hover:border-accent hover:text-accent sm:h-9 sm:w-9"
                        >
                            {/*
                              An icon, not the words "Delete this list".

                              The only destructive control on the page was also its
                              widest button, sitting level with the title and
                              pulling the eye first on a screen that is about what
                              is on the list. Small and cornered is the right
                              weight for something you should have to go and find.
                              The words survive as the label and the tooltip.

                              Top right on every width. The header used to wrap,
                              so on a phone the icon dropped under the title and
                              the pills, where the eye had to hunt for it; the
                              title column now shrinks and the icon keeps the
                              corner. 44px on a phone, the site's minimum target.
                            */}
                            <svg
                                aria-hidden
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.5"
                                className="h-5 w-5"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M4 7h16M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7m2 0v12a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V7M10 11v6M14 11v6"
                                />
                            </svg>
                        </button>
                    )}
                </header>

            {/*
              Two columns from `lg` up, one below it — and the header is not in
              them.

              The board is a conversation *about* the list, so it stands beside
              the list rather than under it. The title, the badges, who it is
              for and the note under them are about the whole page: capped to
              the left column they ran to two-thirds width and stopped under a
              sidebar that has nothing to do with them, so they sit above the
              grid and take the full measure.

              The whole list goes in the left column so that column is the
              taller of the two whatever the conversation does; a rail longer
              than the thing it is beside is what makes a sticky sidebar run on
              past the end of the page.

              No rail at all when there is no board — `board` is null for
              anybody who may not see one, which on a wish list of your own is
              you, because a board is claim state in prose. See
              App\Services\Wishlist\Board.
            */}
            <div
                className={
                    board !== null
                        ? 'lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-10'
                        : ''
                }
            >
                <div className="min-w-0">

                    {/*
                      Straight under the title, above everything else on the page.

                      The row of tools is what you do *to* a list, and it was sitting
                      below the group pot — so on the one kind of list that has a pot,
                      the controls started a card and a half down. Directly under the
                      heading it is the same place on every kind, which is what makes it
                      learnable.
                    */}
                    <ListTools
                        base={base}
                        list={list}
                        friends={friends}
                        access={access}
                        collaborators={collaborators}
                        suggestions={suggestions}
                        canHandOver={canHandOver}
                        handoverEmail={handoverEmail}
                        registryOptions={registryOptions}
                        deliveryAddress={deliveryAddress}
                        quizUrl={quizUrl}
                        quizPlays={quizPlays}
                        santaMemberships={santaMemberships}
                        target={target}
                        asked={asked}
                        panel={panel}
                        onPanel={setPanel}
                    />

                    {/*
                      The pot, on the page the organiser actually works from.

                      Contributions are made through the share link, because that is
                      where the endpoint is mounted and where the members are — but
                      reading the running total should not mean opening your own list as
                      though you were a visitor to it.
                    */}
                    {pot !== null && (
                        <div className="mt-6 rounded-card border border-line bg-card p-4">
                            <Pledge
                                action={list.shareUrl ? `${list.shareUrl}/pledge` : ''}
                                contributions={pot}
                                canContribute={list.shareUrl !== null}
                                price={null}
                            />
                        </div>
                    )}

                    {/*
                      "The list" used to head the items here, on a list about somebody
                      else. It was a divider rather than a title: "what :name asked for"
                      ran above it, and the heading existed to say which of the two
                      lists of products you were now looking at. That section is a tab
                      of its own now — see `ListTools`, panel `asked` — so there is one
                      list on the page again, and a page with one list does not need a
                      heading telling you which it is.
                    */}
                    {items.length === 0 ? (
                        <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                            <p className="line-clamp-3 font-medium">{t('lists.empty_list')}</p>

                            {/*
                              What happens next, in three steps, per kind.

                              The worst screen in the product after this pass was a
                              fresh group list: no items, no members, no votes, no
                              money, and one sentence saying it was empty. A group list
                              is the one kind that does nothing at all until other
                              people are on it, so "add things" is not the whole
                              instruction — and it is the only kind where none of the
                              steps is optional.

                              The `mine` steps stay deliberately soft. A personal list
                              of saved things is a finished, legitimate use of this
                              page, and an empty state that reads as a to-do list tells
                              most owners they have done it wrong.
                            */}
                            <ol className="mx-auto mt-4 max-w-md space-y-2 text-left">
                                {[1, 2, 3].map((step) => (
                                    <li key={step} className="flex gap-3 text-sm text-ink-soft">
                                        <span
                                            aria-hidden
                                            className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line text-2xs font-medium text-ink"
                                        >
                                            {step}
                                        </span>
                                        {t(`lists.empty_${list.kind}_step${step}`)}
                                    </li>
                                ))}
                            </ol>
                            {/*
                              One control here too. An empty list is exactly where
                              somebody discovers their present is not something we
                              stock, and the panel carries that path without making it a
                              second button to choose between.
                            */}
                            {access.canEdit && (
                                <div className="mt-4 flex flex-wrap items-start justify-center gap-2">
                                    <AddProduct base={base} listId={list.id} market={market} />
                                </div>
                            )}
                        </div>
                    ) : (
                        <>
                            {/*
                              Directly on top of the thing it fills.

                              It sat in the header beside Share and Delete, which is a
                              row about the list as a whole — renaming it, giving it
                              away, getting rid of it. Adding to it is not that: it is
                              the ordinary thing you came to do, and it belongs against
                              the items rather than filed with the administration.

                              Once, not also below. Two of the same control on one
                              screen is not twice as findable.
                            */}
                            {access.canEdit && (
                                <div className="mt-8">
                                    <AddProduct base={base} listId={list.id} market={market} />
                                </div>
                            )}

                            {/*
                              The same grid of cards the shared page uses.

                              This was a column of rows inside one bordered box,
                              with 56px thumbnails, while `/l/{code}` showed the
                              identical items as 80px cards in two columns.
                              Nothing chose that — the two pages were written
                              months apart and drifted — and a person meets both
                              sides of their own list within minutes of sharing
                              it, so the mismatch reads as two different lists.

                              `ListItemCard` holds the product half. The actions
                              stay here, because the owner's two are genuinely
                              not the visitor's four.
                            */}
                            <ul className="mt-3 grid gap-4 sm:grid-cols-2">
                                {items.map((item) => (
                                    <ListItemCard
                                        key={item.id}
                                        innerRef={item.id === fresh ? freshRow : undefined}
                                        className={
                                            item.id === fresh
                                                ? 'bg-sage/15 transition-colors duration-1000'
                                                : 'transition-colors duration-1000'
                                        }
                                        title={item.title}
                                        image={item.image}
                                        url={item.url}
                                        externalUrl={item.externalUrl}
                                        note={item.note}
                                        price={item.currentPrice}
                                        was={item.price}
                                        market={market}
                                        aside={
                                            <>
                                                {/*
                                                  Copy to another list, beside remove.

                                                  The two together are also the move:
                                                  copy, then remove. That is the whole
                                                  argument for not having a move — a
                                                  second verb whose only failure mode is
                                                  destroying the original, for something
                                                  the page can already do in two
                                                  deliberate presses.
                                                */}
                                                {/*
                                                  Put it on another of my lists.

                                                  The save picker when there is a
                                                  product behind the row — the same
                                                  control as every product card and
                                                  as the shared page, so it is one
                                                  habit rather than three.

                                                  `CopyToList` only for a
                                                  hand-written item, which has no
                                                  `group_id` to save and must have
                                                  the row itself copied. Same
                                                  bookmark, same menu; a different
                                                  endpoint underneath.
                                                */}
                                                {access.canEdit && (
                                                    item.groupId !== null ? (
                                                        <SaveToList groupId={item.groupId} compact />
                                                    ) : (
                                                        <CopyToList
                                                            action={`${base}/lists/${list.id}/items/${item.id}/copy`}
                                                            targets={copyTargets}
                                                            groupId={null}
                                                        />
                                                    )
                                                )}

                                                {/*
                                                  `isOwner`, not `canEdit`.

                                                  A contributor adds; only the owner
                                                  takes things off. `canEdit` is also
                                                  true for a legacy editor collaborator,
                                                  and a helper able to delete is a list
                                                  that quietly loses items — including
                                                  ones somebody has already claimed and
                                                  bought. Mirrored, never trusted:
                                                  `WishlistItemController::destroy()`
                                                  asks the same question again.
                                                */}
                                                {/*
                                                  Correct what you typed.

                                                  Only on a hand-written item:
                                                  on a catalogue one those
                                                  columns record what the feed
                                                  said, and the price history is
                                                  measured against them.
                                                  `update()` drops the fields
                                                  server-side for such an item,
                                                  so this is the reason that
                                                  branch is never reached rather
                                                  than the thing preventing it.
                                                */}
                                                {access.isOwner && item.manual && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setEditingItem(
                                                                editingItem === item.id ? null : item.id,
                                                            )
                                                        }
                                                        aria-label={t('lists.edit_item')}
                                                        title={t('lists.edit_item')}
                                                        className="rounded p-2 text-ink-soft hover:text-accent"
                                                    >
                                                        ✎
                                                    </button>
                                                )}

                                                {access.isOwner && (
                                                    <button
                                                        // Asked first. Saving has an undo
                                                        // toast; removing had nothing, and
                                                        // the destructive half was the
                                                        // cheaper press.
                                                        onClick={() => {
                                                            if (!confirm(t('lists.remove_confirm', { title: item.title }))) return

                                                            router.delete(`${base}/list-items/${item.id}`, {
                                                                preserveScroll: true,
                                                                // Otherwise the bookmark on the
                                                                // product page still reads as
                                                                // saved after the item has gone.
                                                                onSuccess: () =>
                                                                    item.groupId !== null
                                                                    && markRemoved(item.groupId),
                                                            })
                                                        }}
                                                        aria-label={t('lists.remove')}
                                                        className="rounded p-2 text-ink-soft hover:text-accent"
                                                    >
                                                        ✕
                                                    </button>
                                                )}
                                            </>
                                        }
                                    >
                                        {editingItem === item.id && (
                                            <EditManualItem
                                                action={`${base}/list-items/${item.id}`}
                                                title={item.title}
                                                url={item.externalUrl}
                                                price={item.price}
                                                onDone={() => setEditingItem(null)}
                                            />
                                        )}
                                    </ListItemCard>
                                ))}
                            </ul>
                        </>
                    )}
                </div>

                {board !== null && list.shareUrl !== null && (
                    <aside className="mt-10 lg:sticky lg:top-6 lg:mt-0">
                        {/*
                          Posted through the share token, like every other write
                          on a shared list — `shareUrl` already is that address.
                          The owner's page has no endpoint of its own for this:
                          one route means one gate, and the gate is the token.
                        */}
                        <ListBoard board={board} action={`${list.shareUrl}/messages`} />
                    </aside>
                )}
            </div>
        </>
    )
}
