import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Persona {
    slug: string
    title: string
    blurb: string | null
    url: string
    image: string | null
    findCount: number
}

interface Props {
    personas: Persona[]
}

/**
 * The shelf of gift personas.
 *
 * Deliberately a plain grid rather than a feed. These do not arrive in an
 * order that matters and none of them is more current than another — a persona
 * written in March is exactly as useful in November, which is the whole reason
 * it has no date on it.
 */
export default function Index({ personas }: Props) {
    const { t, n } = useTranslations()

    return (
        <>
            <Head title={t('gift_ideas.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift_ideas.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift_ideas.description')}</p>
            </header>

            {personas.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('gift_ideas.empty')}</p>
            ) : (
                <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {personas.map((persona) => (
                        <li
                            key={persona.slug}
                            className="flex flex-col rounded-lg border border-line bg-card p-4"
                        >
                            <Link href={persona.url} className="group">
                                {persona.image && (
                                    <img
                                        src={persona.image}
                                        alt=""
                                        loading="lazy"
                                        className="mx-auto h-36 object-contain"
                                    />
                                )}
                                <h2 className="mt-3 font-medium group-hover:underline">
                                    {persona.title}
                                </h2>
                            </Link>

                            {persona.blurb && (
                                <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{persona.blurb}</p>
                            )}

                            <p className="mt-auto pt-4 text-xs text-ink-soft">
                                {t('gift_ideas.find_count', { count: n(persona.findCount) })}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
