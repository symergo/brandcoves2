import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import ShareRow from '../../Components/ShareRow'
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
    const [invite, setInvite] = useState('')
    const [role, setRole] = useState('viewer')
    const [handTo, setHandTo] = useState(handoverEmail ?? '')
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

            <p className="mt-3 text-sm text-ink-soft">
                {shared ? t('lists.sharing_on') : t('lists.sharing_off')}
            </p>

            {shared && list.shareUrl && (
                <div className="mt-4 rounded-card border border-line bg-card p-4">
                    <h2 className="text-sm font-medium">{t('lists.share_heading')}</h2>

                    {/*
                      One share row, used identically everywhere a link is
                      handed to somebody. The native sheet already offers
                      WhatsApp and mail, so a button per channel only made this
                      area look like a toolbar.
                    */}
                    <div className="mt-2">
                        <ShareRow
                            url={list.shareUrl}
                            text={t('lists.share_text', { title: list.title })}
                        />
                    </div>

                    {/*
                      The same list, as a quiz.

                      This is the half that gets a list built at all: a list that
                      only helps other people is a chore, and one your friends
                      compete on is a reason to make one.
                    */}
                    {list.claimable && (
                        <div className="mt-4 border-t border-line pt-3">
                            {/*
                              `quiz.own_title`, not `quiz.title`. The latter is
                              the *player's* question — "how well do you know
                              them?" — which is right on the play page and on a
                              shared score, and on your own list page asked you
                              how well you knew yourself.
                            */}
                            <h3 className="text-sm font-medium">{t('quiz.own_title')}</h3>

                            {quizUrl ? (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">
                                        {quizPlays > 0
                                            ? t('quiz.played', { count: String(quizPlays) })
                                            : t('quiz.created')}
                                    </p>
                                    {/*
                                      The link itself, visible and clickable.

                                      Share buttons alone left the owner unable
                                      to open the thing they had just created —
                                      which reads as a quiz link that does not
                                      work, because from where they are sitting
                                      there is no link at all.
                                    */}
                                    <div className="mt-2">
                                        <ShareRow url={quizUrl} text={t('quiz.share_text')} />
                                    </div>

                                    {/* The owner cannot play their own quiz, but
                                        they should be able to look at the thing
                                        they are about to send. */}
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
                </div>
            )}

            {/*
              Answer a Secret Santa with a list you already have.

              Without this join a member's wishlist and their membership are two
              unrelated things, and whoever drew them is still guessing.
            */}
            {santaMemberships.length > 0 && (
                <section className="mt-4 rounded-card border border-line bg-card p-4">
                    <h2 className="text-sm font-medium">{t('santa.title')}</h2>
                    <p className="mt-1 text-xs text-ink-soft">{t('santa.attach_hint')}</p>

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
                </section>
            )}

            {/*
              Suggestions waiting on a decision.

              Visible to the owner, unusually for this feature, because a
              suggestion is a message addressed to them. It is not on the list
              until they accept it — so nobody can claim it, and claiming a
              pending one would announce its existence by making it unavailable.
            */}
            {access.isOwner && suggestions.length > 0 && (
                <section className="mt-4 rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="text-sm font-medium">{t('suggestions.heading')}</h2>
                    <p className="mt-1 text-xs text-ink-soft">{t('suggestions.hint')}</p>

                    <ul className="mt-3 space-y-3">
                        {suggestions.map((s) => (
                            <li key={s.id} className="flex items-center gap-3">
                                {s.image && (
                                    <img src={s.image} alt="" loading="lazy" className="h-12 w-12 object-contain" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{s.title}</p>
                                    {s.from && (
                                        <p className="text-xs text-ink-soft">
                                            {t('suggestions.from', { name: s.from })}
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
                                    onClick={() =>
                                        router.delete(`${base}/suggestions/${s.id}`, { preserveScroll: true })
                                    }
                                    className="text-xs text-ink-soft hover:text-ink"
                                >
                                    {t('suggestions.dismiss')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              Registry: an occasion, a date, and somewhere to send it.

              Only on your own list, because a registry is a thing you publish
              about yourself. The address is stored encrypted and shown only to
              somebody who has claimed an item — a registry is public, and
              publishing a home address to everyone holding the link is a
              different act from giving it to the person posting the parcel.
            */}
            {access.isOwner && list.kind === 'mine' && (
                <details className="mt-4 rounded-card border border-line bg-card p-4">
                    <summary className="cursor-pointer text-sm font-medium">
                        {t('registry.heading')}
                    </summary>
                    <p className="mt-1 text-xs text-ink-soft">{t('registry.hint')}</p>

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
                                    delivery_address: String(data.get('delivery_address') || ''),
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

                        <label className="block text-sm sm:col-span-2">
                            {t('registry.address')}
                            <textarea
                                name="delivery_address"
                                rows={3}
                                defaultValue={deliveryAddress ?? ''}
                                className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                            />
                            <span className="mt-1 block text-xs text-ink-soft">
                                {t('registry.address_hint')}
                            </span>
                        </label>

                        <button
                            type="submit"
                            className="justify-self-start rounded-lg border border-line px-4 py-2 text-sm sm:col-span-2"
                        >
                            {t('lists.save')}
                        </button>
                    </form>
                </details>
            )}

            {/*
              Hand it over.

              A list about somebody is research while you are choosing and
              becomes a burden once they are here and could simply be told. Only
              offered when there is an account to hand it to — handing a list to
              a name gives it to nobody.
            */}
            {canHandOver && (
                <section className="mt-4 rounded-card border border-line bg-card p-4">
                    <h2 className="text-sm font-medium">{t('handover.heading')}</h2>
                    <p className="mt-1 text-xs text-ink-soft">
                        {t('handover.hint', { name: list.recipient?.name ?? '' })}
                    </p>
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()

                            if (confirm(t('handover.confirm', { name: handTo }))) {
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
                </section>
            )}

            {/*
              Co-givers, on a list about somebody else.

              Buying for one person is usually done by several, and the
              coordination problem is the one claiming already solves — except
              here everyone involved is a giver.
            */}
            {list.kind === 'for_someone' && access.isOwner && (
                <section className="mt-4 rounded-card border border-line bg-card p-4">
                    <h2 className="text-sm font-medium">{t('lists.collaborators')}</h2>
                    <p className="mt-1 text-xs text-ink-soft">{t('lists.invite_hint')}</p>

                    {collaborators.length > 0 && (
                        <ul className="mt-3 space-y-2">
                            {collaborators.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-3 text-sm">
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
                    )}

                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()
                            router.post(
                                `${base}/lists/${list.id}/collaborators`,
                                { email: invite, role },
                                { preserveScroll: true, onSuccess: () => setInvite('') },
                            )
                        }}
                    >
                        <input
                            type="email"
                            required
                            value={invite}
                            onChange={(e) => setInvite(e.target.value)}
                            placeholder="name@example.com"
                            className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                        />
                        <select
                            value={role}
                            onChange={(e) => setRole(e.target.value)}
                            className="rounded-lg border border-line px-2 py-2 text-sm"
                        >
                            <option value="viewer">{t('lists.role_viewer')}</option>
                            <option value="editor">{t('lists.role_editor')}</option>
                        </select>
                        <button type="submit" className="rounded-lg border border-line px-4 py-2 text-sm">
                            {t('lists.invite_collaborator')}
                        </button>
                    </form>
                </section>
            )}

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
