import { Head, Link, router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { buttonClasses } from '../../Components/Button'
import PlayDialog from '../../Components/PlayDialog'
import SaveToList from '../../Components/SaveToList'
import SwipeCard from '../../Components/SwipeCard'
import type { ListKind } from '../../Components/ListKindBadge'
import ToolIcon from '../../Components/ToolIcon'
import { send } from '../../http'
import { pictureAttributes } from '../../imageUrl'
import { listFrom, show as showToast } from '../../saveToast'
import { markSaved } from '../../savedItems'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Card {
    id: number
    title: string
    brand: string | null
    image: string | null
    /** Signed by the server for the image proxy's larger copies; null when it may not serve this one. */
    imageToken?: string | null
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
 *
 * ## A popup, not a page (owner, 2026-09-29)
 *
 * "Bigger pictures, no scrolling... maybe a popup?" On a phone the site's
 * header above and footer below left a small card in a page that scrolled.
 * So the whole thing is a dialog over the page: the full screen on a phone,
 * a tall panel over a dimmed page from `sm` up. The card takes every pixel
 * the top bar and the two round buttons leave, the picture most of it, and
 * the page behind does not scroll while it is open. Its close button is Stop.
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
        if (stopped) {
            router.visit(urls.finder)
        } else if (list) {
            router.visit(`${base}/lists/${list.id}`)
        } else if (chosen.length > 0 && !auth.user) {
            setStopped(true)
        } else {
            router.visit(urls.finder)
        }
    }

    // Arrow keys: right onto the list, left to pass. Escape is Stop (PlayDialog).
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

    const count = list
        ? t('gift.swipe.saved_count', { count: String(saved), list: list.title })
        : chosen.length > 0
          ? t('gift.swipe.liked_count', { count: String(chosen.length) })
          : null

    return (
        <>
            <Head title={t('gift.swipe.title')} />

            <PlayDialog
                icon="swipe"
                title={t('gift.swipe.title')}
                // For whom and how many; the labelled buttons below say what to do.
                subtitle={[who ? t('gift.for_label', { who }) : null, count].filter(Boolean).join(' · ') || null}
                onClose={stop}
                closeLabel={t('gift.swipe.stop')}
                aside={
                    list && (
                        <Link href={`${base}/lists/${list.id}`} className="shrink-0 text-sm font-medium text-accent-dark underline">
                            {t('gift.swipe.to_list')}
                        </Link>
                    )
                }
            >
                    {failed && (
                        <p role="alert" className="px-4 text-sm text-danger">
                            {t('gift.swipe.save_failed')}
                        </p>
                    )}

                    {stopped ? (
                        <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
                            <Chosen cards={chosen} finder={urls.finder} />
                        </div>
                    ) : current ? (
                        <>
                            {/* Every pixel the bars leave, most of it the picture. */}
                            <div className="flex min-h-0 flex-1 justify-center px-3 pt-1">
                                <SwipeCard
                                    key={current.id}
                                    label={t('gift.swipe.card_label', { title: current.title })}
                                    onVerdict={verdict}
                                    yesLabel={t('gift.swipe.yes')}
                                    noLabel={t('gift.swipe.no')}
                                    className="h-full w-full"
                                >
                                    <CardPicture card={current} />
                                    <span className="mt-3 shrink-0">
                                        {current.brand && <span className="block text-xs text-ink-soft">{current.brand}</span>}
                                        <span className="line-clamp-2 font-medium">{current.title}</span>
                                        {current.price !== null && (
                                            <span className="mt-1 block text-sm text-ink-soft">{formatPrice(current.price, market)}</span>
                                        )}
                                    </span>
                                </SwipeCard>
                            </div>

                            {/* Two round buttons within a thumb's reach: the swipe's equals, never replaced by it. */}
                            <div className="flex shrink-0 items-start justify-center gap-10 px-4 pt-3 pb-4">
                                <button
                                    type="button"
                                    onClick={() => verdict('no')}
                                    className="flex flex-col items-center gap-1 text-xs text-ink-soft"
                                >
                                    <span className="flex h-16 w-16 items-center justify-center rounded-full border-2 border-line bg-card text-ink shadow-sm hover:border-ink">
                                        <ToolIcon name="close" className="h-7 w-7" />
                                    </span>
                                    {t('gift.swipe.no_button')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => verdict('yes')}
                                    className="flex flex-col items-center gap-1 text-xs text-ink-soft"
                                >
                                    <span className="flex h-16 w-16 items-center justify-center rounded-full bg-accent text-white shadow-sm hover:bg-accent-dark">
                                        <ToolIcon name="wishlist" className="h-7 w-7" />
                                    </span>
                                    {t('gift.swipe.yes_button')}
                                </button>
                            </div>
                            <p className="hidden shrink-0 pb-3 text-center text-xs text-ink-soft sm:block">{t('gift.swipe.keys_hint')}</p>
                        </>
                    ) : fetching ? (
                        <p className="flex flex-1 items-center justify-center text-ink-soft">
                            {t('gift.swipe.loading')}
                            <span className="motion-safe:animate-pulse">...</span>
                        </p>
                    ) : (
                        <div className="min-h-0 flex-1 overflow-y-auto px-4 pt-6 pb-4 text-center">
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
            </PlayDialog>
        </>
    )
}

/**
 * The picture, as large as the card allows: the image proxy's copies up to
 * 960 wide when the server signed one, the shop's own picture otherwise.
 */
function CardPicture({ card }: { card: Card }) {
    const [proxyFailed, setProxyFailed] = useState(false)

    if (!card.image) {
        return <span className="min-h-0 flex-1" />
    }

    return (
        <span className="flex min-h-0 flex-1 items-center justify-center">
            <img
                {...pictureAttributes(card.image, card.imageToken, proxyFailed, 640, '(min-width: 640px) 448px, 100vw', [480, 640, 960])}
                onError={() => setProxyFailed(true)}
                alt=""
                draggable={false}
                // Filling the box, not only shrinking into it: a shop's small
                // picture grows to the card rather than floating in white.
                className="h-full w-full object-contain"
            />
        </span>
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
