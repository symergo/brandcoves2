import { Head, Link, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import PreviewBanner from '../../Components/PreviewBanner'
import { useTranslations } from '../../useTranslations'
import CoveRail, { CoveSeries, type Rail } from '../../Components/CoveRail'
import MoreCoves from '../../Components/MoreCoves'
import EntityRails, { type EntityRailSet } from '../../Components/EntityRails'
import SaveToList from '../../Components/SaveToList'
import SceneIllustration, { type SceneKey } from '../../Components/SceneIllustration'

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

/**
 * A paragraph, and the products it is about.
 *
 * `groupIds` is read back out of the copy's own `[[product:N]]` tokens by
 * ProseCards, so the writer decides where a card goes by deciding where to
 * discuss the product. Empty on a paragraph that names none — most of an
 * intro, and every paragraph of an advice article.
 */
interface Block {
    html: string
    groupIds: number[]
}

interface Props {
    preview?: boolean

    guide: {
        title: string
        kind: 'buying' | 'advice'
        /** Never null — the server sends the kind's default. See GuideController. */
        scene: SceneKey
        intro: Block[]
        body: Block[]
        faq: { q: string; a: string[] }[] | null
        updatedAt: string | null
        searchVolume: number
    }
    /* Always the whole shortlist. What the list below renders is decided here. */
    items: Item[]
    /**
     * The Gift Cove, the other articles, and more products from the categories
     * this one's shortlist is in. A Shop Cove renders this page too and gets
     * its own band — the rail asks what kind the Cove is, not which route
     * served it.
     */
    rail: Rail
    /**
     * A Shop Cove's product rails. Null on every other kind: a buying guide
     * already carries its shortlist, and a rail under one would be a second,
     * unranked answer to the question the article just answered.
     */
    rails?: EntityRailSet | null
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

/**
 * The card that sits under the paragraph discussing a product.
 *
 * It deliberately carries no `copy`. The paragraph above it IS the writing
 * about this product; printing the item's own blurb underneath would say the
 * same thing twice in two voices. `copy` is what the list below falls back to
 * for a product the article never reached, which is the only place it still
 * earns its keep.
 */
function InlineCard({ item }: { item: Item }) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    return (
        <figure
            className={`my-5 flex flex-col gap-4 rounded-lg border border-line bg-card p-4 sm:flex-row ${
                item.unavailable ? 'opacity-60' : ''
            }`}
        >
            {item.image && (
                <Link href={item.url} className="shrink-0">
                    <img
                        src={item.image}
                        alt=""
                        loading="lazy"
                        className="mx-auto h-32 w-32 object-contain"
                    />
                </Link>
            )}

            <figcaption className="min-w-0 flex-1">
                {item.verdict && (
                    <p className="text-xs font-medium tracking-wide text-accent uppercase">
                        {item.verdict}
                    </p>
                )}

                <Link href={item.url} className="mt-1 block font-medium hover:underline">
                    {item.title}
                </Link>

                <div className="mt-3 flex flex-wrap items-center gap-4">
                    {/*
                      Live from the group, never written into the copy. A price
                      baked into editorial is wrong within a week.
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
            </figcaption>
        </figure>
    )
}

/**
 * Paragraphs, each followed by the cards for the products it names.
 *
 * A paragraph naming nothing renders as an ordinary paragraph, which is what
 * makes this safe on every kind: an advice article has no shortlist, so every
 * block falls straight through and the page is prose from top to bottom.
 */
function Article({
    blocks,
    byGroup,
    className = '',
}: {
    blocks: Block[]
    byGroup: Record<number, Item>
    className?: string
}) {
    return (
        <>
            {blocks.map((block, i) => (
                <div key={i}>
                    <p
                        className={`${className} [&_a]:underline`}
                        dangerouslySetInnerHTML={{ __html: block.html }}
                    />

                    {block.groupIds
                        .map((id) => byGroup[id])
                        .filter(Boolean)
                        .map((item) => (
                            <InlineCard key={item.rank} item={item} />
                        ))}
                </div>
            ))}
        </>
    )
}

export default function GuideShow({ preview = false, guide, items, rail, rails = null }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const byGroup: Record<number, Item> = Object.fromEntries(items.map((i) => [i.groupId, i]))

    // Every product the article showed a card for, wherever it showed it.
    const named = new Set([...guide.intro, ...guide.body].flatMap((block) => block.groupIds))

    /*
      What the article did not get to.

      The list is a fallback now rather than the page's spine: when the writing
      covers all seven products this is empty, and when the model skips one — or
      when a guide was written before the prose carried tokens at all — the
      shortlist still renders in full. That is the whole reason it survives.
    */
    const rest = items.filter((item) => !named.has(item.groupId))

    /*
      "How to choose" is a heading about decisions, and it stops being true the
      moment the body is also where the products are discussed. So it appears
      only over a body that names none — the shape of every guide written before
      this, and of one where the writer kept the two sections apart.
    */
    const bodyIsAboutProducts = guide.body.some((block) => block.groupIds.length > 0)

    return (
        <>
            {preview && <PreviewBanner />}
            <Head title={guide.title} />

            {/*
              Two columns from `lg` up, one below it.

              The same shape the Daily edition and the personas have. The
              article was already capped at `max-w-3xl` — a line past about
              seventy characters is harder to read — and the rail uses the space
              that cap was leaving empty on a wide screen rather than adding
              any.

              Everything the article is goes in the left column, the shortlist
              and the FAQ included, so it is the taller of the two whatever the
              writing does. An advice article with three short paragraphs would
              otherwise end above a rail that ran on for another screen.
            */}
            <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-10">
                <div className="min-w-0">
                    <article className="max-w-3xl">
                        {/*
                          The drawing, on an advice article only.

                          Not a rule about page shape but about what else is on
                          the page: a buying guide opens onto a shortlist of
                          photographed products a screen further down, and a
                          generic mark above its title would be decoration
                          competing with them. An advice piece has no product
                          and therefore no picture at all, and it is the one
                          that arrives cold from search — the same mark it wore
                          on the shelf is what says you opened the one you
                          clicked.
                        */}
                        {guide.kind === 'advice' && (
                            <SceneIllustration
                                name={guide.scene}
                                className="mb-4 h-24 w-auto text-accent"
                            />
                        )}

                        <h1 className="text-2xl font-semibold sm:text-3xl">{guide.title}</h1>

                        <Article
                            blocks={guide.intro}
                            byGroup={byGroup}
                            className="mt-3 text-lg text-ink-soft"
                        />

                        <p className="mt-3 text-xs text-ink-soft">
                            {guide.updatedAt && t('guides.updated', { date: guide.updatedAt })}
                            {guide.searchVolume > 0 && (
                                <> · {t('guides.why', { count: n(guide.searchVolume) })}</>
                            )}
                        </p>

                        {/*
                          Where this page sits in its series, on the seasonal
                          Coves that are one.

                          Above the body rather than under it, unlike everything
                          else the rail carries: the rest of the rail is where to
                          go afterwards, and a title reading "deel 2" raises the
                          question of what parts one and three are before the
                          reader has started. Renders nothing at all on a Cove
                          that is not part of a series, which is most of them.
                        */}
                        <CoveSeries parts={rail.series} />

                        {guide.body.length > 0 && (
                            <section className="mt-8">
                                {/*
                                  An advice article's body IS the article, so labelling
                                  it "how to choose" would be a heading about a
                                  shortlist that is not there.
                                */}
                                {guide.kind === 'buying' && !bodyIsAboutProducts && (
                                    <h2 className="text-lg font-medium">{t('guides.how_to_choose')}</h2>
                                )}
                                <Article
                                    blocks={guide.body}
                                    byGroup={byGroup}
                                    className="mt-3 leading-relaxed"
                                />
                            </section>
                        )}
                    </article>

                    {/*
                      No shortlist, no list markup. An advice article renders as an
                      article; an empty <ol> under one reads as a buying guide whose
                      products failed to load — and so does a list emptied because the
                      article covered everything, which is why this is the same test.
                    */}
                    {rest.length > 0 && (
                        <ol className="mt-10 space-y-5">
                            {rest.map((item) => (
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
                    )}

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
                </div>

                <aside className="mt-10 space-y-6 lg:sticky lg:top-6 lg:mt-0">
                    <CoveRail rail={rail} />
                </aside>
            </div>

            {/*
              A Shop Cove's products, under the piece about the shop.

              Null on every other kind: a buying guide already carries its
              shortlist, and a rail underneath one would be a second, unranked
              answer to the question the article just answered.
            */}
            <EntityRails rails={rails} />

            {/*
              The other articles. This page had no onward navigation at all —
              no archive strip, no "back to the shelf", nothing — which mattered
              most here of the three: an article is the Cove search actually
              lands people on, and it was the one that told them least about
              what else is here.
            */}
            <MoreCoves band={rail.coves} />
        </>
    )
}
