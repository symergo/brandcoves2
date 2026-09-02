import { Link } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from './CoveIcon'
import { useTranslations } from '../useTranslations'

/** Other Coves of the kind being read. Null on a market's first of a kind. */
export interface CoveBand {
    key: 'daily' | 'gift' | 'smart' | 'shop'
    /** The index that owns this kind — `/daily`, `/gift-ideas`, `/guides`, `/shops`. */
    url: string
    coves: { title: string; intro: string; url: string; date: string | null }[]
}

/**
 * The mark the band is headed with — the drawings the Discover menu and
 * `/coves` use, so a reader arriving from either meets the mark they clicked.
 *
 * Kept here rather than sent from the controller, exactly as `Coves/Index`
 * keeps its copy: what a band looks like is the page's business, and a server
 * sending icon names is a server deciding layout.
 */
const icons: Record<CoveBand['key'], CoveKey> = {
    daily: 'daily',
    gift: 'persona',
    smart: 'idea',
    shop: 'shop',
}

/**
 * More Coves of the same kind, as cards under the article.
 *
 * Under the reading rather than beside it, and cards rather than a list of
 * titles in the rail. Reaching the end of a Cove is the strongest signal a
 * reader gives, and what it asks for is another one of these — which a card
 * can actually offer, because at the full width of the column there is room
 * for the line of copy that says what the next one is about. The same card
 * `/coves` uses, so the shelf looks the same wherever it is met.
 *
 * Its own kind only; the reasoning is in App\Services\Cove\CoveRail. Renders
 * nothing at all when the market has published no others — an empty grid under
 * a heading reads as a page that failed to load.
 */
export default function MoreCoves({ band }: { band: CoveBand | null }) {
    const { t } = useTranslations()

    if (band === null) {
        return null
    }

    return (
        <section className="mt-12">
            <h2 className="flex items-center gap-2 text-lg font-medium">
                <span aria-hidden className="shrink-0 text-accent">
                    <CoveIcon name={icons[band.key]} className="h-5 w-5" />
                </span>
                {t(`coves.${band.key}_heading`)}
            </h2>

            <ul className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {band.coves.map((cove) => (
                    <li key={cove.url} className="rounded-lg border border-line bg-card p-5">
                        {/*
                          Only an edition carries a date, and the card simply
                          omits it: a persona has none on purpose, because it
                          never stops being current.
                        */}
                        {cove.date && <p className="text-xs text-ink-soft">{cove.date}</p>}

                        <Link
                            href={cove.url}
                            className={`font-medium hover:underline ${cove.date ? 'mt-1 block' : ''}`}
                        >
                            {cove.title}
                        </Link>

                        {cove.intro && (
                            <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{cove.intro}</p>
                        )}
                    </li>
                ))}
            </ul>

            <p className="mt-4">
                <Link href={band.url} className="text-sm text-accent hover:underline">
                    {t(`coves.${band.key}_all`)} →
                </Link>
            </p>
        </section>
    )
}
