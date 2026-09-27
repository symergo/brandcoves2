import { Head, Link, router, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import Button, { buttonClasses } from '../../Components/Button'
import GiftResults, { type GiftPick, type GiftResultsExtras } from '../../Components/GiftResults'
import ShareRow from '../../Components/ShareRow'
import SignInLink from '../../Components/SignInLink'
import ToolIcon from '../../Components/ToolIcon'
import PageHeader from '../../Components/PageHeader'
import PersonPicker, { type PickablePerson } from '../../Components/PersonPicker'
import { send } from '../../http'
import { formatBudget, formatPrice, type Cents, type SavingTo, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Card {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
}

/** One round: two cards ("this or that") or one (like or dislike). */
type Round = Card[]

/**
 * What the page sends back: ids and what was pressed, never tags. A type
 * rather than an interface so Inertia accepts it as form data.
 */
type Choice = {
    shown: number[]
    picked?: number | null
    verdict?: 'like' | 'dislike' | null
}

interface Profile {
    interests: string[]
    avoid: string[]
    budgetMin: Cents | null
    budgetMax: Cents | null
    vibe: string | null
    preferences: string[]
    values: string[]
    answered: number
}

interface Result extends GiftResultsExtras {
    profile: Profile
    thin: boolean
    picks: GiftPick[]
    choices: Choice[]
    for: 'someone' | 'me'
    /** Played through a "help me find out" link: whether this run was kept with the others. */
    recorded?: boolean
    /** The chosen person's list, where a save lands (a giver with a saved person only). */
    into?: SavingTo | null
    /**
     * What was learned, as the answers the questions post: "Refine with the
     * questions" opens the same board with Adjust and Eight more beside it.
     * A giver's result only.
     */
    refine?: Record<string, unknown>
}

interface Props {
    /**
     * `giver` at /gift/taste; `self` at /for/{token}/taste, the person
     * themselves; `together` at /t/{token}, one of several people playing
     * about somebody (docs/features/taste-together.md).
     */
    mode: 'giver' | 'self' | 'together'
    person: { name: string } | null
    /** `card` makes a gift profile card; empty where there is none to make. */
    urls: { next: string; result: string; save: string; restart: string; finder: string; card?: string }
    total: number
    rounds: Round[]
    result: Result | null
    recipients: { id: string; name: string }[]
    /** My people's rows, friends included, for "Bewaar bij …". Signed in, on the result only. */
    people?: PickablePerson[]
    canCreate: boolean
    /** A "help me find out" link that already has as many players as it takes. */
    full?: boolean
    /**
     * Who "Find a gift" already said this is for: one of your people, or a
     * kind of person. Either one skips "for someone or for yourself?".
     * A giver's page only.
     */
    carried?: { person: { id: string; name: string } | null; relationship: string | null }
}

/** Rounds answered (not skipped) before "Show the result" is offered. */
const MIN_ANSWERED = 3

/** How far a single card has to travel before a swipe counts, in pixels. */
const SWIPE_DISTANCE = 90

/**
 * This or that: taste discovery by choosing.
 *
 * The rounds and the choices live here, in component state, and nowhere
 * else until the end: a choice is about a real person and a half-finished
 * session is not worth a row. The page asks the server for the next few
 * rounds while two are still left, so the next pair is ready before it is
 * needed. See docs/features/taste-discovery.md.
 *
 * Every choice can be made three ways, and the buttons are always there:
 * tapping or clicking a card (or its buttons), the arrow keys, and on a
 * single card a swipe. A swipe is a shortcut for the buttons, never the only
 * way, and the fly-away animation is skipped for reduced motion.
 */
export default function Taste(props: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('gift.taste.title')}>
                {props.mode !== 'giver' && <meta name="robots" content="noindex, nofollow" />}
            </Head>

            <PageHeader
                className="max-w-2xl"
                icon={
                    <span className="mr-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <ToolIcon name="taste" className="h-6 w-6" />
                    </span>
                }
                title={t('gift.taste.title')}
            >
                <p className="mt-2 text-ink-soft">
                    {props.mode === 'together'
                        ? t('gift.together.subtitle', { name: props.person?.name ?? '' })
                        : t(props.mode === 'self' ? 'gift.taste.self_subtitle' : 'gift.taste.subtitle')}
                </p>
                {props.mode === 'together' && !props.result && (
                    <p className="mt-2 text-sm text-ink-soft">{t('gift.together.privacy')}</p>
                )}
                <CarriedLine carried={props.carried} finder={props.urls.finder} />
            </PageHeader>

            {props.result ? (
                <Outcome {...props} result={props.result} />
            ) : props.full ? (
                <p className="mt-8 max-w-2xl rounded-card border border-line bg-card p-5 text-ink-soft">
                    {t('gift.together.full', { name: props.person?.name ?? '' })}
                </p>
            ) : (
                <Play {...props} />
            )}
        </>
    )
}

