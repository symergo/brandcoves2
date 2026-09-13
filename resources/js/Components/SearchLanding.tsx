import { Link, usePage } from '@inertiajs/react'
import CoveIcon from './CoveIcon'
import ToolIcon from './ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/** See App\Services\Search\SearchLanding. */
export interface Landing {
    recentSearches: { term: string; url: string; images: string[] }[]
    brands: { name: string; url: string }[]
}

/**
 * The search page before a search: ways in, not products.
 *
 * A grid under an empty box answers a question nobody asked (owner's call,
 * 2026-09-13). So: what people searched lately, the brands on your own lists,
 * and the site's other ways of finding something. Each section is dropped
 * when it has nothing, so a stranger with no history on a quiet market sees
 * the tools alone, which is still a page.
 */
export default function SearchLanding({ landing }: { landing: Landing }) {
    const { market, auth } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const tools = [
        {
            key: 'ask',
            href: `${base}/ask`,
            icon: <CoveIcon name="ask" className="h-5 w-5" />,
            title: t('ask.title'),
            body: t('nav.hint_ask'),
        },
        {
            key: 'suggest',
            href: `${base}/lists?new=mine`,
            icon: <ToolIcon name="suggestions" className="h-5 w-5" />,
            title: t('search.landing_suggest_title'),
            body: t('search.landing_suggest_body'),
        },
        {
            key: 'giftlist',
            href: `${base}/lists?new=for_someone`,
            icon: <ToolIcon name="giftlist" className="h-5 w-5" />,
            title: t('search.landing_giftlist_title'),
            body: t('search.landing_giftlist_body'),
        },
        {
            key: 'tips',
            href: `${base}/search-help`,
            icon: <ToolIcon name="help" className="h-5 w-5" />,
            title: t('search_help.title'),
            body: t('search_help.intro'),
        },
    ]

    return (
        <div className="mt-8 space-y-10">
            {landing.recentSearches.length > 0 && (
                <section aria-labelledby="landing-recent">
                    <h2 id="landing-recent" className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                        {t('search.landing_recent')}
                    </h2>
                    {/* The home page's band, the same shape: `grid-cols-1` and
                        `min-w-0` are what let a long term truncate. */}
                    <ul className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                        {landing.recentSearches.map((recent) => (
                            <li key={recent.term} className="min-w-0">
                                <Link
                                    href={recent.url}
                                    className="flex items-center gap-3 rounded-card border border-line bg-card p-3 transition hover:border-ink"
                                >
                                    <span className="flex shrink-0 -space-x-2">
                                        {recent.images.slice(0, 4).map((src, i) => (
                                            <img
                                                key={i}
                                                src={src}
                                                alt=""
                                                loading="lazy"
                                                className="h-10 w-10 rounded-md border border-line bg-card object-contain"
                                            />
                                        ))}
                                    </span>
                                    <span className="min-w-0 truncate font-medium">{recent.term}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {auth.user && landing.brands.length > 0 && (
                <section aria-labelledby="landing-brands">
                    <h2 id="landing-brands" className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                        {t('search.landing_brands')}
                    </h2>
                    <ul className="mt-4 flex flex-wrap gap-2">
                        {landing.brands.map((brand) => (
                            <li key={brand.url}>
                                <Link
                                    href={brand.url}
                                    className="inline-flex min-h-11 items-center rounded-full border border-line bg-card px-4 text-sm font-medium transition hover:border-ink"
                                >
                                    {brand.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="landing-tools">
                <h2 id="landing-tools" className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                    {t('search.landing_tools')}
                </h2>
                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tools.map((tool) => (
                        <li key={tool.key}>
                            <Link
                                href={tool.href}
                                className="group flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                            >
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent transition group-hover:bg-accent group-hover:text-white">
                                    {tool.icon}
                                </span>
                                <span className="mt-4 font-medium">{tool.title}</span>
                                <span className="mt-2 text-sm text-ink-soft">{tool.body}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    )
}
