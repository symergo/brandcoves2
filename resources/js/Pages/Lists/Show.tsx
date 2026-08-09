import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import ShareLink from '../../Components/ShareLink'
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

interface Props {
    access: { isOwner: boolean; canEdit: boolean }
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
    quizUrl,
    quizPlays,
    santaMemberships,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)
    const [invite, setInvite] = useState('')
    const [role, setRole] = useState('viewer')
    const base = `/${market.key}`

    const shared = list.visibility !== 'private'

    function toggleSharing() {
        router.patch(`${base}/lists/${list.id}`, {
            visibility: shared ? 'private' : 'link',
        }, { preserveScroll: true })
    }

    async function copyLink() {
        if (!list.shareUrl) return
        await navigator.clipboard.writeText(list.shareUrl)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
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

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <code className="min-w-0 flex-1 truncate rounded border border-line px-3 py-2 text-xs">
                            {list.shareUrl}
                        </code>
                        <button onClick={copyLink} className="rounded-lg bg-ink px-3 py-2 text-sm text-cream">
                            {copied ? t('lists.copied') : t('lists.copy_link')}
                        </button>
                    </div>

                    {/*
                      A list has to travel through a group chat. The native sheet
                      is what puts it there on a phone; the explicit links are for
                      desktop, where most lists are actually built.
                    */}
                    <div className="mt-3">
                        <ShareLink url={list.shareUrl} text={t('lists.share_text', { title: list.title })} />
                    </div>

                    {/*
                      The same list, as a quiz.

                      This is the half that gets a list built at all: a list that
                      only helps other people is a chore, and one your friends
                      compete on is a reason to make one.
                    */}
                    {list.claimable && (
                        <div className="mt-4 border-t border-line pt-3">
                            <h3 className="text-sm font-medium">{t('quiz.title')}</h3>

                            {quizUrl ? (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">
                                        {quizPlays > 0
                                            ? t('quiz.played', { count: String(quizPlays) })
                                            : t('quiz.created')}
                                    </p>
                                    <div className="mt-2">
                                        <ShareLink url={quizUrl} text={t('quiz.share_text')} />
                                    </div>
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
                        <div className="mt-3 rounded-card border border-line bg-card p-4 text-sm">
                            <p className="text-ink-soft">{t('recipients.ask_them_hint')}</p>
                            {target.askUrl && (
                                <button
                                    type="button"
                                    onClick={() => navigator.clipboard.writeText(target.askUrl!)}
                                    className="mt-3 rounded-lg border border-line px-4 py-2 text-sm"
                                >
                                    {t('recipients.ask_them')}
                                </button>
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
                                                {formatPrice(entry.price, market.currency)}
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
