import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ListKindBadge, { type ListKind } from '../../Components/ListKindBadge'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'

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
    /** Who owns it. Null on my own rows, where the answer is me. */
    ownerName: string | null
    /** `viewer` or `editor`, on a list shared with me. */
    role: string | null
}

type ListsView = 'mine' | 'shared' | 'group'

interface Props {
    lists: ListSummary[]
    view: ListsView
    recipients: { id: string; name: string; relationship: string | null }[]
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
                    {list.recipient && ` · ${list.recipient.name}`}
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

                <div className="mt-3 flex flex-wrap items-center gap-1.5 text-[11px]">
                    {/*
                      What kind of list this is.

                      The kind lived only in the section heading, so a card read
                      out of context — which is how a card is read, and the only
                      way one is read in the Shared and Group views, where there
                      are no sections — said nothing about what could be done
                      with it.
                    */}
                    <ListKindBadge kind={list.kind as ListKind} />
                    {/*
                      Whose it is, first and in colour. On a page that mixes my
                      lists with lists I was invited to, this is the fact that
                      decides how to read everything else on the card.
                    */}
                    {theirs && (
                        <span className="rounded-full bg-amber/20 px-2 py-0.5 font-medium">
                            {list.ownerName
                                ? t('lists.owned_by', { name: list.ownerName })
                                : t('lists.shared_with_me')}
                        </span>
                    )}
                    {theirs && list.role && (
                        <span className="rounded-full bg-line/60 px-2 py-0.5 text-ink-soft">
                            {list.role === 'editor' ? t('lists.role_editor') : t('lists.role_viewer')}
                        </span>
                    )}
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

export default function ListsIndex({ lists, view, recipients, isSignedIn }: Props) {
    const page = usePage<SharedProps>()
    const { market } = page.props
    const { t } = useTranslations()

    /*
     * The Gift Cove describes nine tools and six of its cards used to land here,
     * on an index, leaving the reader to work out which button started the thing
     * they had just read about. `?new=for_someone` opens this form on the right
     * shape instead.
     */
    const intent = new URLSearchParams(page.url.split('?')[1] ?? '').get('new')
    const [creating, setCreating] = useState(intent !== null)

    /*
     * Three choices, one piece of state.
     *
     * `for_me` and `for_someone` differ by whether a recipient is named;
     * `group` differs from `for_someone` by one further bit, `together`. Held as
     * one value rather than two booleans so the buttons cannot express a fourth
     * combination that means nothing — "for me, together" is not a list.
     */
    const [audience, setAudience] = useState<'mine' | 'for_someone' | 'group'>(
        intent === 'group' ? 'group' : intent === 'for_someone' ? 'for_someone' : 'mine',
    )
    const forSomeone = audience !== 'mine'

    // The recipient decides the kind and `together` adds one bit; the server
    // derives both in `ListMaker` so nothing here can contradict it.
    const form = useForm({ title: '', recipient_id: '', new_recipient: '', together: false })

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

    const subtitle = {
        mine: t('lists.subtitle'),
        shared: t('lists.shared_subtitle'),
        group: t('lists.group_subtitle'),
    }[view]

    return (
        <>
            <Head title={heading} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-xl sm:text-2xl font-semibold">{heading}</h1>
                    <p className="mt-1 text-ink-soft">{subtitle}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {/*
                      A list is empty until something goes in it, and nothing
                      goes in it from this page — every save starts at a product.
                      Leaving "New list" as the only action here sent people to a
                      list they then had no route out of.
                    */}
                    <Link
                        href={`/${market.key}/search`}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('lists.find_things')}
                    </Link>
                    <button
                        onClick={() => setCreating((v) => !v)}
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
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        form.post(`/${market.key}/lists`, { onSuccess: () => setCreating(false) })
                    }}
                    className="mt-6 space-y-3 rounded-card border border-line bg-card p-5"
                >
                    <label className="block text-sm font-medium" htmlFor="title">
                        {t('lists.list_name')}
                    </label>
                    <input
                        id="title"
                        required
                        autoFocus
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                    />

                    {/*
                      Who it is for, asked as a choice rather than left implied
                      by a dropdown that only appears once you already have
                      people in it. Before this the form could only make a list
                      for yourself: the sole place to name a new person was the
                      picker on a product card.
                    */}
                    {/*
                      Three cards, each naming what will HAPPEN on the list.

                      They were three pills — "For me", "For someone else",
                      "Together" — which name who the list is *about*. That is
                      not the choice being made: the three kinds differ in who
                      may claim, who may vote and who sees the money, and none of
                      that is recoverable from the audience. A hint appeared for
                      the group option alone, on the stated grounds that three
                      permanent hints is a paragraph nobody reads. True of a
                      paragraph; not true of three cards, where the sentence is
                      what is being compared and the eye reads across rather than
                      down.

                      This is also the only cheap moment to explain any of it.
                      The choice is free to change here and awkward to change
                      afterwards, and somebody who picks wrong finds out weeks
                      later when the mechanism they wanted is not on the page.

                      Neither of the first two promises an audience — most lists
                      of both kinds stay private, and a card that says "people
                      claim them" describes readers who do not exist. Only the
                      group card does, because a group gift with nobody else on
                      it is not a thing at all, which is exactly why that kind is
                      chosen up front rather than derived.
                    */}
                    <fieldset>
                        <legend className="text-sm font-medium">{t('lists.for_whom')}</legend>
                        <div className="mt-2 grid gap-2 sm:grid-cols-3">
                            {([
                                { value: 'mine', label: t('lists.for_me'), body: t('lists.new_mine_body') },
                                { value: 'for_someone', label: t('lists.for_someone_else'), body: t('lists.new_for_someone_body') },
                                { value: 'group', label: t('lists.for_group'), body: t('lists.new_group_body') },
                            ] as const).map((choice) => (
                                <button
                                    key={choice.value}
                                    type="button"
                                    aria-pressed={audience === choice.value}
                                    onClick={() => {
                                        setAudience(choice.value)

                                        // Only a group list pools money, and the
                                        // server re-derives this from the same
                                        // bit — this just keeps the form honest.
                                        form.setData('together', choice.value === 'group')

                                        if (choice.value === 'mine') {
                                            form.setData('recipient_id', '')
                                            form.setData('new_recipient', '')
                                        }
                                    }}
                                    className={`rounded-card border p-3 text-left ${
                                        audience === choice.value
                                            ? 'border-accent bg-accent/10'
                                            : 'border-line hover:border-ink'
                                    }`}
                                >
                                    <span
                                        className={`block text-sm font-medium ${
                                            audience === choice.value ? 'text-accent' : ''
                                        }`}
                                    >
                                        {choice.label}
                                    </span>
                                    <span className="mt-1 block text-xs text-ink-soft">
                                        {choice.body}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    {forSomeone && (
                        <>
                            {recipients.length > 0 && (
                                <select
                                    aria-label={t('lists.for_whom')}
                                    value={form.data.recipient_id}
                                    onChange={(e) => form.setData('recipient_id', e.target.value)}
                                    className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                                >
                                    <option value="">{t('lists.someone_new')}</option>
                                    {recipients.map((r) => (
                                        <option key={r.id} value={r.id}>{r.name}</option>
                                    ))}
                                </select>
                            )}

                            {form.data.recipient_id === '' && (
                                <>
                                    <label className="block text-sm font-medium" htmlFor="new-recipient">
                                        {t('lists.person_name')}
                                    </label>
                                    <input
                                        id="new-recipient"
                                        required
                                        maxLength={80}
                                        value={form.data.new_recipient}
                                        onChange={(e) => form.setData('new_recipient', e.target.value)}
                                        className="w-full rounded-lg border border-line bg-cream px-3 py-2"
                                    />
                                </>
                            )}
                        </>
                    )}

                    <div className="flex gap-2">
                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60"
                        >
                            {t('lists.create')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setCreating(false)}
                            className="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('lists.cancel')}
                        </button>
                    </div>
                </form>
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
        </>
    )
}
