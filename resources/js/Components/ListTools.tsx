import { router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import type { SharedProps } from '../types'
import Menu, { MenuItem, MenuSeparator, MoreButtonContent } from './Menu'
import PublishCove, { type Publication } from './PublishCove'
import ShareRow from './ShareRow'
import TasteTogetherPanel, { type TasteTogetherState } from './TasteTogetherPanel'
import { invalidate } from '../savedItems'
import ToolIcon, { type ToolKey } from './ToolIcon'
import InfoTip from './InfoTip'
import { useConfirm } from './Modal'
import Option from './Option'
import ShareSettings from './ShareSettings'
import type { Wish } from './TheirWishes'
import { useTranslations } from '../useTranslations'

interface Collaborator {
    id: number
    name: string | null
    role: string
}

interface Suggestion {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    from: string | null
}

interface Membership {
    groupId: string
    title: string
    attached: boolean
}

/**
 * One thing the recipient lets this giver see on a wish list of theirs. Drawn
 * by TheirWishes; the tool row only needs to know whether there are any.
 */
type Asked = Wish

interface Props {
    base: string
    /**
     * The owner's friends, for "Share with friends".
     *
     * Empty for anybody who is not the owner, and empty for an owner with no
     * friends yet — the control is hidden in both cases rather than offering a
     * picker with nothing in it.
     */
    friends: { id: number; name: string }[]
    list: {
        id: string
        title: string
        kind: string
        claimable: boolean
        visibility: string
        shareUrl: string | null
        /**
         * "Visible to my people": the owner's friends see this wish list and
         * can pick from it. Null where the question does not arise (not a wish
         * list, or an owner without an account).
         */
        visibleToFriends: boolean | null
        /** Who this list has already been shared with, by id. */
        sharedWith: number[]
        /** Who the list is for, on a list about somebody else. The id is what the settings form patches. */
        recipient: { id: number; name: string } | null
        eventType: string | null
        eventDate: string | null
        /** Is anybody else on this list? Most lists are private and solo. */
        hasCoGivers: boolean
        /*
         * Still sent by the server and no longer read here: the two switches
         * that set them were removed. Kept on the type rather than deleted
         * because `summarise()` sends them to every surface, and a page that
         * silently stopped receiving a field it never asked to lose is how a
         * prop goes missing for the next reader of it.
         */
        claimVisibility: string
        ownerSeesClaims: boolean
        /** May somebody holding the link put things on the list? */
        linkCanAdd: boolean
        /** On a group gift: may everyone see who is chipping in? Names only. */
        pledgersVisible: boolean
        /** Cents per person, or null for "everyone names their own". */
        pledgeAmount: number | null
        /** On a group gift: do the members choose the present? */
        votingEnabled: boolean
        /** Mail the owner when something drops by at least this many percent; null is off. */
        priceWatchPercent: number | null
        /** The owner's note under the title, or null. Edited in the settings panel. */
        description: string | null
    }
    access: { isOwner: boolean; canEdit: boolean }
    collaborators: Collaborator[]
    suggestions: Suggestion[]
    canHandOver: boolean
    handoverEmail: string | null
    registryOptions: { value: string; label: string }[]
    deliveryAddress: string | null
    quizUrl: string | null
    quizPlays: number
    santaMemberships: Membership[]
    /** The person this list is about, when there is one. */
    target: { name: string; isLinked: boolean; askUrl: string | null } | null
    /** What they have asked for, once they have an account of their own. */
    asked: Asked[]
    /*
     * Which panel is open is owned by the page, not by this row.
     *
     * Lifted when Share was a header button that had to open a panel down here.
     * That button has since moved into this row, so the state could come back —
     * it stays lifted because the page is the thing a future control (a
     * deep link, a flash message pointing at a panel) would reach for, and
     * moving it back and forth costs more than leaving it where it works.
     */
    panel: Panel | null
    onPanel: (panel: Panel | null) => void
    /** Publishing as a Community Cove; the owner's alone, null for anybody else. */
    publication?: Publication | null
    /** This or that together about the list's person: the owner's alone, null otherwise. */
    tasteTogether?: TasteTogetherState | null
}

export type Panel = 'share' | 'ask' | 'settings' | 'quiz' | 'santa' | 'together'


/**
 * The panels behind a list's tools, opened one at a time from the header
 * (`ListToolsBar` below: Share, and a More menu for the rest).
 *
 * The page had grown a panel per feature — share, quiz, Secret Santa,
 * suggestions, registry, handover, co-givers — each permanently open, each with
 * a heading *and* a paragraph explaining itself. Nine tools' worth of prose sat
 * above the one thing the page is for, which is the list.
 *
 * The explanations are not gone; they moved inside the panel they belong to,
 * where the person who opened it is asking the question they answer. One panel
 * is open at a time, because these are alternatives rather than a checklist.
 *
 * Suggestions are the exception and stay visible: a pending suggestion is a
 * message somebody sent, and a message behind a button is a message missed.
 */
export default function ListTools({
    base,
    list,
    friends,
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
    target,
    panel: open,
    onPanel,
    publication = null,
    tasteTogether = null,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [handTo, setHandTo] = useState(handoverEmail ?? '')
    // The settings form: the name and the note, typed here and saved together.
    const [title, setTitle] = useState(list.title)
    const [note, setNote] = useState(list.description ?? '')
    // And who it is for, on a list about somebody else. Lives on the recipient,
    // not the list, so it is saved through its own endpoint first.
    const [personName, setPersonName] = useState(list.recipient?.name ?? '')

    /*
     * Every sharing setting saves the moment it is pressed, and says so.
     *
     * These are privacy switches — who can see this list, who sees who claimed
     * what, whether a stranger's addition goes straight on — and they had no
     * Save button and no confirmation. The control moved, a request went out,
     * and nothing else happened. On a form that is a fair assumption; on "can
     * the people I sent this to see each other's names" it leaves the reader
     * with only the checkbox's own position as evidence that anything was
     * stored, which is exactly the evidence they would have had if it had
     * failed.
     *
     * `back()` returns the whole page, so the control does end up reflecting
     * the stored value — but a re-render that looks identical to no re-render
     * is not feedback. One line, announced, then gone.
     */
    const [saved, setSaved] = useState(0)

    useEffect(() => {
        if (saved === 0) return
        const timer = setTimeout(() => setSaved(0), 2500)

        return () => clearTimeout(timer)
    }, [saved])

    const setting = (data: Record<string, string | number | boolean | null>, done?: () => void) =>
        router.patch(`${base}/lists/${list.id}`, data, {
            preserveScroll: true,
            onSuccess: () => setSaved(Date.now()),
            onFinish: done,
        })

    // The site's own "are you sure?" (Modal.tsx), where `window.confirm()` was.
    const [confirm, confirmDialog] = useConfirm()

    // Only a wish list of your own is a registry; every kind may carry an
    // occasion. The delivery address is the half that stays behind this.
    const isRegistry = list.kind === 'mine'



    /*
     * The panel a tool opened is brought into view and given focus.
     *
     * Since 2026-09-26 the tools are picked from the More menu in the header,
     * and the menu hands focus back to its button when it closes. Without this
     * a keyboard reader would press "Settings" and stay on "More", with the
     * form they asked for somewhere below, unannounced.
     */
    const panelRef = useRef<HTMLDivElement>(null)

    useEffect(() => {
        if (open === null) return

        panelRef.current?.focus({ preventScroll: true })
        panelRef.current?.scrollIntoView({
            block: 'nearest',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        })
    }, [open])

    const pending = access.isOwner && suggestions.length > 0

    if (open === null && !pending) {
        return null
    }

    // Closing a panel was pressing its chip again. The chips are a menu now,
    // so each panel carries its own way out.
    const closeButton = (
        <button
            type="button"
            onClick={() => onPanel(null)}
            aria-label={t('nav.close')}
            title={t('nav.close')}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-ink-soft hover:bg-line/40 hover:text-ink"
        >
            <ToolIcon name="close" className="h-4 w-4" />
        </button>
    )

    /*
      Pending suggestions stay in the open. Everything else is a thing
      you go looking for; this is a thing somebody sent you.
    */
    const suggestionsBlock = pending && (
                <section className="mt-4 rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="text-sm font-medium">{t('suggestions.heading')}</h2>

                    <ul className="mt-3 space-y-3">
                        {suggestions.map((s) => (
                            <li key={s.id} className="flex items-center gap-3">
                                {s.image && (
                                    <img src={s.image} alt="" loading="lazy" className="h-12 w-12 object-contain" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{s.title}</p>

                                    {/*
                                      Always attributed, even when we have no
                                      name.

                                      A suggestion may come from an anonymous
                                      cookie identity — somebody who followed
                                      the link and never signed up — and those
                                      arrived with `from: null` and rendered
                                      nothing at all. A message from nobody is
                                      worse than one from somebody unnamed, and
                                      the accept/dismiss decision is largely a
                                      judgement about who sent it.
                                    */}
                                    <p className="text-xs text-ink-soft">
                                        {s.from
                                            ? t('suggestions.from', { name: s.from })
                                            : t('suggestions.from_anonymous')}
                                    </p>

                                    {/*
                                      What they said about it.

                                      The field has been validated, stored and
                                      sent to the owner since the feature
                                      shipped, and rendered nowhere — so a note
                                      somebody wrote reached the payload and
                                      then vanished. Plain text, clamped, never
                                      a link: this is a stranger's writing.
                                    */}
                                    {s.note && (
                                        <p className="mt-1 line-clamp-2 text-xs text-ink-soft italic">
                                            {s.note}
                                        </p>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(`${base}/suggestions/${s.id}/accept`, {}, { preserveScroll: true })
                                    }
                                    className="rounded-lg border border-sage px-3 py-1.5 text-xs text-sage"
                                >
                                    {t('suggestions.accept')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => router.delete(`${base}/suggestions/${s.id}`, { preserveScroll: true })}
                                    className="text-xs text-ink-soft hover:text-ink"
                                >
                                    {t('suggestions.dismiss')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )

    /*
     * "Find out together what :name likes" is a card of its own
     * (`TasteTogetherPanel`), drawn full size where the other panels open.
     * It sat permanently between the header and the items until 2026-09-26;
     * it is a tool you use now and then, like the others in the menu.
     */
    if (open === 'together' && tasteTogether !== null && list.recipient !== null) {
        return (
            <div>
                <div ref={panelRef} tabIndex={-1} id="list-tools-panel" className="relative mt-4 outline-none">
                    <div className="absolute top-3 right-3">{closeButton}</div>
                    <TasteTogetherPanel name={list.recipient.name} state={tasteTogether} className="pr-12" />
                </div>
                {suggestionsBlock}
            </div>
        )
    }

    return (
        <div>
            {open !== null && (
                <div
                    ref={panelRef}
                    tabIndex={-1}
                    id="list-tools-panel"
                    className="mt-4 rounded-card border border-line bg-card p-4 outline-none"
                >
                    <div className="-mt-2 -mr-2 mb-1 flex justify-end">{closeButton}</div>
                    {open === 'share' && (
                        /*
                          Sections, most with a heading, a gap between them and
                          no rules, in the order the decision is made: is it shared, who
                          gets the link by name, what the link allows, how a
                          group collects, who was let in before links existed
                          — and then, as a further section below this block,
                          handing the list over. The link switches carry no
                          heading of their own: each says what it does, and a
                          title over them was one more line between the link
                          and the first switch. Rules were tried and removed
                          the same day; with headings on the sections the gap
                          separates well enough, and the card is a third
                          shorter without them.

                          It was one column of blocks with no headings and a
                          fixed gap between them, and it rendered every block
                          whether or not it had anything in it. On a private
                          wish list of your own, which is most lists, that was
                          a sentence, a button and a hundred pixels of nothing.
                          A section now exists only when it has content.
                        */
                        <div>
                            {/*
                              Whether it is shared, its link and what the link
                              allows: the same component as the share popup on
                              Mijn Coves and a person's page (ShareSettings), so
                              the two cannot drift apart again. Who gets the
                              link by name, who was let in before, publishing
                              and handing over follow, and are the list page's
                              alone.
                            */}
                            <ShareSettings
                                list={list}
                                friends={friends}
                                onSetting={setting}
                                saved={saved !== 0}
                                readOnly={!access.isOwner}
                            />

                            {/*
                              Share with friends: names you pick, not a switch.

                              A friendship is made by opening a link; a boolean
                              "my friends can see this" would grant access to
                              people the owner never chose. Picking names is
                              consent per person: each gets an email with the
                              link and nobody else learns the list exists.
                              Un-ticking somebody takes it off their page; it
                              does not revoke the link, which is visibility's
                              job.

                              The picker used to sit behind a button, on the
                              argument that sharing by name is a deliberate act.
                              It still is: nothing happens until Send. What the
                              button added was a click between the owner and
                              the names, and a section heading says what the
                              chips are for better than a collapsed button did.
                            */}
                            {list.shareUrl && access.isOwner && friends.length > 0 && (
                                <section className="mt-6">
                                    {/* How the chips behave, behind the (i) (2026-09-27). */}
                                    <h3 className="flex flex-wrap items-center text-sm font-medium">
                                        {t('lists.share_with_friends')}
                                        {list.sharedWith.length > 0 && (
                                            <span className="ml-1 font-normal text-ink-soft">
                                                ({list.sharedWith.length})
                                            </span>
                                        )}
                                        <InfoTip>{t('lists.share_with_friends_hint')}</InfoTip>
                                    </h3>
                                    {/*
                                      One tap per friend, and it acts. A chip
                                      used to be a checkbox and the row ended
                                      in a Send button, so choosing and sending
                                      were two moments; the other half of the
                                      same row already acted on tap (a friend
                                      who has the list is a tick that becomes a
                                      cross), so the button was the visible
                                      seam between two behaviours. Now a tap
                                      shares with that person and the email
                                      goes out; a second tap takes it back,
                                      after the confirm below. The chip turning
                                      green on the spot is what makes a mis-tap
                                      visible, and the harm of one is a friend
                                      hearing about a wish list. Names wrap, so
                                      the whole set is in view and "who have I
                                      not sent this to" is answerable by
                                      looking.
                                    */}
                                    <ul className="mt-3 flex flex-wrap gap-1.5">
                                        {friends.map((friend) => {
                                            const already = list.sharedWith.includes(friend.id)

                                            if (already) {
                                                return (
                                                    <li key={friend.id}>
                                                        <button
                                                            type="button"
                                                            onClick={async () => {
                                                                if (
                                                                    !(await confirm({
                                                                        message: t('lists.unshare_confirm', { name: friend.name }),
                                                                        confirmLabel: t('lists.unshare_from', { name: friend.name }),
                                                                    }))
                                                                ) {
                                                                    return
                                                                }

                                                                router.delete(
                                                                    `${base}/lists/${list.id}/share-with-friends/${friend.id}`,
                                                                    { preserveScroll: true },
                                                                )
                                                            }}
                                                            title={t('lists.unshare_from', {
                                                                name: friend.name,
                                                            })}
                                                            className="group inline-flex items-center gap-1 rounded-full border border-sage/40 bg-sage/10 px-2.5 py-1 text-xs text-ink-soft hover:border-accent hover:text-accent"
                                                        >
                                                            <span aria-hidden className="text-sage group-hover:hidden">
                                                                <ToolIcon name="check" className="h-3.5 w-3.5" />
                                                            </span>
                                                            <span aria-hidden className="hidden group-hover:inline">
                                                                <ToolIcon name="close" className="h-3.5 w-3.5" />
                                                            </span>
                                                            {friend.name}
                                                        </button>
                                                    </li>
                                                )
                                            }

                                            return (
                                                <li key={friend.id}>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/lists/${list.id}/share-with-friends`,
                                                                { friend_ids: [friend.id] },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        title={t('lists.share_with', { name: friend.name })}
                                                        className="inline-flex items-center rounded-full border border-line px-2.5 py-1 text-xs hover:border-sage hover:bg-sage/10"
                                                    >
                                                        {friend.name}
                                                    </button>
                                                </li>
                                            )
                                        })}
                                    </ul>
                                </section>
                            )}

                            {/*
                              The people who were let in one at a time, back
                              when that was how sharing worked. Nothing creates
                              collaborators any more, and people granted access
                              that way still have it, so the owner keeps a way
                              to take it back.
                            */}
                            {access.isOwner && collaborators.length > 0 && (
                                <section className="mt-6">
                                    <h3 className="text-sm font-medium">{t('lists.invited_before')}</h3>
                                    <ul className="mt-3 space-y-2">
                                        {collaborators.map((c) => (
                                            <li key={c.id} className="flex items-center justify-between gap-3 text-sm">
                                                <span>
                                                    {c.name}
                                                    <span className="ml-2 text-xs text-ink-soft">
                                                        {c.role === 'editor' ? t('lists.role_editor') : t('lists.role_viewer')}
                                                    </span>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.delete(
                                                            `${base}/lists/${list.id}/collaborators/${c.id}`,
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="text-xs text-ink-soft hover:text-ink"
                                                >
                                                    {t('lists.remove')}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </div>
                    )}

                    {/*
                      Why the list exists, back behind a button of its own.

                      It spent a day as a section inside Share, on the argument
                      that "who is looking at this list" and "what are they
                      looking at it for" are one errand. They are related, but
                      they are not one press: the occasion is a thing you set
                      once, months before anybody is invited, and a private list
                      has one just as often as a shared one. Filing it under
                      Share hid it behind a word that means something else, and
                      left the panel three forms deep for the one person who
                      came to copy a link.

                      The button says Gelegenheid / Occasion — `registry.occasion`,
                      the same word the field inside it uses.
                    */}

                    {/*
                      Asking the person, on a list about them.

                      Not linked to an account: the link to their own page,
                      where they say what they like. Linked: what they let this
                      giver see of their wish lists is a section under the
                      items since 2026-09-26 (TheirWishes), and this panel only
                      says when there is nothing yet.

                      A tab of its own since 2026-09-01. It was a full-width
                      section stacked between the pot and the list itself, which
                      put a second list of products above the one the page is
                      named after — on a gift list the owner opens to work on
                      *their* picks, the first thing under the header was
                      somebody else's. It is the same errand as everything else
                      in this row: a thing you do with the list, occasionally,
                      and go back to the list afterwards.
                    */}
                    {open === 'ask' && target !== null && (
                        /*
                          Full width, like the rest of the row's panels: this is
                          a list of products with an image, a price and a button
                          on each row, and capping it would leave the buttons
                          bunched against the middle of the page while the right
                          half sat empty.
                        */
                        <div>
                            <h3 className="text-sm font-medium">{t('lists.ask_tab', { name: target.name })}</h3>
                            {!target.isLinked ? (
                                <>
                                    {target.askUrl && (
                                        <>
                                            <p className="text-sm text-ink-soft">
                                                {t('recipients.ask_them_hint')}
                                            </p>

                                            {/*
                                              The link, straight away.

                                              There was a button here — "Ask
                                              them what they want" — that
                                              revealed the link when pressed.
                                              That was right while this lived on
                                              the page: the block opened with a
                                              raw URL in a `<code>` box, which
                                              is reference material at the top
                                              of the one part of the page that
                                              is an *action*, so the button was
                                              what turned it back into one.

                                              Behind a chip it is a press to
                                              undo a press. Opening "Ask Anna"
                                              is the decision; answering it with
                                              a button repeating the chip's own
                                              words asks for the same intent
                                              twice, and puts the thing you came
                                              for one further click away.
                                            */}
                                            <div className="mt-3">
                                                <ShareRow
                                                    url={target.askUrl}
                                                    text={t('recipients.ask_them')}
                                                />
                                            </div>
                                        </>
                                    )}
                                </>
                            ) : (
                                /*
                                  Linked, and nothing of theirs to show this
                                  giver. When there is, it is a section under
                                  the list's items ("From Anna's wish list",
                                  TheirWishes) and this tool is not offered:
                                  picking from it moved there on 2026-09-26.
                                */
                                <p className="text-sm text-ink-soft">
                                    {t('lists.asked_none', { name: target.name })}
                                </p>
                            )}
                        </div>
                    )}

                    {open === 'settings' && (
                        /*
                          The list's own facts, in one place: what it is called,
                          the note under the name, whether its prices are watched
                          and, below, what it is for. The name and the note were
                          edited in the page header and the price switch sat among
                          the sharing options, so changing something about the
                          list meant three places. Sharing is who may see it; this
                          is what it is.
                        */
                        <div>
                            <h3 className="text-sm font-medium">{t('lists.settings')}</h3>
                            <form
                                className="mt-3 grid gap-3 sm:max-w-xl"
                                onSubmit={(e) => {
                                    e.preventDefault()
                                    const saveList = () =>
                                        setting({
                                            title: title.trim() === '' ? list.title : title.trim(),
                                            // Empty is no note, not an empty one: the column
                                            // is nullable and a blank string would render as
                                            // a gap under the title.
                                            description: note.trim() === '' ? null : note.trim(),
                                        })
                                    const renamed = personName.trim()

                                    // The person's name is a fact about the recipient,
                                    // not the list: it goes to the recipient endpoint,
                                    // and the list follows once that has landed so one
                                    // Save means one outcome.
                                    if (list.kind !== 'mine' && list.recipient !== null && renamed !== '' && renamed !== list.recipient.name) {
                                        router.patch(
                                            `${base}/recipients/${list.recipient.id}`,
                                            { name: renamed },
                                            { preserveScroll: true, onSuccess: saveList },
                                        )

                                        return
                                    }

                                    saveList()
                                }}
                            >
                                {/*
                                  Who it is for, when it is about somebody. The
                                  name appeared nowhere on the page once the
                                  "Ask :name" chip lost its label, and a list
                                  about a person whose page never says the
                                  person is a list with its title missing.
                                  First, above the list's own name: the person
                                  is what the list is about, the title is what
                                  it is called.

                                  Never on a wish list of your own: there the
                                  recipient is you, and `mine` lists carry no
                                  recipient at all, so the kind check is belt
                                  to the null check's braces.
                                */}
                                {list.kind !== 'mine' && list.recipient !== null && (
                                    <label className="block text-sm">
                                        <span className="text-ink-soft">{t('lists.recipient_label')}</span>
                                        <input
                                            type="text"
                                            value={personName}
                                            onChange={(e) => setPersonName(e.target.value)}
                                            maxLength={80}
                                            required
                                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                        />
                                    </label>
                                )}
                                <label className="block text-sm">
                                    <span className="text-ink-soft">{t('lists.title_label')}</span>
                                    <input
                                        type="text"
                                        value={title}
                                        onChange={(e) => setTitle(e.target.value)}
                                        maxLength={120}
                                        required
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                    />
                                </label>
                                <label className="block text-sm">
                                    <span className="text-ink-soft">{t('lists.description_label')}</span>
                                    <textarea
                                        value={note}
                                        onChange={(e) => setNote(e.target.value)}
                                        rows={3}
                                        maxLength={2000}
                                        placeholder={t('lists.note_placeholder')}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream p-3 text-sm"
                                    />
                                </label>
                                <div className="flex items-center gap-3">
                                    <button type="submit" className="rounded-lg bg-ink px-3 py-1.5 text-sm text-cream">
                                        {t('lists.save')}
                                    </button>
                                    <span className="text-xs text-sage" aria-live="polite">
                                        {saved !== 0 && t('lists.saved')}
                                    </span>
                                </div>
                            </form>
                            <div className="mt-6 space-y-2">
                                        {/*
                                          Watch the prices on this list.

                                          bstore's wishlist mail, brought over:
                                          one switch for the whole list and a
                                          percentage, instead of a button on
                                          every product. Off is null, like its
                                          neighbours; on starts at 10%, which
                                          is a real drop on anything and not a
                                          rounding error. The choices are the
                                          server's short list, and it refuses
                                          anything outside it.
                                        */}
                                        <Option
                                            type="checkbox"
                                            checked={list.priceWatchPercent !== null}
                                            onChange={() =>
                                                setting({
                                                    price_watch_percent:
                                                        list.priceWatchPercent === null ? 10 : null,
                                                })
                                            }
                                            label={t('lists.price_watch')}
                                            tip={t('lists.price_watch_hint')}
                                        />
                                        {list.priceWatchPercent !== null && (
                                            <label className="flex items-center gap-2 pl-3 text-sm">
                                                <span>{t('lists.price_watch_threshold')}</span>
                                                <select
                                                    value={list.priceWatchPercent}
                                                    onChange={(e) =>
                                                        setting({
                                                            price_watch_percent: Number(e.target.value),
                                                        })
                                                    }
                                                    className="rounded-lg border border-line px-2 py-1 text-sm"
                                                >
                                                    {[5, 10, 15, 20, 30].map((p) => (
                                                        <option key={p} value={p}>
                                                            {p}%
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                        )}
                            </div>
                            {/*
                              How a group gift collects: a choice, not a switch
                              with a field hanging off it, because "each names
                              their own" and "everyone puts in the same" are
                              two collections rather than one with an option.
                              Under Settings, not Sharing (owner, 2026-09-27):
                              how the money is collected is what the list is,
                              not who may see it.
                              The amount appears under the option it belongs to
                              and nowhere else, and is written on blur: this
                              posts, and "€1, €12, €120" typed into a live field
                              is three settings saved and two of them wrong.
                            */}
                            {access.isOwner && list.kind === 'group' && (
                                <section className="mt-6">
                                    <h3 className="text-sm font-medium">{t('lists.pledge_mode')}</h3>
                                    <div className="mt-3 space-y-2">
                                        <Option
                                            type="radio"
                                            name="pledge_mode"
                                            checked={list.pledgeAmount === null}
                                            onChange={() => setting({ pledge_amount: null })}
                                            label={t('lists.pledge_mode_each')}
                                        />
                                        <Option
                                            type="radio"
                                            name="pledge_mode"
                                            checked={list.pledgeAmount !== null}
                                            // Ten is the amount the field opens
                                            // on, not a recommendation; the
                                            // organiser overtypes it.
                                            onChange={() => setting({ pledge_amount: 10 })}
                                            label={t('lists.pledge_mode_fixed')}
                                        />
                                        {list.pledgeAmount !== null && (
                                            <label className="flex items-center gap-2 pl-3 text-sm">
                                                {/* The market's currency sign, never a hard-coded €. */}
                                                <span className="text-ink-soft">
                                                    {(0)
                                                        .toLocaleString(market.hrefLang, {
                                                            style: 'currency',
                                                            currency: market.currency,
                                                            minimumFractionDigits: 0,
                                                            maximumFractionDigits: 0,
                                                        })
                                                        .replace(/[\d\s]/g, '')}
                                                </span>
                                                <input
                                                    type="number"
                                                    min={1}
                                                    max={100000}
                                                    step="0.01"
                                                    defaultValue={list.pledgeAmount / 100}
                                                    onBlur={(e) => {
                                                        const euros = Number(e.target.value)

                                                        if (euros > 0) {
                                                            setting({ pledge_amount: euros })
                                                        }
                                                    }}
                                                    className="w-28 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                                />
                                                <span className="text-xs text-ink-soft">
                                                    {t('lists.pledge_mode_each_person')}
                                                </span>
                                            </label>
                                        )}
                                    </div>
                                </section>
                            )}
                        </div>
                    )}
                    {open === 'settings' && (
                        <div className="mt-8 border-t border-line pt-6">
                            {/* What the occasion is for, behind the (i) (2026-09-27). */}
                            <h3 className="flex flex-wrap items-center text-sm font-medium">
                                {t('registry.badge')}
                                <InfoTip>{t('registry.hint')}</InfoTip>
                            </h3>

                            <form
                                className="mt-3 grid gap-3 sm:grid-cols-2"
                                onSubmit={(e) => {
                                    e.preventDefault()
                                    const data = new FormData(e.currentTarget)
                                    router.patch(
                                        `${base}/lists/${list.id}`,
                                        {
                                            event_type: String(data.get('event_type') || ''),
                                            event_date: String(data.get('event_date') || ''),
                                            /*
                                             * Only when the field is on screen.
                                             *
                                             * `FormData.get` returns null for an
                                             * absent input, which becomes '' and
                                             * would *clear* a stored address every
                                             * time somebody edited the occasion on
                                             * a list that does not show the field.
                                             * Harmless today, since only a `mine`
                                             * list can have one — and exactly the
                                             * kind of thing that stops being
                                             * harmless the moment that changes.
                                             */
                                            ...(isRegistry
                                                ? {
                                                      delivery_address: String(
                                                          data.get('delivery_address') || '',
                                                      ),
                                                  }
                                                : {}),
                                        },
                                        { preserveScroll: true },
                                    )
                                }}
                            >
                                <label className="block text-sm">
                                    {t('registry.occasion')}
                                    <select
                                        name="event_type"
                                        defaultValue={list.eventType ?? ''}
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                    >
                                        <option value="">{t('registry.none')}</option>
                                        {registryOptions.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="block text-sm">
                                    {t('registry.date')}
                                    <input
                                        type="date"
                                        name="event_date"
                                        defaultValue={list.eventDate ?? ''}
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                    />
                                </label>

                                {/*
                                  A registry, and only a registry.

                                  This is the owner's home address, and it is only
                                  ever appropriate on a list belonging to the person
                                  the parcel is for. A gift list about somebody else
                                  may carry an occasion and must never carry an
                                  address — which is why `Wishlist::isRegistry()`
                                  and `hasOccasion()` are two questions rather than
                                  one.
                                */}
                                {isRegistry && (
                                <label className="block text-sm sm:col-span-2">
                                    {t('registry.address')}
                                    <InfoTip>{t('registry.address_hint')}</InfoTip>
                                    <textarea
                                        name="delivery_address"
                                        rows={2}
                                        defaultValue={deliveryAddress ?? ''}
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                    />
                                </label>
                                )}

                                <button
                                    type="submit"
                                    className="justify-self-start rounded-lg border border-line px-4 py-2 text-sm sm:col-span-2"
                                >
                                    {t('lists.save')}
                                </button>
                            </form>
                        </div>
                    )}

                    {open === 'quiz' && (
                        <div>
                            <h3 className="text-sm font-medium">{t('quiz.own_title')}</h3>

                            {quizUrl ? (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">
                                        {quizPlays > 0
                                            ? t('quiz.played', { count: String(quizPlays) })
                                            : t('quiz.created')}
                                    </p>
                                    <div className="mt-2">
                                        <ShareRow url={quizUrl} text={t('quiz.share_text')} />
                                    </div>
                                    <a
                                        href={quizUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-2 inline-block text-sm underline"
                                    >
                                        {t('quiz.open')}
                                    </a>
                                </>
                            ) : (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">{t('quiz.intro_own')}</p>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(`${base}/lists/${list.id}/quiz`, {}, { preserveScroll: true })
                                        }
                                        className="mt-2 rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                                    >
                                        {t('quiz.create')}
                                    </button>
                                </>
                            )}
                        </div>
                    )}



                    {open === 'share' && publication && (
                        <PublishCove base={base} listId={list.id} state={publication} />
                    )}

                    {open === 'share' && canHandOver && (
                        <div className="mt-6">
                        <h3 className="flex flex-wrap items-center text-sm font-medium">
                            {t('handover.badge')}
                            <InfoTip>{t('handover.hint', { name: list.recipient?.name ?? '' })}</InfoTip>
                        </h3>
                        <form
                            className="mt-3 flex flex-wrap gap-2"
                            onSubmit={async (e) => {
                                e.preventDefault()

                                if (
                                    await confirm({
                                        message: t('handover.confirm', { name: handTo }),
                                        confirmLabel: t('handover.action'),
                                        danger: true,
                                    })
                                ) {
                                    router.post(`${base}/lists/${list.id}/handover`, { email: handTo })
                                }
                            }}
                        >
                            <input
                                type="email"
                                required
                                value={handTo}
                                onChange={(e) => setHandTo(e.target.value)}
                                placeholder="name@example.com"
                                className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                            />
                            <button
                                type="submit"
                                className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                            >
                                {t('handover.action')}
                            </button>
                        </form>
                        </div>
                    )}

                    {open === 'santa' && (
                        <div>
                            <p className="text-xs text-ink-soft">{t('santa.attach_hint')}</p>
                            <ul className="mt-3 space-y-2">
                                {santaMemberships.map((m) => (
                                    <li key={m.groupId} className="flex items-center justify-between gap-3">
                                        <span className="text-sm">{m.title}</span>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/santa/${m.groupId}/list`,
                                                    { wishlist_id: m.attached ? null : list.id },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className={`rounded-lg border px-3 py-1.5 text-xs ${
                                                m.attached
                                                    ? 'border-sage bg-sage/10 text-sage'
                                                    : 'border-line hover:border-ink'
                                            }`}
                                        >
                                            {m.attached ? t('santa.list_attached_short') : t('santa.attach_list')}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            {suggestionsBlock}
            {confirmDialog}
        </div>
    )
}

/**
 * Share, and everything else behind one "More" button, in the page header.
 *
 * ## Why the row of chips became a menu (owner's audit, 2026-09-26)
 *
 * The owner's own list opened on features rather than on the list: a row of
 * five or six chips, then the This-or-that-together card, then the link to the
 * person's page, a discussion column beside it all, and only then the items.
 * On a phone the first screen held no item at all. The list is what the page is
 * for, so the items now start directly under the title, and the tools you
 * reach for now and then sit behind one button.
 *
 * Share stays out in the open because it is the one thing most owners come
 * back to do. Everything else — settings, asking the person, finding out
 * together, the person's page, the quiz, Secret Santa and deleting — is in
 * the menu, in that order, with Delete last and apart. Each item opens the
 * same panel its chip opened, under the header; nothing about what a tool does
 * has changed, only where it is found.
 *
 * A tool that does not apply to this list is left out of the menu, exactly as
 * its chip was left out of the row, and the menu itself is left out when
 * nothing is in it (a collaborator's view of somebody else's list).
 */
export function ListToolsBar({
    base,
    list,
    access,
    quizUrl,
    santaMemberships,
    target,
    asked,
    panel: open,
    onPanel,
    tasteTogether = null,
}: Pick<
    Props,
    'base' | 'list' | 'access' | 'quizUrl' | 'santaMemberships' | 'target' | 'asked' | 'panel' | 'onPanel' | 'tasteTogether'
>) {
    const { t } = useTranslations()
    const shared = list.visibility !== 'private'

    /*
     * `show` is whether the tool exists here; `set` is whether the thing behind
     * it is switched on.
     *
     * The row once said nothing about state, so the only way to learn whether
     * this list had an occasion, a quiz or a live link was to open each panel
     * in turn and read it. `set` marks the ones that are, and it is
     * deliberately the *stored* fact each panel writes, never a proxy for it.
     * In the menu it is a small "on" after the name.
     */
    const tools: { key: Panel; icon: ToolKey; label: string; show: boolean; set: boolean }[] = [
        /*
         * The list's own settings: its name, the note under it, whether its
         * prices are watched, and what it is for. Share is who may see the
         * list; this is what the list is. The owner's alone.
         */
        {
            key: 'settings',
            icon: 'settings',
            label: t('lists.settings'),
            show: access.isOwner,
            set: list.priceWatchPercent !== null || Boolean(list.eventType) || Boolean(list.eventDate),
        },
        /*
         * Ask the recipient for suggestions, on a list about somebody else.
         *
         * Its own tool rather than a section under Share: the link it hands
         * out goes to the one person the list must stay hidden from, the
         * opposite direction to everything in Share. Named in full in the menu
         * ("Ask Anna for suggestions"): the chip's one word, "Ask", leaned on
         * the row around it for its meaning.
         *
         * Gated on the kind as well as on the recipient: "ask them what they
         * want" on a wish list of your own would be the page asking you to
         * interview yourself.
         */
        {
            key: 'ask',
            icon: 'suggestions',
            label: target !== null ? t('lists.ask_tab', { name: target.name }) : t('lists.ask_chip'),
            // Not for a linked person with wishes to show: those are a section
            // under the items now (TheirWishes), which is where picking happens.
            show:
                access.isOwner &&
                target !== null &&
                (list.kind === 'for_someone' || list.kind === 'group') &&
                !(target.isLinked && asked.length > 0),
            set: false,
        },
        /*
         * This or that, played by the others about the list's person
         * (taste-together.md). The server sends the state to the owner only,
         * and only where there is a person; null hides the tool.
         */
        {
            key: 'together',
            icon: 'taste',
            label: list.recipient !== null ? t('gift.together.panel_title', { name: list.recipient.name }) : '',
            show: access.isOwner && tasteTogether !== null && list.recipient !== null,
            set: tasteTogether !== null && tasteTogether.open,
        },
        /*
         * A quiz asks "how well do you know **me**", so it only exists over a
         * shared wish list of your own. `access.isOwner` is not decoration:
         * this page is reachable by somebody who merely opened the list's link
         * (`ListAccess::scope()` unions `list_opens`), and `ListQuizController`
         * refuses them; this is the mirror.
         */
        {
            key: 'quiz',
            icon: 'quiz',
            label: t('quiz.badge'),
            show: access.isOwner && shared && list.claimable && list.kind === 'mine',
            set: quizUrl !== null,
        },
        {
            key: 'santa',
            icon: 'santa',
            label: t('santa.title'),
            // The owner's too, for the reason on `quiz` above.
            show: access.isOwner && santaMemberships.length > 0,
            set: santaMemberships.some((membership) => membership.attached),
        },
    ]

    const visible = tools.filter((tool) => tool.show)

    // The person's own page: their profile and the next step
    // (gift-history.md). The owner's only, on a list about somebody else.
    const personPage =
        access.isOwner && list.kind !== 'mine' && list.recipient !== null
            ? { href: `${base}/people/${list.recipient.id}`, label: t('gift_history.link', { name: list.recipient.name }) }
            : null

    // Lit when anybody else can see the list: by its link, or as one of the
    // owner's people.
    const shareOn = (shared && Boolean(list.shareUrl)) || Boolean(list.visibleToFriends)

    const [confirm, confirmDialog] = useConfirm()

    if (!access.isOwner) {
        return null
    }

    return (
        <div className="flex shrink-0 items-center gap-2">
            {confirmDialog}
            {/*
              Share, the one primary action, as a button with its word.
              Lit (sage) while the list has a live link, the colour this
              product uses for a live, benign state; accent while its panel
              is open. Pressing it again closes the panel, as the chip did.
            */}
            <button
                type="button"
                onClick={() => onPanel(open === 'share' ? null : 'share')}
                aria-expanded={open === 'share'}
                aria-controls="list-tools-panel"
                className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium whitespace-nowrap transition ${
                    open === 'share'
                        ? 'border-accent bg-accent/10 text-accent'
                        : shareOn
                          ? 'border-sage/60 bg-sage/10 text-sage hover:border-sage'
                          : 'border-line bg-card hover:border-ink'
                }`}
            >
                <ToolIcon name="shared" className="h-4 w-4 shrink-0" />
                {t('lists.share')}
                {shareOn && <span className="sr-only"> — {t('lists.tool_on')}</span>}
            </button>

            <Menu
                label={t('lists.more_tools_label')}
                width={280}
                button={<MoreButtonContent word={t('lists.more_tools')} wordOnPhone />}
                buttonClassName={`inline-flex items-center gap-1.5 rounded-full border px-3 py-2 text-sm whitespace-nowrap transition ${
                    open !== null && open !== 'share'
                        ? 'border-accent bg-accent/10 text-accent'
                        : 'border-line bg-card hover:border-ink'
                }`}
            >
                {(close) => (
                    <>
                        {visible.map((tool) => (
                            <MenuItem
                                key={tool.key}
                                icon={<ToolIcon name={tool.icon} className="h-4 w-4" />}
                                onSelect={() => {
                                    close()
                                    onPanel(tool.key)
                                }}
                            >
                                <span className="flex items-baseline justify-between gap-2">
                                    <span>{tool.label}</span>
                                    {tool.set && (
                                        <span className="shrink-0 text-xs font-medium text-sage">
                                            {t('lists.tool_on')}
                                        </span>
                                    )}
                                </span>
                            </MenuItem>
                        ))}

                        {/*
                          Ask others, also here (owner, 2026-09-26: "voeg Vraag
                          het aan anderen toe aan het Meer menu"), besides the
                          button next to "Product toevoegen". The same address:
                          the form is filled in on the server from this list
                          (AskPrefill), and ideas saved from the answers come
                          back onto it. Only on a list about somebody else, as
                          the button.
                        */}
                        {list.kind !== 'mine' && (
                            <MenuItem
                                href={`${base}/ask?list=${list.id}`}
                                icon={<ToolIcon name="board" className="h-4 w-4" />}
                            >
                                {t('lists.ask_others')}
                            </MenuItem>
                        )}

                        {personPage !== null && (
                            <MenuItem
                                href={personPage.href}
                                icon={<ToolIcon name="people" className="h-4 w-4" />}
                            >
                                {personPage.label}
                            </MenuItem>
                        )}

                        {/*
                          Getting rid of the list: last, set apart by a rule, in
                          the danger colour, and still asking once before it acts.
                          The store cannot infer a deleted list: every bookmark on
                          the next page would still report its products as saved,
                          into a list that is gone, hence `invalidate()`.
                        */}
                        <MenuSeparator />
                        <MenuItem
                            danger
                            icon={<ToolIcon name="trash" className="h-4 w-4" />}
                            onSelect={async () => {
                                close()

                                if (await confirm({ message: t('lists.delete_confirm'), confirmLabel: t('lists.delete'), danger: true })) {
                                    router.delete(`${base}/lists/${list.id}`, { onSuccess: () => invalidate() })
                                }
                            }}
                        >
                            {t('lists.delete')}
                        </MenuItem>
                    </>
                )}
            </Menu>
        </div>
    )
}
