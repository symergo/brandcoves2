import { Head, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import SaveToList from '../Components/SaveToList'
import ScanButton from '../Components/ScanButton'

export interface DiscoverItem {
    id: number
    title: string
    brand: string | null
    image: string | null
    category: string | null
    price: Cents | null
    merchantCount: number
    inStock: boolean
    discountPercent: number | null
    url: string
    /** The dominant scoring factor — "why you're seeing this". */
    reason: string | null
    sources: string[]
}

interface ModeMeta {
    key: string
    intent: string
    position: number
    retrievers: Record<string, number>
    scoring: { alpha: number; beta: number; gamma: number; lambda: number; epsilon: number }
    layout: string
    candidatesConsidered: number
}

interface Props {
    mode: string
    stops: { key: string; position: number; layout: string }[]
    query: string | null
    surprise: number
    items: DiscoverItem[]
    layout: string
    modeMeta: ModeMeta
}

/**
 * Layouts that read as rows rather than a grid.
 *
 * `compare` is a price ladder and `kit` is an ordered set of parts — both lose
 * their meaning the moment they wrap into columns, because the ordering *is*
 * the content. Everything else is browsing, which is a grid.
 */
const LIST_LAYOUTS = ['list', 'compare', 'kit', 'deals', 'stream']

export default function Discover({ mode, stops, query, surprise, items, layout, modeMeta }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const [dial, setDial] = useState(modeMeta.position)
    const [surpriseDial, setSurpriseDial] = useState(surprise)
    const [term, setTerm] = useState(query ?? '')
    const [results, setResults] = useState(items)
    const [meta, setMeta] = useState(modeMeta)
    const [activeLayout, setActiveLayout] = useState(layout)
    const [busy, setBusy] = useState(false)

    // Every request supersedes the one before it. Dragging a slider fires a
    // burst, and without this the surface settles on whichever response
    // happened to land last rather than the one for the current position.
    const requestId = useRef(0)

    // A ref rather than the state value: `run` is memoised on [market, mode],
    // and closing over `activeLayout` would send the layout as it was when the
    // callback was built rather than as it is now.
    const layoutRef = useRef(layout)
    layoutRef.current = activeLayout

    const run = useCallback(
        async (nextDial: number, nextSurprise: number, nextTerm: string) => {
            const id = ++requestId.current
            setBusy(true)

            try {
                const response = await fetch(`/${market.key}/discover`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)
                                ?.content ?? '',
                    },
                    body: JSON.stringify({
                        mode,
                        dial: nextDial,
                        surprise: nextSurprise,
                        /*
                          The same box feeds `query` or `goal` depending on the
                          mode. One input, because the dial's premise is one
                          surface — a second text field appearing when you drag
                          past a stop would break it.
                        */
                        input:
                            layoutRef.current === 'kit'
                                ? { goal: nextTerm || null }
                                : { query: nextTerm || null },
                        overlays: { modality: 'text', social: false },
                    }),
                })

                if (!response.ok || id !== requestId.current) return

                const data = await response.json()
                setResults(data.items)
                setMeta(data.modeMeta)
                setActiveLayout(data.layout)
            } finally {
                if (id === requestId.current) setBusy(false)
            }
        },
        [market.key, mode],
    )

    /*
     * Debounced, because the dial is a continuous control.
     *
     * 180 ms is long enough that a drag across the axis fires a handful of
     * requests rather than sixty, and short enough that the surface still feels
     * like it is responding to the drag rather than to the release.
     */
    useEffect(() => {
        const timer = setTimeout(() => run(dial, surpriseDial, term), 180)

        return () => clearTimeout(timer)
    }, [dial, surpriseDial, run])

    const react = (item: DiscoverItem, reaction: 'meh' | 'hide') => {
        setResults(results.filter((r) => r.id !== item.id))

        void fetch(`/${market.key}/discover/react`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)
                        ?.content ?? '',
            },
            body: JSON.stringify({
                mode: meta.key,
                group_id: item.id,
                reaction,
                // The factor that put it on the page. Without it a reaction says
                // "they disliked it" but not "they disliked it for the reason we
                // showed it", and it is the second half that tunes a weight.
                factor: item.reason,
            }),
        })
    }

    const nearestStop = stops.reduce((best, stop) =>
        Math.abs(stop.position - dial) < Math.abs(best.position - dial) ? stop : best,
    )

    return (
        <>
            <Head title={t(`discover.modes.${meta.key}.title`)} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">
                    {t(`discover.modes.${meta.key}.title`)}
                </h1>
                <p className="mt-2 text-ink-soft">{t(`discover.modes.${meta.key}.description`)}</p>
            </header>

            {/* ── The dial ──────────────────────────────────────────────── */}
            <section className="mt-6 rounded-lg border border-line bg-card p-5">
                <label htmlFor="dial" className="block text-sm font-medium">
                    {t('discover.dial_label')}
                </label>

                <input
                    id="dial"
                    type="range"
                    min="0"
                    max="1"
                    step="0.01"
                    value={dial}
                    onChange={(e) => setDial(Number(e.target.value))}
                    className="mt-3 w-full"
                    aria-valuetext={t(`discover.modes.${nearestStop.key}.title`)}
                />

                <div className="mt-1 flex justify-between text-xs text-ink-soft">
                    <span>{t('discover.dial_low')}</span>
                    <span>{t('discover.dial_high')}</span>
                </div>

                {/*
                  The numbers that produced the page, shown rather than hidden.

                  A surface that visibly reorganises as a control moves is
                  otherwise mystifying — showing alpha and beta changing is what
                  turns "the results jumped about" into "I moved the dial".
                */}
                <p className="mt-3 font-mono text-xs text-ink-soft">
                    {t('discover.now_showing', {
                        mode: t(`discover.modes.${meta.key}.title`),
                    })}
                    {' · '}α {meta.scoring.alpha} · β {meta.scoring.beta} · γ {meta.scoring.gamma} · λ{' '}
                    {meta.scoring.lambda} · ε {meta.scoring.epsilon}
                    {' · '}
                    {Object.entries(meta.retrievers)
                        .map(([key, weight]) => `${key} ${weight}`)
                        .join(' · ')}
                </p>

                <div className="mt-4 flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <label htmlFor="surprise" className="text-sm">
                            {t('discover.surprise_label')}
                        </label>
                        <input
                            id="surprise"
                            type="range"
                            min="0"
                            max="1"
                            step="0.05"
                            value={surpriseDial}
                            onChange={(e) => setSurpriseDial(Number(e.target.value))}
                        />
                    </div>

                    <form
                        className="flex flex-1 gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()
                            run(dial, surpriseDial, term)
                        }}
                    >
                        <input
                            type="search"
                            className="min-w-0 flex-1 rounded border border-line px-3 py-2"
                            /*
                              The prompt follows the mode. "A product, a brand,
                              or nothing" is wrong in Projects, where the box
                              wants a situation — and a placeholder that
                              contradicts the mode is how someone concludes the
                              control is broken.
                            */
                            placeholder={
                                meta.layout === 'kit'
                                    ? t('discover.goal_placeholder')
                                    : t('discover.query_placeholder')
                            }
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            aria-label={t('discover.query_placeholder')}
                        />
                        {/*
                          "Something like this one." The keyword retriever runs
                          the query through SearchService, which resolves a GTIN
                          as an exact identity — so a scan seeds discovery with
                          the product in your hand rather than a text match on
                          thirteen digits.

                          Hidden in Projects, where the box wants a situation
                          ("home office") and a barcode is the opposite kind of
                          answer — the same reason the placeholder changes.
                        */}
                        {meta.layout !== 'kit' && (
                            <ScanButton
                                className="shrink-0 rounded border border-line px-3 py-2"
                                onScan={(gtin) => {
                                    setTerm(gtin)
                                    run(dial, surpriseDial, gtin)
                                }}
                            />
                        )}
                        <button type="submit" className="rounded bg-accent px-4 py-2 text-sm text-white">
                            {t('discover.go')}
                        </button>
                    </form>
                </div>
            </section>

            <p className="mt-4 text-sm text-ink-soft" aria-live="polite">
                {busy
                    ? t('discover.thinking')
                    : t('discover.considered', {
                          shown: n(results.length),
                          considered: n(meta.candidatesConsidered),
                      })}
            </p>

            {/* ── The surface ───────────────────────────────────────────── */}
            {results.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('discover.empty')}</p>
            ) : (
                <>
                    {/*
                      A kit is the one layout with a fact about the *set* rather
                      than about each item. The running total is the reason
                      Projects exists — five products that add up to a number
                      you can decide about.
                    */}
                    {activeLayout === 'kit' && (
                        <p className="mt-6 text-lg font-semibold">
                            {t('discover.kit_total', {
                                total: formatPrice(
                                    results.reduce((sum, item) => sum + (item.price ?? 0), 0),
                                    market,
                                ),
                                count: n(results.length),
                            })}
                        </p>
                    )}

                    <ul
                        className={
                            /*
                             * One component, several appearances. The server
                             * sends a layout name and the surface renders by
                             * it — which is what stops nine modes becoming nine
                             * pages.
                             *
                             * `compare` is a price ladder, so it stays a single
                             * column however wide the screen: side by side, a
                             * ladder reads as a grid and the ordering — the
                             * whole point — disappears.
                             */
                            LIST_LAYOUTS.includes(activeLayout)
                                ? 'mt-6 divide-y divide-line rounded border border-line'
                                : 'mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4'
                        }
                    >
                        {results.map((item, index) => {
                            const asRow = LIST_LAYOUTS.includes(activeLayout)

                            return (
                                <li
                                    key={item.id}
                                    className={
                                        asRow
                                            ? 'flex items-center gap-4 p-4'
                                            : 'flex flex-col rounded-lg border border-line bg-card p-4'
                                    }
                                >
                                    {activeLayout === 'compare' && (
                                        <span className="w-6 shrink-0 text-sm text-ink-soft tabular-nums">
                                            {index + 1}
                                        </span>
                                    )}

                                    {item.image && (
                                        <img
                                            src={item.image}
                                            alt=""
                                            className={
                                                asRow
                                                    ? 'h-20 w-20 shrink-0 object-contain'
                                                    : 'mx-auto h-36 object-contain'
                                            }
                                            loading="lazy"
                                        />
                                    )}

                                    <div className={asRow ? 'min-w-0 flex-1' : 'contents'}>
                                        <a
                                            href={item.url}
                                            className="line-clamp-2 font-medium hover:underline"
                                        >
                                            {item.title}
                                        </a>

                                        {/* Required of every mode: why this is here. */}
                                        {item.reason && (
                                            <p className="mt-1 text-sm text-ink-soft">
                                                {t(`discover.why.${item.reason}`)}
                                            </p>
                                        )}

                                        <div
                                            className={`flex flex-wrap items-center gap-3 ${
                                                asRow ? 'mt-2' : 'mt-auto pt-4'
                                            }`}
                                        >
                                            <span className="font-semibold">
                                                {item.price === null
                                                    ? '-'
                                                    : formatPrice(item.price, market)}
                                            </span>

                                            {/*
                                              Only where it is the point. A
                                              discount badge on every layout is
                                              a badge nobody reads; on the deals
                                              lane it is the headline.
                                            */}
                                            {activeLayout === 'deals' &&
                                                item.discountPercent !== null &&
                                                item.discountPercent > 0 && (
                                                    <span className="rounded bg-accent px-2 py-0.5 text-xs font-semibold text-white">
                                                        −{n(item.discountPercent)}%
                                                    </span>
                                                )}

                                            {item.merchantCount > 1 && (
                                                <span className="text-xs text-ink-soft">
                                                    {t('discover.shops', {
                                                        count: n(item.merchantCount),
                                                    })}
                                                </span>
                                            )}

                                            <SaveToList groupId={item.id} />
                                            <button
                                                type="button"
                                                className="ml-auto text-xs text-ink-soft underline"
                                                onClick={() => react(item, 'meh')}
                                            >
                                                {t('discover.not_for_me')}
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            )
                        })}
                    </ul>
                </>
            )}
        </>
    )
}
