import { Head, Link, usePage } from '@inertiajs/react'
import type { CoveSceneKey } from '../Components/CoveIllustration'
import CoveIllustration from '../Components/CoveIllustration'
import CoveSubscribe from '../Components/CoveSubscribe'
import HomeIllustration from '../Components/HomeIllustration'
import ListWizard, { type WizardOffer } from '../Components/ListWizard'
import type { SceneKey } from '../Components/SceneIllustration'
import SaveToList from '../Components/SaveToList'
import SearchCard from '../Components/SearchCard'
import { buttonClasses } from '../Components/Button'
import RecentlyViewed from '../Components/RecentlyViewed'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    /** The shape this Cove takes: persona, guide, seasonal, advice, brand or shop. Named on the card. */
    kind: string
    title: string
    intro: string | null
    url: string
    searches: number
}

interface Persona {
    title: string
    blurb: string | null
    url: string
    /** Null until a curator picks one; the component draws a figure. */
    scene: SceneKey | null
    findCount: number
}

interface Props {
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    signedIn: boolean
    /** Everything the list wizard offers, in the shape My Lists and the Gift Cove send it. */
    recipients: WizardOffer['recipients']
    friends: WizardOffer['friends']
    occasions: WizardOffer['occasions']
    myLists: WizardOffer['myLists']
    personas: Persona[]
    coves: Cove[]
}

