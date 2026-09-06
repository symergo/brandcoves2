import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Props {
    urls: {
        home: string
        search: string
        gift: string
        daily: string
        guides: string
        surprise: string
        popular: string
        brands: string
        shops: string
        lists: string
    }
}

/**
 * The page behind every address that does not exist.
 *
 * ## What it is for
 *
 * Everyone who reaches this page wanted something specific and did not get it.
 * That is the only thing known about them, and it is enough to design around:
 * do not explain the error, offer the next move. So the page is a search box
 * and a short list of places worth going, and it says the site is fine in as
 * few words as possible.
 *
 * The search box is first because it is the only control here that can serve
 * the request they actually made. Someone who followed a dead link to a
 * headphones guide can type "koptelefoon" and be a click from what they came
 * for; no arrangement of navigation links does that.
 *
 * ## No apology, no error number
 *
 * "404" means nothing to most visitors and the ones it does mean something to
 * do not need consoling. A long apology also implies something is broken, when
 * an address that no longer exists is the ordinary condition of any site that
 * has been edited. One line, then the way forward.
 *
 * ## Nothing is fetched to build it
 *
 * A 404 is what crawlers and scanners hit most, and it is served on the worst
 * day the site has — the one where something is already wrong. So it does no
 * database work at all: every link on it is a route this market definitely has.
 * A "popular right now" rail would be a query per bogus URL, which is a cost
 * that scales with exactly the traffic worth spending nothing on.
 */
export default function NotFound({ urls }: Props) {
    const { t } = useTranslations()

    /*
     * The six destinations, in the order somebody lost is likely to want them.
     *
     * Searching and browsing gifts first, because those are what the site is
     * for; lists last, because it is the one that needs an account. Each
     * carries a line saying what it is — a grid of bare nouns makes the visitor
     * guess a second time, and they have already guessed wrong once.
     */
    const destinations: { href: string; title: string; blurb: string }[] = [
        { href: urls.gift, title: t('not_found.gift'), blurb: t('not_found.gift_blurb') },
        { href: urls.daily, title: t('not_found.daily'), blurb: t('not_found.daily_blurb') },
        { href: urls.guides, title: t('not_found.guides'), blurb: t('not_found.guides_blurb') },
        { href: urls.surprise, title: t('not_found.surprise'), blurb: t('not_found.surprise_blurb') },
        { href: urls.brands, title: t('not_found.brands'), blurb: t('not_found.brands_blurb') },
        { href: urls.lists, title: t('not_found.lists'), blurb: t('not_found.lists_blurb') },
    ]

    return (
        <>
            <Head title={t('not_found.seo_title')} />

            <div className="mx-auto max-w-3xl px-4 py-16 sm:py-24">
                <h1 className="text-3xl font-semibold text-ink sm:text-4xl">{t('not_found.title')}</h1>
                <p className="mt-3 max-w-xl text-ink-soft">{t('not_found.intro')}</p>

                <form action={urls.search} method="get" role="search" className="mt-8 flex max-w-xl flex-wrap gap-2">
                    <input
                        type="search"
                        name="q"
                        autoFocus
                        // The placeholder is the label, as on the home page: two
                        // strings for one field drift apart the moment one is
                        // rewritten.
                        aria-label={t('not_found.search_placeholder')}
                        placeholder={t('not_found.search_placeholder')}
                        className="w-full min-w-0 rounded-card border border-line bg-card px-4 py-3 text-ink placeholder:text-ink-soft focus:border-ink sm:w-auto sm:flex-1"
                    />
                    <button
                        type="submit"
                        className="shrink-0 rounded-lg bg-ink px-5 py-3 font-medium text-card transition hover:opacity-90"
                    >
                        {t('not_found.search_button')}
                    </button>
                </form>

                <h2 className="mt-14 text-sm font-semibold tracking-wide text-ink-soft uppercase">
                    {t('not_found.elsewhere')}
                </h2>

                <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                    {destinations.map((destination) => (
                        <li key={destination.href}>
                            <Link
                                href={destination.href}
                                className="block h-full rounded-card border border-line bg-card p-4 transition hover:border-ink"
                            >
                                <span className="font-medium text-ink">{destination.title}</span>
                                <span className="mt-1 block text-sm text-ink-soft">{destination.blurb}</span>
                            </Link>
                        </li>
                    ))}
                </ul>

                <p className="mt-10 text-sm text-ink-soft">
                    <Link href={urls.home} className="underline hover:text-ink">
                        {t('not_found.home')}
                    </Link>
                    {' · '}
                    <Link href={urls.popular} className="underline hover:text-ink">
                        {t('not_found.popular')}
                    </Link>
                    {' · '}
                    <Link href={urls.shops} className="underline hover:text-ink">
                        {t('not_found.shops')}
                    </Link>
                </p>
            </div>
        </>
    )
}
