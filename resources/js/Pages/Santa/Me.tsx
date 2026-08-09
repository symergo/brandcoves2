import { Head, router, usePage } from '@inertiajs/react'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Wish {
    id: number
    token: string
    title: string
    image: string | null
    price: Cents | null
    live: boolean
    url: string | null
    claimed: boolean
}

interface Props {
    group: {
        id: string
        title: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        drawn: boolean
    }
    me: {
        joinToken: string
        name: string
        done: boolean
        hasList: boolean
        listUrl: string | null
        giftee: { name: string; isLinked: boolean; wishes: Wish[] } | null
    }
}

/**
 * One member's view: who they drew, and that person's list.
 *
 * Reached by join token so it works without an account — requiring a login to
 * be in an office Secret Santa is how most of the office does not join.
 */
export default function SantaMe({ group, me }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    return (
        <>
            {/* The single page that names a pairing. Never indexed. */}
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold">{group.title}</h1>

                {!group.drawn ? (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        {t('santa.not_drawn')}
                    </p>
                ) : me.giftee ? (
                    <p className="mt-3 text-lg">
                        {t('santa.you_have', { name: me.giftee.name })}
                    </p>
                ) : null}

                <p className="mt-2 text-sm text-ink-soft">
                    {group.budgetMax !== null &&
                        formatPrice(group.budgetMax, market.currency) + ' · '}
                    {group.exchangeDate}
                </p>
            </header>

            {/*
              Your own list, pushed before anything else.

              Joining a group is the strongest possible reason to build one —
              whoever drew you has nothing to go on until you do. Without this
              nudge Secret Santa degrades into "here is a name, good luck".
            */}
            {!me.hasList && (
                <section className="mt-8 max-w-2xl rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="font-medium">{t('santa.build_yours')}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('santa.build_yours_hint')}</p>
                    <a
                        href={`${base}/lists`}
                        className="mt-3 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {t('lists.new_list')}
                    </a>
                </section>
            )}

            {group.drawn && me.giftee && (
                <section className="mt-10">
                    <h2 className="text-lg font-medium">
                        {t('santa.their_list', { name: me.giftee.name })}
                    </h2>

                    {me.giftee.wishes.length === 0 ? (
                        <div className="mt-3 rounded-card border border-line bg-card p-6 text-sm">
                            <p>{t('santa.no_list', { name: me.giftee.name })}</p>
                            <a
                                href={`${base}/gift`}
                                className="mt-3 inline-block rounded-lg border border-line px-4 py-2"
                            >
                                {t('gift.title')}
                            </a>
                        </div>
                    ) : (
                        <ul className="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                            {me.giftee.wishes.map((wish) => (
                                <li key={wish.id} className="flex items-center gap-4 p-4">
                                    {wish.image && (
                                        <img
                                            src={wish.image}
                                            alt=""
                                            loading="lazy"
                                            className="h-14 w-14 shrink-0 object-contain"
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{wish.title}</p>
                                        {wish.price !== null && !wish.live && (
                                            <p className="text-sm text-ink-soft">
                                                {formatPrice(wish.price, market.currency)}
                                            </p>
                                        )}
                                    </div>

                                    {/* Claiming still matters inside a group:
                                        families overlap, and several people may
                                        hold the same person's link. */}
                                    {wish.claimed ? (
                                        <span className="shrink-0 text-sm text-ink-soft">
                                            {t('lists.claimed_by_someone')}
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/l/${wish.token}/claim/${wish.id}`,
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

                    {!me.done && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    `${base}/santa/${group.id}/me/${me.joinToken}/done`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="mt-6 rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('santa.mark_done')}
                        </button>
                    )}
                </section>
            )}
        </>
    )
}
