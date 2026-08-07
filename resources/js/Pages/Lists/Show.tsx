import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
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

interface Props {
    list: {
        id: string
        title: string
        isGiftList: boolean
        visibility: string
        shareUrl: string | null
        recipient: { name: string } | null
    }
    items: Item[]
}

export default function ListShow({ list, items }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)
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
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <code className="flex-1 truncate rounded border border-line bg-card px-3 py-2 text-xs">
                        {list.shareUrl}
                    </code>
                    <button onClick={copyLink} className="rounded-lg bg-ink px-3 py-2 text-sm text-cream">
                        {copied ? t('lists.copied') : t('lists.copy_link')}
                    </button>
                </div>
            )}

            {/*
              No claim state anywhere on this page. This is the owner's view,
              and a gift list exists so the owner does not learn what has been
              bought — not even how many things.
            */}
            {list.isGiftList && shared && (
                <p className="mt-3 rounded-card border border-line bg-card p-3 text-sm text-ink-soft">
                    {t('lists.owner_view_note')}
                </p>
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
