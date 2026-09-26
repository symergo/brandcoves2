import { Head, Link, usePage } from '@inertiajs/react'
import { useState, type ReactNode } from 'react'
import CoveIcon from '../Components/CoveIcon'
import SceneIllustration, { type SceneKey } from '../Components/SceneIllustration'
import SaveToList from '../Components/SaveToList'
import ToolIcon from '../Components/ToolIcon'
import type { SharedProps } from '../types'
import { formatOccasionDate, formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    title: string
    intro: string | null
    url: string
    searches: number
}

interface Question {
    title: string
    answers: number
    url: string
}

/** A persona carries no `searches`: it is written about a person, not mined from a query. */
interface Persona {
    title: string
    intro: string | null
    url: string
    /** Null until a curator picks one; the component draws a figure. */
    scene: SceneKey | null
}

interface Find {
    id: number
    title: string
    brand?: string | null
    image: string | null
    price: number | null
    url: string
}

interface Props {
    urls: { daily: string; surprise: string; guides: string; giftIdeas: string; ask: string; taste: string; gift: string }
    coves: Cove[]
    /** Empty until a market publishes its first; the band goes with it. */
    personas: Persona[]
    /** The gift landing pages for a whole person ("gift ideas for dad"). */
    forWhom: { label: string; url: string }[]
    /** The editions before today's, newest first. Empty below three. */
    dailies: { date: string; title: string; url: string }[]
    /** Null before a market has published its first edition. */
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: Find[]
    } | null
    /** Empty below three: one lonely question reads as an empty board. */
    questions: Question[]
    /** Resampled on every visit; that is the point of the band. */
    surprises: Find[]
    /** Two products for the This or that band, drawn apart from the surprises. */
    pair: Find[]
}

/**
 * The discovery landing page, rebuilt 2026-09-26 after a review with the owner.
 *
 * The earlier page tried to be everything: a search card and Find a gift
 * first (both already in the header, and both for somebody who already knows
 * what they want), then five explainer tiles that repeated the sections below
 * them word for word, then eight bands, nine phone screens long. A page called
 * Discover is for somebody without a goal, so it now opens with something to
 * look at:
 *
 * 1. a title and one line;
 * 2. a row of jump links, one per band on the page, which replaces the tiles;
 * 3. today's Cove, the thing that changes every day;
 * 4. This or that, the new way to find out what somebody likes, shown with two
 *    real products side by side so it reads as a choice before it is read;
 * 5. Surprise, gift ideas per person, Shop Smarter: six at most each, with a
 *    link to the rest;
 * 6. the question board and earlier editions, each only with three or more,
 *    because one lonely row reads as an empty shelf.
 *
 * Still no counts or totals, as homepage.md decided for the front page.
 */
