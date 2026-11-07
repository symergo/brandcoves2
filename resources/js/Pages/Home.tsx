import { Head, Link, usePage } from '@inertiajs/react'
import CoveSubscribe from '../Components/CoveSubscribe'
import SaveToList from '../Components/SaveToList'
import CoveIcon, { type CoveKey } from '../Components/CoveIcon'
import SearchCard from '../Components/SearchCard'
import HeroGift from '../Components/HeroGift'
import ToolIcon, { type ToolKey } from '../Components/ToolIcon'
import { buttonClasses } from '../Components/Button'
import { formatCountdown, formatDay, formatPrice, type SharedProps } from '../types'
import { ListThumb } from '../Components/ListRow'
import { useTranslations } from '../useTranslations'
import SceneIllustration, { type SceneKey } from '../Components/SceneIllustration'

interface Props {
    /** An Amazon sales event while it runs, on the NL and BE markets (AmazonEvent). */
    amazonEvent?: { key: string; url: string; image: string; title: string; body: string; cta: string } | null
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    /** Signed in only: your Coves, last changed first, and your people, nearest date first. */
    mine?: { coves: MyCove[]; people: MyPerson[] } | null
    /** The newest published Coves other than the Daily, newest first. */
    latest?: LatestCove[]
}

interface LatestCove {
    id: number
    kind: 'daily' | 'gift' | 'guide' | 'brand'
    title: string
    intro: string
    image: string | null
    scene: SceneKey | null
    url: string
}

interface MyCove {
    id: string
    title: string
    kind: string
    count: number
    covers: string[]
    url: string
}

