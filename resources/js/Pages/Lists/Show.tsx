import { Head, Link, router, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import ListTools from '../../Components/ListTools'
import { useTranslations } from '../../useTranslations'

interface Item {
    id: number
    title: string
    image: string | null
    price: number | null
    currentPrice: number | null
    note: string | null
    groupId: number | null
    slug: string | null
    merchantCount: number
    inStock: boolean
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

    function toggleSharing() {
        router.patch(`${base}/lists/${list.id}`, {
            visibility: shared ? 'private' : 'link',
        }, { preserveScroll: true })
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
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <button
                        onClick={toggleSharing}
                        className="rounded-lg border border-line px-3 py-2 text-sm hover:border-ink"
                    >
                        {shared ? t('lists.disable_sharing') : t('lists.enable_sharing')}
                    </button>
                    <button
                        onClick={() => {
                            if (confirm(t('lists.delete_confirm'))) {
                                router.delete(`${base}/lists/${list.id}`)
                            }
                        }}
                        className="rounded-lg border border-line px-3 py-2 text-sm text-accent hover:border-accent"
                    >
                        {t('lists.delete_list')}
                    </button>
                </div>
            </header>

            {/* A badge, not a sentence. The state matters; the explanation of
                the state does not need a paragraph every time. */}
            <p className="mt-2 text-xs text-ink-soft">
                {shared ? t('lists.shared_badge') : t('lists.private_badge')}
            </p>

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
                <p className="mt-10 rounded-card border border-line bg-card p-8 text-center text-ink-soft">
                    {t('lists.empty_list')}
                </p>
            ) : (
                <ul className="mt-8 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-center gap-4 p-4">
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
                                onClick={() => router.delete(`${base}/list-items/${item.id}`, { preserveScroll: true })}
                                aria-label={t('lists.remove')}
                                className="rounded p-2 text-ink-soft hover:text-accent"
                            >
                                ✕
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
