import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ListKindBadge, { type ListKind } from '../../Components/ListKindBadge'
import ManualItem from '../../Components/ManualItem'
import SaveToList from '../../Components/SaveToList'
import Pledge, { type Contributions } from '../../Components/Pledge'
import Vote from '../../Components/Vote'
import type { SharedProps } from '../../types'
import { formatOccasionDate, formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

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
     * Absent — not null — wherever there is nothing this viewer may know about
     * the money. The owner of a wish list never receives the key at all.
     */
    contributions?: Contributions
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
    }
    /** Identity: is this my list? NOT "may I see claims" — see `hideClaims`. */
    isOwner: boolean
    /**
     * Mirrored from the claim endpoint, which re-checks it regardless. Not the
     * inverse of `isOwner`: the owner of a gift list about somebody else is a
     * co-giver like anybody else and may claim.
     */
    canClaim: boolean
    /** Whether claims are being withheld from this viewer, so the page can say
     *  so rather than looking like it forgot to render something. */
    hideClaims: boolean
    /** Whether a claimer's name will be shown to the others. */
    claimNames: boolean
    /** null for anybody who may not see claims — a count is claim state too. */
    progress: { claimed: number; total: number } | null
    items: Item[]
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
}

export default function SharedList({
    list,
    isOwner,
    canClaim,
    hideClaims,
    claimNames,
    progress,
    items,
    canSuggest,
    addsDirectly,
    suggestTerm,
    results,
    canContribute,
    canVote,
    pot,
    occasion,
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

            <header>
                {/* Whose list it is, not what they filed it under. */}
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-2xl font-semibold">{list.heading}</h1>
                    {/*
                      What kind of page this is, on the one screen that is
                      always opened cold — from a message, by somebody with no
                      context at all. The three kinds want three different
                      things from that person, and until now the page asked all
                      three the same way.
                    */}
                    <ListKindBadge kind={list.kind as ListKind} />
                </div>
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
                {!isOwner && (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        {list.kind === 'mine'
                            ? list.for
                                ? t('lists.shared_intro', { name: list.for })
                                : t('lists.shared_intro_anon')
                            : list.kind === 'group'
                              ? t('lists.shared_intro_group')
                              : t('lists.shared_intro_gift')}
                    </p>
                )}


                {/*
                  "3 of 11 claimed". The server has sent this since the strip
                  was specced and the page never drew it, so a visitor arriving
                  late had no way to tell a list that was mostly spoken for from
                  one nobody had touched — which is the difference between
                  choosing carefully and choosing quickly.

                  Null for the owner, never zero: the moment a zero stops being
                  zero they have learnt something.
                */}
                {/*
                  What a claim will disclose, said BEFORE the press.

                  A name shown to other people is a consent decision, and
                  consent given inside a settings panel that somebody else
                  opened is not consent. This is the one place on the page a
                  claimer can learn what pressing the button reveals, so it sits
                  above the items rather than under any one of them — and it is
                  shown in the anonymous case too, because "nobody will see it
                  was you" is the reassurance that makes people press at all.
                */}
                {canClaim && (
                    <div className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        <p className="text-ink-soft">
                            {claimNames ? t('lists.claim_named_note') : t('lists.claim_anonymous_note')}
                        </p>

                        {claimNames && (
                            <label className="mt-2 block text-xs font-medium">
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
                    </div>
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

                {progress !== null && progress.total > 0 && (
                    <p className="mt-4 text-sm text-ink-soft">
                        {t(
                            list.kind === 'mine' ? 'lists.progress' : 'lists.progress_gift',
                            {
                                claimed: String(progress.claimed),
                                total: String(progress.total),
                            },
                        )}
                    </p>
                )}

                {/*
                  The occasion block.

                  The occasion and the date are why the list exists and are
                  shown to everybody holding the link, on a list of any kind —
                  "Wedding, 14 June" on my own, "Dad's birthday" on one about
                  him. The address is not, and it is registry-only besides: it
                  appears once you have claimed something, which is the promise
                  `registry.address_hint` has made to the owner since the
                  feature shipped and which nothing implemented until now.

                  The locked state says the address is there rather than saying
                  nothing — otherwise somebody who has claimed nothing concludes
                  the owner forgot to add one.
                */}
                {occasion !== null && (
                    <section className="mt-4 rounded-card border border-line bg-card p-4">
                        <p className="text-sm font-medium">
                            {occasion.date
                                ? t('registry.occasion_on', {
                                      occasion: occasion.name,
                                      date: formatOccasionDate(occasion.date, market),
                                  })
                                : occasion.name}
                        </p>

                        {occasion.address !== null && (
                            <div className="mt-3">
                                <p className="text-xs font-medium text-ink-soft">{t('registry.send_to')}</p>
                                {/* An address, not a link. `pre-line` keeps the
                                    owner's line breaks and gives them nothing
                                    else. */}
                                <address className="mt-1 text-sm whitespace-pre-line not-italic">
                                    {occasion.address}
                                </address>
                            </div>
                        )}

                        {occasion.locked && (
                            <p className="mt-3 text-xs text-ink-soft">{t('registry.address_locked')}</p>
                        )}
                    </section>
                )}
            </header>

            {/*
              A group list is a SHORTLIST, and it has to read as one.

              This is the biggest misreading risk on the page: five product
              cards, and a visitor concludes five presents are being bought. One
              line above the grid, plus the tally ordering the cards, is what
              turns a pile into a set of candidates for one present.
            */}
            {canVote && items.length > 0 && (
                <h2 className="mt-8 text-sm font-medium text-ink-soft">{t('votes.heading')}</h2>
            )}

            <ul className="mt-3 grid gap-4 sm:grid-cols-2">
                {items.map((item) => (
                    <li
                        key={item.id}
                        /*
                          Opacity was the only signal that an item was taken.
                          There is a text label beside it, so it was not broken
                          — but a 40% fade is doing the work of a state, and it
                          is the first thing lost to a bright screen outdoors or
                          to anyone who does not perceive the difference. The
                          border carries it now; the fade reinforces.
                        */
                        className={`flex flex-col rounded-card border bg-card p-4 ${
                            item.claimed && !item.claimedByMe
                                ? 'border-dashed border-ink-soft/40 opacity-70'
                                : 'border-line'
                        }`}
                    >
                        <div className="flex gap-4">
                            {item.image && (
                                <img
                                    src={item.image}
                                    alt=""
                                    className="h-20 w-20 rounded object-contain"
                                    onError={(e) => { e.currentTarget.style.visibility = 'hidden' }}
                                />
                            )}

                            <div className="min-w-0 flex-1">
                                {item.url ? (
                                    <Link href={item.url} className="font-medium hover:underline">
                                        {item.title}
                                    </Link>
                                ) : item.externalUrl ? (
                                    /*
                                      Somebody else's link, on a page strangers
                                      open. `noopener` so the destination cannot
                                      reach back through `window.opener`,
                                      `noreferrer` so it is not told which list
                                      sent the visitor, and `nofollow` because
                                      we are not vouching for it. The scheme was
                                      settled server-side; this is the rest.
                                    */
                                    <a
                                        href={item.externalUrl}
                                        target="_blank"
                                        rel="nofollow noopener noreferrer"
                                        className="font-medium hover:underline"
                                    >
                                        {item.title}
                                    </a>
                                ) : (
                                    <span className="font-medium">{item.title}</span>
                                )}
                                {item.note && <p className="mt-1 text-sm text-ink-soft">{item.note}</p>}
                                {item.price !== null && (
                                    <p className="mt-1 font-semibold">{formatPrice(item.price, market)}</p>
                                )}
                            </div>

                            {/*
                              Keep it for myself.
                              
                              Somebody looking at a friend's list is looking at
                              a page full of things chosen for a person they
                              also know, and had no way to note one down for
                              later. It reads *my* lists and writes to *my*
                              list; the owner's list is untouched and learns
                              nothing, so this is not a claim and invariant #4
                              is not involved.
                              
                              Hidden from the owner for a different reason: on
                              their own list everything here is already theirs,
                              so the control would do nothing but confuse.
                            */}
                            {!isOwner && item.groupId !== null && (
                                <div className="shrink-0 self-start">
                                    <SaveToList groupId={item.groupId} compact />
                                </div>
                            )}
                        </div>

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
                                            <button
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/l/${token}/sent/${item.id}`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-sm font-medium text-sage"
                                            >
                                                {t('lists.mark_sent')}
                                            </button>
                                            <button
                                                onClick={() =>
                                                    router.delete(`${base}/l/${token}/claim/${item.id}`, {
                                                        preserveScroll: true,
                                                    })
                                                }
                                                className="text-xs text-ink-soft underline hover:text-ink"
                                            >
                                                {t('lists.unclaim')}
                                            </button>
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
                                ) : canClaim ? (
                                    <button
                                        onClick={() =>
                                            router.post(
                                                `${base}/l/${token}/claim/${item.id}`,
                                                claimNames ? { display_name: claimName } : {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('lists.claim')}
                                    </button>
                                ) : null}
                            </div>
                        )}

                        {/*
                          Money, beside the claim controls rather than behind a
                          panel: it is a fact about this present, and the person
                          reading it is deciding about this present. Rendered
                          only when the server sent a payload — its absence is
                          the privacy rule doing its job, so there is deliberately
                          no fallback branch here.
                        */}
                        {/*
                          The candidate's primary action, and its only one.

                          Money on a group list is pooled in the header, not
                          here: pledging against a candidate the group has not
                          chosen asks people to bet, and most of those bets end
                          up attached to something nobody buys.
                        */}
                        {item.votes !== undefined && (
                            <Vote
                                action={`${base}/l/${token}/vote/${item.id}`}
                                votes={item.votes}
                                votedByMe={item.votedByMe ?? false}
                                canVote={canVote}
                            />
                        )}

                        {item.contributions !== undefined && (
                            <Pledge
                                action={`${base}/l/${token}/pledge/${item.id}`}
                                contributions={item.contributions}
                                canContribute={canContribute}
                                price={item.price}
                            />
                        )}
                    </li>
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
                <section className="mt-12 rounded-card border border-line bg-card p-6">
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
        </>
    )
}
