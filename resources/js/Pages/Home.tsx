import { Head, Link, usePage } from '@inertiajs/react'
import CoveSubscribe from '../Components/CoveSubscribe'
import HomeIllustration from '../Components/HomeIllustration'
import ListWizard, { type WizardOffer } from '../Components/ListWizard'
import SaveToList from '../Components/SaveToList'
import SearchCard from '../Components/SearchCard'
import { buttonClasses } from '../Components/Button'
import RecentlyViewed from '../Components/RecentlyViewed'
import { formatOccasionDate, formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Cove {
    /** The shape this Cove takes: daily, persona, guide, seasonal, advice, brand or shop. Named on the row. */
    kind: string
    title: string
    intro: string | null
    url: string
    /** The edition's day for a daily, the publication day for the rest. ISO date, null if unknown. */
    date: string | null
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
    coves: Cove[]
}

export default function Home({ today, signedIn, recipients, friends, occasions, myLists, coves }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
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
              Making a list, right here, straight under the search card
              (owner's call, 2026-09-13; moved above Today's Cove the same day).

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
              What this visitor looked at, from the device's own memory. A
              returning visitor deciding between two things wants the two
              things back; the band is empty until there is something in it.
            */}
            <RecentlyViewed className="mt-10 sm:mt-14" />

            {/*
              Recent Coves, every kind, newest first (owner's call, 2026-09-13).

              This was a grid of six cards drawn round-robin from four lanes,
              under the Discover band's five signposts. The signposts went the
              same day: a list of what was actually published this week is
              the better invitation, and the archive it links to is where the
              kinds are grouped. A row, not a card, because ten cards is a
              page and ten rows is a band. Today's edition is left out — it
              has the band above — and each row names its kind, because a
              persona beside an advice piece beside a brand reads as three
              unrelated things without it.
            */}
            {coves.length > 0 && (
                <section className="mt-10 sm:mt-14" aria-labelledby="coves-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="coves-heading" className="text-xl sm:text-2xl font-semibold tracking-tight">
                            {t('home.coves_heading')}
                        </h2>
                        <Link href={`${base}/coves`} className="inline-flex min-h-11 items-center text-sm font-medium text-accent-dark hover:text-ink sm:min-h-0">
                            {t('home.coves_all')} →
                        </Link>
                    </div>

                    <ul className="mt-6 divide-y divide-line rounded-card border border-line bg-card">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex flex-col gap-1 p-4 transition hover:bg-cream sm:flex-row sm:items-baseline sm:gap-4"
                                >
                                    <span className="flex shrink-0 gap-2 text-2xs font-medium tracking-wide text-ink-soft uppercase sm:w-44">
                                        <span>{t(`home.cove_kind_${cove.kind}`)}</span>
                                        {cove.date && (
                                            <time dateTime={cove.date} className="normal-case tracking-normal">
                                                {formatOccasionDate(cove.date, market)}
                                            </time>
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block font-medium">{cove.title}</span>
                                        {cove.intro && (
                                            <span className="mt-0.5 line-clamp-2 block text-sm text-ink-soft">{cove.intro}</span>
                                        )}
                                    </span>
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
