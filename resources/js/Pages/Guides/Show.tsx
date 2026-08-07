import { Head, Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
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
    copy: string | null
    verdict: string | null
    unavailable: boolean
    url: string
}

interface Props {
    guide: {
        title: string
        intro: string | null
        body: string | null
        faq: { q: string; a: string }[] | null
        updatedAt: string | null
        searchVolume: number
    }
    items: Item[]
}

export default function GuideShow({ guide, items }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    return (
        <>
            <Head title={guide.title} />

            <article className="max-w-3xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{guide.title}</h1>

                {guide.intro && <p className="mt-3 text-lg text-ink-soft">{guide.intro}</p>}

                <p className="mt-3 text-xs text-ink-soft">
                    {guide.updatedAt && t('guides.updated', { date: guide.updatedAt })}
                    {guide.searchVolume > 0 && (
                        <> · {t('guides.why', { count: n(guide.searchVolume) })}</>
                    )}
                </p>

                {guide.body && (
                    <section className="mt-8">
                        <h2 className="text-lg font-medium">{t('guides.how_to_choose')}</h2>
                        {/*
                          Plain text, split on blank lines. Not a Markdown
                          renderer: the copy comes from a language model, and
                          the one thing you never do with model output is hand
                          it to something that interprets markup.
                        */}
                        {guide.body.split(/\n{2,}/).map((paragraph, i) => (
                            <p key={i} className="mt-3 leading-relaxed">
                                {paragraph}
                            </p>
                        ))}
                    </section>
                )}
            </article>

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

                            {item.copy && <p className="mt-2 text-sm text-ink-soft">{item.copy}</p>}

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
                                <dd className="mt-1 text-ink-soft">{pair.a}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}
        </>
    )
}
