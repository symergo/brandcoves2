import { Head, Link } from '@inertiajs/react'
import SceneIllustration, { type SceneKey } from '../../Components/SceneIllustration'
import { useTranslations } from '../../useTranslations'

interface Props {
    guides: {
        title: string
        intro: string | null
        /** Never null — the server sends the kind's default. See GuideController. */
        scene: SceneKey
        url: string
        publishedAt: string | null
    }[]
}

/**
 * The shelf of buying guides, seasonal guides and advice articles.
 *
 * Each card carries a drawing of what its article is *about*. It carried none
 * until 2026-09-05, which on the three markets whose `/guides` is entirely
 * Advice Coves meant eight pieces about consumer rights, customs, reviews and
 * scam messages arriving as eight identical rectangles of text — a page a
 * reader has to read in full to find the one they came for, on a shelf where
 * the titles are the least distinctive thing about the writing.
 *
 * The same drawing the article's own page opens with, so clicking one confirms
 * you opened the one you meant. Same visual language as the persona shelf and
 * the homepage cards, and the same trick: `currentColor` throughout means the
 * whole card changes together on hover.
 */
export default function GuidesIndex({ guides }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('guides.seo_title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('guides.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('guides.subtitle')}</p>
            </header>

            {guides.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('guides.empty')}</p>
            ) : (
                <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {guides.map((guide) => (
                        <li
                            key={guide.url}
                            className="flex flex-col rounded-lg border border-line bg-card p-4"
                        >
                            <Link href={guide.url} className="group text-ink hover:text-accent">
                                <SceneIllustration
                                    name={guide.scene}
                                    className="h-28 w-full text-ink-soft transition group-hover:text-accent"
                                />
                                <h2 className="mt-3 font-medium group-hover:underline">
                                    {guide.title}
                                </h2>
                            </Link>

                            {guide.intro && (
                                <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{guide.intro}</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