/**
 * "For Mum · change": who "Find a gift" said this is for, and the way back
 * to its first question. Nothing when nobody was said.
 */
function CarriedLine({ carried, finder }: { carried?: Props['carried']; finder: string }) {
    const { t } = useTranslations()

    const who = carried?.person?.name ?? (carried?.relationship ? t(`gift.relationships.${carried.relationship}`) : null)

    if (!who) {
        return null
    }

    return (
        <p className="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span className="rounded-full bg-accent/10 px-3 py-1 font-medium text-accent-dark">{t('gift.for_label', { who })}</span>
            <Link href={finder} className="text-accent underline">
                {t('gift.change')}
            </Link>
        </p>
    )
}

function Play({ mode, urls, total, rounds, carried }: Props) {
    const { t } = useTranslations()
    const page = usePage<SharedProps>()
    const { market } = page.props
    /*
     * Choosing for one of your saved people: from "Find a gift" or from their
     * page (`?person=<id>`, which the server checked is yours and sent back
     * as `carried`). The server leaves out what they were already given
     * (gift-history.md). A kind of person travels as its value.
     */
    const person = carried?.person?.id ?? null
    const relationship = carried?.relationship ?? null

    // The person themselves is always "you", a player on a shared link always
    // "they"; a giver says who first, unless "Find a gift" already asked.
    const [forWhom, setForWhom] = useState<'someone' | 'me' | null>(
        mode === 'self' ? 'me' : mode === 'together' || person || relationship ? 'someone' : null,
    )
    const [queue, setQueue] = useState<Round[]>(rounds)
    const [index, setIndex] = useState(0)
    const [choices, setChoices] = useState<Choice[]>([])
    const [fetching, setFetching] = useState(false)
    const [exhausted, setExhausted] = useState(rounds.length === 0)
    const [finishing, setFinishing] = useState(false)

    const answered = choices.filter((c) => c.picked != null || c.verdict != null).length
    const current: Round | undefined = queue[index]

    const finish = useCallback(
        (all: Choice[]) => {
            if (all.length === 0) {
                return
            }

            setFinishing(true)
            router.post(
                urls.result,
                {
                    choices: all,
                    for: forWhom ?? 'someone',
                    ...(person ? { recipient_id: person } : {}),
                    ...(relationship && !person ? { relationship } : {}),
                },
                { onFinish: () => setFinishing(false) },
            )
        },
        [urls.result, forWhom, person, relationship],
    )

    /*
     * The next rounds, asked for while two are still left. The server composes
     * them from the choices so far (what has been learned decides what is
     * shown next) and is told every id already queued, so nothing repeats.
     */
    useEffect(() => {
        if (forWhom === null || fetching || exhausted || queue.length >= total || queue.length - index > 2) {
            return
        }

        setFetching(true)
        send<{ rounds: Round[] }>(urls.next, 'POST', {
            choices,
            exclude: queue.flat().map((card) => card.id),
            from: queue.length,
        })
            .then(({ rounds: more }) => {
                if (more.length === 0) {
                    setExhausted(true)
                } else {
                    setQueue((q) => [...q, ...more])
                }
            })
            .catch(() => setExhausted(true))
            .finally(() => setFetching(false))
    }, [forWhom, fetching, exhausted, queue, index, total, choices, urls.next])

    // Out of rounds: the result, from whatever was answered.
    useEffect(() => {
        if (!finishing && forWhom !== null && current === undefined && !fetching && (exhausted || index >= total)) {
            finish(choices)
        }
    }, [current, fetching, exhausted, index, total, choices, finish, finishing, forWhom])

    const answer = useCallback(
        (choice: Choice) => {
            const all = [...choices, choice]
            setChoices(all)
            setIndex((i) => i + 1)

            if (all.length >= total) {
                finish(all)
            }
        },
        [choices, total, finish],
    )

    const shownIds = () => (current ?? []).map((c) => c.id)

    const pick = (card: Card) => {
        if (current) {
            answer({ shown: shownIds(), picked: card.id })
        }
    }

    const verdict = (value: 'like' | 'dislike') => {
        if (current) {
            answer({ shown: shownIds(), verdict: value })
        }
    }

    const skip = () => {
        if (current) {
            answer({ shown: shownIds() })
        }
    }

    // Arrow keys: left and right choose (or no and yes on one card), down skips.
    useEffect(() => {
        if (forWhom === null || !current || finishing) {
            return
        }

        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null

            if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
                return
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault()
                if (current.length === 2) {
                    pick(current[0])
                } else {
                    verdict('dislike')
                }
            } else if (event.key === 'ArrowRight') {
                event.preventDefault()
                if (current.length === 2) {
                    pick(current[1])
                } else {
                    verdict('like')
                }
            } else if (event.key === 'ArrowDown') {
                event.preventDefault()
                skip()
            }
        }

        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    })

    if (forWhom === null) {
        return (
            <section className="mt-8">
                <h2 className="text-lg font-medium">{t('gift.taste.who_title')}</h2>
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    {(['someone', 'me'] as const).map((who) => (
                        <button
                            key={who}
                            type="button"
                            disabled={rounds.length === 0}
                            onClick={() => setForWhom(who)}
                            className="rounded-card border border-line bg-card p-5 text-left transition hover:border-ink disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span className="block font-medium">{t(`gift.taste.for_${who}`)}</span>
                            <span className="mt-1 block text-sm text-ink-soft">{t(`gift.taste.for_${who}_hint`)}</span>
                        </button>
                    ))}
                </div>
                {rounds.length === 0 && <Empty finder={urls.finder} />}
            </section>
        )
    }

    if (rounds.length === 0) {
        return (
            <section className="mt-8">
                <Empty finder={urls.finder} />
            </section>
        )
    }

    const shown = Math.min(index + 1, total)

    return (
        <section className="mx-auto mt-6 max-w-2xl" aria-busy={finishing || (current === undefined && fetching)}>
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-xs text-ink-soft" aria-live="polite">
                    {t('gift.taste.round', { current: shown, total })}
                </p>
                <button
                    type="button"
                    disabled={answered < MIN_ANSWERED || finishing}
                    onClick={() => finish(choices)}
                    className="text-sm text-accent underline disabled:cursor-not-allowed disabled:no-underline disabled:opacity-40"
                >
                    {t('gift.taste.done')}
                </button>
            </div>

            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-line" aria-hidden>
                <div
                    className="h-full rounded-full bg-accent motion-safe:transition-[width] motion-safe:duration-300"
                    style={{ width: `${(Math.min(index, total) / total) * 100}%` }}
                />
            </div>

            {current === undefined || finishing ? (
                <p className="mt-10 text-center text-ink-soft">
                    {t('gift.taste.loading')}
                    <span className="motion-safe:animate-pulse">...</span>
                </p>
            ) : (
                <>
                    <h2 className="mt-5 text-lg font-medium">
                        {t(`gift.taste.ask_${current.length === 2 ? 'pair' : 'single'}_${forWhom}`)}
                    </h2>

                    {current.length === 2 ? (
                        <div key={current.map((c) => c.id).join('-')} className="mt-4 grid grid-cols-2 gap-3 sm:gap-5">
                            {current.map((card) => (
                                <button
                                    key={card.id}
                                    type="button"
                                    onClick={() => pick(card)}
                                    aria-label={t('gift.taste.pick_label', { title: card.title })}
                                    className="group flex flex-col rounded-card border border-line bg-card p-3 text-left transition hover:border-accent focus-visible:border-accent sm:p-4 motion-safe:hover:-translate-y-0.5"
                                >
                                    <CardFace card={card} market={market} />
                                </button>
                            ))}
                        </div>
                    ) : (
                        <Single
                            key={current[0].id}
                            card={current[0]}
                            onVerdict={verdict}
                            likeLabel={t('gift.taste.like')}
                            dislikeLabel={t('gift.taste.dislike')}
                        />
                    )}

                    <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
                        {current.length === 1 && (
                            <Button variant="secondary" onClick={() => verdict('dislike')} className="min-w-24">
                                <span aria-hidden>←</span> {t('gift.taste.dislike')}
                            </Button>
                        )}
                        <Button variant="ghost" onClick={skip}>
                            {t('gift.taste.skip')}
                        </Button>
                        {current.length === 1 && (
                            <Button onClick={() => verdict('like')} className="min-w-24">
                                {t('gift.taste.like')} <span aria-hidden>→</span>
                            </Button>
                        )}
                    </div>

                    <p className="mt-6 text-center text-xs text-ink-soft">
                        <span className="sm:hidden">{t('gift.taste.swipe_hint')}</span>
                        <span className="hidden sm:inline">{t('gift.taste.keys_hint')}</span>
                    </p>
                </>
            )}
        </section>
    )
}

