import { Head, Link, router, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Item {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    url: string | null
    inStock: boolean
    /** null for the list owner — they must never learn what is taken. */
    claimed: boolean | null
    claimedByMe: boolean
}

interface Props {
    list: {
        title: string
        description: string | null
        kind: string
        claimable: boolean
        recipient: string | null
        for: string | null
    }
    isOwner: boolean
    items: Item[]
}

export default function SharedList({ list, isOwner, items }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const token = window.location.pathname.split('/').pop()
    const base = `/${market.key}`

    return (
        <>
            {/* A shared gift list must never be indexed: it is a private URL
                that happens to be unauthenticated. */}
            <Head title={list.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header>
                <h1 className="text-2xl font-semibold">{list.title}</h1>
                {list.description && <p className="mt-2 text-ink-soft">{list.description}</p>}

                {list.claimable && !isOwner && (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        {/*
                          Name the person, or say nothing about a person at all.
                          Falling back to the list *title* told visitors that
                          "Saved items" would not see who claimed what — and an
                          anonymous owner genuinely has no name to give.
                        */}
                        {list.for
                            ? t('lists.shared_intro', { name: list.for })
                            : t('lists.shared_intro_anon')}
                    </p>
                )}

                {isOwner && (
                    <p className="mt-4 rounded-card border border-amber/40 bg-amber/10 p-4 text-sm">
                        {t('lists.owner_view_note')}
                    </p>
                )}
            </header>

            <ul className="mt-8 grid gap-4 sm:grid-cols-2">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className={`flex flex-col rounded-card border bg-card p-4 ${
                            item.claimed && !item.claimedByMe ? 'border-line opacity-60' : 'border-line'
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
                                ) : (
                                    <span className="font-medium">{item.title}</span>
                                )}
                                {item.note && <p className="mt-1 text-sm text-ink-soft">{item.note}</p>}
                                {item.price !== null && (
                                    <p className="mt-1 font-semibold">{formatPrice(item.price, market)}</p>
                                )}
                            </div>
                        </div>

                        {/*
                          Claim controls are absent entirely for the owner —
                          `claimed` is null in their payload, so there is nothing
                          to render even if this branch were reached.
                        */}
                        {!isOwner && item.claimed !== null && (
                            <div className="mt-4">
                                {item.claimedByMe ? (
                                    <button
                                        onClick={() =>
                                            router.delete(`${base}/l/${token}/claim/${item.id}`, { preserveScroll: true })
                                        }
                                        className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-sm font-medium text-sage"
                                    >
                                        {t('lists.claimed')} · {t('lists.unclaim')}
                                    </button>
                                ) : item.claimed ? (
                                    <p className="w-full rounded-lg border border-line px-4 py-2 text-center text-sm text-ink-soft">
                                        {t('lists.claimed_by_someone')}
                                    </p>
                                ) : (
                                    <button
                                        onClick={() =>
                                            router.post(`${base}/l/${token}/claim/${item.id}`, {}, { preserveScroll: true })
                                        }
                                        className="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('lists.claim')}
                                    </button>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </>
    )
}
