import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { type ListKind } from '../../Components/ListKindBadge'
import CopyToList, { type CopyTarget } from '../../Components/CopyToList'
import ListItemCard from '../../Components/ListItemCard'
import ListPills from '../../Components/ListPills'
import ManualItem from '../../Components/ManualItem'
import SaveToList from '../../Components/SaveToList'
import Pledge, { type Contributions } from '../../Components/Pledge'
import Vote from '../../Components/Vote'
import type { SharedProps } from '../../types'
import { formatOccasionDate, formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import ScanButton from '../../Components/ScanButton'
import ListBoard, { type BoardState } from '../../Components/ListBoard'
import { send } from '../../http'
import { useSignIn } from '../../signIn'

interface Item {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    url: string | null
    /** So a visitor can keep it on a list of their own. Null for a manual wish. */
    groupId: number | null
    /** Off-site, for a hand-written item. Never an Inertia visit. */
    externalUrl: string | null
    inStock: boolean
    /**
     * Absent — not null — for anybody who may not see claims, which on a wish
     * list means its owner. `claimed === undefined` is therefore the test for
     * "there is no claiming to show here"; a `claimed: false` on every item
     * would be a channel that goes live the moment one of them flips.
     */
    claimed?: boolean
    claimedByMe?: boolean
    /** Present only on a list that shows names, and null on a claim made before
     *  it did — which renders as "spoken for", because nobody consented then. */
    claimedBy?: string | null
    /** Only ever non-null for the person who claimed it. */
    sent?: boolean | null
    /**
     * Present only on a group list, where the items are candidates rather than
     * presents. Absent elsewhere, so the key's presence IS "this can be voted
     * on" — the same discipline as `claimed` and `contributions`.
     */
    votes?: number
    votedByMe?: boolean
}

interface Result {
    id: number
    title: string
    image: string | null
    price: number | null
}

interface Props {
    list: {
        title: string
        description: string | null
        kind: string
        claimable: boolean
        recipient: string | null
        for: string | null
        heading: string
        /** Who sent this. Null for an anonymous owner, who has no name. */
        sharedBy: string | null
    }
    /** Identity: is this my list? NOT "may I see claims" — see `hideClaims`. */
    isOwner: boolean
    /**
     * Mirrored from the claim endpoint, which re-checks it regardless. Not the
     * inverse of `isOwner`: the owner of a gift list about somebody else is a
     * co-giver like anybody else and may claim.
     */
    canClaim: boolean
    /**
     * The same button, for somebody who has not signed in yet.
     *
     * Claiming needs an account — a claim hangs off a hash of the claimer's
     * identity, and a cookie identity cannot be reached from a second device
     * or handed back after clearing the browser. So this draws the same
     * control, which stashes the press and opens the sign-in dialog instead of
     * posting. Not the inverse of `canClaim`: on a group list there is nothing
     * to sign in for. Decided on the server, so the rule lives in one place.
     */
    claimNeedsAccount: boolean
    /** Whether claims are being withheld from this viewer, so the page can say
     *  so rather than looking like it forgot to render something. */
    hideClaims: boolean
    /** Whether a claimer's name will be shown to the others. */
    claimNames: boolean
    /** null for anybody who may not see claims — a count is claim state too. */
    progress: { claimed: number; total: number } | null
    items: Item[]
    /** Lists this viewer may copy an item into. Empty for a visitor with none. */
    copyTargets: CopyTarget[]
    canSuggest: boolean
    /** Whether what a visitor adds lands on the list or in the owner's queue. */
    addsDirectly: boolean
    suggestTerm: string
    /** null before a search is run; `[]` once one found nothing. */
    results: Result[] | null
    /** Mirrored from the pledge endpoint, which re-checks it regardless. */
    canContribute: boolean
    /** Mirrored from the vote endpoint, which re-checks it regardless. */
    canVote: boolean
    /**
     * The pot on a group list — one payload for the whole present.
     *
     * Null on every other kind, where money is pooled per item and rides on
     * `items[].contributions` instead. Two shapes because they are two facts.
     */
    pot: Contributions | null
    /**
     * Null unless this list has an occasion on it — any kind of list may carry
     * one. `address` is non-null only on a registry, and only for somebody who
     * has claimed something: the server decides both, and this page renders
     * what it is given.
     */
    occasion: {
        name: string
        date: string | null
        address: string | null
        locked: boolean
    } | null
    /**
     * The discussion beside the list. Null for anybody who may not see one —
     * on a wish list, that is its owner, because a board is claim state in
     * prose. Its absence is the privacy rule, not a loading state.
     */
    board: BoardState | null
}

export default function SharedList({
    list,
    isOwner,
    canClaim,
    claimNeedsAccount,
    hideClaims,
    claimNames,
    progress,
    items,
    copyTargets,
    canSuggest,
    addsDirectly,
    suggestTerm,
    results,
    canContribute,
    canVote,
    pot,
    occasion,
    board,
}: Props) {
    const page = usePage<SharedProps>()
    const { market } = page.props
    const { t } = useTranslations()
    /*
     * From the page, not from `window`.
     *
     * `window` does not exist while the server renders, so reading it here
     * threw and Inertia fell back to client-side rendering — silently, and on
     * precisely the three pages a stranger opens from a link they were sent:
     * this one, the quiz and the self-describe page. They arrived as an empty
     * shell that had to boot React before showing anything.
     */
    const token = page.url.split('?')[0].split('/').filter(Boolean).pop()
    const base = `/${market.key}`
    const [query, setQuery] = useState(suggestTerm)

    /*
     * The name a claim will carry, when the list shows names.
     *
     * Prefilled from the account, exactly as `Pledge` does and for the same
     * reason: this is a promise made to people, and most people type their own
     * name. Held here rather than per card so that typing it once covers every
     * claim on the page — asking for it again under each item would be the same
     * question ten times.
     */
    const [claimName, setClaimName] = useState(page.props.auth.user?.name ?? '')

    const signIn = useSignIn()

    /*
     * "I'll get this" — one handler, two endings.
     *
     * Signed in, it posts the claim. Signed out, it stashes the press and opens
     * the sign-in dialog over the list, rather than navigating to a login page
     * that would take the list away at the moment somebody was reaching for it.
     *
     * The intent is stashed server-side first and it matters: a magic link goes
     * out by email, so the round trip happens in another tab or another hour,
     * and `PendingClaim` is what finishes the claim when they come back. The
     * dialog shortens the journey; it does not remove it.
     *
     * The button looks identical in both cases on purpose. "Sign in to claim"
     * as a label asks for the account first and the decision second, which is
     * the wrong order — the decision is the thing the person came to make.
     */
    async function claim(itemId: number): Promise<void> {
        if (canClaim) {
            router.post(
                `${base}/l/${token}/claim/${itemId}`,
                claimNames ? { display_name: claimName } : {},
                { preserveScroll: true },
            )

            return
        }

        try {
            await send(`${base}/claim-intent`, 'POST', {
                token,
                item: itemId,
                return_to: window.location.pathname + window.location.search,
            })
        } catch {
            // Losing the intent makes for a worse sign-in, not a broken one:
            // they land back here and press again.
        }

        signIn.open(t('lists.claim_sign_in_hint'))
    }

    /*
     * The shortlist settles on load, and stops moving while you use it.
     *
     * The server orders a group list by its tally, most-backed first, so a
     * shortlist that has been voted on reads as one. That is right for the
     * *arrival*: you open the link and see where the group has landed.
     *
     * It was wrong for the next five seconds. Voting posts and Inertia
     * re-renders in place, so the card you just backed climbed past the ones
     * above it — under your finger, on a two-column grid, while you were still
     * reading them. Backing three things meant the page rearranged itself three
     * times and you lost your place each time. Approval voting invites exactly
     * that: press as many as you like.
     *
     * So the order is captured once and kept. The counts still update live,
     * which is the feedback that matters — you can see your vote land — but
     * nothing moves until the page is loaded again.
     *
     * Re-captured when the *set* of items changes rather than never: an item
     * added or removed while the page is open has to find a place, and a frozen
     * order that did not know about it would drop it silently. A vote changes
     * counts and not membership, so it never triggers this.
     */
    const ids = items.map((item) => item.id).join(',')
    const [order, setOrder] = useState(() => items.map((item) => item.id))
    const [orderedFor, setOrderedFor] = useState(ids)

    if (orderedFor !== ids) {
        setOrder(items.map((item) => item.id))
        setOrderedFor(ids)
    }

    /*
     * Anything the captured order does not know about goes last rather than
     * being dropped — belt and braces for the render between a membership
     * change and the re-capture above.
     */
    const ordered = [...items].sort((a, b) => {
        const left = order.indexOf(a.id)
        const right = order.indexOf(b.id)

        return (left === -1 ? order.length : left) - (right === -1 ? order.length : right)
    })

    /*
     * A registry date is booked a long way out — a wedding eighteen months
     * ahead is ordinary — so the year is shown whenever it is not this one.
     * Adding it unconditionally makes every near date heavier than it needs
     * to be.
     */
    return (
        <>
            {/* A shared gift list must never be indexed: it is a private URL
                that happens to be unauthenticated. */}
            <Head title={list.heading}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            {/*
              Two columns from `lg` up, one below it — header included.

              The header used to sit *above* the grid, on the argument that the
              title and badges are about the whole page and should take the full
              measure. What that actually produced was a right column beginning
              level with the first product: on a group gift, where the header
              carries an intro box and the pot, the conversation started a
              screen down with a tall empty rectangle beside two paragraphs.

              The board is the second thing people come to a group list to do,
              and it was the last thing they could see. So the whole left column
              starts at the top and the rail starts with it. The title is capped
              to that column, which is what a title in a column does.

              The whole list stays in the left column so it is the taller of the
              two whatever the conversation does; a rail longer than the thing it
              is beside is what makes a sticky sidebar run on past the end of the
              page.

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
                <header>
                    {/* Whose list it is, not what they filed it under. */}
                    <h1 className="text-xl font-semibold sm:text-2xl">{list.heading}</h1>

                    {/*
                      What this is and what you are on it, on the one screen
                      that is always opened cold — from a message, by somebody
                      with no context at all.

                      This used to be a kind badge here and a line of prose
                      above the title saying "Bvandoveren shared this list".
                      That is the same fact in two shapes on one screen, which
                      reads as two facts; the pill carries it now, in the order
                      and the colours the other two pages already use.
                    */}
                    <ListPills
                        className="mt-2"
                        kind={list.kind as ListKind}
                        role={isOwner ? 'owner' : 'contributor'}
                        ownerName={list.sharedBy}
                        canAdd={addsDirectly}
                    />
                    {/* One caption line: what this is for, and when. */}
                    {occasion !== null && (
                        <p className="mt-1 text-sm text-ink-soft">
                            {occasion.date
                                ? t('registry.occasion_on', {
                                      occasion: occasion.name,
                                      date: formatOccasionDate(occasion.date, market),
                                  })
                                : occasion.name}
                        </p>
                    )}

                    {list.description && <p className="mt-2 text-ink-soft">{list.description}</p>}

                    {/*
                      "Claims are hidden from you, that is the point."

                      Gated on `hideClaims`, not on `isOwner`: on a gift list the
                      owner IS looking at their own list and DOES see claim state,
                      and on a wish list they may now have asked to. Either way this
                      banner must only appear when something is actually withheld.
                    */}
                    {hideClaims && (
                        <p className="mt-4 rounded-card border border-amber/40 bg-amber/10 p-4 text-sm">
                            {t('lists.owner_view_note')}
                        </p>
                    )}

                    {/*
                      What this page is, and what to do with it — per kind.

                      It used to render one sentence, written for a wish list, and
                      only when the list was claimable. So a group list opened with
                      nothing at all: five product cards and no statement that they
                      are candidates for one present. Three genuinely different jobs
                      arrive through this URL from the same kind of message, and
                      saying the same thing to all three is how somebody acts on the
                      wrong one.

                      The wish-list branch keeps naming the person, or says nothing
                      about a person at all — falling back to the list *title* once
                      told visitors that "Saved items" would not see who claimed
                      what, and an anonymous owner genuinely has no name to give.
                    */}
                    {/*
                      One box, two sentences: what this page is, and what
                      pressing the button will disclose.

                      They were a bordered card and a loose grey line below it,
                      and they are one thought — "several of you are buying from
                      this list, and nobody will see which part was you". Split
                      across two blocks the second read as a footnote to the
                      items rather than as the reassurance that makes somebody
                      press at all.

                      Still above the items, because both have to be read before
                      the first claim. A name shown to other people is a consent
                      decision, and consent given after the press is not consent.
                    */}
                    {(!isOwner || canClaim || claimNeedsAccount) && (
                        <div className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                            {/*
                              The wish-list branch names the person, or says
                              nothing about a person at all — falling back to the
                              list *title* once told visitors that "Saved items"
                              would not see who claimed what, and an anonymous
                              owner genuinely has no name to give.
                            */}
                            {!isOwner && (
                                <p>
                                    {list.kind === 'mine'
                                        ? list.for
                                            ? t('lists.shared_intro', { name: list.for })
                                            : t('lists.shared_intro_anon')
                                        : list.kind === 'group'
                                          ? t('lists.shared_intro_group')
                                          : t('lists.shared_intro_gift')}
                                </p>
                            )}

                            {/* Shown to a signed-out visitor too: what a claim
                                discloses has to be readable before the press,
                                and the press is what asks them to sign in. */}
                            {(canClaim || claimNeedsAccount) && (
                                <p className={isOwner ? 'text-ink-soft' : 'mt-2 text-ink-soft'}>
                                    {claimNames
                                        ? t('lists.claim_named_note')
                                        : t('lists.claim_anonymous_note')}
                                </p>
                            )}
                        </div>
                    )}

                    {canClaim && claimNames && (
                        <label className="mt-3 block text-xs font-medium">
                            {t('pledges.your_name')}
                            <input
                                required
                                maxLength={80}
                                value={claimName}
                                onChange={(e) => setClaimName(e.target.value)}
                                className="mt-1 w-full max-w-xs rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                            />
                        </label>
                    )}

                    {/*
                      The pot, above the shortlist rather than under a card.

                      A group list is one present and the items are candidates, so
                      "€75 in, three people" is a fact about the page and not about
                      any row on it. Under a card it would read as money against
                      that candidate — which is exactly the bet this change stopped
                      asking people to make.
                    */}
                    {pot !== null && (
                        <div className="mt-4 rounded-card border border-line bg-card p-4">
                            <Pledge
                                action={`${base}/l/${token}/pledge`}
                                contributions={pot}
                                canContribute={canContribute}
                                /*
                                  No price to measure against until the group has
                                  chosen. Whichever candidate leads today is not a
                                  target — it moves every time somebody votes.
                                */
                                price={null}
                            />
                        </div>
                    )}

                    {/*
                      The "3 of 11 spoken for" strip used to sit here and is gone.
                      Every row already says whether it is taken, so the count was
                      the same information a second time, in a weaker form — and
                      on a one-item list it read "0 of 1 spoken for", which is a
                      sentence nobody needed.

                      `progress` is still computed and still withheld from anyone
                      who may not see claims; see ClaimView and the tests that
                      pin it. Nothing renders it.
                    */}

                    {/*
                      The occasion, and where to send it.

                      The date rides up next to the title as a single line — it is
                      a caption for the page, not a section of it, and on a phone a
                      bordered card for four words was a whole block of the first
                      screen spent on "Birthday · 14 June".

                      The address keeps its card, because it is the one thing here
                      somebody has to read carefully and copy.
                    */}
                    {occasion !== null && (occasion.address !== null || occasion.locked) && (
                        <section className="mt-4 rounded-card border border-line bg-card p-4">
                            {occasion.address !== null && (
                                <>
                                    <p className="text-xs font-medium text-ink-soft">{t('registry.send_to')}</p>
                                    {/* An address, not a link. `pre-line` keeps the
                                        owner's line breaks and gives them nothing
                                        else. */}
                                    <address className="mt-1 text-sm whitespace-pre-line not-italic">
                                        {occasion.address}
                                    </address>
                                </>
                            )}

                            {occasion.locked && (
                                <p className="text-xs text-ink-soft">{t('registry.address_locked')}</p>
                            )}
                        </section>
                    )}
                </header>

                    {/*
                      No heading over the grid.

                      There was one — "Vote on what we should buy" — added
                      because a group list is a SHORTLIST and five product cards
                      read as five presents being bought. That risk is real and
                      it is already answered twice over on this page: the intro
                      box says "you are buying one present together" in the
                      first paragraph, and every card carries a vote button with
                      a tally under it. A third statement of the same thing, in
                      a grey line between them, was the one nobody needed.
                    */}
                    <ul className="mt-6 grid gap-4 sm:grid-cols-2">
                        {ordered.map((item) => (
                            <ListItemCard
                                key={item.id}
                                title={item.title}
                                image={item.image}
                                url={item.url}
                                externalUrl={item.externalUrl}
                                note={item.note}
                                price={item.price}
                                market={market}
                                // Spoken for by somebody else: the card steps back
                                // without disappearing.
                                muted={Boolean(item.claimed && !item.claimedByMe)}
                                aside={
                                    /*
                                      Keep it for myself.

                                      Somebody looking at a friend's list is looking
                                      at a page full of things chosen for a person
                                      they also know, and had no way to note one down
                                      for later. It reads *my* lists and writes to
                                      *my* list; the owner's list is untouched and
                                      learns nothing, so this is not a claim and
                                      invariant #4 is not involved.

                                      **The save picker whenever there is a product**,
                                      which is the same control this site uses on
                                      every product card and on the owner's own list.
                                      One bookmark, one menu, one habit.

                                      `CopyToList` only for a **hand-written** item,
                                      because there is no `group_id` to save: somebody
                                      typed a title and maybe a link, so the row
                                      itself has to be copied. It draws the same
                                      bookmark and the same menu, and the endpoint
                                      underneath is the only difference.

                                      Hidden from the owner entirely: on their own
                                      list everything here is already theirs.
                                    */
                                    isOwner ? undefined : item.groupId !== null ? (
                                        <SaveToList groupId={item.groupId} compact />
                                    ) : (
                                        <CopyToList
                                            action={`${base}/l/${token}/items/${item.id}/copy`}
                                            targets={copyTargets}
                                            groupId={null}
                                        />
                                    )
                                }
                            >
                                {/*
                                  Back this one, on a group gift.

                                  Everything about voting existed except this: the
                                  endpoint, the tally in the payload, the setting in
                                  the panel, the ordering by most-backed, and a
                                  heading above the grid announcing a vote — with no
                                  button under any card. The page said "choose
                                  together" and offered no way to choose. The
                                  component was even imported here and never used.

                                  Driven by the payload exactly as `claimed` is
                                  below: `votes` is absent unless this is a group
                                  list with voting switched on, so the key's presence
                                  IS the permission and there is no second copy of
                                  that question here to drift from the server's.

                                  `Vote` renders the tally on its own for somebody
                                  who cannot vote, which is why it is not gated on
                                  `canVote` here — a member without an identity yet
                                  should still see where the group has landed.
                                */}
                                {item.votes !== undefined && (
                                    <Vote
                                        action={`${base}/l/${token}/vote/${item.id}`}
                                        votes={item.votes}
                                        votedByMe={item.votedByMe ?? false}
                                        canVote={canVote}
                                    />
                                )}

                                {/*
                                  Driven by the payload, not by who is looking.

                                  `claimed` is absent for anybody who may not see claim
                                  state, so its presence IS the permission — one rule,
                                  decided on the server by `ClaimView`, rather than a
                                  second copy of the question here that could drift from
                                  it. It used to read `!isOwner`, which is now wrong in
                                  both directions: the owner of a gift list may claim,
                                  and a visitor to a `group` list may not.
                                */}
                                {item.claimed !== undefined && (
                                    <div className="mt-4">
                                        {item.claimedByMe ? (
                                            /*
                                              Claiming was a dead end: you said you would
                                              get it and then had nowhere to say you had.
                                              The endpoint and the `sent` flag both
                                              existed; only the button was missing, so
                                              the strip above could never finish.
                                            */
                                            item.sent ? (
                                                <p className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-center text-sm font-medium text-sage">
                                                    {t('lists.sent')}
                                                </p>
                                            ) : (
                                                <div className="flex flex-col gap-2">
                                                    {/*
                                                      What you did, before what you
                                                      could do next.

                                                      The strip used to lead with "I
                                                      have bought it" — the *next*
                                                      action, dressed as the state,
                                                      since it was the only thing in the
                                                      claimed strip wearing the sage
                                                      box. So the page said "bought"
                                                      about something merely spoken for,
                                                      and the fact that the tap had
                                                      worked lived in a banner at the
                                                      top of the document instead.
                                                      Now the box states the promise, in
                                                      the first person it was made in,
                                                      and the two follow-ups sit under
                                                      it as the small print they are.
                                                    */}
                                                    <p className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-center text-sm font-medium text-sage">
                                                        {t('lists.claimed')}
                                                    </p>
                                                    <div className="flex items-center justify-center gap-4 text-xs text-ink-soft">
                                                        <button
                                                            onClick={() =>
                                                                router.post(
                                                                    `${base}/l/${token}/sent/${item.id}`,
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                            className="underline hover:text-ink"
                                                        >
                                                            {t('lists.mark_sent')}
                                                        </button>
                                                        <button
                                                            onClick={() =>
                                                                router.delete(`${base}/l/${token}/claim/${item.id}`, {
                                                                    preserveScroll: true,
                                                                })
                                                            }
                                                            className="underline hover:text-ink"
                                                        >
                                                            {t('lists.unclaim')}
                                                        </button>
                                                    </div>
                                                </div>
                                            )
                                        ) : item.claimed ? (
                                            <p className="w-full rounded-lg border border-line px-4 py-2 text-center text-sm text-ink-soft">
                                                {/*
                                                  Who has it, when the list shows names.
                                                  `claimedBy` is null on a claim made
                                                  before the setting was turned on —
                                                  that one stays "spoken for", because
                                                  nobody agreed to be named then.
                                                */}
                                                {item.claimedBy
                                                    ? t('lists.claimed_by', { name: item.claimedBy })
                                                    : t('lists.claimed_by_someone')}
                                            </p>
                                        ) : canClaim || claimNeedsAccount ? (
                                            <button
                                                onClick={() => void claim(item.id)}
                                                className="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                            >
                                                {t('lists.claim')}
                                            </button>
                                        ) : null}
                                    </div>
                                )}

                            </ListItemCard>
                        ))}
                    </ul>

                    {/*
                      "I think you would like this."

                      The other half of a feature that shipped with only one: the
                      endpoint, its guards and the owner's accept/dismiss row all
                      existed, and nothing on any page could send one — so the copy
                      below ("Suggest something") sat in four language files, rendered
                      nowhere, for as long as the feature has been live. Its tests
                      passed throughout, because they POST to the endpoint directly.

                      Below the list, never above it. Somebody arrived to see what this
                      person wants; putting a search box first answers a question they
                      have not asked yet, and the empty list is exactly the case where
                      they scroll far enough to reach this anyway.
                    */}
                    {canSuggest && (
                        <section className="mt-8 rounded-card border border-line bg-card p-5 sm:mt-12 sm:p-6">
                            {/*
                              The verb depends on where the item lands.

                              "Suggest something" and "Add to this list" are different
                              promises, and getting it the wrong way round either
                              surprises an owner who thought they would be asked, or
                              makes a helper on a gift list think nothing happened.
                            */}
                            <h2 className="font-medium">
                                {addsDirectly ? t('suggestions.add_invite') : t('suggestions.invite')}
                            </h2>
                            <p className="mt-1 text-sm text-ink-soft">
                                {addsDirectly ? t('suggestions.add_invite_hint') : t('suggestions.invite_hint')}
                            </p>

                            <form
                                className="mt-4 flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault()

                                    /*
                                      A GET back to this same URL, which re-renders the
                                      page with `results`. One route, one token check —
                                      a second endpoint would be a second place the
                                      share token has to be resolved and gated.
                                    */
                                    router.get(
                                        `${base}/l/${token}`,
                                        { q: query },
                                        { preserveState: true, preserveScroll: true },
                                    )
                                }}
                            >
                                <input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder={t('suggestions.search_placeholder')}
                                    aria-label={t('suggestions.search_placeholder')}
                                    className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                />
                                {/*
                                  Somebody suggesting a present is often holding it, or
                                  looking at it in a shop. The same GET as the submit
                                  button, with the barcode as the query — not a visit to
                                  /search, which would leave the list behind.
                                */}
                                <ScanButton
                                    className="shrink-0 rounded-lg border border-line px-3 py-2"
                                    onScan={(gtin) => {
                                        setQuery(gtin)
                                        router.get(
                                            `${base}/l/${token}`,
                                            { q: gtin },
                                            { preserveState: true, preserveScroll: true },
                                        )
                                    }}
                                />
                                <button type="submit" className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink">
                                    {t('search.submit')}
                                </button>
                            </form>

                            {results !== null && results.length === 0 && (
                                <p className="mt-4 text-sm text-ink-soft">{t('suggestions.none_found')}</p>
                            )}

                            {/*
                              The thing somebody most wants to put forward is often the
                              thing we do not sell — a voucher, the local bike shop, one
                              particular edition of a book. Ending the search with "no
                              results" wastes the one moment they were willing to help.
                            */}
                            <div className="mt-4">
                                <ManualItem
                                    action={`${base}/l/${token}/suggest`}
                                    hint={t('suggestions.manual_hint')}
                                />
                            </div>

                            {results !== null && results.length > 0 && (
                                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    {results.map((result) => (
                                        <li key={result.id} className="flex flex-col rounded-card border border-line p-4">
                                            {result.image && (
                                                <img
                                                    src={result.image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="mx-auto h-28 w-auto max-w-full object-contain"
                                                    onError={(e) => { e.currentTarget.style.visibility = 'hidden' }}
                                                />
                                            )}
                                            <p className="mt-3 line-clamp-2 text-sm font-medium">{result.title}</p>
                                            {result.price !== null && (
                                                <p className="mt-1 text-sm text-ink-soft">
                                                    {formatPrice(result.price, market)}
                                                </p>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/l/${token}/suggest`,
                                                        { group_id: result.id },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="mt-3 w-full rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-white hover:bg-accent-dark"
                                            >
                                                {addsDirectly ? t('suggestions.add_action') : t('suggestions.suggest')}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>

                {board !== null && (
                    <aside className="mt-10 lg:sticky lg:top-6 lg:mt-0">
                        <ListBoard board={board} action={`${base}/l/${token}/messages`} />
                    </aside>
                )}
            </div>
        </>
    )
}