export default function Home({ today, signedIn, recipients, friends, occasions, myLists, personas, coves }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    return (
        <>
            <Head title={t('home.title')} />

            {/*
              Wider than the body copy that follows it.

              Each headline line is a whole sentence, and at 5xl in a 2xl column
              both of them wrapped — which breaks the rhythm the two lines exist
              to create. The paragraph keeps its own narrower measure, because
              prose at this width is genuinely harder to read.
            */}
            <section className="max-w-5xl">
                {/*
                  The drawing sits beside the whole pitch, not just the
                  paragraph, so the headline and the search box stay on one
                  optical column rather than being stepped around it.

                  Below md it is not stacked but set beside the headline, small.
                  Stacked on a phone it cost roughly a screen of height and
                  pushed the search field — the one thing this page wants
                  pressed — under the fold, so until 2026-09-08 it was hidden
                  there. The owner missed it: the drawing is the one place the
                  mark appears at size, and a first screen without it is a
                  page of words. Beside a three-line headline it costs no
                  height at all; the headline wraps the same three lines in
                  the narrower column.
                */}
                <div className="flex flex-col gap-10 md:flex-row md:items-center md:gap-12">
                    <div className="min-w-0 flex-1">
                        {/*
                          `text-3xl` on a phone, and the step to `text-4xl`
                          waits for `sm`.

                          Two sentences on two lines is the whole shape of this
                          headline — that is what the `<br />` is for. At 36px
                          in a 358px column both of them wrap, so the reader
                          gets three ragged lines instead of two whole thoughts,
                          and 120px of the first screen goes to the wrapping
                          rather than the words.
                        */}
                        <div className="flex items-center gap-3 md:block">
                            <h1 className="min-w-0 flex-1 text-3xl font-semibold tracking-tight text-balance sm:text-4xl lg:text-5xl">
                                {t('home.headline_1')}
                                <br />
                                {t('home.headline_2')}
                            </h1>
                            <HomeIllustration className="w-32 shrink-0 text-ink-soft sm:w-40 md:hidden" />
                        </div>

                        {/*
                          The pitch, in the owner's words (2026-09-13): what
                          you make here and whom you share it with. It replaced
                          a search field, which had replaced the Gift Finder
                          button. The field taught the site's second job on
                          its first screen; this says the first job, and the
                          header carries the search on every page, and the
                          search card lower down carries the camera. A line
                          under the buttons ("Search anything · Scan a
                          barcode · Keep it all in one place") lasted a few
                          hours; the owner took it out the same day.
                        */}
                        <p className="mt-5 max-w-xl text-lg text-ink-soft">{t('home.intro')}</p>

                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link href={`${base}/lists?new=mine`} className={buttonClasses('primary', 'lg')}>
                                {t('home.cta_wishlist')}
                            </Link>
                            <Link href={`${base}/discover-cove`} className={buttonClasses('secondary', 'lg')}>
                                {t('home.cta_gift')}
                            </Link>
                        </div>
                    </div>

                    <HomeIllustration className="hidden w-72 shrink-0 text-ink-soft md:block lg:w-80" />
                </div>
            </section>

            {/*
              A search, where "Recently searched" was (owner's call, 2026-09-13).

              That band showed three of other people's searches as pictures.
              It was invisible in development and on any market without
              search history, and it answered a question nobody arrives
              with. The card asks the one they do. The same card sits at the
              top of Find a gift; see SearchCard.
            */}
            <SearchCard className="mt-10 sm:mt-12" />

            {/*
              Today's Cove and the signup for it, straight under the search
              card (owner's call, 2026-09-13). They sat below the Organise
              and Discover bands, a screen and a half down; the thing that
              makes somebody return tomorrow should not be that deep. The
              search answers the visitor who knows what they want; this
              answers the one who does not, and the email keeps them.
            */}
            {today && (
                <section className="mt-10 sm:mt-14" aria-labelledby="today-heading">
                    <div className="rounded-card border border-line bg-card p-5 sm:p-8">
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
                                          button — the anchor takes the click.
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

                        <Link
                            href={today.url}
                            className="mt-6 inline-block font-medium text-accent-dark hover:text-ink"
                        >
                            {t('home.today_cta')} →
                        </Link>
                    </div>
                </section>
            )}

            {/* Only where there is a Cove to subscribe to. Offering a daily
                email on a site with no editions yet is a promise we would then
                have to keep. */}
            {today && (
                <div className="mt-10">
                    <CoveSubscribe source="home" />
                </div>
            )}

            {/*
              Making a list, right here (owner's call, 2026-09-13).

              This was the Organise band: a heading, a "Make a new list"
              button that unfolded four kinds, and five cards into lists that
              already exist. The wizard those four kinds led to lives on My
              Lists and the Gift Cove; the front page now mounts it under the
              button's own words, so making a list is one step from the
              pitch that promised it, and the cards into existing lists are
              where the header's Make a list entry already goes.
            */}
            <section className="mt-10 sm:mt-14" aria-labelledby="new-list-heading">
                <h2 id="new-list-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                    {t('lists.make_new')}
                </h2>
                <div className="mt-4">
                    <ListWizard
                        signedIn={signedIn}
                        recipients={recipients}
                        friends={friends}
                        occasions={occasions}
                        myLists={myLists}
                    />
                </div>
            </section>

            {/*
              The discovery band, and the header for everything under it.

              Three of these are demonstrated further down with real content —
              today's edition, the personas, and the Coves themselves — so this
              is a signpost followed by proof rather than a signpost on its own.
              Surprise is the one that has nowhere else to appear on this page,
              and it is also the one whose name promises least: "Surprise me"
              cannot be evaluated before you press it, so the sentence under it
              is doing the work the label cannot.

              Heading and intro come from `discover_cove.*`, the same keys the
              hub page uses. One source, so the front page and the page it links
              to cannot drift into describing the same three things differently
              — which is the defect that produced two names for the Gift Cove.
            */}
            <section className="mt-10 sm:mt-14" aria-labelledby="discover-heading">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="discover-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                        {t('discover_cove.title')}
                    </h2>
                    <Link
                        href={`${base}/discover-cove`}
                        className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0"
                    >
                        {t('nav.discover_cove')} →
                    </Link>
                </div>

                <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    {(
                        [
                            {
                                key: 'daily',
                                href: `${base}/${market.coveSegment}`,
                                name: t('nav.daily'),
                                what: t('discover_cove.daily_what'),
                            },
                            {
                                key: 'surprise',
                                href: `${base}/surprise`,
                                name: t('nav.surprise'),
                                what: t('discover_cove.surprise_what'),
                            },
                            {
                                key: 'idea',
                                href: `${base}/guides`,
                                name: t('nav.smart'),
                                what: t('discover_cove.idea_what'),
                            },
                            /*
                              Personas, and only once a market has one.

                              The same rule the Discover hub applies, and for
                              the same reason: the persona shelf starts empty
                              in a new market, and a card pointing at "nothing
                              here yet" would be this band's only bad link.
                              Asked for on 2026-09-08, when the band listed
                              the other three shapes and not the one built
                              around a person.
                            */
                            ...(personas.length > 0
                                ? [
                                      {
                                          key: 'persona',
                                          href: `${base}/gift-ideas`,
                                          name: t('gift_ideas.title'),
                                          what: t('discover_cove.persona_what'),
                                      },
                                  ]
                                : []),
                            /*
                              The last one is the one that is not ours.

                              Daily, Surprise and the Coves are all this site
                              showing you something it chose; Ask others is the
                              one where the answer comes from another person. Its
                              sentence comes from `ask.nav_hint` — the same key
                              the Discover hub uses — so the two pages describing
                              it cannot drift into describing it differently.

                              That is also why `what` is now spelled out per
                              entry rather than derived from the key: three of
                              these live under `discover_cove.*` and this one
                              does not, and inventing a fourth `discover_cove`
                              key would be a second copy of a sentence that
                              already exists.
                            */
                            {
                                key: 'ask',
                                href: `${base}/ask`,
                                name: t('ask.title'),
                                what: t('ask.nav_hint'),
                            },
                        ] as { key: CoveSceneKey; href: string; name: string; what: string }[]
                    ).map((cove) => (
                        <li key={cove.key}>
                            {/* Same treatment as Organise above, and for the
                                same reason — the two bands sit one under the
                                other, so one of them staying tall would undo
                                half the saving and read as the odd one out. */}
                            <Link
                                href={cove.href}
                                className="flex h-full flex-row items-center gap-4 rounded-card border border-line bg-card p-4 text-ink transition hover:border-ink hover:text-accent sm:flex-col sm:items-stretch sm:gap-0 sm:p-5"
                            >
                                <CoveIllustration
                                    name={cove.key}
                                    className="h-12 w-16 shrink-0 sm:h-28 sm:w-full"
                                />
                                <div className="min-w-0 sm:mt-4">
                                    <h3 className="font-medium">{cove.name}</h3>
                                    <p className="mt-1 text-sm text-ink-soft sm:mt-2">{cove.what}</p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            {/*
              No persona band here any more. It stood between the Discover
              band and the Coves band from 2026-09-01 to 2026-09-08, three
              drawn cards under "Cadeau-ideeën, per type", and the owner took
              it out: the Discover band above already carries a card to the
              persona shelf, and the Coves band below now mixes personas in
              with the other kinds, so the front page named the same shelf
              three times. `personas` still arrives; the Discover card is
              shown only when the market has one.
            */}

            {/*
              What this visitor looked at, from the device's own memory. A
              returning visitor deciding between two things wants the two
              things back; the band is empty until there is something in it.
            */}
            <RecentlyViewed className="mt-10 sm:mt-14" />

            {coves.length > 0 && (
                <section className="mt-10 sm:mt-14" aria-labelledby="coves-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="coves-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                            {t('home.coves_heading')}
                        </h2>
                        {/*
                          "All Coves" now goes to the page that is all Coves.
                          It pointed at /guides, which is the theme archive —
                          one of three — so the homepage promised the whole
                          shelf and delivered a third of it. Two links reading
                          "All Coves" and landing in different places is the
                          drift this codebase keeps writing about.
                        */}
                        <Link href={`${base}/coves`} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                            {t('home.coves_all')} →
                        </Link>
                    </div>

                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-4 transition hover:border-ink sm:p-5"
                                >
                                    <span className="text-2xs font-medium tracking-wide text-ink-soft uppercase">
                                        {t(`home.cove_kind_${cove.kind}`)}
                                    </span>
                                    <h3 className="mt-1 font-medium">{cove.title}</h3>
                                    {cove.intro && (
                                        <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>
                                    )}
                                    {cove.searches > 0 && (
                                        <span className="mt-auto pt-3 text-xs text-ink-soft">
                                            {t('home.coves_volume', { count: n(cove.searches) })}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              The catalogue counters and the `bc:ingest` hint that used to close
              this page are gone.

              They were scaffolding: honest numbers, shown while ingestion was
              being built, to prove the pipeline was real. To a visitor they are
              a boast about our warehouse — "412,908 products" says nothing
              about whether we have the one they want, and a page that ends on
              inventory size ends on us instead of on them. The empty state was
              worse: an artisan command, on the front page, telling a shopper to
              run something on a server they do not have.

              The numbers that survive here are the ones that belong to the
              visitor (their lists, their people) or to a Cove (its monthly
              search volume) — those are reasons to click.
            */}
        </>
    )
}
