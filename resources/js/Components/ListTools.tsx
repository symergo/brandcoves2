import { router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import CopyToList from './CopyToList'
import ShareRow from './ShareRow'
import { invalidate } from '../savedItems'
import ToolIcon, { type ToolKey } from './ToolIcon'
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
 * One thing the recipient put on a list of their own.
 *
 * `token` is that list's share token: claiming here posts to the shared-list
 * endpoint, so there is one claim mechanism and the privacy rule is enforced in
 * one place rather than two.
 */
interface Asked {
    id: number
    token: string
    title: string
    image: string | null
    price: number | null
    live: boolean
    claimed: boolean
    claimedByMe: boolean
    sent: boolean | null
}

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
}

export type Panel = 'share' | 'ask' | 'settings' | 'quiz' | 'santa'

/**
 * One choice, as a card you press rather than a dot you aim at.
 *
 * The sharing settings were bare inputs with their label and a grey hint
 * running the full width of the panel — about 1,100px on a laptop, so a
 * two-line explanation became one very long line and the eye had to travel back
 * across the whole page to find the next option. Four of them stacked like that
 * read as a form to fill in rather than a question to answer, which is the
 * opposite of what these are: nothing here is typed, every one is a choice
 * between two stated outcomes.
 *
 * So each option is a bordered card, the whole of it is the hit target, and the
 * selected one is tinted. That gives the group a shape you can take in without
 * reading it, makes the target a finger rather than a 13px circle, and — with
 * the column capped — puts the hint on two comfortable lines under its own
 * label instead of one line under all of them.
 */
