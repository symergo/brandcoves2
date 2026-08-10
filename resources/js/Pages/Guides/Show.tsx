import { Head, Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import PreviewBanner from '../../Components/PreviewBanner'
import { useTranslations } from '../../useTranslations'
import SaveToList from '../../Components/SaveToList'

interface Item {
    rank: number
    groupId: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    merchantCount: number
    inStock: boolean
    /* Paragraphs of safe HTML — see Prose below for why that is not alarming. */
    copy: string[]
    verdict: string | null
    unavailable: boolean
    url: string
}

interface Props {
    preview?: boolean

    guide: {
        title: string
        kind: 'buying' | 'advice'
        intro: string[]
        body: string[]
        faq: { q: string; a: string[] }[] | null
        updatedAt: string | null
        searchVolume: number
    }
    items: Item[]
}

/**
 * Copy with its links already resolved.
 *
 * dangerouslySetInnerHTML, deliberately and narrowly: this HTML is built by
 * CoveMarkup, which escapes the author's text FIRST and then emits only its own
 * <a> tags pointing at allowlisted destinations. The writer cannot introduce a
 * tag or a URL — CoveMarkupTest asserts both. Same contract as the Cove's
 * editorial.
 */
function Prose({ blocks, className = '' }: { blocks: string[]; className?: string }) {
    return (
        <>
            {blocks.map((html, i) => (
                <p
                    key={i}
                    className={`${className} [&_a]:underline`}
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            ))}
        </>
    )
}

export default function GuideShow({ preview = false, guide, items }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    return (
        <>
            {preview && <PreviewBanner />}
            <Head title={guide.title} />

            <article className="max-w-3xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{guide.title}</h1>

                <Prose blocks={guide.intro} className="mt-3 text-lg text-ink-soft" />

                <p className="mt-3 text-xs text-ink-soft">
                    {guide.updatedAt && t('guides.updated', { date: guide.updatedAt })}
                    {guide.searchVolume > 0 && (
                        <> · {t('guides.why', { count: n(guide.searchVolume) })}</>
                    )}
                </p>

                {guide.body.length > 0 && (
                    <section className="mt-8">
                        {/*
                          An advice article's body IS the article, so labelling
                          it "how to choose" would be a heading about a
                          shortlist that is not there.
                        */}
                        {guide.kind === 'buying' && (
                            <h2 className="text-lg font-medium">{t('guides.how_to_choose')}</h2>
                        )}
                        <Prose blocks={guide.body} className="mt-3 leading-relaxed" />
                    </section>
                )}
            </article>

            {/*
              No shortlist, no list markup. An advice article renders as an
              article; an empty <ol> under one reads as a buying guide whose
              products failed to load.
            */}
            <ol className="mt-10 space-y-5">
                {items.map((item) => (
                    <li
                        key={item.rank}
                        className={`flex flex-col gap-4 rounded-lg border border-line p-5 sm:flex-row ${
                            item.unavailable ? 'opacity-60' : ''
                        }`}
                    >
                        {item.image && (
                            <img
                                src={item.image}
                                alt=""
                                className="h-32 w-32 shrink-0 self-center object-contain"
                                loading="lazy"
                            />
                        )}

                        <div className="min-w-0 flex-1">
                            {item.verdict && (
                                <p className="text-xs font-medium tracking-wide text-accent uppercase">
                                    {item.verdict}
                                </p>
                            )}

                            <h2 className="mt-1 font-medium">
                                <Link href={item.url} className="hover:underline">
                                    {item.title}
                                </Link>
                            </h2>

                            <Prose blocks={item.copy} className="mt-2 text-sm text-ink-soft" />

                            <div className="mt-3 flex flex-wrap items-center gap-4">
                                {/*
                                  Live from the group, never written into the
                                  copy. A price baked into editorial is wrong
                                  within a week, and the copy is what a reader
                                  trusts.
                                */}
                                <span className="font-semibold">
                                    {item.unavailable
                                        ? t('guides.unavailable')
                                        : item.price === null
                                          ? '—'
                                          : formatPrice(item.price, market)}
                                </span>

                                {item.merchantCount > 1 && (
                                    <span className="text-sm text-ink-soft">
                                        {t('guides.shops', { count: n(item.merchantCount) })}
                                    </span>
                                )}

                                <SaveToList groupId={item.groupId} />
                            </div>
                        </div>
                    </li>
                ))}
            </ol>

            {guide.faq && guide.faq.length > 0 && (
                <section className="mt-12 max-w-3xl">
                    <h2 className="text-lg font-medium">{t('guides.faq')}</h2>
                    <dl className="mt-4 space-y-4">
                        {guide.faq.map((pair, i) => (
                            <div key={i}>
                                <dt className="font-medium">{pair.q}</dt>
                                <dd className="mt-1 text-ink-soft">
                                    <Prose blocks={pair.a} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}
        </>
    )
}