/** A row of My people (MyPeople::for), as much of it as this band draws. */
interface MyPerson {
    key: string
    name: string
    relationship: string | null
    next: { date: string; days: number; kind: 'birthday' | 'occasion'; title: string | null } | null
    urls: { person: string | null }
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
 *  3. Daily, the thing that makes somebody come back (moved up from fifth
 *     on 2026-10-02, owner: right under "Gewoon rondkijken").
 *  4. From anywhere: what makes this more than an affiliate catalogue.
 *  5. (Coves, one card per kind: removed 2026-11-07.)
 *  6. Trust, short.
 *  7. Start your first Cove.
 *
 * What left, and why: the list wizard (it is where "Create a Cove" leads, so
 * the page no longer repeats the next page) and recently viewed (a returning
 * visitor's convenience, not an answer to the three questions). The search
 * card left too and came back under the hero later the same day; see below.
 */
export default function Home({ today, amazonEvent = null, mine = null, latest = [] }: Props) {
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
                {/*
                  Rewritten 2026-10-03 with the owner, over several rounds: a
                  small line naming the category ("the social gift network"),
                  the headline ("Cadeaus geven en krijgen. Samen.") and the
                  two buttons. The pitch paragraph under it went, and four
                  points proposed in its place were left out on the owner's
                  word. docs/features/homepage.md.
                */}
                {/*
                  Two columns since 2026-10-03 (owner): the words left, the
                  drawing beside the title rather than under it. The title one
                  step smaller so the two sit at the same height.
                */}
                {/*
                  On a red band since 2026-10-05 (Rood + oranje, owner's
                  choice; Koraal's coral before it). Everything on it is
                  white, 5.0:1 on the red, and the primary button is the
                  `light` variant, because a red button vanishes into it.
                */}
                {/*
                  Edge to edge (2026-10-05), as in the chosen proposal. The
                  colour is painted outward by a shadow and clipped to the
                  band's own height, so the band spans the window while its
                  content stays on the page's grid, and no 100vw width can add
                  a sideways scrollbar on a desktop with a visible one. The
                  negative top margin meets the header. The bottom padding was
                  deeper while the search card overlapped the band's lower edge
                  (until 2026-11-07); the card is inside the band now.
                */}
                <div className="-mt-6 grid items-center gap-8 bg-hero pt-10 pb-10 shadow-[0_0_0_100vmax_var(--color-hero)] [clip-path:inset(0_-100vmax)] sm:-mt-10 sm:pt-14 sm:pb-14 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] md:gap-12">
                    {/* min-w-0: on a phone the search field's own width pushed the column past the screen. */}
                    <div className="min-w-0">
                        <p className="text-sm font-semibold tracking-wide text-ink uppercase">{t('home.hero_kicker')}</p>
                        <h1 id="hero-heading" className="mt-2 text-3xl font-semibold tracking-tight text-balance text-ink sm:text-4xl">
                            {t('home.hero_title')}
                        </h1>
                        {/* The owner's combination of three proposals (2026-10-03). */}
                        {/* Wider with the page (owner, 2026-11-07): the title, the line and the search field had 576px. */}
                        <p className="mt-4 max-w-3xl text-lg text-ink">{t('home.hero_subtitle')}</p>

                        {/*
                          The search card where "Maak een Cove" and "Zoek
                          cadeaus" stood (owner, 2026-11-07). It overlapped the
                          band's lower edge before; both ways in stay one tap
                          away, in the header and in the three ways to start
                          below.
                        */}
                        <SearchCard className="mt-7 max-w-3xl shadow-[0_8px_24px_rgb(42_18_19/0.08)]" />
                    </div>

                    {/*
                      A wrapped present on two circles, a deeper red and
                      orange, as in the chosen proposal (owner, 2026-10-05). It
                      replaced the line drawing of shops, a cove and two
                      people. Decoration only.
                    */}
                    <div className="relative aspect-[5/4] w-full max-w-[18rem] justify-self-center md:max-w-sm">
                        <span aria-hidden="true" className="absolute top-[4%] left-[6%] aspect-square w-[62%] rounded-full bg-tint/80" />
                        <span aria-hidden="true" className="absolute top-[6%] right-0 aspect-square w-[36%] rounded-full bg-peach" />
                        <span aria-hidden="true" className="absolute bottom-[2%] left-[16%] aspect-square w-[22%] rounded-full bg-peach/70" />
                        <HeroGift className="absolute top-[12%] left-[16%] h-auto w-[68%]" />
                    </div>
                </div>
                {/* "Zoek · Verzamel · Deel" stood here until the owner removed it (2026-09-27). */}
            </section>

            {mine && <ForYou coves={mine.coves} people={mine.people} base={base} />}

            {/*
              The search card stood here, under the hero and overlapping its
              lower edge (2026-09-26 to 2026-11-07). It is inside the band now,
              where the two buttons were (owner).
            */}

            {/*
              An Amazon sales event, under the hero while it runs (owner,
              2026-10-06: the Prime days, with affiliate links). Amazon's own
              banner, loaded from Amazon; the link is tagged and marked
              sponsored, in words too, since it is an advertisement.
            */}
            {amazonEvent !== null && (
                <a
                    href={amazonEvent.url}
                    target="_blank"
                    rel="sponsored nofollow noopener"
                    className="group mt-8 grid overflow-hidden rounded-card border border-line bg-card transition hover:border-ink sm:mt-10 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                >
                    <img src={amazonEvent.image} alt={amazonEvent.title} width={900} height={400} className="aspect-[9/4] w-full object-cover" />
                    <span className="flex flex-col justify-center p-5 sm:p-6">
                        <span className="text-xs text-ink-soft">{t('home.sponsored')}</span>
                        <span className="mt-1 text-xl font-semibold">{amazonEvent.title}</span>
                        <span className="mt-2 text-ink-soft">{amazonEvent.body}</span>
                        <span className="mt-4 font-medium text-accent-dark group-hover:text-ink">{amazonEvent.cta} →</span>
                    </span>
                </a>
            )}

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
              The Daily, right under the three ways in, the last of which is
              "Gewoon rondkijken?" (owner, 2026-10-02). It stood fifth, after
              the Coves cards, until then.
            */}
            {(today || latest.length > 0) && (
                /*
                  On a soft orange band, edge to edge (Rood + oranje,
                  2026-10-05), painted the same way as the hero band.

                  The Daily on the left, smaller, and the newest Coves beside it
                  (owner, 2026-11-07: "put the latest coves on the frontpage",
                  then "make the daily cove card smaller and put the other
                  latest guides next to it"): what changed today and lately,
                  side by side, so a returning visitor sees something new
                  without scrolling. Either one alone takes the full width.
                */
                <section
                    className="mt-14 bg-band py-10 shadow-[0_0_0_100vmax_var(--color-band)] [clip-path:inset(0_-100vmax)] sm:mt-20 sm:py-14"
                    aria-labelledby={today ? 'today-heading' : 'latest-heading'}
                >
                <div className={`grid gap-8 ${today && latest.length > 0 ? 'lg:grid-cols-2 lg:items-start' : ''}`}>
                {today && (
                <div>
                    {/* No line above the card (owner, 2026-10-05): its own badge and title say what it is. */}
                    <div className="rounded-card border border-line bg-card p-5 sm:p-6">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="rounded-full bg-accent/10 px-3 py-1 text-xs font-medium uppercase tracking-wide text-accent">
                                {t('home.today_badge')}
                            </span>
                            <time dateTime={today.date} className="text-sm text-ink-soft">
                                {today.label}
                            </time>
                        </div>

                        <h2 id="today-heading" className="mt-3 text-xl font-semibold tracking-tight sm:text-2xl">
                            <Link href={today.url} className="hover:text-accent">
                                {today.theme}
                            </Link>
                        </h2>
                        {today.blurb && <p className="mt-2 max-w-2xl text-ink-soft">{today.blurb}</p>}

                        {today.finds.length > 0 && (
                            <ul className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                                            <div className="aspect-square overflow-hidden rounded-lg">
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

                        <div className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2">
                            <Link href={today.url} className="font-medium text-accent-dark hover:text-ink">
                                {t('home.today_cta')} →
                            </Link>
                            {/* Every earlier edition (owner, 2026-10-02). */}
                            <Link href={`${base}/${market.coveSegment}/archive`} className="text-sm text-ink-soft hover:text-ink">
                                {t('coves.daily_all')} →
                            </Link>
                        </div>
                    </div>

                    {/* Only where there is a Cove to subscribe to: a daily email
                        on a site with no editions is a promise we would then
                        have to keep. */}
                    <div className="mt-6">
                        <CoveSubscribe source="home" />
                    </div>
                </div>
                )}

                {latest.length > 0 && (
                    <div>
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="latest-heading" className="text-xl font-semibold tracking-tight sm:text-2xl">
                                {t('home.latest_heading')}
                            </h2>
                            <Link href={`${base}/coves`} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                                {t('home.coves_all')} →
                            </Link>
                        </div>
                        <ul className={`mt-4 grid gap-3 ${today ? '' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                            {latest.map((cove) => (
                                <LatestCoveCard key={cove.id} cove={cove} />
                            ))}
                        </ul>
                    </div>
                )}
                </div>
                </section>
            )}

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

            {/*
              4. Coves, one card per kind, stood here until 2026-11-07, when the
              owner removed it ("verwijder de sectie Coves om te ontdekken"):
              the newest Coves now sit beside the Daily, and the same six
              entries are in the header's Ontdek menu on every page.
            */}

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

                {/*
                  7. The last thing on the page is the first thing it asked.

                  A card in the hero's own orange, with the present on a cream
                  circle: the page ends the way it began (owner, 2026-10-06:
                  "the same background as the hero"; it was red, and before
                  that a faint wash). Dark text and the red button, exactly as
                  in the hero.
                */}
                <section
                    className="relative flex flex-col overflow-hidden rounded-card bg-hero p-6 text-ink sm:p-8"
                    aria-labelledby="final-heading"
                >
                    <div className="flex items-start gap-5">
                        <div className="min-w-0 flex-1">
                            <h2 id="final-heading" className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                {t('home.final_title')}
                            </h2>
                            <p className="mt-2 text-ink">{t('home.final_body')}</p>
                        </div>
                        <div aria-hidden="true" className="relative hidden aspect-square w-24 shrink-0 sm:block">
                            <span className="absolute inset-0 rounded-full bg-peach" />
                            <HeroGift className="absolute top-[18%] left-[12%] h-auto w-[76%]" />
                        </div>
                    </div>
                    <Link href={createCove} className={`mt-6 self-start ${buttonClasses('primary', 'lg')}`}>
                        {t('home.cta_create')} <span aria-hidden="true">→</span>
                    </Link>
                </section>
            </div>
        </>
    )
}

/**
 * "Voor jou": your Coves and your people, under the hero, signed in only
 * (owner, 2026-11-07: "the homepage should be personalized with the coves and
 * the people of the logged in person"). Each card opens its page; the heading
 * links to the whole overview. Two columns only when both have something
 * (the owner's rule: no empty column); otherwise the one there takes the width.
 * Nothing at all when both are empty: the page below already says how to start.
 */
function ForYou({ coves, people, base }: { coves: MyCove[]; people: MyPerson[]; base: string }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const both = coves.length > 0 && people.length > 0

    if (coves.length === 0 && people.length === 0) {
        return null
    }

    const heading = (title: string, href: string) => (
        <div className="flex items-baseline justify-between gap-3">
            <h2 className="text-xl font-semibold tracking-tight">{title}</h2>
            <Link href={href} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                {t('home.mine_all')} →
            </Link>
        </div>
    )

    return (
        <section className={`mt-10 grid gap-8 sm:mt-12 ${both ? 'lg:grid-cols-2' : ''}`} aria-label={t('home.mine_label')}>
            {coves.length > 0 && (
                <div className="min-w-0">
                    {heading(t('lists.title'), `${base}/lists`)}
                    <ul className={`mt-4 grid gap-3 sm:grid-cols-2 ${both ? '' : 'lg:grid-cols-3'}`}>
                        {coves.map((cove) => (
                            <li key={cove.id}>
                                <Link
                                    href={cove.url}
                                    className="flex items-center gap-3 rounded-card border border-line bg-card p-3 transition hover:border-ink"
                                >
                                    <ListThumb covers={cove.covers} kind={cove.kind} />
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{cove.title}</span>
                                        <span className="block text-sm text-ink-soft">
                                            {cove.count === 1 ? t('lists.one_item') : t('lists.items', { count: n(cove.count) })}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {people.length > 0 && (
                <div className="min-w-0">
                    {heading(t('people.title'), `${base}/people`)}
                    <ul className={`mt-4 grid gap-3 sm:grid-cols-2 ${both ? '' : 'lg:grid-cols-3'}`}>
                        {people.map((person) => {
                            const relationship =
                                person.relationship && person.relationship.toLowerCase() !== person.name.toLowerCase()
                                    ? person.relationship
                                    : null
                            const body = (
                                <>
                                    <span
                                        aria-hidden="true"
                                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-tint font-semibold text-accent"
                                    >
                                        {person.name.slice(0, 1).toUpperCase()}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            {person.name}
                                            {relationship && <span className="font-normal text-ink-soft"> · {relationship}</span>}
                                        </span>
                                        {person.next && (
                                            <span className="flex items-center text-sm text-ink-soft">
                                                {person.next.kind === 'birthday' && <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />}
                                                {formatDay(person.next.date, market)}
                                                {' · '}
                                                <span className={person.next.days <= 14 ? 'ml-1 font-medium text-ink' : 'ml-1'}>
                                                    {formatCountdown(person.next.days, t)}
                                                </span>
                                            </span>
                                        )}
                                    </span>
                                </>
                            )
                            const card = 'flex items-center gap-3 rounded-card border border-line bg-card p-3'

                            return (
                                <li key={person.key}>
                                    {person.urls.person ? (
                                        <Link href={person.urls.person} className={`${card} transition hover:border-ink`}>
                                            {body}
                                        </Link>
                                    ) : (
                                        <div className={card}>{body}</div>
                                    )}
                                </li>
                            )
                        })}
                    </ul>
                </div>
            )}
        </section>
    )
}

function Entry({ icon, title, body, cta, href }: { icon: ToolKey; title: string; body: string; cta: string; href: string }) {
    return (
        <li>
            <Link
                href={href}
                className="group flex h-full flex-col rounded-card border border-line bg-card p-6 transition hover:border-ink"
            >
                {/* The title beside the icon, not under it (owner, 2026-10-05): one line saves a row. */}
                <span className="flex items-center gap-3">
                    <ToolIcon name={icon} className="h-8 w-8 shrink-0 text-accent" duo />
                    <span className="text-lg font-semibold">{title}</span>
                </span>
                <span className="mt-2 flex-1 text-ink-soft">{body}</span>
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
                <ToolIcon name={icon} className="mt-0.5 h-7 w-7 shrink-0 text-accent" duo />
                <span className="underline decoration-line underline-offset-4 group-hover:decoration-ink">{text}</span>
            </Link>
        </li>
    )
}

/**
 * One of the newest Coves, one row: a picture, what kind it is, its title and
 * the start of its intro, the whole row one link. The picture is its first
 * product, or the drawing an advice article carries.
 */
function LatestCoveCard({ cove }: { cove: LatestCove }) {
    const { t } = useTranslations()
    const label = { daily: t('nav.daily'), gift: t('nav.gift_ideas'), guide: t('nav.smart'), brand: t('nav.brands_shops') }[cove.kind]
    const icon: CoveKey = { daily: 'daily', gift: 'persona', guide: 'idea', brand: 'brand' }[cove.kind] as CoveKey

    return (
        <li>
            <Link
                href={cove.url}
                className="group flex h-full items-center gap-4 rounded-card border border-line bg-card p-3 transition hover:border-ink"
            >
                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white">
                    {cove.image ? (
                        <img src={cove.image} alt="" loading="lazy" className="h-full w-full object-contain p-1" />
                    ) : (
                        <SceneIllustration name={cove.scene} className="h-11 w-auto text-accent" />
                    )}
                </div>
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5 text-xs font-medium text-ink-soft">
                        <CoveIcon name={icon} className="h-4 w-4 shrink-0 text-accent" />
                        {label}
                    </div>
                    <div className="mt-0.5 line-clamp-1 font-medium group-hover:text-accent">{cove.title}</div>
                    {cove.intro && <p className="mt-0.5 line-clamp-1 text-sm text-ink-soft">{cove.intro}</p>}
                </div>
            </Link>
        </li>
    )
}