function Option({
    type,
    name,
    checked,
    onChange,
    label,
    hint,
}: {
    type: 'radio' | 'checkbox'
    name?: string
    checked: boolean
    onChange: () => void
    label: string
    hint?: string
}) {
    return (
        <label
            className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${
                checked ? 'border-accent bg-accent/5' : 'border-line hover:border-ink/30'
            }`}
        >
            <input
                type={type}
                name={name}
                checked={checked}
                onChange={onChange}
                className="mt-0.5 shrink-0"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium">{label}</span>
                {hint && <span className="mt-0.5 block text-xs text-ink-soft">{hint}</span>}
            </span>
        </label>
    )
}

/**
 * Everything you can do with a list, behind one row of buttons.
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
    asked,
    panel: open,
    onPanel,
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

    const shared = list.visibility !== 'private'

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

    const setting = (data: Record<string, string | number | boolean | null>) =>
        router.patch(`${base}/lists/${list.id}`, data, {
            preserveScroll: true,
            onSuccess: () => setSaved(Date.now()),
        })

    // Only a wish list of your own is a registry; every kind may carry an
    // occasion. The delivery address is the half that stays behind this.
    const isRegistry = list.kind === 'mine'

    /*
     * Does the share panel have anything to say about what the link allows?
     *
     * The section used to render regardless and be empty on a private wish
     * list of your own, which is most lists, so the panel opened onto a
     * heading-less gap. Adding needs a live link on a list that is not about
     * you; the two group switches need a group. Nothing else exists.
     */
    const linkOptions = access.isOwner && ((list.shareUrl !== null && list.kind !== 'mine') || list.kind === 'group')

    /*
     * `show` is whether the chip exists; `set` is whether the thing behind it
     * is switched on.
     *
     * The row said nothing about state, so the only way to learn whether this
     * list had an occasion, a quiz or a live link was to open each panel in
     * turn and read it. Five identical chips, one of which was already doing
     * something. `set` lights the ones that are — and it is deliberately the
     * *stored* fact each panel writes, never a proxy for it.
     */
    const tabs: { key: Panel; icon: ToolKey; label: string; show: boolean; set: boolean }[] = [
        /*
         * Share sits in this row, always, and is the first thing in it.
         *
         * It used to appear here only once sharing was already on, with the
         * button that turns it on living up in the header beside Delete — a row
         * about administering the list. So the one control people came for was
         * in a different place before and after the single press that matters,
         * and the row of things you can do with a list did not include the main
         * one. `toggle()` below turns sharing on when it is off, so the button
         * means the same thing in both states.
         *
         * **The owner's, and nobody else's.** It was shown to a collaborator on
         * an already-shared list too, on the argument that they cannot change
         * visibility but can pass the link on. That argument was about the old
         * panel, which was a link and a copy button. This one is the list's
         * sharing settings — who may add, whether names are shown, who was
         * invited before — and none of that is a guest's to look at, let alone
         * to decide. Handing a list on is what a browser's address bar is for;
         * a settings panel is not a share sheet.
         */
        {
            key: 'share',
            icon: 'shared',
            label: t('lists.share'),
            show: access.isOwner,
            // Lit when there is a live link, not merely when the list is not
            // private: the link is the thing the panel hands out.
            set: shared && Boolean(list.shareUrl),
        },
        /*
         * Ask the recipient for suggestions, on a list about somebody else.
         *
         * It spent an afternoon as a section under Share, on the argument that
         * it is the same errand as sharing: the people you sent the list to.
         * The owner wanted it back as a button of its own, and the case is
         * fair: the link it hands out goes to the one person the list must
         * stay hidden from, which is the opposite direction to everything in
         * Share, and the answers that come back are a list to read, not a
         * setting. Second in the row because it is the other half of the same
         * job as Share: one is what you send them, the other what they sent
         * you. Lit once they have actually answered.
         *
         * Gated on the kind as well as on the recipient: ListMaker derives one
         * from the other today, and "ask them what they want" on a wish list
         * of your own would be the page asking you to interview yourself.
         */
        {
            key: 'ask',
            icon: 'suggestions',
            label: t('lists.ask_chip'),
            show:
                access.isOwner
                && target !== null
                && (list.kind === 'for_someone' || list.kind === 'group'),
            set: asked.length > 0,
        },
        /*
         * The list's own settings: its name, the note under it, whether its
         * prices are watched, and what it is for.
         *
         * Share is who may see the list; this is what the list is. The
         * occasion used to be a chip of its own and the price switch sat among
         * the sharing options, so the row named one property and hid another
         * under a word that means something else. Lit when any of it is set:
         * a watched price or an occasion is a fact about the list worth seeing
         * from the row.
         */
        {
            key: 'settings',
            icon: 'settings',
            label: t('lists.settings'),
            show: access.isOwner,
            set: list.priceWatchPercent !== null || Boolean(list.eventType) || Boolean(list.eventDate),
        },
        /*
         * A quiz asks "how well do you know **me**", so it only exists over a
         * wish list of your own.
         *
         * This was `shared && claimable`, which were the same thing as "mine"
         * until gift lists became claimable — at which point the tab appeared
         * on private research about a named person, offering to publish it as a
         * game. `ListQuizController` enforces the kind too; this is the mirror.
         */
        {
            key: 'quiz',
            icon: 'quiz',
            label: t('quiz.badge'),
            /*
             * `access.isOwner` is not decoration here.
             *
             * This page is reachable by somebody who merely opened the list's
             * link — `ListAccess::scope()` unions `list_opens` — and without
             * the check a visitor to your wish list was offered a tab to
             * publish it as a game about you. `ListQuizController` refuses
             * them, so it was a button that 403s rather than a hole; the cards
             * on My Lists now send readers to `l/{token}` instead, and this is
             * the second lock on a door that should not have been ajar.
             */
            show: access.isOwner && shared && list.claimable && list.kind === 'mine',
            // A quiz exists or it does not; `quizPlays` is how it went, which
            // is a fact for inside the panel.
            set: quizUrl !== null,
        },
        {
            key: 'santa',
            icon: 'santa',
            label: t('santa.title'),
            // The owner's too, and for the reason spelled out on `quiz` above:
            // this page is reachable by anybody who has opened the link.
            show: access.isOwner && santaMemberships.length > 0,
            // Being in a group is why the chip is there at all; being the list
            // that group reads is the setting.
            set: santaMemberships.some((membership) => membership.attached),
        },
    ]

    /*
     * Ordered by what this kind is for, not by the order they were written in.
     *
     * A group list leads with the people, because it does nothing at all until
     * somebody else is on it. Everything else leads with Share. The array below
     * is a fixed order, so without this the tab that matters most for a kind
     * falls wherever it happens to.
     */
    const visible = tabs.filter((tab) => tab.show)

    function toggle(panel: Panel) {
        if (open === panel) {
            onPanel(null)

            return
        }

        /*
         * Opening this panel used to publish the list.
         *
         * That was right while the panel was only the link: sharing took two
         * presses in two places, people turned it on and left without the URL,
         * and collapsing them meant the button meant the same thing in both
         * states.
         *
         * It stopped being right when the panel absorbed the roster. An
         * invited sibling is something an owner adds to a list they have
         * **not** decided to share, and a tab that published the list as a
         * side effect of being opened would be a privacy change nobody asked
         * for, on the one page where privacy is the whole point.
         *
         * So the press is inside the panel now, where it is a button that says
         * what it does. The two-press objection is answered by that button
         * being the first thing in it rather than in a different place.
         */
        onPanel(panel)
    }

    return (
        <div className="mt-6">
            {/*
              Pending suggestions stay in the open. Everything else is a thing
              you go looking for; this is a thing somebody sent you.
            */}
            {access.isOwner && suggestions.length > 0 && (
                <section className="mb-4 rounded-card border border-accent/40 bg-accent/5 p-4">
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
            )}

            {visible.length > 0 && (
                /*
                 * Scrolls rather than wraps on a phone. Four chips wrap to two
                 * rows on a narrow screen and push the list itself below the
                 * fold, which is the thing the page is for.
                 */
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    {visible.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => toggle(tab.key)}
                            aria-expanded={open === tab.key}
                            aria-controls="list-tools-panel"
                            /*
                             * Three states, and open beats set.
                             *
                             * Accent is "you are looking at this one" and has
                             * to stay the loudest, or the row reads as two
                             * things being open at once. Sage is the colour
                             * this product already uses for a live, benign
                             * state — the Shared chip on an index card, a
                             * claimed item — so a switched-on tool matches the
                             * badge that says the same thing elsewhere.
                             */
                            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm whitespace-nowrap transition ${
                                open === tab.key
                                    ? 'border-accent bg-accent/10 text-accent'
                                    : tab.set
                                      ? 'border-sage/60 bg-sage/10 text-sage hover:border-sage'
                                      : 'border-line hover:border-ink'
                            }`}
                        >
                            <ToolIcon name={tab.icon} className="h-4 w-4 shrink-0" />
                            {/*
                              The colour says whether the tool is switched on;
                              a filled dot used to say it too, from before the
                              chips had icons. With an icon in front of every
                              label the dot was a third mark for one fact, and
                              the owner asked for it to go. The words below
                              still carry the state for a reader who gets no
                              colour at all.
                            */}
                            {tab.label}
                            {/* And in words, for a reader who gets neither. */}
                            {tab.set && <span className="sr-only"> — {t('lists.tool_on')}</span>}
                        </button>
                    ))}
                    {/*
                      Getting rid of the list, last in the row and pushed to its
                      far end. It sat in the page header before, an icon with no
                      word, level with the title; the row of things you can do with
                      a list is where somebody looks for it, and the one
                      destructive control reads better with its name beside it.
                      Not a panel: it asks once and acts.
                    */}
                    {access.isOwner && (
                        <button
                            type="button"
                            onClick={() => {
                                if (confirm(t('lists.delete_confirm'))) {
                                    // The store cannot infer a deleted list: every
                                    // bookmark on the next page would still report
                                    // its products as saved, into a list that is gone.
                                    router.delete(`${base}/lists/${list.id}`, { onSuccess: () => invalidate() })
                                }
                            }}
                            className="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-sm whitespace-nowrap text-ink-soft transition hover:border-accent hover:text-accent"
                        >
                            <ToolIcon name="trash" className="h-4 w-4 shrink-0" />
                            {t('lists.delete')}
                        </button>
                    )}
                </div>
            )}

            {open !== null && (
                <div id="list-tools-panel" className="mt-3 rounded-card border border-line bg-card p-4">
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
                              What is true right now, as the heading, before
                              any control. On a page where the mistake is
                              thinking something is private when it is not,
                              the state is worth being the first line.
                            */}
                            <section>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h3 className="text-sm font-medium">
                                            {list.shareUrl ? t('lists.sharing_on') : t('lists.sharing_off')}
                                        </h3>
                                        {!list.shareUrl && (
                                            <p className="mt-1 text-xs text-ink-soft">{t('lists.share_hint')}</p>
                                        )}
                                    </div>
                                    {/*
                                      Stop sharing beside the sentence it ends,
                                      quiet and second: a bordered secondary
                                      button against a heading, not grey text
                                      under the link.
                                    */}
                                    {list.shareUrl && access.isOwner && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm(t('lists.disable_sharing_confirm'))) {
                                                    setting({ visibility: 'private' })
                                                }
                                            }}
                                            className="rounded-lg border border-line px-3 py-1.5 text-xs text-ink-soft hover:border-ink hover:text-ink"
                                        >
                                            {t('lists.disable_sharing')}
                                        </button>
                                    )}
                                </div>

                                {/*
                                  The press that publishes the list is a button
                                  that says so. It used to be labelled "Share",
                                  the same word as the chip that opened this
                                  panel, so the panel appeared to ask the same
                                  question twice; "Turn sharing on" is the
                                  answer to the sentence above it.
                                */}
                                {!list.shareUrl && access.isOwner && (
                                    <button
                                        type="button"
                                        onClick={() => setting({ visibility: 'link' })}
                                        className="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('lists.enable_sharing')}
                                    </button>
                                )}

                                {list.shareUrl && (
                                    <div className="mt-3">
                                        <ShareRow
                                            url={list.shareUrl}
                                            text={t('lists.share_text', { title: list.title })}
                                        />
                                    </div>
                                )}
                            </section>

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
                                    <h3 className="text-sm font-medium">
                                        {t('lists.share_with_friends')}
                                        {list.sharedWith.length > 0 && (
                                            <span className="ml-1 font-normal text-ink-soft">
                                                ({list.sharedWith.length})
                                            </span>
                                        )}
                                    </h3>
                                    <p className="mt-1 text-xs text-ink-soft">{t('lists.share_with_friends_hint')}</p>
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
                                                            onClick={() => {
                                                                if (
                                                                    !window.confirm(
                                                                        t('lists.unshare_confirm', {
                                                                            name: friend.name,
                                                                        }),
                                                                    )
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
                                                                ✓
                                                            </span>
                                                            <span aria-hidden className="hidden group-hover:inline">
                                                                ✕
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
                              What the link allows, as a short list of switches
                              under one heading. Each is its own condition,
                              because the three are answerable at different
                              times: adding needs a live link on a list that is
                              not about you (on your own wish list the holders
                              are shopping for you, and "anyone can add" would
                              invite them to write your list); the two group
                              switches need a group. See the Wishlist model
                              for what each defaults to when nobody has said.
                            */}
                            {linkOptions && (
                                <section className="mt-6">
                                    <div className="space-y-2">
                                        {list.shareUrl && list.kind !== 'mine' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.linkCanAdd}
                                                onChange={() => setting({ link_can_add: ! list.linkCanAdd })}
                                                label={t('lists.anyone_can_add')}
                                                hint={t('lists.anyone_can_add_hint')}
                                            />
                                        )}
                                        {/*
                                          Names only, never amounts: the ladder
                                          of who put in how much stays the
                                          organiser's whatever this says. See
                                          ContributionView.
                                        */}
                                        {list.kind === 'group' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.pledgersVisible}
                                                onChange={() => setting({ pledgers_visible: ! list.pledgersVisible })}
                                                label={t('lists.pledgers_visible')}
                                                hint={t('lists.pledgers_visible_hint')}
                                            />
                                        )}
                                        {/*
                                          Switching voting off deletes nothing:
                                          a vote is somebody's opinion, and
                                          turning it back on shows the tally as
                                          it was.
                                        */}
                                        {list.kind === 'group' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.votingEnabled}
                                                onChange={() => setting({ voting_enabled: ! list.votingEnabled })}
                                                label={t('lists.voting_enabled')}
                                                hint={t('lists.voting_enabled_hint')}
                                            />
                                        )}
                                    </div>
                                </section>
                            )}

                            {/*
                              How a group gift collects: a choice, not a switch
                              with a field hanging off it, because "each names
                              their own" and "everyone puts in the same" are
                              two collections rather than one with an option.
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

                            {/*
                              One confirmation for the switches, at the foot,
                              holding its height so a save never nudges the
                              panel. Only when there is a switch to confirm:
                              a private wish list of your own has none, and
                              held blank height under a single button was part
                              of the gap this redesign removed.
                            */}
                            {(linkOptions || (access.isOwner && list.kind === 'group')) && (
                                <p role="status" aria-live="polite" className="mt-3 h-4 text-xs text-sage">
                                    {saved !== 0 && t('lists.saved')}
                                </p>
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
                      What they actually asked for.

                      The payoff of linking a recipient to an account. Claiming
                      here hits the same endpoint as the shared-list page — one
                      claim mechanism, so the privacy rule is enforced in one
                      place. They never see any of this on their own list.

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
                            ) : asked.length === 0 ? (
                                <p className="text-sm text-ink-soft">
                                    {t('lists.asked_none', { name: target.name })}
                                </p>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {asked.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="flex items-center gap-4 py-3 first:pt-0 last:pb-0"
                                        >
                                            {entry.image && (
                                                <img
                                                    src={entry.image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-14 w-14 shrink-0 object-contain"
                                                />
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {entry.title}
                                                </p>
                                                {entry.price !== null && !entry.live && (
                                                    <p className="text-sm text-ink-soft">
                                                        {formatPrice(entry.price, market)}
                                                    </p>
                                                )}
                                            </div>

                                            {/*
                                              Put what they asked for onto the
                                              list you asked from.

                                              The payoff of this whole panel.
                                              Their list was readable here and
                                              nothing else — a giver could see
                                              "she wants the green kettle" and
                                              then had to go and find it again
                                              on their own list by hand.
                                              Everything below this is about
                                              *claiming* it, which is a
                                              different act by a different
                                              person.

                                              A copy, never a move: the source
                                              is somebody else's wishlist, and
                                              a giver has no business taking a
                                              row off it. Their list is
                                              unchanged; `ItemTransferController::
                                              fromShared` enforces that.

                                              Labelled "add to my list" rather
                                              than "copy to another list": from
                                              here it is not another list, it is
                                              the one you are working from.
                                            */}
                                            <CopyToList
                                                action={`${base}/l/${entry.token}/items/${entry.id}/copy`}
                                                targets={[{ id: list.id, title: list.title }]}
                                                label={t('lists.add_to_my_list')}
                                            />

                                            {entry.claimedByMe ? (
                                                <div className="flex shrink-0 items-center gap-2">
                                                    <span className="text-sm text-sage">
                                                        {t('lists.claimed')}
                                                    </span>
                                                    {entry.sent === false && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/l/${entry.token}/sent/${entry.id}`,
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                            className="rounded-lg border border-line px-3 py-1.5 text-sm"
                                                        >
                                                            {t('lists.mark_sent')}
                                                        </button>
                                                    )}
                                                    {entry.sent && (
                                                        <span className="text-sm text-ink-soft">
                                                            {t('lists.sent')}
                                                        </span>
                                                    )}
                                                </div>
                                            ) : entry.claimed ? (
                                                <span className="shrink-0 text-sm text-ink-soft">
                                                    {t('lists.claimed_by_someone')}
                                                </span>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            `${base}/l/${entry.token}/claim/${entry.id}`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="shrink-0 rounded-lg border border-line px-3 py-1.5 text-sm"
                                                >
                                                    {t('lists.claim')}
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
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
                                    if (list.recipient !== null && renamed !== '' && renamed !== list.recipient.name) {
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
                                {/*
                                  Who it is for, when it is about somebody. The
                                  name appeared nowhere on the page once the
                                  "Ask :name" chip lost its label, and a list
                                  about a person whose page never says the
                                  person is a list with its title missing.
                                */}
                                {list.recipient !== null && (
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
                                            hint={t('lists.price_watch_hint')}
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
                        </div>
                    )}
                    {open === 'settings' && (
                        <div className="mt-8 border-t border-line pt-6">
                            <h3 className="text-sm font-medium">{t('registry.badge')}</h3>

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
                                <p className="text-xs text-ink-soft sm:col-span-2">{t('registry.hint')}</p>

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
                                    <textarea
                                        name="delivery_address"
                                        rows={2}
                                        defaultValue={deliveryAddress ?? ''}
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                    />
                                    <span className="mt-1 block text-xs text-ink-soft">
                                        {t('registry.address_hint')}
                                    </span>
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



                    {open === 'share' && canHandOver && (
                        <div className="mt-6">
                        <h3 className="text-sm font-medium">{t('handover.badge')}</h3>
                        <form
                            className="mt-3 flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault()

                                if (confirm(t('handover.confirm', { name: handTo }))) {
                                    router.post(`${base}/lists/${list.id}/handover`, { email: handTo })
                                }
                            }}
                        >
                            <p className="w-full text-xs text-ink-soft">
                                {t('handover.hint', { name: list.recipient?.name ?? '' })}
                            </p>
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
        </div>
    )
}
