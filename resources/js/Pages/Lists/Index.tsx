import { Head, Link, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { type ListKind } from '../../Components/ListKindBadge'
import ListPills from '../../Components/ListPills'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import ListWizard, { hasListDraft, type WizardOffer } from '../../Components/ListWizard'

interface ListSummary {
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
     *
     * My Lists shows both now, so the card has to carry the difference: what I
     * may do with the two is not the same, and a list I merely have access to
     * can be changed out from under me by the person who owns it.
     */
    sharedWithMe: boolean
    /** May somebody who is not the owner put things on it? From `summarise()`. */
    linkCanAdd: boolean
    /** Who owns it. Null on my own rows, where the answer is me. */
    ownerName: string | null
    /** `viewer` or `editor`, on a list shared with me. */
    role: string | null
}

type ListsView = 'mine' | 'shared' | 'group'

/**
 * The page's own props, plus everything the list wizard needs — people,
 * friends, occasions — in the shape the Gift Cove already sends it.
 */
interface Props extends WizardOffer {
    lists: ListSummary[]
    view: ListsView
    isSignedIn: boolean
}

/**
 * One card per list, in two groups.
 *
 * Every list rendered the same way — a title and an item count — even though
 * `kind` was already in the payload. So a wishlist for yourself and private
 * research about your sister were indistinguishable, and the only way to tell
 * them apart was to open them. The save picker has always sorted them into "for
 * me" and "for someone else"; this page now uses the same two words, so the
 * place you save to and the place you look for it agree.
 */
function ListCard({ list }: { list: ListSummary }) {
    const { t, n } = useTranslations()
    const shared = list.visibility !== 'private'

    /*
     * "Shared" means two different things on this page and they must not be
     * confused: `shared` above is *I have published this outward*, and
     * `sharedWithMe` is *this is not mine at all*. Same word, opposite
     * direction, which is why the second one gets a badge naming the owner
     * rather than a second grey pill.
     */
    const theirs = list.sharedWithMe

    return (
        <Link
            href={list.url}
            className="flex h-full flex-col rounded-card border border-line bg-card transition hover:border-ink/30"
        >
            {/*
              A strip of what is in it. An empty list gets a placeholder rather
              than a collapsed card, so the grid keeps its rhythm and an empty
              list still reads as a list.
            */}
            <div className="flex gap-1 overflow-hidden rounded-t-card border-b border-line bg-cream p-2">
                {list.covers.length === 0 ? (
                    <span className="flex h-16 w-full items-center justify-center text-xs text-ink-soft">
                        {t('lists.empty_list')}
                    </span>
                ) : (
                    list.covers.map((src, i) => (
                        <img
                            key={i}
                            src={src}
                            alt=""
                            loading="lazy"
                            className="h-16 min-w-0 flex-1 object-contain"
                            onError={(e) => {
                                e.currentTarget.style.visibility = 'hidden'
                            }}
                        />
                    ))
                )}
            </div>

            <div className="flex flex-1 flex-col p-4">
                <h3 className="font-medium">{list.title}</h3>

                <p className="mt-1 text-sm text-ink-soft">
                    {list.itemCount === 1
                        ? t('lists.one_item')
                        : t('lists.items', { count: n(list.itemCount) })}
                    {/*
                      Who the list is for. On a list about somebody, the
                      recipient; on a wish list somebody shared with me, its
                      owner, because that is the person I shop for. Never on
                      a wish list of your own: there the recipient is you,
                      and a handed-over list keeps your name as its record.
                    */}
                    {list.kind !== 'mine' && list.recipient && ` · ${list.recipient.name}`}
                    {theirs && list.kind === 'mine' && list.ownerName && ` · ${list.ownerName}`}
                </p>

                {/*
                  What this card is FOR, on somebody else's wish list.

                  A list Anna shared with me is, from where I stand, how I shop
                  for Anna — and that is the commonest gifting act on the site.
                  The card said "11 items" and nothing else, so the one row that
                  answers "what do I get her?" read exactly like a row of my own
                  filing.

                  Only on a `mine` list of theirs: those are the ones with
                  something to claim. A `for_someone` or `group` list I was
                  invited to is co-giver coordination, and its own kind sentence
                  covers it.
                */}
                {theirs && list.kind === 'mine' && list.ownerName && (
                    <p className="mt-1 text-sm text-accent">
                        {t('lists.shop_for', { name: list.ownerName })}
                    </p>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-1.5 text-2xs">
                    {/*
                      What kind of list this is.

                      The kind lived only in the section heading, so a card read
                      out of context — which is how a card is read, and the only
                      way one is read in the Shared and Group views, where there
                      are no sections — said nothing about what could be done
                      with it.
                    */}
                    {/*
                      Kind, whose it is, and what you may do — one component,
                      the same order and the same colours as the list page and
                      the shared page. These three pills were built here and
                      copied outward by hand, which is how the same list came to
                      describe itself differently depending on which page you
                      reached it from.
                    */}
                    <ListPills
                        kind={list.kind as ListKind}
                        role={theirs ? 'contributor' : 'owner'}
                        ownerName={theirs ? list.ownerName : null}
                        canAdd={list.visibility !== 'private' && list.linkCanAdd}
                    />
                    {list.isDefault && (
                        <span className="rounded-full bg-line/60 px-2 py-0.5">{t('lists.default_badge')}</span>
                    )}
                    {/*
                      Shared or not is the fact people most need off this page —
                      it is the difference between a private note and something
                      anyone with the link can read.
                    */}
                    {!theirs && (
                        <span
                            className={
                                shared
                                    ? 'rounded-full bg-sage/15 px-2 py-0.5 text-sage'
                                    : 'rounded-full bg-line/60 px-2 py-0.5 text-ink-soft'
                            }
                        >
                            {shared ? t('lists.shared_short') : t('lists.private_short')}
                        </span>
                    )}

                    {/*
                      Somebody put something forward and it is waiting on you.

                      This is the badge the Gift Cove's suggestions card was
                      always pointing at: it sends you here so you can see which
                      list received one, and until now the index said nothing
                      about them at all.
                    */}
                    {list.suggestions !== null && list.suggestions > 0 && (
                        <span className="rounded-full bg-accent/15 px-2 py-0.5 font-medium text-accent">
                            {list.suggestions === 1
                                ? t('suggestions.one_waiting')
                                : t('suggestions.waiting', { count: n(list.suggestions) })}
                        </span>
                    )}
                </div>
            </div>
        </Link>
    )
}

export default function ListsIndex({ lists, view, recipients, friends, occasions, isSignedIn }: Props) {
    const page = usePage<SharedProps>()
    const { market } = page.props
    const { t } = useTranslations()

    /*
     * The Gift Cove describes nine tools and six of its cards used to land here,
     * on an index, leaving the reader to work out which button started the thing
     * they had just read about. `?new=for_someone` opens the wizard with that
     * question answered instead.
     */
    const intent = new URLSearchParams(page.url.split('?')[1] ?? '').get('new')
    const initialKind = intent === 'mine' || intent === 'for_someone' || intent === 'group' ? intent : undefined
    const [creating, setCreating] = useState(intent !== null)

    /*
     * A draft the wizard remembered across a sign-in is finished here too.
     * The magic link lands wherever it lands; if that is this page, the
     * wizard has to be open for the draft to be replayed.
     */
    useEffect(() => {
        if (isSignedIn && hasListDraft()) {
            setCreating(true)
        }
    }, [isSignedIn])

    /*
     * Three views, and only one of them splits.
     *
     * My Lists is now every list this person may open — mine of all three
     * kinds, and the ones other people have let me into — so the sections carry
     * the whole taxonomy rather than a two-way split. They are not decoration:
     * a wish list exists to be seen, a list about somebody is research they
     * must never see, a group list is money and a third person, and a list
     * shared with me belongs to somebody who can change it. Same table, four
     * different sets of rules.
     *
     * Shared and Group as their own views are already one thing each, and
     * splitting those would invent a distinction the rows do not have.
     *
     * Empty sections are dropped rather than shown empty: a heading over
     * nothing reads as a thing that failed to load.
     */
    const mineOnly = lists.filter((l) => !l.sharedWithMe)

    const groups =
        view === 'mine'
            ? [
                  {
                      key: 'mine',
                      label: t('lists.for_me'),
                      lists: mineOnly.filter((l) => l.kind === 'mine'),
                  },
                  {
                      key: 'others',
                      label: t('lists.for_someone_else'),
                      lists: mineOnly.filter((l) => l.kind === 'for_someone'),
                  },
                  {
                      key: 'group',
                      label: t('lists.for_group'),
                      lists: mineOnly.filter((l) => l.kind === 'group'),
                  },
                  {
                      key: 'shared',
                      label: t('lists.shared_with_me'),
                      lists: lists.filter((l) => l.sharedWithMe),
                  },
              ].filter((g) => g.lists.length > 0)
            : [{ key: view, label: '', lists }]

    // Each view names itself and its own empty state. "You have no lists" and
    // "nobody has shared a list with you" are different facts, and one sentence
    // for three questions tells the reader nothing about which they asked.
    const heading = {
        mine: t('lists.title'),
        shared: t('nav.shared_lists'),
        group: t('nav.group_lists'),
    }[view]

    // "My lists" carries no subtitle: the groups below it are already labelled
    // ("For me", "Shared with me"), so a sentence restating that was saying
    // nothing the page did not show. The other two views are reached from the
    // nav without that context and still explain themselves.
    const subtitle = {
        mine: null,
        shared: t('lists.shared_subtitle'),
        group: t('lists.group_subtitle'),
    }[view]

    return (
        <>
            <Head title={heading} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-xl sm:text-2xl font-semibold">{heading}</h1>
                    {subtitle && <p className="mt-1 text-ink-soft">{subtitle}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                    {/*
                      "New list" is the only action in this header, deliberately.

                      A "find things to add" button stood beside it, on the
                      reasoning that nothing goes into a list from this page —
                      every save starts at a product — so somebody arriving here
                      needed a way onward. It was removed on 2026-09-06: the
                      empty state already offers exactly that link, at the moment
                      it is the only thing to do, and the site header carries
                      search on every page. Two buttons for one intention made
                      the header compete with the page under it.
                    */}
                    {/*
                      One button for everybody. The wizard behind it is the
                      same one the Gift Cove opens with: it walks a signed-out
                      visitor through the four questions as the explanation,
                      and its last button is the sign-in, which remembers the
                      answers and replays them on return. Before 2026-09-07 this
                      opened a one-screen form that asked the same things with
                      none of the explanation, and a second copy of the picker
                      that had already been fixed once elsewhere.
                    */}
                    <button
                        onClick={() => setCreating((v) => !v)}
                        aria-expanded={creating}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink"
                    >
                        {t('lists.new_list')}
                    </button>
                </div>
            </header>

            {/*
              Lists work before signup, so this is a nudge rather than a wall.
              Shown only when there is something to lose.
            */}
            {!isSignedIn && lists.length > 0 && (
                <div className="mt-6 rounded-card border border-amber/40 bg-amber/10 p-4">
                    <p className="font-medium">{t('lists.sign_in_to_keep')}</p>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.sign_in_hint')}</p>
                    <SignInLink
                        hint={t('lists.sign_in_hint')}
                        className="mt-2 inline-block text-sm text-accent underline"
                    >
                        {t('nav.sign_in')}
                    </SignInLink>
                </div>
            )}

            {creating && (
                <div className="mt-6">
                    <ListWizard
                        signedIn={isSignedIn}
                        recipients={recipients}
                        friends={friends}
                        occasions={occasions}
                        initialKind={initialKind}
                        onCancel={() => setCreating(false)}
                    />
                </div>
            )}

            {lists.length === 0 ? (
                <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                    {/*
                      Shared Lists says something different when it is empty,
                      and the difference is not cosmetic: "You have no lists yet"
                      is *wrong* here — you may have a dozen — and the button
                      under it sends somebody off to build a fourteenth when what
                      they came to do was find a list somebody sent them. The
                      page already draws this distinction for its heading and its
                      subtitle; the empty state was the one place it did not.
                    */}
                    {view === 'shared' ? (
                        <p className="font-medium">{t('lists.shared_empty')}</p>
                    ) : !isSignedIn ? (
                        <>
                            {/*
                              Keeping anything needs an account now, so "find
                              things to add" led to a bookmark that opened the
                              sign-in dialog anyway — a loop that named its
                              precondition at the last step. Say it here.
                            */}
                            <p className="font-medium">{t('lists.sign_in_to_keep')}</p>
                            <p className="mt-1 text-sm text-ink-soft">{t('lists.sign_in_hint')}</p>
                            <SignInLink
                                hint={t('lists.sign_in_hint')}
                                className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                            >
                                {t('nav.sign_in')}
                            </SignInLink>
                        </>
                    ) : (
                        <>
                            <p className="font-medium">{t('lists.empty')}</p>
                            <p className="mt-1 text-sm text-ink-soft">{t('lists.empty_hint')}</p>
                            <Link
                                href={`/${market.key}/search`}
                                className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                            >
                                {t('lists.find_things')}
                            </Link>
                        </>
                    )}
                </div>
            ) : (
                groups.map((group) => (
                    <section key={group.key} className="mt-8">
                        {/* The heading only earns its place when both groups
                            exist; with one group it is a label for the obvious. */}
                        {groups.length > 1 && (
                            <h2 className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                                {group.label}
                            </h2>
                        )}
                        <ul className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {group.lists.map((list) => (
                                <li key={list.id}>
                                    <ListCard list={list} />
                                </li>
                            ))}
                        </ul>
                    </section>
                ))
            )}
        
            {/*
              Under the lists rather than over them.

              Somebody who already has lists does not need to be told how they
              work, and putting the explanation above their own content makes
              the page about the instructions. Somebody who has none reaches it
              in a couple of lines because the empty state is short.
            */}
            <p className="mt-12 border-t border-line pt-6 text-sm text-ink-soft">
                <Link href={`/${market.key}/lists-help`} className="underline hover:text-ink">
                    {t('lists_help.link')}
                </Link>
            </p>
        </>
    )
}
