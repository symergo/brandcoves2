import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AddProduct from '../../Components/AddProduct'
import Pledge, { type Contributions } from '../../Components/Pledge'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import ListTools, { type Panel } from '../../Components/ListTools'
import ListKindBadge, { type ListKind, useListKindWords } from '../../Components/ListKindBadge'
import ShareRow from '../../Components/ShareRow'
import { markRemoved } from '../../savedItems'
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
    slug: string | null
    merchantCount: number
    inStock: boolean
    /**
     * Only ever present on a `group` list, where the owner is the organiser.
     * Absent on a wish list — an owner must not learn what has been claimed,
     * and money pooled against an item is claim state (invariant #4).
     */
    contributions?: Contributions
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
    }
    items: Item[]
    /** The pot on a group list, for the organiser's own page. */
    pot: Contributions | null
}

export default function ListShow({
    list,
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
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const shared = list.visibility !== 'private'
    const [panel, setPanel] = useState<Panel | null>(null)
    // The ask-them link, revealed on press rather than shown outright.
    const [asking, setAsking] = useState(false)
    const { sentence } = useListKindWords()

    return (
        <>
            <Head title={list.title} />

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
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
                        <ListKindBadge kind={list.kind as ListKind} />
                    </div>
                    {list.recipient && (
                        <p className="mt-1 text-ink-soft">{list.recipient.name}</p>
                    )}
                    {/* A badge, not a sentence. The state matters; the
                        explanation of the state does not need a paragraph every
                        time. Beside the title, where it describes the thing it
                        is true of. */}
                    <p className="mt-1 text-xs text-ink-soft">
                        {shared ? t('lists.shared_badge') : t('lists.private_badge')}
                    </p>
                    {/*
                      And what that means you can do with it, read against
                      whether anybody else is actually on the list. A private
                      list of any kind offers none of the mechanisms, so this
                      says what the list is now and then what sharing would do.
                    */}
                    <p className="mt-2 max-w-prose text-sm text-ink-soft">
                        {sentence(list.kind as ListKind, shared)}
                    </p>
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
                  Share has moved down into `ListTools`, next to the other
                  things you can do with this list. Two copies of one control on
                  one screen is not twice as findable, and the row below is
                  where somebody looks for what a list can do — this header is
                  about getting rid of it.
                */}
                <div className="flex flex-wrap items-center gap-2">
                    {access.isOwner && (
                        <button
                            onClick={() => {
                                if (confirm(t('lists.delete_confirm'))) {
                                    router.delete(`${base}/lists/${list.id}`)
                                }
                            }}
                            aria-label={t('lists.delete_list')}
                            title={t('lists.delete_list')}
                            className="rounded-lg border border-line px-3 py-2 text-sm text-ink-soft hover:border-accent hover:text-accent"
                        >
                            {t('lists.delete_list')}
                        </button>
                    )}
                </div>
            </header>

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
              "Claims are hidden from you, that is the point."

              Not shown to an owner who has asked to see them — invariant #4 is
              a default now, and explaining a state somebody has turned off is
              worse than saying nothing.
            */}
            {list.claimable && shared && !list.ownerSeesClaims && (
                <p className="mt-3 rounded-card border border-line bg-card p-3 text-sm text-ink-soft">
                    {t('lists.owner_view_note')}
                </p>
            )}

            <ListTools
                base={base}
                list={list}
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
                panel={panel}
                onPanel={setPanel}
            />

            {/*
              Lane one: what they actually asked for.

              The payoff of linking a recipient to an account. Claiming here
              hits the same endpoint as the shared-list page — one claim
              mechanism, so the privacy rule is enforced in one place. They
              never see any of this on their own list.
            */}
            {target !== null && (
                <section className="mt-10">
                    <h2 className="text-lg font-medium">
                        {t('lists.asked_for', { name: target.name })}
                    </h2>

                    {!target.isLinked ? (
                        <div className="mt-3 rounded-card border border-line bg-card p-4">
                            {target.askUrl && (
                                <>
                                    <h3 className="text-sm font-medium">
                                        {t('recipients.ask_them')}
                                    </h3>
                                    <p className="mt-1 text-xs text-ink-soft">
                                        {t('recipients.ask_them_hint')}
                                    </p>

                                    {/*
                                      A button, and the link behind it.

                                      This block used to open with a raw URL in a
                                      `<code>` box — the first thing on the one
                                      section of the page that is an *action*, and
                                      a URL is not one. It read as reference
                                      material for something you had already
                                      decided to do, so the deciding never
                                      happened.

                                      Not replaced by a lone copy button, though:
                                      `ShareRow` was extracted precisely because
                                      the Santa invite was a copy button with the
                                      URL nowhere in sight, and its reasoning
                                      holds — people check a link before pasting
                                      it into a message to one named person, and a
                                      button claiming to have copied something is
                                      worth less than the thing itself. So the
                                      press reveals it rather than skipping it.
                                    */}
                                    {asking ? (
                                        <div className="mt-3">
                                            <ShareRow
                                                url={target.askUrl}
                                                text={t('recipients.ask_them')}
                                            />
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => setAsking(true)}
                                            className="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                        >
                                            {t('recipients.ask_them')}
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    ) : asked.length === 0 ? (
                        <p className="mt-3 rounded-card border border-line bg-card p-6 text-center text-sm text-ink-soft">
                            {t('lists.asked_none', { name: target.name })}
                        </p>
                    ) : (
                        <ul className="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                            {asked.map((entry) => (
                                <li key={entry.id} className="flex items-center gap-4 p-4">
                                    {entry.image && (
                                        <img
                                            src={entry.image}
                                            alt=""
                                            loading="lazy"
                                            className="h-14 w-14 shrink-0 object-contain"
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{entry.title}</p>
                                        {entry.price !== null && !entry.live && (
                                            <p className="text-sm text-ink-soft">
                                                {formatPrice(entry.price, market)}
                                            </p>
                                        )}
                                    </div>

                                    {entry.claimedByMe ? (
                                        <div className="flex shrink-0 items-center gap-2">
                                            <span className="text-sm text-sage">{t('lists.claimed')}</span>
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
                                                <span className="text-sm text-ink-soft">{t('lists.sent')}</span>
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
                </section>
            )}

            {target !== null && (
                <h2 className="mt-10 text-lg font-medium">{t('lists.my_finds')}</h2>
            )}

            {items.length === 0 ? (
                <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                    <p className="font-medium">{t('lists.empty_list')}</p>

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
                                    className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line text-[11px] font-medium text-ink"
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

                    <ul className="mt-3 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                        {items.map((item) => (
                            <li key={item.id} className="p-4">
                              <div className="flex items-center gap-4">
                                {item.image && (
                                    <img
                                        src={item.image}
                                        alt=""
                                        className="h-14 w-14 rounded object-contain"
                                        onError={(e) => { e.currentTarget.style.visibility = 'hidden' }}
                                    />
                                )}

                                <div className="min-w-0 flex-1">
                                    {item.groupId && item.slug ? (
                                        <Link href={`${base}/p/${item.groupId}/${item.slug}`} className="font-medium hover:underline">
                                            {item.title}
                                        </Link>
                                    ) : item.externalUrl ? (
                                        // A link the owner typed. Still `noopener
                                        // noreferrer nofollow`: this list gets
                                        // shared, and by then the link is being
                                        // followed by people who did not type it.
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
                                    {item.note && <p className="text-sm text-ink-soft">{item.note}</p>}
                                </div>

                                <div className="text-right text-sm">
                                    {item.currentPrice !== null && (
                                        <div className="font-semibold">
                                            {t('lists.price_now', { price: formatPrice(item.currentPrice, market) })}
                                        </div>
                                    )}
                                    {/* Only when it actually moved — otherwise it is noise. */}
                                    {item.price !== null &&
                                        item.currentPrice !== null &&
                                        item.price !== item.currentPrice && (
                                            <div className="text-xs text-ink-soft line-through">
                                                {formatPrice(item.price, market)}
                                            </div>
                                        )}
                                </div>

                                <button
                                    onClick={() =>
                                        router.delete(`${base}/list-items/${item.id}`, {
                                            preserveScroll: true,
                                            // Otherwise the bookmark on the product
                                            // page still reads as saved after the
                                            // item has gone.
                                            onSuccess: () =>
                                                item.groupId !== null && markRemoved(item.groupId),
                                        })
                                    }
                                    aria-label={t('lists.remove')}
                                    className="rounded p-2 text-ink-soft hover:text-accent"
                                >
                                    ✕
                                </button>
                              </div>

                                {/*
                                  Who put in what, on a group list.

                                  The one place an owner is shown anything resembling
                                  claim state, and it is legitimate: the recipient of
                                  a group list is a third party who never opens this
                                  page, so there is no surprise to protect from the
                                  organiser — who is the person fronting the money.
                                  On every other kind of list the server sends no
                                  key here at all, which is why there is no `else`.

                                  Pledging needs the share link, because that is the
                                  URL the endpoint is mounted on. A private group
                                  list can therefore be read but not contributed to
                                  from here, which is honest: nobody else can reach
                                  it either until it is shared.
                                */}
                                {item.contributions !== undefined && (
                                    <Pledge
                                        action={`${list.shareUrl}/pledge/${item.id}`}
                                        contributions={item.contributions}
                                        canContribute={list.shareUrl !== null}
                                        price={item.currentPrice ?? item.price}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </>
    )
}
