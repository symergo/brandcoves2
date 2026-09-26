import { Link } from '@inertiajs/react'

/** One Community Cove as a card; built by App\Services\Cove\CommunityCoves::card(). */
export interface CommunityCoveCard {
    title: string
    /** "For a dad · Birthday · 9 ideas": who it is for in general words, never a name. */
    intro: string
    url: string
    image: string | null
    saves: number
    /** "Saved by 3 people", worded on the server where plurals live; null at none. */
    savesLabel: string | null
}

/**
 * Community Coves as a grid of cards: lists other people published
 * (docs/features/community-coves.md). Drawn by the Community Coves index and
 * by Find a gift's "Coves others made for someone like this".
 */
export default function CommunityCoveCards({ coves }: { coves: CommunityCoveCard[] }) {
    return (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {coves.map((cove) => (
                <li key={cove.url}>
                    <Link
                        href={cove.url}
                        className="group flex h-full gap-3 rounded-card border border-line bg-card p-4 transition hover:border-ink"
                    >
                        <span className="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-cream">
                            {cove.image && (
                                <img src={cove.image} alt="" loading="lazy" className="h-full w-full object-contain" />
                            )}
                        </span>
                        <span className="min-w-0">
                            <span className="block font-medium group-hover:text-accent">{cove.title}</span>
                            <span className="mt-1 block text-sm text-ink-soft">{cove.intro}</span>
                            {cove.savesLabel && (
                                <span className="mt-1 block text-xs text-ink-soft">{cove.savesLabel}</span>
                            )}
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    )
}
