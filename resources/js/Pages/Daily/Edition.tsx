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
    deals: {
        id: number
        title: string
        image: string | null
        price: Cents | null
        was: Cents | null
        discountPercent: number | null
        url: string
    }[]
    archive: { date: string; label: string; theme: string; url: string }[]
}

export default function Edition({ preview = false, edition, finds, guide, deals, archive }: Props) {
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

            {/*
              Two columns from `lg` up, one below it.

              The article keeps its measure — prose past about 70 characters a
              line is harder to read, which is why it was capped at `max-w-2xl`
              in the first place — and the column beside it uses the space that
              cap was already leaving empty on a wide screen.
            */}
            <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-10">
            <div className="min-w-0">
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

            </div>

            <aside className="mt-10 space-y-6 lg:sticky lg:top-6 lg:mt-0">
                {/*
                  The sharpest drops we have seen lately.

                  "Newest highest" is two orderings that fight — the deepest
                  discount may be a month old — so it is sorted by discount
                  inside a recency window. Every figure is against our own
                  30-day median, never a shop's crossed-out price.
                */}
                {deals.length > 0 && (
                    <section className="rounded-lg border border-line bg-card p-4">
                        <h2 className="text-sm font-medium text-ink-soft">{t('daily.deals_title')}</h2>
                        <p className="mt-1 text-xs text-ink-soft">{t('daily.deals_hint')}</p>

                        <ul className="mt-3 divide-y divide-line">
                            {deals.map((deal) => (
                                <li key={deal.id} className="py-3 first:pt-0 last:pb-0">
                                    <Link href={deal.url} className="flex items-center gap-3 group">
                                        {deal.image && (
                                            <img
                                                src={deal.image}
                                                alt=""
                                                loading="lazy"
                                                className="h-12 w-12 shrink-0 object-contain"
                                            />
                                        )}
                                        <span className="min-w-0 flex-1">
                                            <span className="line-clamp-2 text-sm group-hover:underline">
                                                {deal.title}
                                            </span>
                                            <span className="mt-1 flex items-baseline gap-2">
                                                {deal.price !== null && (
                                                    <span className="text-sm font-semibold">
                                                        {formatPrice(deal.price, market)}
                                                    </span>
                                                )}
                                                {deal.was !== null && (
                                                    <span className="text-xs text-ink-soft line-through">
                                                        {formatPrice(deal.was, market)}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        {deal.discountPercent !== null && (
                                            <span className="shrink-0 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">
                                                −{n(deal.discountPercent)}%
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/*
                  The Gift Cove, next to the thing people are already reading.

                  It is the one part of the site a reader here has no reason to
                  have found: the nav names it and nothing explains it. Three
                  lines and a link do more than a nav entry ever did.
                */}
                <section className="rounded-lg border border-accent/40 bg-accent/5 p-4">
                    <h2 className="font-medium">{t('gift_cove.title')}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('daily.gift_cove_hint')}</p>

                    <ul className="mt-3 space-y-1.5 text-sm">
                        {['wishlist', 'giftlist', 'santa', 'quiz'].map((tool) => (
                            <li key={tool} className="flex gap-2">
                                <span aria-hidden className="text-accent">·</span>
                                <span>{t(`gift_cove.${tool}_title`)}</span>
                            </li>
                        ))}
                    </ul>

                    <Link
                        href={`/${market.key}/gift-cove`}
                        className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {t('daily.gift_cove_cta')}
                    </Link>
                </section>
            </aside>
            </div>

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
