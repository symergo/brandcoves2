import { Head, Link, router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import Button, { buttonClasses } from '../../Components/Button'
import PageHeader from '../../Components/PageHeader'
import SaveToList from '../../Components/SaveToList'
import SwipeCard from '../../Components/SwipeCard'
import type { ListKind } from '../../Components/ListKindBadge'
import ToolIcon from '../../Components/ToolIcon'
import { send } from '../../http'
import { listFrom, show as showToast } from '../../saveToast'
import { markSaved } from '../../savedItems'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Card {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
}

interface Props {
    /** Who "Find a gift" said this is for; see App\Services\Gift\CarriedWho. */
    carried: { person: { id: string; name: string } | null; relationship: string | null; forMe: boolean }
    cards: Card[]
    urls: { next: string; finder: string }
}

interface SaveResult {
    itemId: number
    listId: string
    listTitle: string
    listKind?: ListKind
    messageTemplate?: string
    message: string
}

/** Ask for more while this many cards are still waiting, so the next is ready. */
const LOW_WATER = 3

/** What the next request carries at most; the server caps the same. */
const MAX_SWIPES = 200
const MAX_EXCLUDE = 400

/**
 * Swipe gifts: one product at a time, right onto the list, left to pass
 * (owner, 2026-09-28). No end but the visitor's own: the page asks for more
 * whenever it runs low, and Stop leaves.
 *
 * A right swipe saves straight away for a signed-in visitor, through the same
 * `POST /list-items` as every Save button, so the toast and its Undo are the
 * site's own. Which list: the saved person's (made on the first right swipe,
 * never on opening the page), a list for the relationship picked, and for
 * yourself or nobody in particular the one a Save button would pick. A
 * visitor who is not signed in keeps what they chose on the page, and Stop
 * shows it with a Save button each, which is where signing in is asked.
 *
 * The swipes are only in this page's state, as in This or that: a half
 * finished session is not worth a row. See docs/features/swipe-gifts.md.
 */
export default function Swipe({ carried, cards: first, urls }: Props) {
    const { t } = useTranslations()
    const { market, auth, savingTo } = usePage<SharedProps>().props
    const base = `/${market.key}`

    const [queue, setQueue] = useState<Card[]>(first)
    const [yes, setYes] = useState<number[]>([])
    const [no, setNo] = useState<number[]>([])
    const [chosen, setChosen] = useState<Card[]>([])
    const [fetching, setFetching] = useState(false)
    const [exhausted, setExhausted] = useState(first.length === 0)
    const [stopped, setStopped] = useState(false)
    const [failed, setFailed] = useState(false)
    // Where the right swipes went: learned from the first save.
    const [list, setList] = useState<{ id: string; title: string } | null>(null)
    const [saved, setSaved] = useState(0)
    const listRequest = useRef<Promise<string | undefined> | null>(null)
    const seen = useRef<number[]>(first.map((c) => c.id))

    const current = queue[0]

    const more = useCallback(() => {
        if (fetching || exhausted) {
            return
        }

        setFetching(true)
        send<{ cards: Card[] }>(urls.next, 'POST', {
            yes: yes.slice(-MAX_SWIPES),
            no: no.slice(-MAX_SWIPES),
            exclude: seen.current.slice(-MAX_EXCLUDE),
            recipient_id: carried.person?.id ?? null,
        })
            .then(({ cards }) => {
                const fresh = cards.filter((c) => !seen.current.includes(c.id))
                seen.current = [...seen.current, ...fresh.map((c) => c.id)]
                setQueue((q) => [...q, ...fresh])

                if (fresh.length === 0) {
                    setExhausted(true)
                }
            })
            .catch(() => setExhausted(true))
            .finally(() => setFetching(false))
    }, [fetching, exhausted, urls.next, yes, no, carried.person])

    useEffect(() => {
        if (!stopped && queue.length <= LOW_WATER) {
            more()
        }
    }, [queue.length, stopped, more])

    /**
     * The list a right swipe lands on. Asked for once, on the first right
     * swipe; undefined leaves the choice to the save itself, as a Save button
     * with nothing chosen does.
     */
    const target = (): Promise<string | undefined> => {
        if (list) {
            return Promise.resolve(list.id)
        }

        listRequest.current ??= carried.person
            ? send<{ id: string }>(`${base}/people/${carried.person.id}/list`, 'POST').then((l) => l.id)
            : carried.relationship
              ? send<{ id: string }>(`${base}/people/for-relationship/list`, 'POST', {
                    relationship: carried.relationship,
                }).then((l) => l.id)
              : Promise.resolve(savingTo?.id)

        return listRequest.current
    }

    const save = (card: Card) => {
        setFailed(false)
        target()
            .then((wishlistId) =>
                send<SaveResult>(`${base}/list-items`, 'POST', {
                    group_id: card.id,
                    ...(wishlistId ? { wishlist_id: wishlistId } : {}),
                }),
            )
            .then((result) => {
                setList({ id: result.listId, title: result.listTitle })
                setSaved((n) => n + 1)
                markSaved(card.id, result.listId, result.itemId)
                showToast({
                    message: result.message,
                    list: listFrom(result),
                    tone: 'ok',
                    undo: { itemId: result.itemId, groupId: card.id },
                    listId: result.listId,
                })
            })
            .catch(() => {
                listRequest.current = null
                setFailed(true)
            })
    }

    const verdict = (value: 'yes' | 'no') => {
        if (!current) {
            return
        }

        if (value === 'yes') {
            setYes((ids) => [...ids, current.id])
            setChosen((c) => [...c, current])

            if (auth.user) {
                save(current)
            }
        } else {
            setNo((ids) => [...ids, current.id])
        }

        setQueue((q) => q.slice(1))
    }

    /**
     * Stop: to the list the right swipes went to, or back to Find a gift
     * when nothing was chosen. Only a visitor who is not signed in and chose
     * something stays, to save what they chose.
     */
    const stop = () => {
        if (list) {
            router.visit(`${base}/lists/${list.id}`)
        } else if (chosen.length > 0 && !auth.user) {
            setStopped(true)
        } else {
            router.visit(urls.finder)
        }
    }

    // Arrow keys: right onto the list, left to pass.
    useEffect(() => {
        if (stopped || !current) {
            return
        }

        const onKey = (event: KeyboardEvent) => {
            const el = event.target as HTMLElement | null

            if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) {
                return
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault()
                verdict('yes')
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault()
                verdict('no')
            }
        }

        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    })

    const who = carried.forMe
        ? t('gift.who_me_label')
        : (carried.person?.name ?? (carried.relationship ? t(`gift.relationships.${carried.relationship}`) : null))

    return (
        <>
            <Head title={t('gift.swipe.title')} />

            <PageHeader
                className="max-w-2xl"
                icon={
                    <span className="mr-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <ToolIcon name="swipe" className="h-6 w-6" />
                    </span>
                }
                title={t('gift.swipe.title')}
            >
                <p className="mt-2 text-ink-soft">{t('gift.swipe.subtitle')}</p>
                {who && (
                    <p className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        <span className="rounded-full bg-accent/10 px-3 py-1 font-medium text-accent-dark">
                            {t('gift.for_label', { who })}
                        </span>
                        <Link href={urls.finder} className="text-accent underline">
                            {t('gift.change')}
                        </Link>
                    </p>
                )}
            </PageHeader>

            <section className="mx-auto mt-6 max-w-md">
                {/* The way out is always on screen: the owner's "a way to stop". */}
                <div className="flex items-center justify-between gap-3 text-sm">
                    <span className="text-ink-soft">
                        {list ? (
                            <Link href={`${base}/lists/${list.id}`} className="hover:underline">
                                {t('gift.swipe.saved_count', { count: String(saved), list: list.title })}
                            </Link>
                        ) : chosen.length > 0 ? (
                            t('gift.swipe.liked_count', { count: String(chosen.length) })
                        ) : null}
                    </span>
                    {!stopped && (
                        <Button variant="secondary" onClick={stop}>
                            {t('gift.swipe.stop')}
                        </Button>
                    )}
                </div>

                {failed && (
                    <p role="alert" className="mt-3 text-sm text-danger">
                        {t('gift.swipe.save_failed')}
                    </p>
                )}

                {stopped ? (
                    <Chosen cards={chosen} finder={urls.finder} />
                ) : current ? (
                    <>
                        <div className="mt-4 flex justify-center">
                            <SwipeCard
                                key={current.id}
                                label={t('gift.swipe.card_label', { title: current.title })}
                                onVerdict={verdict}
                                yesLabel={t('gift.swipe.yes')}
                                noLabel={t('gift.swipe.no')}
                            >
                                <span className="flex h-56 items-center justify-center sm:h-64">
                                    {current.image && (
                                        <img
                                            src={current.image}
                                            alt=""
                                            draggable={false}
                                            className="max-h-full max-w-full object-contain"
                                        />
                                    )}
                                </span>
                                {current.brand && <span className="mt-3 text-xs text-ink-soft">{current.brand}</span>}
                                <span className="mt-1 line-clamp-3 font-medium">{current.title}</span>
                                {current.price !== null && (
                                    <span className="mt-2 text-sm text-ink-soft">{formatPrice(current.price, market)}</span>
                                )}
                            </SwipeCard>
                        </div>

                        <div className="mt-5 flex items-center justify-center gap-3">
                            <Button variant="secondary" onClick={() => verdict('no')} className="min-w-28">
                                <span aria-hidden>←</span> {t('gift.swipe.no_button')}
                            </Button>
                            <Button onClick={() => verdict('yes')} className="min-w-28">
                                {t('gift.swipe.yes_button')} <span aria-hidden>→</span>
                            </Button>
                        </div>

                        <p className="mt-6 text-center text-xs text-ink-soft">
                            <span className="sm:hidden">{t('gift.swipe.swipe_hint')}</span>
                            <span className="hidden sm:inline">{t('gift.swipe.keys_hint')}</span>
                        </p>
                    </>
                ) : fetching ? (
                    <p className="mt-10 text-center text-ink-soft">
                        {t('gift.swipe.loading')}
                        <span className="motion-safe:animate-pulse">...</span>
                    </p>
                ) : (
                    <div className="mt-10 text-center">
                        <p className="text-ink-soft">{t('gift.swipe.empty')}</p>
                        {chosen.length > 0 && !auth.user ? (
                            <Chosen cards={chosen} finder={urls.finder} />
                        ) : (
                            <Link
                                href={list ? `${base}/lists/${list.id}` : urls.finder}
                                className={`${buttonClasses('secondary')} mt-4`}
                            >
                                {list ? t('gift.swipe.to_list') : t('gift.swipe.back')}
                            </Link>
                        )}
                    </div>
                )}
            </section>
        </>
    )
}

/** What a visitor who is not signed in chose, each with the site's Save button. */
function Chosen({ cards, finder }: { cards: Card[]; finder: string }) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props

    return (
        <div className="mt-6 text-left">
            <h2 className="text-lg font-medium">{t('gift.swipe.done_title')}</h2>
            <p className="mt-1 text-sm text-ink-soft">{t('gift.swipe.done_hint')}</p>
            <ul className="mt-4 space-y-3">
                {cards.map((card) => (
                    <li key={card.id} className="flex items-center gap-3 rounded-card border border-line bg-card p-3">
                        <span className="flex h-14 w-14 shrink-0 items-center justify-center">
                            {card.image && <img src={card.image} alt="" className="max-h-full max-w-full object-contain" />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="line-clamp-2 text-sm font-medium">{card.title}</span>
                            {card.price !== null && (
                                <span className="text-xs text-ink-soft">{formatPrice(card.price, market)}</span>
                            )}
                        </span>
                        <SaveToList groupId={card.id} compact />
                    </li>
                ))}
            </ul>
            <Link href={finder} className={`${buttonClasses('ghost')} mt-4`}>
                {t('gift.swipe.back')}
            </Link>
        </div>
    )
}
