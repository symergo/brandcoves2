import { Head, Link, usePage } from '@inertiajs/react'
import CoveSubscribe from '../Components/CoveSubscribe'
import SaveToList from '../Components/SaveToList'
import CommunityCoveCards, { type CommunityCoveCard } from '../Components/CommunityCoveCards'
import SearchCard from '../Components/SearchCard'
import SharedCoveIllustration from '../Components/SharedCoveIllustration'
import ToolIcon, { type ToolKey } from '../Components/ToolIcon'
import { buttonClasses } from '../Components/Button'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    /** The shape this Cove takes: persona, guide, seasonal, advice, brand or shop. Named on the card. */
    kind: string
    title: string
    intro: string | null
    url: string
}

interface Props {
    /** The newest Community Coves; empty means no band. */
    collected?: CommunityCoveCard[]
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    coves: Cove[]
}

/**
 * The front page, rebuilt 2026-09-26 to the owner's structure (docs/strategy.md,
 * section 6; docs/features/homepage.md).
 *
 * It answers three questions and nothing else: what is this, why should I care,
 * what can I do now. Every section below is one of the owner's, in the owner's
 * order, and each ends in one action:
 *
 *  1. Hero: the idea, Create a Cove and Explore Coves.
 *  2. Three ways in, one per audience: a gift, a wish list, browsing.
 *  3. From anywhere: what makes this more than an affiliate catalogue.
 *  4. Coves: where the catalogue becomes discovery.
 *  5. Daily: only now, the thing that makes somebody come back.
 *  6. Trust, short.
 *  7. Start your first Cove.
 *
 * What left, and why: the list wizard (it is where "Create a Cove" leads, so
 * the page no longer repeats the next page) and recently viewed (a returning
 * visitor's convenience, not an answer to the three questions). The search
 * card left too and came back under the hero later the same day; see below.
 */
