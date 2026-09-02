import { router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import CopyToList from './CopyToList'
import ShareRow from './ShareRow'
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
    list: {
        id: string
        title: string
        kind: string
        claimable: boolean
        visibility: string
        shareUrl: string | null
        recipient: { name: string } | null
        eventType: string | null
        eventDate: string | null
        /** Is anybody else on this list? Most lists are private and solo. */
        hasCoGivers: boolean
        claimVisibility: string
        /** Whether the owner has asked to see what has been claimed. */
        ownerSeesClaims: boolean
        /** May somebody holding the link put things on the list? */
        linkCanAdd: boolean
        /** On a group gift: may everyone see who is chipping in? Names only. */
        pledgersVisible: boolean
        /** Cents per person, or null for "everyone names their own". */
        pledgeAmount: number | null
        /** On a group gift: do the members choose the present? */
        votingEnabled: boolean
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

export type Panel = 'share' | 'asked' | 'occasion' | 'quiz' | 'handover' | 'santa'

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
     * `show` is whether the chip exists; `set` is whether the thing behind it
     * is switched on.
     *
     * The row said nothing about state, so the only way to learn whether this
     * list had an occasion, a quiz or a live link was to open each panel in
     * turn and read it. Five identical chips, one of which was already doing
     * something. `set` lights the ones that are — and it is deliberately the
     * *stored* fact each panel writes, never a proxy for it.
     */
    const tabs: { key: Panel; label: string; show: boolean; set: boolean }[] = [
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
            label: t('lists.share'),
            show: access.isOwner,
            // Lit when there is a live link, not merely when the list is not
            // private: the link is the thing the panel hands out.
            set: shared && Boolean(list.shareUrl),
        },
        /*
         * What they asked for, on a list about somebody else.
         *
         * Second, because it is the other half of the same errand as Share: one
         * is what you are sending them, the other is what they sent you. On a
         * list about a person those two are the whole job, and everything below
         * is occasional.
         *
         * The label carries their name — "Ask Anna" — which makes it the one
         * chip in the row that is not a category. That is deliberate: the panel
         * is about a person, and "Asked" alone would read as a state of the
         * list rather than as somebody's answers.
         *
         * Gated on the kind as well as on the recipient. `ListMaker` derives
         * one from the other — a list with a recipient is `for_someone` or
         * `group`, one without is `mine` — so on today's data these are the
         * same condition. Written out anyway, because "ask them what they want"
         * on a wish list of your own would be the page asking you to interview
         * yourself, and a kind that is derived somewhere else is exactly the
         * sort of thing that stops being derived. `ListQuizController` names
         * its kind for the same reason.
         */
        {
            key: 'asked',
            label: t('lists.ask_tab', { name: target?.name ?? '' }),
            // The owner's, like Share. It holds a link that asks the recipient
            // to describe their own taste, and the answers they gave — an
            // errand belonging to whoever is organising the buying, not to
            // everyone the list was passed to.
            show:
                access.isOwner
                && target !== null
                && (list.kind === 'for_someone' || list.kind === 'group'),
            // Lit once they have actually answered. Not "have they an account":
            // a linked recipient with an empty list is the same nothing as an
            // unlinked one, from this page.
            set: asked.length > 0,
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
            label: t('quiz.badge'),
            show: shared && list.claimable && list.kind === 'mine',
            // A quiz exists or it does not; `quizPlays` is how it went, which
            // is a fact for inside the panel.
            set: quizUrl !== null,
        },
        /*
         * An occasion sits on any kind of list.
         *
         * This was `kind === 'mine'`, which made it the *registry* panel — and
         * the column, the validator and the shared page were all kind-agnostic
         * the whole time, so a birthday on a list about your father was storable
         * and renderable and simply had nowhere to be typed. Only the delivery
         * address inside the panel is registry-only; see the panel below.
         *
         * It was then folded into Share for a day and is a tab again. Setting
         * what a list is for is not the same errand as letting people see it —
         * you name the occasion months before you invite anybody, and often on
         * a list you never share at all — so under Share it was hidden behind a
         * word that means something else.
         *
         * Labelled with `registry.occasion` rather than `registry.badge`: a chip
         * in a scrolling row wants one word ("Gelegenheid"), and the panel it
         * opens carries the full "Speciale gelegenheid" as its heading.
         */
        {
            key: 'occasion',
            label: t('registry.occasion'),
            show: access.isOwner,
            // Either half counts: a date with no type is still an answer to
            // "what is this list for", and the panel stores them separately.
            set: Boolean(list.eventType) || Boolean(list.eventDate),
        },
        /*
         * Handing over is an act, not a setting, so it is never lit.
         *
         * `canHandOver` is already false once it has happened — the chip goes
         * away rather than lighting up — and `handoverEmail` is only the
         * recipient's address prefilled for convenience. Lighting the chip off
         * that would announce a handover nobody has offered.
         */
        { key: 'handover', label: t('handover.badge'), show: canHandOver, set: false },
        {
            key: 'santa',
            label: t('santa.title'),
            show: santaMemberships.length > 0,
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
                            {/*
                              A dot as well as the colour. Colour alone is not
                              a state anybody can rely on — and these chips
                              scroll past at a glance, where a filled dot reads
                              faster than a tint does.
                            */}
                            {tab.set && (
                                <span
                                    aria-hidden
                                    className={`h-1.5 w-1.5 shrink-0 rounded-full ${
                                        open === tab.key ? 'bg-accent' : 'bg-sage'
                                    }`}
                                />
                            )}
                            {tab.label}
                            {/* And in words, for a reader who gets neither. */}
                            {tab.set && <span className="sr-only"> — {t('lists.tool_on')}</span>}
                        </button>
                    ))}
                </div>
            )}

            {open !== null && (
                <div id="list-tools-panel" className="mt-3 rounded-card border border-line bg-card p-4">
                    {open === 'share' && (
                        /*
                          One column, full width, one option per row.

                          This went through both alternatives first. Capped at
                          `max-w-xl` it read well and left half the panel empty,
                          which on a control surface looks like something failed
                          to load. Two columns filled the width and paired
                          settings side by side — and a pair of switches at the
                          same height reads as one choice with two halves, which
                          none of these are: they are independent facts about
                          one link, and each is answered on its own.

                          So: the full measure, and every option on a row of its
                          own. `space-y-8` between blocks is deliberately wider
                          than the space inside one, which is what makes three
                          blocks read as three rather than as one long form.
                        */
                        <div className="space-y-8">
                            {/*
                              The link, what it lets people do, and who can see
                              what once they have it.

                              Share and People were two chips asking two halves
                              of one question — who else is looking at this
                              list — with the roster, the one thing a group list
                              cannot work without, filed furthest from the
                              button that shares it. They are one panel.

                              Four blocks now, each with a heading, in the order
                              the decision is actually made: is it shared, what
                              does the link allow, who sees the claims, who
                              already has access. Before this they ran link →
                              claim privacy → a loose sentence about the link →
                              what the link allows → roster, all under one
                              heading reading "Visibility" — so the two settings
                              that are about the *link* sat below the two that
                              are about *claims*, separated by a paragraph
                              explaining the link they were nowhere near.

                              Every block below the first is the owner's. A
                              collaborator on an already-shared list sees the
                              link and can pass it on, which is the whole reason
                              this tab is offered to them.
                            */}

                            {/*
                              What is true right now, in one sentence, before
                              any control.

                              The panel used to open straight into either a
                              button or a URL and leave the reader to infer the
                              state from which of the two they got. On a page
                              where the mistake is thinking something is private
                              when it is not, the state is worth a line of its
                              own.
                            */}
                            <section>
                                {/*
                                  The state, and the press that ends it, on one
                                  row.

                                  Stop sharing sat under the URL, which put the
                                  control that revokes the link below the link
                                  itself — read in order, that is "here is the
                                  link, here is the field, and by the way you can
                                  destroy it". Beside the sentence it belongs to
                                  it reads as what it is: this is the state, and
                                  this ends it. It also gets the link field a
                                  row higher, which is what most people opened
                                  the panel for.

                                  Still quiet, still second: a bordered
                                  secondary button, right-aligned, against a
                                  sentence in medium. It was grey underlined
                                  text before — the least prominent thing on the
                                  panel, and the one irreversible one.
                                */}
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-sm font-medium">
                                        {list.shareUrl
                                            ? t('lists.sharing_on')
                                            : t('lists.sharing_off')}
                                    </p>

                                    {list.shareUrl && access.isOwner && (
                                        <button
                                            type="button"
                                            onClick={() => setting({ visibility: 'private' })}
                                            className="rounded-lg border border-line px-3 py-1.5 text-xs text-ink-soft hover:border-ink hover:text-ink"
                                        >
                                            {t('lists.disable_sharing')}
                                        </button>
                                    )}
                                </div>

                                {/*
                                  Private lists land here too, now that this
                                  panel is also where the roster lives — so it
                                  has to offer the press rather than assume it
                                  has already happened.
                                */}
                                {!list.shareUrl && access.isOwner && (
                                    <>
                                        <p className="mt-1 text-xs text-ink-soft">
                                            {t('lists.share_hint')}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => setting({ visibility: 'link' })}
                                            className="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                        >
                                            {t('lists.share')}
                                        </button>
                                    </>
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
                              Everything that is true about the link, as a
                              stack of switches.

                              This was two blocks with two headings: the
                              adding switch inside the link section, and a
                              "Who sees what" section carrying a legend and
                              a switch under it. Three settings, three
                              levels of heading, and each heading naming a
                              category rather than telling you anything the
                              switch beside it did not already say.

                              Under each other with no heading at all, they
                              read as what they are: a short list of facts
                              about this link, each with an on and an off.
                              The panel is a link and the things that are
                              true about it; a category name in front of one
                              of them was scaffolding for a form this is not.

                              Each condition is its own, because the three
                              are answerable at different times: adding needs
                              a live link, and the two claim questions need
                              somebody else to be on the list at all — a
                              privacy choice offered on a solo research list
                              is a question about an audience of one, which
                              reads as though somebody is already looking.
                            */}
                            {/*
                              How a group gift collects.

                              The pot only ever worked one way: each person types
                              what they are putting in. Right for "chip in what
                              you can", wrong for the commonest group gift there
                              is — twelve colleagues, €10 each, done. Asking
                              twelve people to type the same number is twelve
                              chances to get a different one, and the organiser
                              then chases the two who typed €5.

                              A choice rather than a switch with a field hanging
                              off it, because the two are genuinely different
                              collections rather than one with an option. The
                              amount appears under the option it belongs to and
                              nowhere else, so the form never shows a field that
                              means nothing yet.

                              Written on blur, not per keystroke: this posts, and
                              "€1, €12, €120" typed into a live-saving field is
                              three settings saved and two of them wrong.
                            */}
                            {/*
                              No heading, and no rule above it.

                              "How everyone chips in" named a category over two
                              options that already say what they are, and the
                              hairline above it drew a boundary between settings
                              that are all one list of facts about one link. The
                              panel is that list; the only heading left is the
                              state at the top, which is not a setting.
                            */}
                            {access.isOwner && list.kind === 'group' && (
                                <section>
                                    <div className="space-y-2">
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
                                            /*
                                              Choosing this needs a number to
                                              become true at all, so the press
                                              seeds one rather than writing null
                                              and leaving the radio unable to
                                              select itself. Ten is the amount
                                              this setting exists for.
                                            */
                                            onChange={() => setting({ pledge_amount: 10 })}
                                            label={t('lists.pledge_mode_fixed')}
                                        />

                                        {list.pledgeAmount !== null && (
                                            <label className="flex items-center gap-2 pl-3 text-sm">
                                                {/*
                                                  The symbol, from the market's
                                                  own currency code rather than
                                                  a hard-coded €: four markets
                                                  today all use euros, and the
                                                  fifth to be added would find
                                                  this quietly wrong.
                                                */}
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

                            {access.isOwner && (
                                <section>
                                    <div className="space-y-2">
                                        {/*
                                          Who may put something on it.

                                          Not offered on a wish list of your
                                          own: the people holding that link are
                                          shopping for you, and "anyone can add
                                          gifts" there would invite them to
                                          write your list for you. On a group
                                          list and on a list about somebody else
                                          the holders are co-givers, adding is
                                          half of what they are there for, and
                                          the switch says so in those words
                                          rather than in the language of
                                          permissions.

                                          It replaced a two-outcome choice
                                          between additions going on straight
                                          away and additions waiting for the
                                          owner to accept them. The approval
                                          queue is no longer offered — somebody
                                          handed the link has already been
                                          trusted with the list, and moderating
                                          a queue is work invented by the
                                          setting. `Wishlist::linkCanAdd()`
                                          still answers per kind when nobody has
                                          said, and a hand-written item still
                                          waits regardless, because that is free
                                          text arriving through a forwardable
                                          link.
                                        */}
                                        {list.shareUrl && list.kind !== 'mine' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.linkCanAdd}
                                                onChange={() =>
                                                    setting({ link_can_add: ! list.linkCanAdd })
                                                }
                                                label={t('lists.anyone_can_add')}
                                            />
                                        )}

                                        {/*
                                          A group gift's question, and only its.

                                          The pot said one number and one count
                                          to everybody but the organiser: €140
                                          from six people, and nothing about
                                          which six. That is the right default
                                          and it was the only answer, which made
                                          it a rule — and it is not one. Six
                                          colleagues buying a leaving present
                                          mostly want to know whether the other
                                          five are actually in.

                                          The hint is the important half of the
                                          label: this adds *who*, never how
                                          much. Amounts stay the organiser's
                                          whatever this says, because a visible
                                          ladder is pressure on whoever put in
                                          least — and a switch called "everyone
                                          sees who is chipping in" would
                                          otherwise be read as offering the
                                          numbers too.
                                        */}
                                        {list.kind === 'group' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.pledgersVisible}
                                                onChange={() =>
                                                    setting({
                                                        pledgers_visible: ! list.pledgersVisible,
                                                    })
                                                }
                                                label={t('lists.pledgers_visible')}
                                                hint={t('lists.pledgers_visible_hint')}
                                            />
                                        )}

                                        {/*
                                          Do the members choose the present?

                                          On for every group list since voting
                                          shipped, decided by the kind — a good
                                          default and a bad rule. Half of these
                                          are "we already know what we're
                                          buying, here it is, chip in", and on
                                          those the vote button under each
                                          candidate invites a decision that has
                                          been made, and reorders the shortlist
                                          under somebody reading it.

                                          Switching it off deletes nothing. A
                                          vote is somebody's opinion, and
                                          turning it back on shows the tally
                                          exactly as it was.
                                        */}
                                        {list.kind === 'group' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.votingEnabled}
                                                onChange={() =>
                                                    setting({
                                                        voting_enabled: ! list.votingEnabled,
                                                    })
                                                }
                                                label={t('lists.voting_enabled')}
                                                hint={t('lists.voting_enabled_hint')}
                                            />
                                        )}

                                        {/*
                                          A wish list's question, and only its.

                                          On a list about somebody else the
                                          owner is a co-giver organising the
                                          buying, the recipient never opens the
                                          page, and seeing what is covered is
                                          the entire point of the list — so it
                                          defaults on and there is nothing to
                                          weigh. Offering it there invited the
                                          owner to switch off the thing they
                                          came for.

                                          Here it is a real trade, and the only
                                          one: seeing spoils it. Invariant #4 as
                                          a default rather than an absolute — a
                                          wish list hides by default because the
                                          surprise is the point, nothing infers
                                          otherwise, and only this press turns it
                                          on.
                                        */}
                                        {list.claimable
                                            && list.hasCoGivers
                                            && list.kind === 'mine' && (
                                            <Option
                                                type="checkbox"
                                                checked={list.ownerSeesClaims}
                                                onChange={() =>
                                                    setting({
                                                        owner_sees_claims: ! list.ownerSeesClaims,
                                                    })
                                                }
                                                label={t('lists.claim_mine_show')}
                                                hint={t('lists.claim_mine_show_hint_mine')}
                                            />
                                        )}

                                        {/*
                                          One switch, not a question and two
                                          answers.

                                          `claim_visibility` is a two-valued
                                          column and it was a radio pair: a
                                          legend asking "who can see who claimed
                                          what?", then "nobody sees names" and
                                          "everyone sees names", each with a line
                                          of explanation under it. Five lines of
                                          copy and a heading to carry one bit —
                                          and the two options are not two things,
                                          they are one thing and its negation,
                                          which is what a switch is for.

                                          The label states the *on* position, so
                                          the switch and its label agree: ticked
                                          means names are visible. `anonymous`
                                          stays the unticked value, which keeps
                                          the stored default — nobody sees names
                                          until somebody says otherwise — exactly
                                          where it was.
                                        */}
                                        {list.claimable && list.hasCoGivers && (
                                            <Option
                                                type="checkbox"
                                                checked={list.claimVisibility === 'named'}
                                                onChange={() =>
                                                    setting({
                                                        claim_visibility:
                                                            list.claimVisibility === 'named'
                                                                ? 'anonymous'
                                                                : 'named',
                                                    })
                                                }
                                                label={t('lists.claim_names_visible')}
                                            />
                                        )}
                                    </div>
                                </section>
                            )}

                            {/*
                              The people who were let in one at a time, back
                              when that was how sharing worked.

                              Nothing creates collaborators any more, and people
                              granted access that way still have it — so the
                              owner keeps a way to take it back. Its own block
                              with its own heading: it was a bare `<ul>` whose
                              only explanation was an `aria-label` nobody
                              sighted ever heard.
                            */}
                            {access.isOwner && collaborators.length > 0 && (
                                <section className="border-t border-line pt-5">
                                    <h3 className="text-sm font-medium">
                                        {t('lists.invited_before')}
                                    </h3>

                                    <ul className="mt-3 space-y-2">
                                        {collaborators.map((c) => (
                                            <li
                                                key={c.id}
                                                className="flex items-center justify-between gap-3 text-sm"
                                            >
                                                <span>
                                                    {c.name}
                                                    <span className="ml-2 text-xs text-ink-soft">
                                                        {c.role === 'editor'
                                                            ? t('lists.role_editor')
                                                            : t('lists.role_viewer')}
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
                              One confirmation for the whole panel, at the foot
                              of it.

                              Not one per control: four of these save the same
                              way, and a "Saved" beside each would be four
                              places for the same sentence to appear and four
                              chances for a row to change height while somebody
                              is reading it. The height is held whether or not
                              there is anything to say, so a save never nudges
                              the page.
                            */}
                            <p
                                role="status"
                                aria-live="polite"
                                className="h-4 text-xs text-ink-soft"
                            >
                                {saved !== 0 && t('lists.saved')}
                            </p>
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
                    {open === 'asked' && target !== null && (
                        /*
                          Full width, like the rest of the row's panels: this is
                          a list of products with an image, a price and a button
                          on each row, and capping it would leave the buttons
                          bunched against the middle of the page while the right
                          half sat empty.
                        */
                        <div>
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

                    {open === 'occasion' && (
                        <div>
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



                    {open === 'handover' && (
                        <form
                            className="flex flex-wrap gap-2"
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
