import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import AddProduct from '../../Components/AddProduct'
import NewListPrompt from '../../Components/NewListPrompt'
import Pledge, { type Contributions } from '../../Components/Pledge'
import type { SharedProps } from '../../types'
import ListTools, { ListToolsBar, type Panel } from '../../Components/ListTools'
import type { Publication } from '../../Components/PublishCove'
import { type ListKind } from '../../Components/ListKindBadge'
import EditManualItem from '../../Components/EditManualItem'
import SaveToList from '../../Components/SaveToList'
import ListItemCard from '../../Components/ListItemCard'
import ListPills, { type ListRole } from '../../Components/ListPills'
import ListBoard, { type BoardState } from '../../Components/ListBoard'
import PageHeader from '../../Components/PageHeader'
import { useHiddenItems } from '../../pendingRemovals'
import CopyToList, { type CopyTarget } from '../../Components/CopyToList'
import OwnItemMenu from '../../Components/OwnItemMenu'
import { type TasteTogetherState } from '../../Components/TasteTogetherPanel'
import ShareMenu from '../../Components/ShareMenu'
import TheirWishes, { type Wish } from '../../Components/TheirWishes'
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
    /** A pasted link still being looked up. See `ReadItemLink`. */
    reading: boolean
}

/** One wish of the person this list is about; see TheirWishes. */
type Asked = Wish & { url: string | null }

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
        /** "Visible to my people"; null where the question does not arise. */
        visibleToFriends: boolean | null
        recipient: { id: number; name: string } | null
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
        /** Mail the owner when something drops by at least this many percent; null is off. */
        priceWatchPercent: number | null
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
    /** Publishing as a Community Cove: the owner's alone, null for anybody else. */
    publication: Publication | null
    /** This or that together about the list's person: the owner's alone, null otherwise. */
    tasteTogether?: TasteTogetherState | null
}

