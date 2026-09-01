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
    key: 'daily' | 'gift' | 'smart' | 'brand' | 'shop'
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
    smart: 'idea',
    brand: 'brand',
    shop: 'shop',
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

            {sections.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('coves.empty')}</p>
            ) : (
                sections.map((section) => (
                    <section key={section.key} className="mt-12">
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 shrink-0 text-accent">
                                <CoveIcon name={icons[section.key]} className="h-6 w-6" />
                            </span>

                            <div className="max-w-2xl">
                                <h2 className="text-lg font-medium">
                                    {t(`coves.${section.key}_heading`)}
                                </h2>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {t(`coves.${section.key}_what`)}
                                </p>
                            </div>
                        </div>

                        <ul className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {section.coves.map((cove) => (
                                <li key={cove.url} className="rounded-lg border border-line bg-card p-5">
                                    {cove.date && (
                                        <p className="text-xs text-ink-soft">{cove.date}</p>
                                    )}

                                    <Link
                                        href={cove.url}
                                        className={`font-medium hover:underline ${cove.date ? 'mt-1 block' : ''}`}
                                    >
                                        {cove.title}
                                    </Link>

                                    {cove.intro && (
                                        <p className="mt-2 line-clamp-3 text-sm text-ink-soft">
                                            {cove.intro}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <p className="mt-4">
                            <Link href={section.url} className="text-sm text-accent hover:underline">
                                {t(`coves.${section.key}_all`)} →
                            </Link>
                        </p>
                    </section>
                ))
            )}
        </>
    )
}