function CardFace({ card, market }: { card: Card; market: SharedProps['market'] }) {
    return (
        <>
            <span className="flex h-32 items-center justify-center sm:h-48">
                {card.image && (
                    <img src={card.image} alt="" draggable={false} className="max-h-full max-w-full object-contain" />
                )}
            </span>
            <span className="mt-3 line-clamp-3 text-sm font-medium">{card.title}</span>
            {card.price !== null && (
                <span className="mt-auto pt-2 text-sm text-ink-soft">{formatPrice(card.price, market)}</span>
            )}
        </>
    )
}

/**
 * One card, like or dislike, that can also be swiped.
 *
 * `touch-action: pan-y` keeps the page scrolling vertically under a finger;
 * only a sideways drag moves the card. The card follows the finger either
 * way (that is the finger, not an animation), and only the fly-away at the
 * end is skipped when the visitor asked for reduced motion.
 */
function Single({
    card,
    onVerdict,
    likeLabel,
    dislikeLabel,
}: {
    card: Card
    onVerdict: (v: 'like' | 'dislike') => void
    likeLabel: string
    dislikeLabel: string
}) {
    const { market } = usePage<SharedProps>().props
    const [dx, setDx] = useState(0)
    const [leaving, setLeaving] = useState<'like' | 'dislike' | null>(null)
    const start = useRef<number | null>(null)

    const reduced =
        typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

    const release = () => {
        const moved = dx
        start.current = null

        if (Math.abs(moved) < SWIPE_DISTANCE) {
            setDx(0)
            return
        }

        const value = moved > 0 ? 'like' : 'dislike'

        if (reduced) {
            onVerdict(value)
            return
        }

        setLeaving(value)
        window.setTimeout(() => onVerdict(value), 180)
    }

    const offset = leaving === null ? dx : leaving === 'like' ? 600 : -600

    return (
        <div className="mt-4 flex justify-center">
            <div
                role="group"
                aria-label={card.title}
                onPointerDown={(e: ReactPointerEvent<HTMLDivElement>) => {
                    start.current = e.clientX
                    e.currentTarget.setPointerCapture(e.pointerId)
                }}
                onPointerMove={(e) => start.current !== null && setDx(e.clientX - start.current)}
                onPointerUp={release}
                onPointerCancel={() => {
                    start.current = null
                    setDx(0)
                }}
                style={{
                    transform: `translateX(${offset}px) rotate(${offset / 25}deg)`,
                    transition: start.current !== null || reduced ? 'none' : 'transform 180ms ease-out',
                    touchAction: 'pan-y',
                }}
                className="relative flex w-full max-w-xs cursor-grab flex-col rounded-card border border-line bg-card p-4 select-none active:cursor-grabbing"
            >
                <CardFace card={card} market={market} />
                <span
                    aria-hidden
                    style={{ opacity: Math.min(Math.max(dx, 0) / SWIPE_DISTANCE, 1) }}
                    className="absolute top-3 left-3 rounded-full bg-sage px-3 py-1 text-sm font-medium text-white"
                >
                    {likeLabel}
                </span>
                <span
                    aria-hidden
                    style={{ opacity: Math.min(Math.max(-dx, 0) / SWIPE_DISTANCE, 1) }}
                    className="absolute top-3 right-3 rounded-full bg-ink px-3 py-1 text-sm font-medium text-cream"
                >
                    {dislikeLabel}
                </span>
            </div>
        </div>
    )
}

