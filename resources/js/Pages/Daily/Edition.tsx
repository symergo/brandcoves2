import { Head, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import PreviewBanner from '../../Components/PreviewBanner'
import { useTranslations } from '../../useTranslations'
import CoveSubscribe from '../../Components/CoveSubscribe'
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
    mindblown: number
    meh: number
}

interface Props {
    preview?: boolean

    edition: {
        id: number
        date: string
        label: string
        theme: string
        blurb: string | null
        isToday: boolean
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
    archive: { date: string; label: string; theme: string; url: string }[]
}

export default function Edition({ preview = false, edition, finds, guide, archive }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const [reactions, setReactions] = useState<Record<number, string>>({})
    const [counts, setCounts] = useState<Record<number, { mindblown: number; meh: number }>>(
        Object.fromEntries(finds.map((f) => [f.id, { mindblown: f.mindblown, meh: f.meh }])),
    )

    const csrf = () =>
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

    const react = async (pickId: number, reaction: 'mindblown' | 'meh') => {
        const response = await fetch(`/${market.key}/picks/${pickId}/react`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ reaction }),
        })

        if (!response.ok) return

        const data = await response.json()
        setReactions({ ...reactions, [pickId]: data.mine })
        setCounts({ ...counts, [pickId]: { mindblown: data.mindblown, meh: data.meh } })
    }

    /*
     * One lookup, so a paragraph naming a product can find it without scanning
     * the list per token.
     */
    const byGroup: Record<number, Find> = Object.fromEntries(finds.map((f) => [f.groupId, f]))

    const named = new Set(edition.editorial.flatMap((block) => block.groupIds))
    const rest = finds.filter((find) => !named.has(find.groupId))

    const reactionButtons = (find: Find) =>
        (['mindblown', 'meh'] as const).map((kind) => (
            <button
                key={kind}
                type="button"
                aria-pressed={reactions[find.id] === kind}
                className={`rounded-full border px-3 py-1 text-sm ${
                    reactions[find.id] === kind ? 'border-accent' : 'border-line'
                }`}
                onClick={() => react(find.id, kind)}
            >
                {kind === 'mindblown' ? '🤯' : '😐'} {n(counts[find.id]?.[kind] ?? 0)}
            </button>
        ))

    return (
        <>
            {preview && <PreviewBanner />}
            <Head title={edition.theme} />

            <header className="max-w-2xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">
                    {t('daily.title')} · {edition.label}
                </p>
                <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{edition.theme}</h1>
                {edition.blurb && <p className="mt-2 text-ink-soft">{edition.blurb}</p>}
            </header>

            {/*
              The editorial.

              dangerouslySetInnerHTML, deliberately and narrowly: this HTML is
              built by CoveMarkup, which escapes the model's text FIRST and then
              emits only its own <a> tags pointing at allowlisted destinations.
              The model cannot introduce a tag or a URL — see CoveMarkupTest,
              which asserts both.
            */}
            {edition.editorial.length > 0 && (
                <div className="mt-6 max-w-2xl leading-relaxed text-ink">
                    {edition.editorial.map((block, i) => (
                        <div key={i}>
                            <p
                                className="mt-3 [&_a]:underline"
                                dangerouslySetInnerHTML={{ __html: block.html }}
                            />

                            {/*
                              The products this paragraph is about, right under
                              it. The token in the copy is the writer saying
                              which those are.
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
                                                <a
                                                    href={find.url}
                                                    className="text-sm text-accent underline"
                                                >
                                                    {t('daily.see_offers')}
                                                </a>
                                                <span className="ml-auto flex items-center gap-2">
                                                    {reactionButtons(find)}
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

            {/*
              Whatever the article did not get to.

              An edition can carry more finds than the copy names, and dropping
              them would quietly lose products the builder chose. They keep the
              grid; the ones the writing is about no longer need it.
            */}
            {rest.length > 0 && (
            <section className="mt-10">
                <h2 className="text-sm font-medium text-ink-soft">{t('daily.finds_title')}</h2>

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

                            <div className="mt-auto space-y-3 pt-4">
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold">
                                        {find.price === null ? '—' : formatPrice(find.price, market)}
                                    </span>
                                    <SaveToList groupId={find.groupId} />
                                </div>

                                <div className="flex gap-2">{reactionButtons(find)}</div>
                            </div>
                        </li>
                    ))}
                </ul>
            </section>
            )}

            {/* ── The guide ─────────────────────────────────────────────── */}
            {guide && (
                <section className="mt-10 rounded-lg border border-line p-5">
                    <h2 className="text-sm font-medium text-ink-soft">{t('daily.guide_title')}</h2>
                    <Link href={guide.url} className="mt-2 block text-lg font-medium hover:underline">
                        {guide.title}
                    </Link>
                    {guide.intro && <p className="mt-2 text-ink-soft">{guide.intro}</p>}
                    {/*
                      Stated plainly. "We wrote this because people searched for
                      it here" is both the honest reason and a fact no
                      competitor has.
                    */}
                    {guide.searchVolume > 0 && (
                        <p className="mt-2 text-xs text-ink-soft">
                            {t('daily.guide_why', { count: n(guide.searchVolume) })}
                        </p>
                    )}
                </section>
            )}

            {/*
              After the edition, before the archive.

              Someone who has just read today's Cove is the only person who has
              evidence that tomorrow's is worth an inbox slot. Above the fold it
              would be an interruption; below the archive nobody would reach it.
            */}
            <div className="mt-12">
                <CoveSubscribe source="daily" />
            </div>

            {archive.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-sm font-medium text-ink-soft">{t('daily.archive')}</h2>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {archive.map((entry) => (
                            <li key={entry.date}>
                                <Link
                                    href={entry.url}
                                    className="block rounded border border-line px-3 py-1.5 text-sm hover:bg-card"
                                >
                                    <span className="text-ink-soft">{entry.label}</span> · {entry.theme}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    )
}
