import { router } from '@inertiajs/react'
import { useState } from 'react'
import type { CurrentMarket } from '../types'
import { formatPrice } from '../types'
import InfoTip from './InfoTip'
import ListName from './ListName'
import { markSaved } from '../savedItems'
import { useTranslations } from '../useTranslations'

/**
 * One thing on a wish list the person this list is about lets you see.
 *
 * `token` is that wish list's share token: adding and claiming post to the
 * shared-list endpoints, so there is one copy path and one claim mechanism,
 * and the privacy rules are enforced where they always were.
 */
export interface Wish {
    id: number
    token: string
    listTitle: string
    title: string
    image: string | null
    price: number | null
    /** What they wrote under it, as their list's own page shows it. Never copied. */
    note: string | null
    live: boolean
    groupId: number | null
    claimed: boolean
    claimedByMe: boolean
    sent: boolean | null
}

/**
 * "From Anna's wish list": pick from what she asked for, on a list about her.
 *
 * The owner's request of 2026-09-26: a wish list can be visible to your people,
 * and "when others build a list for you, they will be able to pick from your
 * shared wish lists". Which wishes arrive here is decided on the server
 * (`GiftTarget::wishesSeenBy()`); this only draws them. See
 * docs/features/wish-list-for-my-people.md.
 *
 * Two places, one component:
 *
 * - `page`: a section under the items of a gift list about her. One tap puts a
 *   wish on this list; "I'll get this" claims it on her list, which she never
 *   sees (invariant 4).
 * - `add`: a block inside "Add a product", because that is where somebody
 *   filling the list is looking. Adding only: claiming is a decision about
 *   buying, not about filling a list.
 *
 * Under the items rather than above them: on a gift list the owner opens to
 * work on their own picks, the first thing under the title must be theirs.
 */
export default function TheirWishes({
    base,
    name,
    wishes,
    listId,
    onList,
    market,
    variant = 'page',
}: {
    base: string
    name: string
    wishes: Wish[]
    /** The list a wish is added to: the one being looked at. */
    listId: string
    /** Products already on that list, so a wish on it says so instead of offering itself twice. */
    onList: Set<number>
    market: CurrentMarket
    variant?: 'page' | 'add'
}) {
    const { t } = useTranslations()
    const [busy, setBusy] = useState<number | null>(null)

    if (wishes.length === 0) {
        return null
    }

    // Each wish list once, for the link to it. Usually one.
    const lists = [...new Map(wishes.map((wish) => [wish.token, wish.listTitle])).entries()]

    const add = (wish: Wish) => {
        setBusy(wish.id)
        router.post(
            `${base}/l/${wish.token}/items/${wish.id}/copy`,
            { to: listId },
            {
                preserveScroll: true,
                // The browser's saved-items cache, as CopyToList keeps it: the
                // product's own page should now say it is on a list.
                onSuccess: () => wish.groupId !== null && markSaved(wish.groupId),
                onFinish: () => setBusy(null),
            },
        )
    }

    const page = variant === 'page'

    return (
        <section className={page ? 'mt-10' : 'mt-4 border-t border-line pt-3'}>
            <div className="flex flex-wrap items-center gap-x-2">
                {page ? (
                    <h2 className="text-lg font-semibold">{t('lists.their_wishes', { name })}</h2>
                ) : (
                    <p className="text-sm font-medium">{t('lists.their_wishes', { name })}</p>
                )}
                <InfoTip>{t('lists.their_wishes_tip', { name })}</InfoTip>
            </div>

            {page && (
                <p className="mt-1 flex flex-wrap gap-x-3 text-sm">
                    {lists.map(([token, title]) => (
                        // A real anchor: their list is the sort of thing people open in a tab.
                        <a key={token} href={`${base}/l/${token}`} className="text-ink-soft underline hover:text-ink">
                            {lists.length === 1 ? t('lists.their_wishes_open') : <ListName name={title} kind="mine" />}
                        </a>
                    ))}
                </p>
            )}

            <ul
                className={`divide-y divide-line ${page ? 'mt-3 rounded-card border border-line bg-card px-4' : 'mt-1'}`}
            >
                {wishes.map((wish) => {
                    const already = wish.groupId !== null && onList.has(wish.groupId)

                    return (
                        <li
                            key={wish.id}
                            className={`flex items-start gap-3 sm:items-center ${page ? 'py-3' : 'py-2'}`}
                        >
                            {wish.image ? (
                                <img
                                    src={wish.image}
                                    alt=""
                                    loading="lazy"
                                    className={`${page ? 'h-14 w-14' : 'h-10 w-10'} shrink-0 object-contain`}
                                />
                            ) : (
                                <span className={`${page ? 'h-14 w-14' : 'h-10 w-10'} shrink-0 rounded bg-cream`} />
                            )}

                            {/*
                              The words and the buttons side by side from `sm`,
                              the buttons under the words on a phone: side by
                              side at 390px squeezed the title to nothing.
                            */}
                            <div className="min-w-0 flex-1 sm:flex sm:items-center sm:gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="line-clamp-2 text-sm font-medium">{wish.title}</p>
                                    <p className="text-xs text-ink-soft">
                                        {wish.price !== null && !wish.live && formatPrice(wish.price, market)}
                                        {page && wish.note && (
                                            <span className="italic">
                                                {wish.price !== null && !wish.live ? ' · ' : ''}
                                                {wish.note}
                                            </span>
                                        )}
                                    </p>
                                </div>

                                <div className="mt-2 flex flex-wrap items-center gap-2 sm:mt-0 sm:shrink-0">
                                    {already ? (
                                        <span className="text-sm text-sage">✓ {t('lists.on_your_list')}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => add(wish)}
                                            disabled={busy === wish.id}
                                            className="rounded-lg border border-accent px-3 py-1.5 text-sm font-medium text-accent hover:bg-accent hover:text-white disabled:opacity-50"
                                        >
                                            + {t('lists.add_to_my_list')}
                                        </button>
                                    )}

                                    {/*
                                  "I'll get this", on her list: the same endpoint
                                  as her list's own page, so the claim rules and
                                  invariant 4 live in one place. Page only.
                                */}
                                    {page &&
                                        (wish.claimedByMe ? (
                                            <>
                                                <span className="text-sm text-sage">{t('lists.claimed')}</span>
                                                {wish.sent === false && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `${base}/l/${wish.token}/sent/${wish.id}`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className="rounded-lg border border-line px-3 py-1.5 text-sm"
                                                    >
                                                        {t('lists.mark_sent')}
                                                    </button>
                                                )}
                                                {wish.sent && (
                                                    <span className="text-sm text-ink-soft">{t('lists.sent')}</span>
                                                )}
                                            </>
                                        ) : wish.claimed ? (
                                            <span className="text-sm text-ink-soft">
                                                {t('lists.claimed_by_someone')}
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/l/${wish.token}/claim/${wish.id}`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="rounded-lg border border-line px-3 py-1.5 text-sm hover:border-ink"
                                            >
                                                {t('lists.claim')}
                                            </button>
                                        ))}
                                </div>
                            </div>
                        </li>
                    )
                })}
            </ul>
        </section>
    )
}