function Empty({ finder }: { finder: string }) {
    const { t } = useTranslations()

    return (
        <p className="mt-6 rounded-card border border-line bg-card p-5 text-ink-soft">
            {t('gift.taste.empty')}{' '}
            <Link href={finder} className="text-accent underline">
                {t('gift.taste.open_finder')}
            </Link>
        </p>
    )
}

/** "a", "a and b", "a, b and c", in the reader's language. */
function joinList(items: string[], and: string): string {
    if (items.length <= 1) {
        return items.join('')
    }

    return `${items.slice(0, -1).join(', ')} ${and} ${items[items.length - 1]}`
}

function Outcome({ mode, person, urls, result, recipients, people = [], canCreate, carried }: Props & { result: Result }) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const { profile, picks } = result
    const me = result.for === 'me'

    const and = t('gift.taste.and')
    const interest = (v: string) => {
        const label = t(`gift.interests.${v}`)
        return label === `gift.interests.${v}` ? v : label
    }

    const budget =
        profile.budgetMin !== null && profile.budgetMax !== null
            ? t('gift.taste.budget', {
                  min: formatBudget(profile.budgetMin, market),
                  max: formatBudget(profile.budgetMax, market),
              })
            : null

    const style = [
        ...(profile.vibe ? [t(`gift.vibes.${profile.vibe}`)] : []),
        ...profile.preferences.map((p) => t(`gift.preferences.${p}`)),
        ...profile.values.map((v) => t(`gift.values.${v}`)),
    ]

    /*
     * The profile in plain words, one fact a line. The interests and the
     * budget share a line because that is how people say it: "into cooking
     * and coffee, around 30 to 60 euro".
     */
    const lines: string[] = []

    if (profile.interests.length > 0) {
        const loves = t(me ? 'gift.taste.loves_me' : 'gift.taste.loves', {
            list: joinList(profile.interests.map(interest), and),
        })
        lines.push(budget ? `${loves}, ${budget}.` : `${loves}.`)
    } else if (budget) {
        lines.push(`${budget.charAt(0).toUpperCase()}${budget.slice(1)}.`)
    }

    if (style.length > 0) {
        lines.push(`${t(me ? 'gift.taste.style_me' : 'gift.taste.style', { list: joinList(style, and) })}.`)
    }

    if (profile.avoid.length > 0) {
        lines.push(`${t('gift.taste.not_into', { list: joinList(profile.avoid.map(interest), and) })}.`)
    }

    const learnedNothing = profile.interests.length === 0 && budget === null && profile.avoid.length === 0

    /*
     * "Choose again" keeps who it is for, so a second round is about the
     * same person without going back through "Find a gift".
     */
    const restart = (() => {
        if (mode !== 'giver') {
            return urls.restart
        }

        if (carried?.person) {
            return `${urls.restart}?person=${encodeURIComponent(carried.person.id)}`
        }

        return carried?.relationship ? `${urls.restart}?relationship=${encodeURIComponent(carried.relationship)}` : urls.restart
    })()

    /*
     * The same results page the questions end on (GiftResults), with what
     * was learned on top and the ways to keep it. On the person's own page
     * and on a shared "help me find out" link, only the ideas: the person is
     * describing themselves there, and a player is not shopping.
     */
    const giver = mode === 'giver'

    return (
        <GiftResults
            picks={picks}
            heading={t('gift.taste.ideas_title')}
            note={me && giver ? <p className="mt-1 text-sm text-ink-soft">{t('gift.taste.me_hint')}</p> : undefined}
            emptyText={t('gift.taste.no_ideas')}
            forMe={me}
            canSave={giver}
            into={giver ? (result.into ?? null) : null}
            personName={giver ? (carried?.person?.name ?? null) : null}
            pageUrl={giver ? result.pageUrl : null}
            offlineIdeas={giver ? result.offlineIdeas : []}
            communityCoves={giver ? result.communityCoves : []}
            nextSteps={giver ? result.nextSteps : []}
            personUrl={giver ? result.personUrl : null}
            askUrl={giver ? result.askUrl : null}
            // Thumbs only for a giver: on the person's own page and a shared
            // link nobody is shopping. About the saved person when there is one.
            thumbs={giver ? { recipientId: carried?.person?.id ?? null, relationship: carried?.relationship ?? null } : null}
            top={
                <>
                    <h2 className="text-lg font-medium">{t('gift.taste.result_title')}</h2>

                    <div className="mt-3 rounded-card bg-accent/5 p-5 sm:p-6">
                        {learnedNothing ? (
                            <p className="text-ink-soft">{t('gift.taste.nothing')}</p>
                        ) : (
                            <ul className="space-y-1.5 text-lg">
                                {lines.map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        )}
                        {result.thin && !learnedNothing && <p className="mt-3 text-sm text-ink-soft">{t('gift.taste.thin')}</p>}
                    </div>

                    {mode === 'together' && (
                        <p role="status" className="mt-5 max-w-2xl rounded-card border border-sage/40 bg-sage/10 p-4 text-sm">
                            {t(result.recorded ? 'gift.together.recorded' : 'gift.together.not_recorded', {
                                name: person?.name ?? '',
                            })}
                        </p>
                    )}

                    {mode === 'self' && !learnedNothing && <SelfSave urls={urls} choices={result.choices} name={person?.name ?? ''} />}

                    {/*
                      "My gift profile" (docs/features/gift-profile-card.md): only
                      about yourself, only on request, and only when there is
                      something to put on it.
                    */}
                    {me && mode !== 'together' && !learnedNothing && urls.card && (
                        <MakeCard url={urls.card} choices={result.choices} />
                    )}

                    {giver && !me && !learnedNothing && (
                        <KeepOnPerson
                            urls={urls}
                            choices={result.choices}
                            recipients={recipients}
                            people={people}
                            canCreate={canCreate}
                            first={carried?.person?.id ?? null}
                        />
                    )}
                </>
            }
            actions={
                <>
                    <a href={restart} className={buttonClasses('secondary')}>
                        {t('gift.taste.again')}
                    </a>
                    {giver && !me && result.refine ? (
                        /*
                          Refine with the questions: the learned answers,
                          POSTed to the questions, open the same board with
                          Adjust, Eight more and "Something else" beside it.
                        */
                        <button
                            type="button"
                            className={buttonClasses('secondary')}
                            onClick={() => router.post(urls.finder, result.refine as Record<string, string>)}
                        >
                            {t('gift.refine')}
                        </button>
                    ) : (
                        <Link href={urls.finder} className={buttonClasses('secondary')}>
                            {t(mode === 'self' ? 'gift.taste.self_back' : 'gift.taste.open_finder')}
                        </Link>
                    )}
                </>
            }
        />
    )
}