export default function ListShow({
    list,
    friends,
    role,
    ownerName,
    items: allItems,
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
    publication = null,
    tasteTogether = null,
}: Props) {
    const { market, flash } = usePage<SharedProps>().props
    const pageUrl = usePage().url
    const { t } = useTranslations()
    // An item you just took off is gone from the page while its Undo is on
    // screen, before the server hears of it (pendingRemovals.ts).
    const hidden = useHiddenItems()
    const items = hidden.size === 0 ? allItems : allItems.filter((item) => !hidden.has(item.id))

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
    /*
     * Wait for a pasted link to be looked up.
     *
     * The item is saved at once under the shop's name, and a queued job fills
     * in the rest a few seconds later (ReadItemLink). Asking for the items
     * again every two seconds, for at most thirty, is what makes the name and
     * picture appear without a reload. It stops as soon as nothing is pending,
     * and gives up quietly after that: the row is still there, as typed.
     */
    const reading = items.some((item) => item.reading)

    useEffect(() => {
        if (!reading) return

        let tries = 0
        const timer = window.setInterval(() => {
            tries += 1

            if (tries > 15) {
                window.clearInterval(timer)

                return
            }

            router.reload({ only: ['items'] })
        }, 2000)

        return () => window.clearInterval(timer)
    }, [reading])

    const [fresh, setFresh] = useState<number | null>(null)
    const freshRow = useRef<HTMLLIElement | null>(null)

    const savedItem = flash.savedItem ?? null
    const askForIdeas = flash.askForIdeas ?? []

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
    // `?panel=share` (or another tool) opens the page on that tool: "Deel een
    // lijst en laat anderen iets voorstellen" on Find a gift and the list
    // actions on a person's page land here (2026-09-27).
    const [panel, setPanel] = useState<Panel | null>(() => {
        const asked = new URLSearchParams(pageUrl.split('?')[1] ?? '').get('panel')
        const panels: Panel[] = ['share', 'ask', 'settings', 'quiz', 'santa', 'together']

        return access.canEdit && panels.includes(asked as Panel) ? (asked as Panel) : null
    })

    /*
     * Their wishes, on a list about somebody who lets this giver see a wish
     * list (wish-list-for-my-people.md). Shown under the items and inside the
     * add panel, to whoever may add to this list.
     */
    const wishes = target !== null && list.kind !== 'mine' && access.canEdit ? asked : []
    const onList = new Set(items.map((item) => item.groupId).filter((id): id is number => id !== null))
    const theirWishes =
        wishes.length > 0 && target !== null
            ? { name: target.name, wishes, onList }
            : null


    const toolProps = {
        base,
        list,
        access,
        quizUrl,
        santaMemberships,
        target,
        asked,
        panel,
        onPanel: setPanel,
        tasteTogether,
    }

    // Absent when `board` is null: for anybody who may not see one, which on
    // a wish list of your own is you, because a board is claim state in
    // prose (App\Services\Wishlist\Board). Posted through the share token.
    const hasBoard = board !== null && list.shareUrl !== null

    // "Vraag het aan anderen" lives in the Meer menu only (owner, 2026-09-26);
    // see ListToolsBar and docs/features/list-surfaces.md.

    return (
        <>
            <Head title={list.title} />

            {/*
              The page, top to bottom, since the owner's audit of 2026-09-26:
              the title with Share and More beside it, whichever tool was just
              opened, the add control and the items, and then what is about
              the list rather than on it (the pot, the discussion).

              It used to open on features: a row of five or six tool chips, the
              This-or-that-together card, the link to the person's page and a
              discussion column on the right, with the items last. On a phone
              the first screen held no item at all. See list-surfaces.md,
              "Items first".
            */}
            <PageHeader
                size="md"
                back={{ href: `${base}/lists`, label: t('lists.title') }}
                title={list.title}
                /*
                  What kind of list this is — the fact that decides who may
                  claim, who may vote and who sees the money. Only the kind:
                  "Shared" is what the lit Share button says.
                */
                beside={<ListPills kind={list.kind as ListKind} role={role} ownerName={ownerName} />}
                /*
                  Share, and a More menu for everything else. The owner's: for
                  anybody else it renders nothing.
                */
                actions={<ListToolsBar {...toolProps} />}
            >
                {/*
                  The owner's note, under the name. Read-only here: it is
                  written in Settings. The shared page renders the same line
                  for the people the link was sent to.
                */}
                {list.description && <p className="mt-2 max-w-prose text-ink-soft">{list.description}</p>}
                {/*
                  The quiz, named on the one list it cannot appear on.

                  The quiz is offered only over a shared wish list (a quiz
                  publishes what is on the list), which made the feature
                  invented to solve "nobody fills in a wishlist" invisible on
                  exactly the wishlist nobody had filled in. The gate does not
                  move; the sentence is how you learn it is there to be earned.
                */}
                {access.isOwner && !shared && list.kind === 'mine' && (
                    <p className="mt-1 max-w-prose text-sm text-ink-soft">{t('lists.quiz_unlocks')}</p>
                )}
            </PageHeader>

            {/*
              Whichever tool was opened from the header, directly under it, and
              the suggestions somebody sent, which stay in the open: a message
              behind a button is a message missed.
            */}
            <ListTools
                {...toolProps}
                friends={friends}
                collaborators={collaborators}
                suggestions={suggestions}
                canHandOver={canHandOver}
                handoverEmail={handoverEmail}
                registryOptions={registryOptions}
                deliveryAddress={deliveryAddress}
                quizPlays={quizPlays}
                publication={publication}
            />

            {/*
              The links to send, right after the list was made with "ask for
              ideas" chosen in the wizard. Once: it answers the choice just
              made, and Share stays for every visit after. The person's own
              page is where they say what they like without seeing this list;
              the list's link lets others suggest, and suggestions wait for
              the owner.
            */}
            {/* Just made by the one-step create: what it no longer asks (one-step-list.md). */}
            {flash.newList && access.isOwner && askForIdeas.length === 0 && (
                <NewListPrompt
                    kind={list.kind}
                    askName={target?.askUrl && list.kind !== 'mine' ? target.name : null}
                    onPanel={setPanel}
                />
            )}

            {askForIdeas.length > 0 && (
                <div className="mt-6 space-y-4 rounded-card border border-accent/40 bg-accent/5 p-4 sm:p-5">
                    <p className="font-medium">{t('wizard.ask_card_title')}</p>

                    {askForIdeas.includes('recipient') && target?.askUrl && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="max-w-md text-sm text-ink-soft">
                                {t('wizard.ask_card_recipient', { name: target.name })}
                            </p>
                            <ShareMenu
                                url={target.askUrl}
                                text={t('wizard.ask_message_recipient')}
                                label={t('wizard.ask_card_send', { name: target.name })}
                            />
                        </div>
                    )}

                    {askForIdeas.includes('others') && list.shareUrl && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="max-w-md text-sm text-ink-soft">{t('wizard.ask_card_others')}</p>
                            <ShareMenu
                                url={list.shareUrl}
                                text={t('wizard.ask_message_others', { title: list.title })}
                                label={t('wizard.ask_card_share')}
                            />
                        </div>
                    )}
                </div>
            )}

            {/*
              The items with the discussion beside them (owner, 2026-09-26: "put
              the overleg section next to the list"), the same layout the shared
              page uses. Two columns only when there is a discussion to show;
              without one the items keep the full width (owner's rule).
            */}
            <div className={hasBoard ? 'lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-10' : ''}>
            <div className="min-w-0">
            {items.length === 0 ? (
                <div className="mt-6 rounded-card border border-line bg-card p-8 text-center">
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
                            <AddProduct base={base} listId={list.id} market={market} defaultOpen theirWishes={theirWishes} />
                        </div>
                    )}
                </div>
            ) : (
                <>
                    {/*
                      Directly on top of the thing it fills, and the first thing
                      under the title: adding to the list is the ordinary thing
                      you came to do. Once, not also below. Two of the same
                      control on one screen is not twice as findable.
                    */}
                    {access.canEdit && (
                        <div className="mt-6 flex flex-wrap items-start gap-2">
                            <AddProduct base={base} listId={list.id} market={market} theirWishes={theirWishes} />
                        </div>
                    )}

                    {/*
                      The same grid of cards the shared page uses, so a person
                      who meets both sides of their own list within minutes of
                      sharing it sees one list, not two. `ListItemCard` holds
                      the product half; the actions stay here, because the
                      owner's are genuinely not the visitor's.
                    */}
                    <ul className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-1 sm:gap-4">
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
                                    access.isOwner ? (
                                        /*
                                          Your own list: one "⋯" with edit, copy
                                          and remove, instead of a bookmark that
                                          was always filled (every item here is
                                          saved, on this list) beside a pencil
                                          and a bin on some items and not on
                                          others. See OwnItemMenu.
                                        */
                                        <OwnItemMenu
                                            base={base}
                                            listId={list.id}
                                            itemId={item.id}
                                            listTitle={list.title}
                                            title={item.title}
                                            groupId={item.groupId}
                                            manual={item.manual}
                                            targets={copyTargets}
                                            onEdit={() => setEditingItem(editingItem === item.id ? null : item.id)}
                                        />
                                    ) : access.canEdit ? (
                                        /*
                                          Somebody who may add to a list that is
                                          not theirs (a legacy editor
                                          collaborator): put it on one of *my*
                                          lists, the same control as every
                                          product card. The save picker when
                                          there is a product behind the row;
                                          `CopyToList` for a hand-written item,
                                          which has no `group_id` to save and
                                          must have the row itself copied. They
                                          do not remove: only the owner takes
                                          things off, and `destroy()` asks again.
                                        */
                                        item.groupId !== null ? (
                                            <SaveToList groupId={item.groupId} compact />
                                        ) : (
                                            <CopyToList
                                                action={`${base}/lists/${list.id}/items/${item.id}/copy`}
                                                targets={copyTargets}
                                                groupId={null}
                                            />
                                        )
                                    ) : undefined
                                }
                            >
                                {item.reading && (
                                    <p className="mt-1 text-xs text-ink-soft" aria-live="polite">
                                        {t('lists.reading_link')}
                                    </p>
                                )}

                                {editingItem === item.id && (
                                    <EditManualItem
                                        action={`${base}/list-items/${item.id}`}
                                        title={item.title}
                                        url={item.externalUrl}
                                        price={item.price}
                                        image={item.image}
                                        onDone={() => setEditingItem(null)}
                                    />
                                )}
                            </ListItemCard>
                        ))}
                    </ul>
                </>
            )}

            {theirWishes !== null && (
                <TheirWishes
                    base={base}
                    name={theirWishes.name}
                    wishes={theirWishes.wishes}
                    listId={list.id}
                    onList={theirWishes.onList}
                    market={market}
                />
            )}

            {/*
              The pot, on the page the organiser actually works from, under the
              items it is collecting for.

              Contributions are made through the share link, because that is
              where the endpoint is mounted and where the members are — but
              reading the running total should not mean opening your own list
              as though you were a visitor to it.
            */}
            {pot !== null && (
                <div className="mt-8 rounded-card border border-line bg-card p-4">
                    <Pledge
                        action={list.shareUrl ? `${list.shareUrl}/pledge` : ''}
                        contributions={pot}
                        canContribute={list.shareUrl !== null}
                        price={null}
                    />
                </div>
            )}

            </div>

            {hasBoard && (
                <aside className="mt-10 lg:sticky lg:top-[calc(var(--header-h)+1.5rem)] lg:mt-6">
                    <ListBoard board={board!} action={`${list.shareUrl}/messages`} />
                </aside>
            )}
            </div>
        </>
    )
}
