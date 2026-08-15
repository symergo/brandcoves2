import { Head, Link } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from '../Components/CoveIcon'
import { useTranslations } from '../useTranslations'

interface Cove {
    title: string
    intro: string | null
    url: string
    searches: number
}

interface Props {
    urls: { daily: string; surprise: string; guides: string }
    coves: Cove[]
}

/**
 * The Discover Cove hub.
 *
 * Three cards, one per Cove, each answering *what is this* in one sentence
 * before it asks for a click — the same rule the Gift Cove cards follow, and
 * for the same reason: "Surprise me" promises nothing a visitor can evaluate
 * in advance, so the page that sends them there has to say what arrives.
 *
 * No counts and no totals. Everything worth counting belongs to a Cove and is
 * already on that Cove's page; a hub that totals things repeats the mistake
 * homepage.md removed from the front page.
 *
 * The Coves themselves are listed underneath, because the archive is the one
 * card here whose value is its contents rather than its promise. "Long reads
 * around a theme" cannot be evaluated; a dozen titles can.
 */
export default function DiscoverCove({ urls, coves }: Props) {
    const { t, n } = useTranslations()

    // The three surfaces. Named `sections` rather than `coves` because `coves`
    // is the archive's articles here, exactly as it is on the front page.
    const sections: { key: CoveKey; href: string; name: string; what: string }[] = [
        { key: 'daily', href: urls.daily, name: t('nav.daily'), what: t('discover_cove.daily_what') },
        { key: 'surprise', href: urls.surprise, name: t('nav.surprise'), what: t('discover_cove.surprise_what') },
        { key: 'idea', href: urls.guides, name: t('nav.coves'), what: t('discover_cove.idea_what') },
    ]

    return (
        <>
            <Head title={t('discover_cove.seo_title')} />

            <div className="mx-auto max-w-4xl px-4 py-10">
                <h1 className="text-3xl font-semibold tracking-tight text-ink">{t('discover_cove.title')}</h1>
                <p className="mt-3 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

                <ul className="mt-8 grid gap-4 sm:grid-cols-3">
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
                  The archive, spelled out. Same copy keys as the front page's
                  band — one source, so the two pages describing the same shelf
                  cannot drift into describing it differently.
                */}
                {coves.length > 0 && (
                    <section className="mt-12" aria-labelledby="coves-heading">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="coves-heading" className="text-2xl font-semibold tracking-tight text-ink">
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
            </div>
        </>
    )
}
