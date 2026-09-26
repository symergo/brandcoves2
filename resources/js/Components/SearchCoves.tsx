import { Link } from '@inertiajs/react'
import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

export interface SearchCove {
    /** A CoveKind value, or `community` for a list somebody published. */
    kind: string
    title: string
    url: string
    image: string | null
}

/**
 * The Coves a search term matches, as one row of small cards above the
 * products (owner's request, 2026-09-26).
 *
 * One row whatever the width, scrolling sideways when the cards do not fit,
 * so the products below never move further down than one small card's
 * height. Ours and the lists people published share the row; the label on each
 * card says which is which ("Buying guide", "Community Cove"). Chosen and
 * cached by `App\Services\Search\CoveMatches`; nothing here decides what matches.
 */
export default function SearchCoves({ coves, allUrl }: { coves: SearchCove[]; allUrl: string }) {
    const { t } = useTranslations()

    if (coves.length === 0) {
        return null
    }

    return (
        <section className="mb-6" aria-labelledby="search-coves-heading">
            <div className="mb-2 flex items-baseline justify-between gap-3">
                <h2 id="search-coves-heading" className="text-sm font-semibold">
                    {t('search.coves_heading')}
                </h2>
                <Link href={allUrl} className="inline-flex min-h-10 items-center text-sm text-accent-dark hover:text-ink sm:min-h-0">
                    {t('home.coves_all')} →
                </Link>
            </div>

            <ul className="-mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
                {coves.map((cove) => (
                    <li key={cove.url} className="w-64 shrink-0 snap-start">
                        <Link
                            href={cove.url}
                            className="flex h-full items-center gap-3 rounded-card border border-line bg-card p-3 transition hover:border-ink"
                        >
                            {cove.image ? (
                                <img
                                    src={cove.image}
                                    alt=""
                                    loading="lazy"
                                    width={48}
                                    height={48}
                                    className="h-12 w-12 shrink-0 rounded bg-white object-contain"
                                    onError={(e) => {
                                        e.currentTarget.hidden = true
                                    }}
                                />
                            ) : (
                                <span aria-hidden className="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-cream text-ink-soft">
                                    <ToolIcon name="guides" className="h-5 w-5" />
                                </span>
                            )}
                            <span className="min-w-0">
                                <span className="block text-2xs font-medium tracking-wide text-ink-soft uppercase">
                                    {t(`home.cove_kind_${cove.kind}`)}
                                </span>
                                <span className="mt-0.5 line-clamp-2 text-sm font-medium">{cove.title}</span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    )
}