/**
 * Keep the result on one of your people, or somebody new.
 *
 * The choices go back, not the profile: the server works the profile out
 * again from the catalogue, so what is saved is what the products say and
 * never what a request claims.
 */
function KeepOnPerson({
    urls,
    choices,
    recipients,
    people,
    canCreate,
    first = null,
}: {
    urls: Props['urls']
    choices: Choice[]
    recipients: Props['recipients']
    people: PickablePerson[]
    canCreate: boolean
    /** The person "Find a gift" said this is for: offered first. */
    first?: string | null
}) {
    const { t } = useTranslations()
    const [name, setName] = useState('')
    const [busy, setBusy] = useState<string | null>(null)
    const [message, setMessage] = useState<string | null>(null)
    const [failed, setFailed] = useState(false)

    const save = (target: { recipient_id: string } | { friend_id: number } | { name: string }, key: string) => {
        setBusy(key)
        setFailed(false)
        send<{ name: string; tasteWritten: boolean }>(urls.save, 'POST', { choices, ...target })
            .then((saved) =>
                setMessage(t(saved.tasteWritten ? 'gift.taste.saved' : 'gift.taste.saved_theirs', { name: saved.name })),
            )
            .catch(() => setFailed(true))
            .finally(() => setBusy(null))
    }

    /*
     * Played for somebody already saved (Find a gift passed who it is for):
     * kept on them without a press (owner, 2026-09-27: "the info about the
     * person when searching gifts is not saved to the profile"). The button
     * was the only way, and a result nobody pressed on was lost. Once, on
     * arrival; their own answers still win over it on the server.
     */
    useEffect(() => {
        if (first !== null && recipients.some((r) => r.id === first)) {
            save({ recipient_id: first }, `p:${first}`)
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    // My people's rows when signed in; otherwise the saved people, by name.
    const cards: PickablePerson[] =
        people.length > 0
            ? people
            : recipients.map((r) => ({ key: `p:${r.id}`, name: r.name, personId: r.id, friend: null }))

    if (message) {
        return (
            <p role="status" className="mt-5 rounded-card border border-sage/40 bg-sage/10 p-4 text-sm">
                {message}
            </p>
        )
    }

    return (
        <div className="mt-5 rounded-card border border-line bg-card p-5">
            <h3 className="font-medium">{t('gift.taste.save_title')}</h3>
            <p className="mt-1 text-sm text-ink-soft">{t('gift.taste.save_hint')}</p>

            {/*
              The people cards (consistency review round 3, 2026-09-27), where
              this was a row of "Bewaar voor …" buttons with a name each. A
              press still saves at once; a friend nobody saved yet is saved as
              a person by the same request (`friend_id`). The person Find a
              gift passed along goes first.
            */}
            {cards.length > 0 && (
                <div className="mt-4">
                    <PersonPicker
                        variant="compact"
                        people={[...cards].sort((a, b) => Number(b.personId === first) - Number(a.personId === first))}
                        isChosen={() => false}
                        onChoose={(p) => save(p.personId !== null ? { recipient_id: p.personId } : { friend_id: p.friend?.id ?? 0 }, p.key)}
                        busyKey={busy}
                        disabled={busy !== null}
                        trailing={(p) => t('gift.taste.save_for', { name: p.name })}
                    />
                </div>
            )}

            {canCreate ? (
                <form
                    className="mt-4 flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault()
                        if (name.trim() !== '') {
                            save({ name: name.trim() }, 'new')
                        }
                    }}
                >
                    <label htmlFor="taste-new-person" className="sr-only">
                        {t('gift.taste.save_new')}
                    </label>
                    <input
                        id="taste-new-person"
                        value={name}
                        maxLength={80}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t('gift.taste.save_new_placeholder')}
                        className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2"
                    />
                    <Button type="submit" busy={busy === 'new'} disabled={busy !== null || name.trim() === ''}>
                        {t('gift.taste.save')}
                    </Button>
                </form>
            ) : (
                <div className="mt-4">
                    <SignInLink hint={t('gift.taste.save_sign_in')} className={buttonClasses('secondary', 'sm')}>
                        {t('gift.taste.save_sign_in')}
                    </SignInLink>
                </div>
            )}

            {failed && (
                <p role="alert" className="mt-3 text-sm text-danger">
                    {t('gift.taste.save_failed')}
                </p>
            )}
        </div>
    )
}

