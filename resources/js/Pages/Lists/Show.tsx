import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ManualItem from '../../Components/ManualItem'
import Pledge, { type Contributions } from '../../Components/Pledge'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import ListTools, { type Panel } from '../../Components/ListTools'
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
    }
    items: Item[]
}

export default function ListShow({
    list,
    items,
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

    /**
     * Share, in one press.
     *
     * It used to take two, in two places: a header toggle that minted the link
     * and a tab below that displayed it — and the tab did not exist until the
     * toggle had been used, so nothing on the screen suggested the second step
     * was there. People turned sharing on and left without the link.
     */
    function share() {
        if (shared) {
            setPanel('share')

            return
        }

        router.patch(
            `${base}/lists/${list.id}`,
            { visibility: 'link' },
            { preserveScroll: true, onSuccess: () => setPanel('share') },
        )
    }

    return (
        <>
            <Head title={list.title} />

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link href={`${base}/lists`} className="text-sm text-ink-soft hover:text-ink">
                        ← {t('lists.title')}
                    </Link>
                    <h1 className="mt-1 text-2xl font-semibold">{list.title}</h1>
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
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {/*
                      Adding is what a list is for, and there was no way to do it
                      from one: every save starts at a product, and this page
                      pointed at no products. A dead end at the exact moment
                      somebody has decided to fill it.
                    */}
                    {access.canEdit && (
                        <Link
                            href={`${base}/search`}
                            className="rounded-lg border border-line px-3 py-2 text-sm hover:border-ink"
                        >
                            + {t('lists.find_things')}
                        </Link>
                    )}

                    {access.isOwner && (
                        <button
                            onClick={share}
                            className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                        >
                            {t('lists.share')}
                        </button>
                    )}

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
              No claim state anywhere on this page. This is the owner's view,
              and a gift list exists so the owner does not learn what has been
              bought — not even how many things.
            */}
            {list.claimable && shared && (
                <p className="mt-3 rounded-card border border-line bg-card p-3 text-sm text-ink-soft">
                    {t('lists.owner_view_note')}
                </p>
            )}

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
                                <ShareRow
                                    url={target.askUrl}
                                    text={t('recipients.ask_them')}
                                    label={t('recipients.ask_them')}
                                    hint={t('recipients.ask_them_hint')}
                                />
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
                    <p className="text-ink-soft">{t('lists.empty_list')}</p>
                    {access.canEdit && (
                        <div className="mt-4 flex flex-wrap items-start justify-center gap-2">
                            <Link
                                href={`${base}/search`}
                                className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                            >
                                {t('lists.find_things')}
                            </Link>
                            {/*
                              Beside the search, not instead of it. Most things
                              are in the catalogue and searching is the better
                              path; this is for the ones that are not, and an
                              empty list is exactly where somebody discovers
                              their present is one of them.
                            */}
                            <ManualItem
                                action={`${base}/list-items`}
                                data={{ source: 'manual', wishlist_id: list.id }}
                                hint={t('lists.manual_hint')}
                                withNote
                            />
                        </div>
                    )}
                </div>
            ) : (
                <ul className="mt-8 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
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
            )}

            {/*
              After the list, not before it: this is the exception, and the
              catalogue is still the ordinary way in. Present on a list that
              already has things on it because "the last one is not in the
              shops" is when it comes up.
            */}
            {items.length > 0 && access.canEdit && (
                <div className="mt-4">
                    <ManualItem
                        action={`${base}/list-items`}
                        data={{ source: 'manual', wishlist_id: list.id }}
                        hint={t('lists.manual_hint')}
                        withNote
                    />
                </div>
            )}
        </>
    )
}
