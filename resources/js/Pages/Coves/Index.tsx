import { Head, Link } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from '../../Components/CoveIcon'
import { useTranslations } from '../../useTranslations'

interface Cove {
    title: string
    intro: string | null
    url: string
    date: string | null
}

interface Section {
    key: 'daily' | 'gift' | 'occasion' | 'smart' | 'brand' | 'shop' | 'community'
    url: string
    coves: Cove[]
}

interface Props {
    sections: Section[]
}

/**
 * The icon each band is headed with.
 *
 * The same drawings the Discover menu uses, so a reader arriving from the menu
 * meets the mark they just clicked. Kept as a map rather than passed from the
 * controller: what a section looks like is this page's business, and a server
 * sending icon names is a server deciding layout.
 */
const icons: Record<Section['key'], CoveKey> = {
    daily: 'daily',
    gift: 'persona',
    occasion: 'occasion',
    smart: 'idea',
    brand: 'brand',
    shop: 'shop',
    // Lists other visitors published: the same mark as Ask others, the other
    // place on the site where what you read comes from people.
    community: 'ask',
}

/**
 * All Coves: the whole shelf, by shape.
 *
 * Bands rather than one stream, and the reasoning is in `CovesController`: a
 * market publishes an edition every morning and a persona every few weeks, so
 * anything sorted purely by date is the daily column with strangers in it.
 *
 * Each band ends in a link to the index that owns it. This page is the overview
 * and is capped; the archives are where you go to read the rest, and pretending
 * otherwise would make this a fourth index competing with three that already
 * work.
 */
export default function CovesIndex({ sections }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('coves.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('coves.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('coves.intro')}</p>
            </header>

            {/*
              The sections as chips to jump to (site review, 2026-11-12): the
              page ran to some forty-five boxes in a column, and what kinds
              there are was only learned by scrolling past all of them.
            */}
            {sections.length > 1 && (
                <nav aria-label={t('coves.title')} className="mt-6 flex flex-wrap gap-2">
                    {sections.map((section) => (
                        <a
                            key={section.key}
                            href={`#${section.key}`}
                            className="inline-flex items-center gap-2 rounded-full border border-line bg-card px-3 py-1.5 text-sm font-medium transition hover:border-ink"
                        >
                            <CoveIcon name={icons[section.key]} className="h-4 w-4 text-accent" />
                            {t(`coves.${section.key}_heading`)}
                        </a>
                    ))}
                </nav>
            )}

            {sections.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('coves.empty')}</p>
            ) : (
                sections.map((section) => (
                    <section key={section.key} id={section.key} className="mt-12 scroll-mt-24">
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 shrink-0 text-accent">
                                <CoveIcon name={icons[section.key]} className="h-6 w-6" />
                            </span>

                            <div className="max-w-2xl flex-1">
                                <h2 className="text-lg font-semibold">
                                    {t(`coves.${section.key}_heading`)}
                                </h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {t(`coves.${section.key}_what`)}
                                </p>
                            </div>

                            {/* "All of them" beside the heading, where the eye already is. */}
                            <Link href={section.url} className="hidden shrink-0 text-sm font-medium text-accent-dark hover:underline sm:block">
                                {t(`coves.${section.key}_all`)} →
                            </Link>
                        </div>

                        {/* Six a section: a taste, and the rest is one press away. The whole card is the link. */}
                        <ul className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {section.coves.slice(0, 6).map((cove) => (
                                <li key={cove.url}>
                                    <Link href={cove.url} className="group flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink">
                                        {cove.date && <span className="text-xs text-ink-soft">{cove.date}</span>}
                                        <span className={`font-medium group-hover:underline ${cove.date ? 'mt-1' : ''}`}>{cove.title}</span>
                                        {cove.intro && <span className="mt-2 line-clamp-2 text-sm text-ink-soft">{cove.intro}</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <p className="mt-4 sm:hidden">
                            <Link href={section.url} className="text-sm font-medium text-accent-dark hover:underline">
                                {t(`coves.${section.key}_all`)} →
                            </Link>
                        </p>
                    </section>
                ))
            )}
        </>
    )
}
