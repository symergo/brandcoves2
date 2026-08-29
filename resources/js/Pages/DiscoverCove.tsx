import { Head, Link, usePage } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from '../Components/CoveIcon'
import SaveToList from '../Components/SaveToList'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
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

interface Props {
    urls: { daily: string; surprise: string; guides: string; ask: string }
    coves: Cove[]
    /** Null before a market has published its first edition. */
    today: {
        theme: string
        blurb: string | null
        date: string
        label: string
        url: string
        finds: { id: number; title: string; image: string | null; price: number | null; url: string }[]
    } | null
    questions: Question[]
    askUrl: string
    /** Resampled on every visit — that is the point of the band. */
    surprises: { id: number; title: string; brand: string | null; image: string | null; price: number | null; url: string }[]
}

/**
 * The discovery landing page.
 *
 * Four cards, one per surface, each answering *what is this* in one sentence
 * before it asks for a click — the same rule the Gift Cove cards follow, and
 * for the same reason: "Surprise me" promises nothing a visitor can evaluate
 * in advance, so the page that sends them there has to say what arrives.
 *
 * ## Then it shows, rather than describes
 *
 * Four cards and nothing else made this a table of contents for the discovery
 * half rather than a landing page for it. Three of the four surfaces have
 * something real to put on the page, and each one is more persuasive than the
 * sentence about it:
 *
 * - **Today's edition**, dated, with its finds. The Daily Cove's whole argument
 *   is "this changes, come back tomorrow", and a card saying so asks the reader
 *   to take it on trust.
 * - **The Coves**, by title. "Long reads around a theme" sends a reader one
 *   click away to find out whether any of them is about anything they care
 *   about; a dozen titles answers that here.
 * - **The questions**. An unanswered one is the most effective invitation the
 *   board has — somebody who happens to know the answer recognises it on sight.
 *
 * Surprise is the one with nothing to show, by construction, and its card is
 * doing the work its name cannot.
 *
 * **Still no counts or totals.** A hub that totals things repeats the
 * catalogue-counter mistake `homepage.md` removed from the front page. Each
 * question's own answer count is a different thing: it belongs to that question
 * and travels with it.
 *
 * No container of its own — `SiteLayout`'s `<main>` is already `max-w-6xl px-4
 * py-10`, and this page used to nest a narrower one inside it.
 */
