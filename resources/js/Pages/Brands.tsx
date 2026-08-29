import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../useTranslations'

interface Props {
    brands: { name: string; url: string }[]
}

/**
 * The brand index.
 *
 * Its whole job is that brand pages are not orphans. A URL space reachable only
 * from a search result is one a crawler finds slowly and trusts less, however
 * good the individual pages are — this is the single page that links to all of
 * them, and it is linked from the footer.
 *
 * Grouped by first letter rather than presented as one long list, because a
 * five-hundred-item alphabetical column is a page nobody scans.
 */
export default function Brands({ brands }: Props) {
    const { t, n } = useTranslations()

    // Non-letter initials (numbers, "3M") collect under "#" rather than each
    // getting a heading of their own.
    const groups = new Map<string, typeof brands>()

    for (const brand of brands) {
        const first = brand.name.charAt(0).toUpperCase()
        const key = /[A-Z]/.test(first) ? first : '#'
        groups.set(key, [...(groups.get(key) ?? []), brand])
    }

    const letters = [...groups.keys()].sort((a, b) => {
        if (a === '#') return 1
        if (b === '#') return -1
        return a.localeCompare(b)
    })

    return (
        <>
            <Head title={t('brand.index_seo_title')} />

            <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{t('brand.index_title')}</h1>
            <p className="mt-3 max-w-2xl text-ink-soft">{t('brand.index_intro')}</p>

            {letters.length === 0 ? (
                <p className="mt-8 rounded-card border border-line bg-card p-5 text-sm text-ink-soft">
                    {t('search.empty_hint')}
                </p>
            ) : (
                <>
                    <nav aria-label={t('brand.index_title')} className="mt-6 flex flex-wrap gap-2 text-sm">
                        {letters.map((letter) => (
                            <a
                                key={letter}
                                href={`#letter-${letter}`}
                                className="rounded border border-line px-2 py-1 hover:border-ink"
                            >
                                {letter}
                            </a>
                        ))}
                    </nav>

                    <div className="mt-8 space-y-8">
                        {letters.map((letter) => (
                            <section key={letter} id={`letter-${letter}`}>
                                <h2 className="border-b border-line pb-2 text-lg font-semibold">{letter}</h2>
                                <ul className="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                                    {(groups.get(letter) ?? []).map((brand) => (
                                        <li key={brand.url}>
                                            <Link href={brand.url} className="flex gap-2 py-0.5 hover:text-accent">
                                                <span className="flex-1 truncate">{brand.name}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                </>
            )}
        </>
    )
}