/** The person's own result becomes their own taste, and they go back to their page. */
function SelfSave({ urls, choices, name }: { urls: Props['urls']; choices: Choice[]; name: string }) {
    const { t } = useTranslations()
    const [busy, setBusy] = useState(false)
    const [failed, setFailed] = useState(false)

    return (
        <div className="mt-5 flex flex-wrap items-center gap-3">
            <Button
                busy={busy}
                aria-label={name ? `${t('gift.taste.self_save')} (${name})` : undefined}
                onClick={() => {
                    setBusy(true)
                    setFailed(false)
                    send<{ redirect: string }>(urls.save, 'POST', { choices })
                        .then(({ redirect }) => router.visit(redirect))
                        .catch(() => {
                            setFailed(true)
                            setBusy(false)
                        })
                }}
            >
                {t('gift.taste.self_save')}
            </Button>
            {failed && (
                <p role="alert" className="text-sm text-danger">
                    {t('gift.taste.save_failed')}
                </p>
            )}
        </div>
    )
}

/**
 * "My gift profile": a link to what you just found, for somebody who buys for
 * you. Opt-in, a name only when typed, and removable from here or from the
 * card itself. The choices go to the server, never the profile: the card is
 * worked out there, as a saved result is.
 */
function MakeCard({ url, choices }: { url: string; choices: Choice[] }) {
    const { t } = useTranslations()
    const [name, setName] = useState('')
    const [busy, setBusy] = useState(false)
    const [failed, setFailed] = useState(false)
    const [card, setCard] = useState<{ url: string; summary: string; remove: string; key: string } | null>(null)

    const make = () => {
        setBusy(true)
        setFailed(false)
        send<{ url: string; summary: string; remove: string; key: string }>(url, 'POST', {
            choices,
            name: name.trim() === '' ? null : name.trim(),
        })
            .then(setCard)
            .catch(() => setFailed(true))
            .finally(() => setBusy(false))
    }

    const remove = () => {
        if (!card) {
            return
        }

        setBusy(true)
        setFailed(false)
        send(card.remove, 'DELETE', { key: card.key })
            .then(() => setCard(null))
            .catch(() => setFailed(true))
            .finally(() => setBusy(false))
    }

    return (
        <div className="mt-5 rounded-card border border-line bg-card p-5">
            <h3 className="font-medium">{t('gift.card.make_title')}</h3>

            {card ? (
                <>
                    <p className="mt-1 text-sm text-ink-soft">{t('gift.card.made', { summary: card.summary })}</p>
                    <div className="mt-3">
                        <ShareRow url={card.url} text={t('gift.card.share_text', { summary: card.summary })} />
                    </div>
                    <Button variant="ghost" size="sm" busy={busy} onClick={remove}>
                        {t('gift.card.remove')}
                    </Button>
                </>
            ) : (
                <>
                    <p className="mt-1 text-sm text-ink-soft">{t('gift.card.make_hint')}</p>
                    <form
                        className="mt-4 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()
                            make()
                        }}
                    >
                        <label htmlFor="taste-card-name" className="sr-only">
                            {t('gift.card.name_label')}
                        </label>
                        <input
                            id="taste-card-name"
                            value={name}
                            maxLength={40}
                            onChange={(e) => setName(e.target.value)}
                            placeholder={t('gift.card.name_placeholder')}
                            className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2"
                        />
                        <Button type="submit" busy={busy}>
                            {t('gift.card.make')}
                        </Button>
                    </form>
                </>
            )}

            {failed && (
                <p role="alert" className="mt-3 text-sm text-danger">
                    {t('gift.taste.save_failed')}
                </p>
            )}
        </div>
    )
}