export default function DiscoverCove({ urls, coves, today, questions, askUrl, surprises }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    // The four surfaces. Named `sections` rather than `coves` because `coves`
    // is the archive's articles here, exactly as it is on the front page.
    const sections: { key: CoveKey; href: string; name: string; what: string }[] = [
        { key: 'daily', href: urls.daily, name: t('nav.daily'), what: t('discover_cove.daily_what') },
        { key: 'surprise', href: urls.surprise, name: t('nav.surprise'), what: t('discover_cove.surprise_what') },
        { key: 'idea', href: urls.guides, name: t('nav.inspiration_coves'), what: t('discover_cove.idea_what') },
        /*
         * The fourth is not ours.
         *
         * Daily, Surprise and the Coves are all this site showing you something
         * it chose. Ask others is the one surface where the answer comes from
         * another person — which is exactly why it belongs here rather than
         * under Organise: it is a way of finding something when you cannot
         * describe it well enough to search for it.
         */
        { key: 'ask', href: urls.ask, name: t('ask.title'), what: t('ask.nav_hint') },
    ]

    return (
        <>
            <Head title={t('discover_cove.seo_title')} />

            <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight text-ink">{t('discover_cove.title')}</h1>
            <p className="mt-3 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

            <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {sections.map((section) => (
                    <li key={section.key}>
                        <Link
                            href={section.href}
                            className="flex h-full flex-col gap-3 rounded-xl border border-line p-5 hover:border-accent"
                        >
                            <span className="text-accent">
                                <CoveIcon name={section.key} className="h-8 w-8" />
                            </span>
                            <span className="font-medium text-ink">{section.name}</span>
                            <span className="text-sm text-ink-soft">{section.what}</span>
                        </Link>
                    </li>
                ))}
            </ul>

            {/*
              Today's edition, shown rather than described. Same copy keys as
              the front page's band — one source, so the two pages cannot drift
              into describing the same edition differently.
            */}
            {today && (
                <section className="mt-14" aria-labelledby="today-heading">
                    <div className="rounded-card border border-line bg-card p-6">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="rounded-full bg-accent/10 px-3 py-1 text-xs font-medium tracking-wide text-accent uppercase">
                                {t('home.today_badge')}
                            </span>
                            <time dateTime={today.date} className="text-sm text-ink-soft">
                                {today.label}
                            </time>
                        </div>

                        <h2 id="today-heading" className="mt-3 text-xl sm:text-2xl font-semibold tracking-tight text-ink">
                            {today.theme}
                        </h2>
                        {today.blurb && <p className="mt-2 max-w-2xl text-ink-soft">{today.blurb}</p>}

                        {today.finds.length > 0 && (
                            <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                                        <Link
                                            href={find.url}
                                            className="flex h-full flex-col rounded-lg border border-line p-3 transition hover:border-ink"
                                        >
                                            {find.image && (
                                                <img
                                                    src={find.image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="mx-auto h-24 w-auto max-w-full object-contain"
                                                    onError={(e) => {
                                                        e.currentTarget.style.visibility = 'hidden'
                                                    }}
                                                />
                                            )}
                                            <p className="mt-2 line-clamp-2 text-sm font-medium">{find.title}</p>
                                            {find.price !== null && (
                                                <p className="mt-1 text-sm text-ink-soft">
                                                    {formatPrice(find.price, market)}
                                                </p>
                                            )}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link
                            href={today.url}
                            className="mt-6 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                        >
                            {t('home.today_cta')}
                        </Link>
                    </div>
                </section>
            )}

            {/*
              Surprise, demonstrated.

              This was the one card with nothing underneath it, which left the
              page arguing for three surfaces and merely asserting a fourth —
              and it is the surface whose promise is least evaluable in advance.
              "Show me something I didn't know existed" cannot be judged until
              you have seen one.

              Resampled server-side on every visit, which is also the property
              the band has to demonstrate rather than claim.
            */}
            {surprises.length > 0 && (
                <section className="mt-14" aria-labelledby="surprise-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="surprise-heading" className="text-xl sm:text-2xl font-semibold tracking-tight text-ink">
                            {t('nav.surprise')}
                        </h2>
                        <Link href={urls.surprise} className="text-sm font-medium text-accent hover:text-accent-dark">
                            {t('surprise.reroll')} →
                        </Link>
                    </div>
                    <p className="mt-1 max-w-2xl text-ink-soft">{t('discover_cove.surprise_what')}</p>

                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {surprises.map((find) => (
                            <li key={find.id} className="relative">
                                {/* Outside the anchor; see today's finds above. */}
                                <div className="absolute top-2 right-2 z-10">
                                    <SaveToList groupId={find.id} compact />
                                </div>
                                <Link
                                    href={find.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                >
                                    {find.image && (
                                        <img
                                            src={find.image}
                                            alt=""
                                            loading="lazy"
                                            className="mx-auto h-28 w-auto max-w-full object-contain"
                                            onError={(e) => {
                                                e.currentTarget.style.visibility = 'hidden'
                                            }}
                                        />
                                    )}
                                    {find.brand && (
                                        <span className="mt-3 text-xs tracking-wide text-ink-soft uppercase">
                                            {find.brand}
                                        </span>
                                    )}
                                    <p className="mt-1 line-clamp-2 text-sm font-medium">{find.title}</p>
                                    {find.price !== null && (
                                        <p className="mt-auto pt-2 text-sm text-ink-soft">
                                            {formatPrice(find.price, market)}
                                        </p>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {/*
              What the board is chewing on.

              An unanswered question is the most effective invitation this
              feature has: somebody who happens to know the answer recognises it
              on sight, and that is a far better reason to click than a card
              explaining what a question board is.
            */}
            {questions.length > 0 && (
                <section className="mt-14" aria-labelledby="questions-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="questions-heading" className="text-xl sm:text-2xl font-semibold tracking-tight text-ink">
                            {t('ask.title')}
                        </h2>
                        <Link href={askUrl} className="text-sm font-medium text-accent hover:text-accent-dark">
                            {t('ask.all')} →
                        </Link>
                    </div>
                    <p className="mt-1 max-w-2xl text-ink-soft">{t('ask.nav_hint')}</p>

                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {questions.map((question) => (
                            <li key={question.url}>
                                <Link
                                    href={question.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
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
                </section>
            )}

            {/*
              The archive, spelled out. Same copy keys as the front page's
              band — one source, so the two pages describing the same shelf
              cannot drift into describing it differently.
            */}
            {coves.length > 0 && (
                <section className="mt-14" aria-labelledby="coves-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="coves-heading" className="text-xl sm:text-2xl font-semibold tracking-tight text-ink">
                            {t('home.coves_heading')}
                        </h2>
                        <Link
                            href={urls.guides}
                            className="text-sm font-medium text-accent hover:text-accent-dark"
                        >
                            {t('home.coves_all')} →
                        </Link>
                    </div>
                    <p className="mt-1 max-w-2xl text-ink-soft">{t('home.coves_intro')}</p>

                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {coves.map((cove) => (
                            <li key={cove.url}>
                                <Link
                                    href={cove.url}
                                    className="flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                                >
                                    <h3 className="font-medium text-ink">{cove.title}</h3>
                                    {cove.intro && (
                                        <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>
                                    )}
                                    {cove.searches > 0 && (
                                        <span className="mt-auto pt-3 text-xs text-ink-soft/70">
                                            {t('home.coves_volume', { count: n(cove.searches) })}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    )
}