export default function Home({ today, coves, collected = [] }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    // Works without an account (owner's decision, 2026-09-26): the growth
    // loop's first step must not be a sign-in form. A bare `?new`, not
    // `?new=mine`: the wizard then opens on "Who is it for?", which decides
    // what kind of Cove it becomes (owner's request, the same day).
    const createCove = `${base}/lists?new`

    return (
        <>
            <Head title={t('home.title')} />

            {/*
              1. The hero, in the approved layout ("A2, round 3"): the headline
              across the full width, the pitch and the buttons beside the
              drawing.
            */}
            <section aria-labelledby="hero-heading">
                <h1 id="hero-heading" className="max-w-4xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl lg:text-5xl">
                    {t('home.hero_title')}
                </h1>

                {/* The drawing smaller than the words since 2026-09-26 (owner): it
                    illustrates the promise, it is not the promise. */}
                <div className="mt-6 grid items-center gap-8 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] md:gap-12">
                    <div>
                        <p className="max-w-xl text-lg text-ink-soft">{t('home.hero_intro')}</p>

                        <div className="mt-7 flex flex-wrap gap-3">
                            <Link href={createCove} className={buttonClasses('primary', 'lg')}>
                                {t('home.cta_create')}
                            </Link>
                            <Link href={`${base}/coves`} className={buttonClasses('secondary', 'lg')}>
                                {t('home.cta_explore')}
                            </Link>
                        </div>
                    </div>

                    <SharedCoveIllustration className="h-auto w-full max-w-xs justify-self-center text-ink md:max-w-sm" />
                </div>
                {/* "Zoek · Verzamel · Deel" stood here until the owner removed it (2026-09-27). */}
            </section>

            {/*
              The search card, right under the hero (owner, 2026-09-26): the
              same card as on Discover, with the barcode camera one tap away.
              Somebody who arrives knowing what they want should not have to
              find the header's search first.
            */}
            <SearchCard className="mt-10" />

            {/* 2. One way in per audience. */}
            <section className="mt-14 sm:mt-20" aria-label={t('home.entries_label')}>
                <ul className="grid gap-4 md:grid-cols-3">
                    <Entry
                        icon="whisperer"
                        title={t('home.entry_gift_title')}
                        body={t('home.entry_gift_body')}
                        cta={t('home.entry_gift_cta')}
                        href={`${base}/gift`}
                    />
                    <Entry
                        icon="wishlist"
                        title={t('home.entry_list_title')}
                        body={t('home.entry_list_body')}
                        cta={t('home.entry_list_cta')}
                        // This card has already answered "for whom": a wish
                        // list is for yourself, so the form opens with it chosen.
                        href={`${base}/lists?new=mine`}
                    />
                    <Entry
                        icon="guides"
                        title={t('home.entry_browse_title')}
                        body={t('home.entry_browse_body')}
                        cta={t('home.entry_browse_cta')}
                        href={`${base}/coves`}
                    />
                </ul>
            </section>

            {/*
              3. From anywhere. The difference from an affiliate catalogue,
              said outright. Generic sources, never other companies' names
              (owner's decision, 2026-09-26).
            */}
            <section className="mt-14 sm:mt-20" aria-labelledby="open-heading">
                <div className="rounded-card bg-accent/5 p-6 sm:p-10">
                    <h2 id="open-heading" className="text-2xl font-semibold tracking-tight sm:text-3xl">
                        {t('home.open_title')}
                    </h2>
                    <p className="mt-2 max-w-2xl text-ink-soft">{t('home.open_intro')}</p>

                    {/* Four ways, so 2 or 4 columns: 3 would leave the fourth alone on a row. */}
                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <OpenWay icon="link" text={t('home.open_link')} href={`${base}/lists`} />
                        <OpenWay icon="barcode" text={t('home.open_scan')} href={`${base}/scan`} />
                        <OpenWay icon="search" text={t('home.open_search')} href={`${base}/search`} />
                        {/* Offline items with a photo (owner, 2026-09-27). */}
                        <OpenWay icon="picture" text={t('home.open_photo')} href={`${base}/lists`} />
                    </ul>

                    <p className="mt-6 text-xs tracking-wide text-ink-soft uppercase">{t('home.open_sources')}</p>

                    <Link href={createCove} className={`mt-6 ${buttonClasses('primary', 'md')}`}>
                        {t('home.open_cta')}
                    </Link>
                </div>
            </section>

            {/* 4. Coves: where the catalogue becomes discovery. */}
            {coves.length > 0 && (
                <section className="mt-14 sm:mt-20" aria-labelledby="coves-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="coves-heading" className="text-2xl font-semibold tracking-tight sm:text-3xl">
                            {t('home.coves_heading')}
                        </h2>
                        <Link href={`${base}/coves`} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                            {t('home.coves_all')} →
                        </Link>
                    </div>

                    <ul className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                                >
                                    <span className="text-2xs font-medium tracking-wide text-ink-soft uppercase">
                                        {t(`home.cove_kind_${cove.kind}`)}
                                    </span>
                                    <span className="mt-2 font-medium text-balance">{cove.title}</span>
                                    {cove.intro && (
                                        <span className="mt-1 line-clamp-3 text-sm text-ink-soft">{cove.intro}</span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              4b. What others collect: the newest Community Coves (owner,
              2026-09-26). The same small cards the Gift Finder results use.
            */}
            {collected.length > 0 && (
                <section className="mt-14 sm:mt-20" aria-labelledby="collected-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="collected-heading" className="text-2xl font-semibold tracking-tight sm:text-3xl">
                            {t('home.collected_heading')}
                        </h2>
                        <Link href={`${base}/coves/community`} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                            {t('home.collected_all')} →
                        </Link>
                    </div>
                    <p className="mt-2 max-w-2xl text-ink-soft">{t('home.collected_intro')}</p>
                    <div className="mt-6">
                        <CommunityCoveCards coves={collected} />
                    </div>
                </section>
            )}

            {/* 5. Daily. Only now, as the owner ordered it. */}
            {today && (
                <section className="mt-14 sm:mt-20" aria-labelledby="today-heading">
                    <p className="text-sm font-medium text-accent-dark">{t('home.daily_title')}</p>
                    <div className="mt-3 rounded-card border border-line bg-card p-5 sm:p-8">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="rounded-full bg-accent/10 px-3 py-1 text-xs font-medium uppercase tracking-wide text-accent">
                                {t('home.today_badge')}
                            </span>
                            <time dateTime={today.date} className="text-sm text-ink-soft">
                                {today.label}
                            </time>
                        </div>

                        <h2 id="today-heading" className="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">
                            <Link href={today.url} className="hover:text-accent">
                                {today.theme}
                            </Link>
                        </h2>
                        {today.blurb && <p className="mt-2 max-w-2xl text-ink-soft">{today.blurb}</p>}

                        {today.finds.length > 0 && (
                            <ul className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                {today.finds.map((find) => (
                                    <li key={find.id} className="relative">
                                        {/*
                                          Outside the anchor and above it on the
                                          z-axis. A tile is one big link, and a
                                          button nested inside it is not a
                                          button: the anchor takes the click.
                                        */}
                                        <div className="absolute top-2 right-2 z-10">
                                            <SaveToList groupId={find.id} compact />
                                        </div>
                                        <Link href={find.url} className="group block">
                                            <div className="aspect-square overflow-hidden rounded-lg bg-cream">
                                                {find.image && (
                                                    <img
                                                        src={find.image}
                                                        alt=""
                                                        loading="lazy"
                                                        className="h-full w-full object-contain transition group-hover:scale-105"
                                                    />
                                                )}
                                            </div>
                                            <div className="mt-2 line-clamp-2 text-sm group-hover:text-accent">
                                                {find.title}
                                            </div>
                                            {find.price !== null && (
                                                <div className="text-sm font-medium tabular-nums">
                                                    {formatPrice(find.price, market)}
                                                </div>
                                            )}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link href={today.url} className="mt-6 inline-block font-medium text-accent-dark hover:text-ink">
                            {t('home.today_cta')} →
                        </Link>
                    </div>

                    {/* Only where there is a Cove to subscribe to: a daily email
                        on a site with no editions is a promise we would then
                        have to keep. */}
                    <div className="mt-6">
                        <CoveSubscribe source="home" />
                    </div>
                </section>
            )}

            {/*
              6 and 7, side by side (owner, 2026-09-27: "sections on the
              homepage with no content on the right"). Each was a short block
              on the left of an empty width; together they fill the row. Trust
              on the left, the last call to action on the right, where the eye
              ends. Stacked on a phone, trust first, as before.
            */}
            <div className="mt-14 grid gap-4 sm:mt-20 md:grid-cols-2">
                {/* 6. Trust, short. */}
                <section className="flex flex-col rounded-card border border-line bg-card p-6 sm:p-8" aria-labelledby="trust-heading">
                    <h2 id="trust-heading" className="text-xl font-semibold tracking-tight sm:text-2xl">
                        {t('home.trust_title')}
                    </h2>
                    <p className="mt-2 text-ink-soft">
                        {t('home.trust_sources')} {t('home.trust_commission')}
                    </p>
                    <Link
                        href={`${base}/help`}
                        className="mt-auto inline-flex min-h-11 items-center pt-4 font-medium text-accent-dark hover:text-ink sm:min-h-0"
                    >
                        {t('home.trust_link')} →
                    </Link>
                </section>

                {/* 7. The last thing on the page is the first thing it asked. */}
                <section className="flex flex-col rounded-card bg-accent/5 p-6 sm:p-8" aria-labelledby="final-heading">
                    <h2 id="final-heading" className="text-xl font-semibold tracking-tight sm:text-2xl">
                        {t('home.final_title')}
                    </h2>
                    <p className="mt-2 text-ink-soft">{t('home.final_body')}</p>
                    <Link href={createCove} className={`mt-auto self-start pt-0 ${buttonClasses('primary', 'lg')}`}>
                        {t('home.cta_create')}
                    </Link>
                </section>
            </div>
        </>
    )
}

function Entry({ icon, title, body, cta, href }: { icon: ToolKey; title: string; body: string; cta: string; href: string }) {
    return (
        <li>
            <Link
                href={href}
                className="group flex h-full flex-col rounded-card border border-line bg-card p-6 transition hover:border-ink"
            >
                <ToolIcon name={icon} className="h-7 w-7 text-accent" />
                <span className="mt-4 text-lg font-semibold">{title}</span>
                <span className="mt-1 flex-1 text-ink-soft">{body}</span>
                <span className="mt-4 font-medium text-accent-dark group-hover:text-ink">{cta} →</span>
            </Link>
        </li>
    )
}

/**
 * One way in, and a link to where it is done (owner, 2026-09-27). Pasting a
 * link and adding a photo both happen in a list's add panel, which the + on
 * every card in My Coves opens; scanning and searching have their own pages.
 */
function OpenWay({ icon, text, href }: { icon: ToolKey; text: string; href: string }) {
    return (
        <li>
            <Link href={href} className="group flex items-start gap-3 hover:text-ink">
                <ToolIcon name={icon} className="mt-0.5 h-6 w-6 shrink-0 text-ink" />
                <span className="underline decoration-line underline-offset-4 group-hover:decoration-ink">{text}</span>
            </Link>
        </li>
    )
}