export default function DiscoverCove({ urls, coves, personas, forWhom, today, dailies, questions, surprises, pair }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    // A pair with a picture that will not load reads as a broken card, and
    // half a choice is no choice: the picture goes, the words stay.
    const [pairBroken, setPairBroken] = useState(false)

    // One jump link per band that is actually on the page, in page order.
    const jumps: { id: string; label: string; icon: ReactNode }[] = [
        ...(today ? [{ id: 'today', label: t('home.today_badge'), icon: <CoveIcon name="daily" className="h-4 w-4" /> }] : []),
        { id: 'taste', label: t('gift.taste.title'), icon: <ToolIcon name="taste" className="h-4 w-4" /> },
        ...(surprises.length > 0 ? [{ id: 'surprise', label: t('nav.surprise'), icon: <CoveIcon name="surprise" className="h-4 w-4" /> }] : []),
        ...(personas.length > 0 || forWhom.length > 0
            ? [{ id: 'gift-ideas', label: t('gift_ideas.title'), icon: <CoveIcon name="persona" className="h-4 w-4" /> }]
            : []),
        ...(coves.length > 0 ? [{ id: 'guides', label: t('nav.smart'), icon: <CoveIcon name="idea" className="h-4 w-4" /> }] : []),
        ...(questions.length > 0 ? [{ id: 'ask', label: t('ask.title'), icon: <CoveIcon name="ask" className="h-4 w-4" /> }] : []),
    ]

    return (
        <>
            <Head title={t('discover_cove.seo_title')} />

            <h1 className="text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{t('discover_cove.title')}</h1>
            <p className="mt-2 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

            <nav aria-label={t('discover_cove.jump_label')} className="mt-5 flex flex-wrap gap-2">
                {jumps.map((jump) => (
                    <a
                        key={jump.id}
                        href={`#${jump.id}`}
                        className="inline-flex min-h-10 items-center gap-2 rounded-full border border-line bg-card px-3 text-sm text-ink transition hover:border-ink"
                    >
                        <span className="text-accent">{jump.icon}</span>
                        {jump.label}
                    </a>
                ))}
            </nav>

            {today && (
                <section id="today" className="mt-8 scroll-mt-24" aria-labelledby="today-heading">
                    <div className="rounded-card border border-line bg-card p-5 sm:p-6">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="rounded-full bg-accent/10 px-3 py-1 text-xs font-medium tracking-wide text-accent uppercase">
                                {t('home.today_badge')}
                            </span>
                            <time dateTime={today.date} className="text-sm text-ink-soft">
                                {today.label}
                            </time>
                        </div>

                        <h2 id="today-heading" className="mt-3 text-xl font-semibold tracking-tight text-ink sm:text-2xl">
                            {today.theme}
                        </h2>
                        {today.blurb && <p className="mt-2 max-w-2xl text-ink-soft">{today.blurb}</p>}

                        {today.finds.length > 0 && <FindGrid finds={today.finds} className="mt-5" />}

                        <Link
                            href={today.url}
                            className="mt-5 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                        >
                            {t('home.today_cta')}
                        </Link>
                    </div>
                </section>
            )}

            {/*
              This or that, shown as what it is: two things side by side and a
              choice. The pictures are not links; the whole band leads to the
              tool, which starts with its own pair. Without two products with
              a picture the band still stands, as words and a button.
            */}
            <section id="taste" className="mt-10 scroll-mt-24" aria-labelledby="taste-heading">
                <div className="grid items-center gap-6 rounded-card border border-line bg-accent/5 p-5 sm:p-6 md:grid-cols-[1fr_auto]">
                    <div>
                        <h2 id="taste-heading" className="flex items-center gap-2 text-xl font-semibold tracking-tight text-ink sm:text-2xl">
                            <span className="text-accent">
                                <ToolIcon name="taste" className="h-6 w-6" />
                            </span>
                            {t('gift.taste.title')}
                        </h2>
                        <p className="mt-2 max-w-xl text-ink-soft">{t('discover_cove.taste_body')}</p>
                        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <Link
                                href={urls.taste}
                                className="inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                            >
                                {t('discover_cove.taste_cta')}
                            </Link>
                            <Link href={urls.gift} className="text-sm text-accent-dark underline hover:text-ink">
                                {t('discover_cove.finder_link')}
                            </Link>
                        </div>
                    </div>

                    {pair.length === 2 && !pairBroken && (
                        <div className="flex items-center justify-center gap-3" aria-hidden>
                            <PairCard find={pair[0]} tilt="-rotate-3" onBroken={() => setPairBroken(true)} />
                            <span className="text-sm font-medium text-ink-soft">{t('discover_cove.or')}</span>
                            <PairCard find={pair[1]} tilt="rotate-3" onBroken={() => setPairBroken(true)} />
                        </div>
                    )}
                </div>
            </section>

            {surprises.length > 0 && (
                <Band
                    id="surprise"
                    title={t('nav.surprise')}
                    intro={t('discover_cove.surprise_what')}
                    more={{ href: urls.surprise, label: t('surprise.reroll') }}
                >
                    <FindGrid finds={surprises} />
                </Band>
            )}

            {(personas.length > 0 || forWhom.length > 0) && (
                <Band
                    id="gift-ideas"
                    title={t('gift_ideas.title')}
                    intro={t('gift_ideas.description')}
                    more={{ href: urls.giftIdeas, label: t('discover_cove.persona_all') }}
                >
                    {personas.length > 0 && (
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {personas.map((persona) => (
                                <li key={persona.url}>
                                    <Link
                                        href={persona.url}
                                        className="group flex h-full flex-row items-center gap-4 rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                    >
                                        <SceneIllustration
                                            name={persona.scene}
                                            className="h-14 w-20 shrink-0 text-ink-soft transition group-hover:text-accent"
                                        />
                                        <div className="min-w-0">
                                            <h3 className="font-medium text-ink">{persona.title}</h3>
                                            {persona.intro && (
                                                <p className="mt-1 line-clamp-2 text-sm text-ink-soft">{persona.intro}</p>
                                            )}
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    {/* The gift landing pages per person: a row of words, not more cards. */}
                    {forWhom.length > 0 && (
                        <div className={personas.length > 0 ? 'mt-4' : ''}>
                            <p className="text-sm font-medium text-ink">{t('discover_cove.for_whom')}</p>
                            <ul className="mt-2 flex flex-wrap gap-2">
                                {forWhom.map((page) => (
                                    <li key={page.url}>
                                        <Link
                                            href={page.url}
                                            className="inline-flex min-h-10 items-center rounded-full border border-line bg-card px-3 text-sm hover:border-ink"
                                        >
                                            {page.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Band>
            )}

            {coves.length > 0 && (
                <Band
                    id="guides"
                    title={t('nav.smart')}
                    intro={t('home.coves_intro')}
                    more={{ href: urls.guides, label: t('discover_cove.guides_all') }}
                >
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                >
                                    <h3 className="font-medium text-ink">{cove.title}</h3>
                                    {cove.intro && <p className="mt-2 line-clamp-2 text-sm text-ink-soft">{cove.intro}</p>}
                                    {cove.searches > 0 && (
                                        <span className="mt-auto pt-3 text-xs text-ink-soft">
                                            {t('home.coves_volume', { count: n(cove.searches) })}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Band>
            )}

            {questions.length > 0 && (
                <Band id="ask" title={t('ask.title')} intro={t('ask.nav_hint')} more={{ href: urls.ask, label: t('ask.all') }}>
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {questions.map((question) => (
                            <li key={question.url}>
                                <Link
                                    href={question.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                >
                                    <h3 className="font-medium text-ink">{question.title}</h3>
                                    <span
                                        className={`mt-auto pt-3 text-xs ${
                                            question.answers > 0 ? 'font-medium text-accent' : 'text-ink-soft'
                                        }`}
                                    >
                                        {question.answers === 0
                                            ? t('ask.no_answers')
                                            : question.answers === 1
                                              ? t('ask.one_answer')
                                              : t('ask.answers', { count: n(question.answers) })}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Band>
            )}

            {dailies.length > 0 && (
                <Band id="dailies" title={t('discover_cove.dailies_heading')} more={{ href: urls.daily, label: t('discover_cove.dailies_all') }}>
                    <ul className="divide-y divide-line rounded-card border border-line bg-card">
                        {dailies.map((edition) => (
                            <li key={edition.url}>
                                <Link
                                    href={edition.url}
                                    className="flex flex-col gap-0.5 p-4 transition hover:bg-cream sm:flex-row sm:items-baseline sm:gap-4"
                                >
                                    <time dateTime={edition.date} className="shrink-0 text-sm text-ink-soft sm:w-24">
                                        {formatOccasionDate(edition.date, market)}
                                    </time>
                                    <span className="font-medium">{edition.title}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Band>
            )}
        </>
    )
}

/** One band: a heading, a line, a link to the rest, and its contents. */
function Band({
    id,
    title,
    intro,
    more,
    children,
}: {
    id: string
    title: string
    intro?: string
    more: { href: string; label: string }
    children: ReactNode
}) {
    return (
        <section id={id} className="mt-12 scroll-mt-24" aria-labelledby={`${id}-heading`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id={`${id}-heading`} className="text-xl font-semibold tracking-tight text-ink sm:text-2xl">
                    {title}
                </h2>
                <Link
                    href={more.href}
                    className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0"
                >
                    {more.label} →
                </Link>
            </div>
            {intro && <p className="mt-1 max-w-2xl text-ink-soft">{intro}</p>}
            <div className="mt-5">{children}</div>
        </section>
    )
}

/**
 * Product tiles with the save bookmark. The bookmark sits outside the link and
 * above it: a button inside an anchor is not a button, the anchor takes the click.
 */
function FindGrid({ finds, className = '' }: { finds: Find[]; className?: string }) {
    const { market } = usePage<SharedProps>().props

    return (
        <ul className={`grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4 ${className}`}>
            {finds.map((find) => (
                <li key={find.id} className="relative">
                    <div className="absolute top-2 right-2 z-10">
                        <SaveToList groupId={find.id} compact />
                    </div>
                    <Link
                        href={find.url}
                        className="flex h-full flex-col rounded-lg border border-line bg-card p-3 transition hover:border-ink"
                    >
                        <div className="flex h-24 items-center justify-center sm:h-28">
                            {find.image && (
                                <img
                                    src={find.image}
                                    alt=""
                                    loading="lazy"
                                    className="max-h-full w-auto max-w-full object-contain"
                                    onError={(e) => {
                                        e.currentTarget.style.visibility = 'hidden'
                                    }}
                                />
                            )}
                        </div>
                        {find.brand && (
                            <span className="mt-2 text-2xs tracking-wide text-ink-soft uppercase">{find.brand}</span>
                        )}
                        <p className="mt-1 line-clamp-2 text-sm font-medium">{find.title}</p>
                        {find.price !== null && (
                            <p className="mt-auto pt-1 text-sm text-ink-soft">{formatPrice(find.price, market)}</p>
                        )}
                    </Link>
                </li>
            ))}
        </ul>
    )
}

/** One side of the This or that picture: a product photo on a tilted card. */
function PairCard({ find, tilt, onBroken }: { find: Find; tilt: string; onBroken: () => void }) {
    return (
        <div className={`flex h-32 w-28 items-center justify-center rounded-card border border-line bg-card p-3 shadow-sm sm:h-36 sm:w-32 ${tilt}`}>
            {find.image && (
                <img src={find.image} alt="" loading="lazy" onError={onBroken} className="max-h-full max-w-full object-contain" />
            )}
        </div>
    )
}
