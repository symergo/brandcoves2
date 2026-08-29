import { Head, Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import PreviewBanner from '../../Components/PreviewBanner'
import { useTranslations } from '../../useTranslations'
import SaveToList from '../../Components/SaveToList'

interface Find {
    id: number
    groupId: number
    title: string
    image: string | null
    price: Cents | null
    merchantCount: number
    discountPercent: number | null
    blurb: string | null
    url: string
}

interface Props {
    preview?: boolean

    persona: {
        id: number
        slug: string
        title: string
        blurb: string | null
        /**
         * Paragraphs of HTML, links already resolved server-side, each
         * carrying the ids of the products that paragraph names.
         */
        editorial: { html: string; groupIds: number[] }[]
    }
    finds: Find[]
    guide: {
        title: string
        intro: string | null
        url: string
        itemCount: number
        searchVolume: number
    } | null
}

/**
 * One gift persona.
 *
 * The Daily Cove's article, without the furniture that only makes sense for a
 * dated page: no archive strip, no "earlier editions", no subscribe box. A
 * persona is not something you catch up with — it is a shelf, permanently
 * there, and the invitation at the end is to the next persona rather than to
 * tomorrow.
 *
 * Reactions are absent for the same reason. 👍 / 👎 on a Daily is a signal
 * about a find on the day it appeared; on a page that stands for a year it
 * would accumulate into a rating nobody meant to give.
 */
export default function Persona({ preview = false, persona, finds, guide }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const byGroup: Record<number, Find> = Object.fromEntries(finds.map((f) => [f.groupId, f]))

    // Whatever the article did not get to. A persona can carry more finds than
    // the copy names, and dropping them would lose products somebody chose.
    const named = new Set(persona.editorial.flatMap((block) => block.groupIds))
    const rest = finds.filter((find) => !named.has(find.groupId))

    return (
        <>
            {preview && <PreviewBanner />}
            <Head title={persona.title} />

            <header className="max-w-2xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">
                    <Link href={`/${market.key}/gift-ideas`} className="hover:underline">
                        {t('gift_ideas.title')}
                    </Link>
                </p>
                <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{persona.title}</h1>
                {persona.blurb && <p className="mt-2 text-ink-soft">{persona.blurb}</p>}
            </header>

            {/*
              dangerouslySetInnerHTML, deliberately and narrowly: this HTML is
              built by CoveMarkup, which escapes the writer's text FIRST and
              then emits only its own <a> tags pointing at allowlisted
              destinations. Neither a model nor an author can introduce a tag or
              a URL — see CoveMarkupTest, which asserts both.
            */}
            {persona.editorial.length > 0 && (
                <div className="mt-6 max-w-2xl leading-relaxed text-ink">
                    {persona.editorial.map((block, i) => (
                        <div key={i}>
                            <p
                                className="mt-3 [&_a]:underline"
                                dangerouslySetInnerHTML={{ __html: block.html }}
                            />

                            {/*
                              The products this paragraph is about, right under
                              it — which on a persona is the curator's ordering
                              showing through, because the article was written
                              to follow the shortlist.
                            */}
                            {block.groupIds
                                .map((id) => byGroup[id])
                                .filter(Boolean)
                                .map((find) => (
                                    <figure
                                        key={find.id}
                                        className="my-5 flex flex-col gap-4 rounded-lg border border-line bg-card p-4 sm:flex-row"
                                    >
                                        <a href={find.url} className="shrink-0">
                                            {find.image && (
                                                <img
                                                    src={find.image}
                                                    alt=""
                                                    loading="lazy"
                                                    className="mx-auto h-32 w-32 object-contain"
                                                />
                                            )}
                                        </a>

                                        <figcaption className="min-w-0 flex-1">
                                            <a href={find.url} className="font-medium hover:underline">
                                                {find.title}
                                            </a>

                                            {find.blurb && (
                                                <p className="mt-1 text-sm text-ink-soft">{find.blurb}</p>
                                            )}

                                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                                <span className="font-semibold">
                                                    {find.price === null
                                                        ? '—'
                                                        : formatPrice(find.price, market)}
                                                </span>
                                                <a href={find.url} className="text-sm text-accent underline">
                                                    {t('daily.see_offers')}
                                                </a>
                                                <span className="ml-auto">
                                                    <SaveToList groupId={find.groupId} />
                                                </span>
                                            </div>
                                        </figcaption>
                                    </figure>
                                ))}
                        </div>
                    ))}
                </div>
            )}

            {rest.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-sm font-medium text-ink-soft">{t('gift_ideas.finds_title')}</h2>

                    <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {rest.map((find) => (
                            <li
                                key={find.id}
                                className="flex flex-col rounded-lg border border-line bg-card p-4"
                            >
                                <a href={find.url}>
                                    {find.image && (
                                        <img
                                            src={find.image}
                                            alt=""
                                            className="mx-auto h-36 object-contain"
                                            loading="lazy"
                                        />
                                    )}
                                    <h3 className="mt-3 line-clamp-2 font-medium">{find.title}</h3>
                                </a>

                                {find.blurb && <p className="mt-2 text-sm text-ink-soft">{find.blurb}</p>}

                                <div className="mt-auto flex items-center justify-between pt-4">
                                    <span className="font-semibold">
                                        {find.price === null ? '—' : formatPrice(find.price, market)}
                                    </span>
                                    <SaveToList groupId={find.groupId} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {guide && (
                <section className="mt-10 rounded-lg border border-line p-5">
                    <h2 className="text-sm font-medium text-ink-soft">{t('daily.guide_title')}</h2>
                    <Link href={guide.url} className="mt-2 block text-lg font-medium hover:underline">
                        {guide.title}
                    </Link>
                    {guide.intro && <p className="mt-2 text-ink-soft">{guide.intro}</p>}
                    {guide.searchVolume > 0 && (
                        <p className="mt-2 text-xs text-ink-soft">
                            {t('daily.guide_why', { count: n(guide.searchVolume) })}
                        </p>
                    )}
                </section>
            )}

            <div className="mt-12">
                <Link
                    href={`/${market.key}/gift-ideas`}
                    className="inline-block rounded-lg border border-line px-4 py-2 text-sm hover:bg-card"
                >
                    {t('gift_ideas.title')}
                </Link>
            </div>
        </>
    )
}
