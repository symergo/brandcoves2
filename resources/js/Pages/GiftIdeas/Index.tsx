import { Head, Link, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import SceneIllustration, { type SceneKey } from '../../Components/SceneIllustration'
import { useTranslations } from '../../useTranslations'

interface Persona {
    slug: string
    title: string
    blurb: string | null
    url: string
    /** Null until a curator picks one; the component draws a figure. */
    scene: SceneKey | null
    findCount: number
}

interface Props {
    personas: Persona[]
    /** Gift landing pages for a whole person: "Gift ideas for dad". Closest people first. */
    forWhom?: { label: string; url: string }[]
    /** Gifts per occasion (Moederdag, a housewarming): a row of their own. Same card. */
    occasions?: Persona[]
}

/**
 * The shelf of gift personas, and since 2026-09-28 of occasions.
 *
 * Deliberately a plain grid rather than a feed. These do not arrive in an
 * order that matters and none of them is more current than another — a persona
 * written in March is exactly as useful in November, which is the whole reason
 * it has no date on it.
 *
 * Headings only once there are occasions: until then the page is the one row
 * it always was, and a lone heading over it would announce a second row that
 * is not there.
 */
export default function Index({ personas, forWhom = [], occasions = [] }: Props) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const twoRows = occasions.length > 0

    return (
        <>
            <Head title={t('gift_ideas.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift_ideas.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift_ideas.description')}</p>
            </header>

            {/* The pages per person, back on 2026-11-24 (owner: "Put the row back"), with the
                line under it to every page per person and interest. */}
            {forWhom.length > 0 && (
                <nav aria-label={t('gift_landing.by_recipient')} className="mt-6">
                    <h2 className="text-sm font-medium text-ink-soft">{t('gift_landing.by_recipient')}</h2>
                    <ul className="mt-2 flex flex-wrap gap-2">
                        {forWhom.map((page) => (
                            <li key={page.url}>
                                <Link href={page.url} className="rounded-full border border-line bg-card px-3 py-1 text-sm hover:border-ink/40">
                                    {page.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}

            <div>
                <p className="mt-3">
                    <Link href={`/${market.key}/gift-ideas/for`} className="font-medium text-accent-dark hover:text-ink">
                        {t('gift_ideas.for_whom_link')} <span aria-hidden>&rarr;</span>
                    </Link>
                </p>
            </div>

            {personas.length === 0 && !twoRows ? (
                <p className="mt-8 text-ink-soft">{t('gift_ideas.empty')}</p>
            ) : (
                <>
                    {personas.length > 0 && (
                        <section className="mt-8" aria-labelledby={twoRows ? 'shelf-personas' : undefined}>
                            {twoRows && (
                                <h2 id="shelf-personas" className="mb-4 text-lg font-semibold">
                                    {t('gift_ideas.personas_heading')}
                                </h2>
                            )}
                            <Shelf cards={personas} />
                        </section>
                    )}

                    {twoRows && (
                        <section className="mt-10" aria-labelledby="shelf-occasions">
                            <h2 id="shelf-occasions" className="mb-4 text-lg font-semibold">
                                {t('gift_ideas.occasions_heading')}
                            </h2>
                            <Shelf cards={occasions} />
                        </section>
                    )}
                </>
            )}
        </>
    )
}

function Shelf({ cards }: { cards: Persona[] }) {
    const { t, n } = useTranslations()

    return (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {cards.map((persona) => (
                <li key={persona.url}>
                    {/*
                      Compact since 2026-09-29 (owner): a small drawing beside
                      the words instead of a wide one above them, two lines of
                      blurb, so a screen holds three times the cards.

                      The whole card is the link. The drawing takes the card's
                      text colour, so it changes with the title on hover; that
                      is what `currentColor` throughout the scene buys.
                    */}
                    <Link
                        href={persona.url}
                        className="group flex h-full items-start gap-3 rounded-card border border-line bg-card p-3 text-ink hover:border-accent/40"
                    >
                        <SceneIllustration
                            name={persona.scene}
                            className="h-16 w-16 shrink-0 text-ink-soft transition group-hover:text-accent"
                        />
                        <span className="min-w-0">
                            <h3 className="font-medium group-hover:text-accent group-hover:underline">{persona.title}</h3>
                            {persona.blurb && (
                                // No `block` here: it would override line-clamp's own display and show every line.
                                <span className="mt-0.5 line-clamp-2 text-sm text-ink-soft">{persona.blurb}</span>
                            )}
                            <span className="mt-1 block text-xs text-ink-soft">
                                {t('gift_ideas.find_count', { count: n(persona.findCount) })}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    )
}
