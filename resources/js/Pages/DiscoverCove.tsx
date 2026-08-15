import { Head, Link } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from '../Components/CoveIcon'
import { useTranslations } from '../useTranslations'

interface Props {
    urls: { daily: string; surprise: string; guides: string }
}

/**
 * The Discover Cove hub.
 *
 * Three cards, one per Cove, each answering *what is this* in one sentence
 * before it asks for a click — the same rule the Gift Cove cards follow, and
 * for the same reason: "Surprise me" promises nothing a visitor can evaluate
 * in advance, so the page that sends them there has to say what arrives.
 *
 * No counts and no numbers. Everything worth counting belongs to a Cove and is
 * already on that Cove's page; a hub that totals things repeats the mistake
 * homepage.md removed from the front page.
 */
export default function DiscoverCove({ urls }: Props) {
    const { t } = useTranslations()

    const coves: { key: CoveKey; href: string; name: string; what: string }[] = [
        { key: 'daily', href: urls.daily, name: t('nav.daily'), what: t('discover_cove.daily_what') },
        { key: 'surprise', href: urls.surprise, name: t('nav.surprise'), what: t('discover_cove.surprise_what') },
        { key: 'idea', href: urls.guides, name: t('nav.coves'), what: t('discover_cove.idea_what') },
    ]

    return (
        <>
            <Head title={t('discover_cove.title')} />

            <div className="mx-auto max-w-4xl px-4 py-10">
                <h1 className="text-3xl font-semibold tracking-tight text-ink">{t('discover_cove.title')}</h1>
                <p className="mt-3 max-w-2xl text-ink-soft">{t('discover_cove.intro')}</p>

                <ul className="mt-8 grid gap-4 sm:grid-cols-3">
                    {coves.map((cove) => (
                        <li key={cove.key}>
                            <Link
                                href={cove.href}
                                className="flex h-full flex-col gap-3 rounded-xl border border-line p-5 hover:border-accent"
                            >
                                <span className="text-accent">
                                    <CoveIcon name={cove.key} className="h-8 w-8" />
                                </span>
                                <span className="font-medium text-ink">{cove.name}</span>
                                <span className="text-sm text-ink-soft">{cove.what}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    )
}
