import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { formatPrice } from '../types'
import type { CurrentMarket } from '../types'
import { useTranslations } from '../useTranslations'

interface GroupHit {
    id: number
    title: string
    image: string | null
    price: number | null
    brand: string | null
    merchantCount: number
}

interface LiveHit {
    source: string
    externalId: string
    title: string
    image: string | null
    price: number | null
    merchant: string
    /** Whether we may keep a title of our own for it. See invariant #6. */
    storable: boolean
}

/** What has been chosen and is about to be added, in whichever shape. */
type Chosen =
    | { kind: 'group'; hit: GroupHit }
    | { kind: 'live'; hit: LiveHit }
    | { kind: 'manual' }

/**
 * "Product toevoegen" — one control for the whole of putting a thing on a list.
 *
 * ## Why one button and not two
 *
 * The list page had **Find things to add**, which navigated away to a search,
 * and **Add something yourself**, a separate form for the case where the
 * catalogue does not have it. Two buttons, one intention — *put a thing on this
 * list* — and the split was ours rather than the visitor's: it asks them to
 * know, before they have typed anything, whether we happen to stock what they
 * are thinking of. That is a question only we can answer, and this panel
 * answers it: they type a term, press Enter, and see.
 *
 * ## The three ways out, in one place
 *
 * - A catalogue result, kept with its price, link and offer comparison intact.
 * - A live result from a source we do not mirror.
 * - Something typed by hand, reachable **without searching first** — the
 *   voucher for the climbing gym, a book in one particular edition. It is a
 *   footer on the panel from the moment it opens, not a consolation prize
 *   offered after a search has failed.
 *
 * Whichever is chosen, the wording is editable before it lands. Feed titles are
 * written for a search engine; a list is read by a person.
 */
export default function AddProduct({
    base,
    listId,
    market,
}: {
    base: string
    listId: string
    market: CurrentMarket
}) {
    const { t } = useTranslations()

    const [open, setOpen] = useState(false)
    const [term, setTerm] = useState('')
    const [groups, setGroups] = useState<GroupHit[]>([])
    const [live, setLive] = useState<LiveHit[]>([])
    const [searching, setSearching] = useState(false)
    const [searched, setSearched] = useState(false)

    const [chosen, setChosen] = useState<Chosen | null>(null)
    const [title, setTitle] = useState('')
    const [note, setNote] = useState('')
    const [url, setUrl] = useState('')
    const [price, setPrice] = useState('')
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState<string | null>(null)

    const field = useRef<HTMLInputElement>(null)

    /*
     * On Enter, not as you type.
     *
     * A typeahead would fire a search per keystroke, and the live half of this
     * one costs real requests to bol and Amazon — "koptelefoon" is eleven
     * searches for one intention. `SearchService` caches the mirrorable
     * connectors and the route is throttled, but the cheapest request is still
     * the one never made.
     *
     * It also reads better here. A product search is a considered act: people
     * type two or three words and then look. Results reshuffling under a
     * half-typed word is noise, and the row you were reaching for moves.
     *
     * The request id guards against an earlier answer landing after a later one
     * — two presses of Enter on a slow connection, where the first search would
     * otherwise overwrite the second.
     */
    const latest = useRef(0)

    function runSearch(event?: React.FormEvent): void {
        event?.preventDefault()

        const q = term.trim()

        setError(null)

        if (q.length < 2) {
            setGroups([])
            setLive([])
            setSearched(false)
            setSearching(false)

            return
        }

        const id = ++latest.current

        setSearching(true)

        fetch(`${base}/list-search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data: { groups: GroupHit[]; live: LiveHit[] }) => {
                if (id !== latest.current) return

                setGroups(data.groups ?? [])
                setLive(data.live ?? [])
                setSearched(true)
            })
            .catch(() => {
                if (id !== latest.current) return

                // An empty result and a failed request read identically
                // otherwise, and the second invites somebody to type it again
                // rather than to write it in by hand.
                setError(t('lists.search_failed'))
                setSearched(true)
            })
            .finally(() => {
                if (id === latest.current) setSearching(false)
            })
    }

    useEffect(() => {
        if (open) field.current?.focus()
    }, [open])

    function reset(): void {
        setChosen(null)
        setTitle('')
        setNote('')
        setUrl('')
        setPrice('')
        setError(null)
    }

    function close(): void {
        setOpen(false)
        setTerm('')
        setGroups([])
        setLive([])
        setSearched(false)
        reset()
    }

    function choose(next: Chosen): void {
        setError(null)
        setChosen(next)
        setNote('')
        setUrl('')
        setPrice('')

        /*
         * Prefilled, not blank. The point is *adjusting* the wording, and a
         * person asked to retype a product name will either do it badly or
         * abandon the step. For a hand-written entry the search term is the
         * best guess available — they typed it because it is what the thing is
         * called.
         */
        setTitle(next.kind === 'manual' ? term.trim() : next.hit.title)
    }

    function submit(event: React.FormEvent): void {
        event.preventDefault()
        setError(null)
        setBusy(true)

        const common = {
            wishlist_id: listId,
            title: title.trim(),
            note: note.trim() || null,
        }

        const payload =
            chosen?.kind === 'group'
                ? { ...common, group_id: chosen.hit.id }
                : chosen?.kind === 'live'
                  ? {
                        ...common,
                        source: chosen.hit.source,
                        external_id: chosen.hit.externalId,
                        image_url: chosen.hit.image,
                        price: chosen.hit.price,
                    }
                  : {
                        ...common,
                        source: 'manual',
                        url: url.trim() || null,
                        /*
                         * Euros in the box, cents on the wire (invariant #7).
                         * A comma is accepted because half our markets write
                         * €12,50 and typing it the way you say it should not be
                         * a validation error.
                         */
                        price:
                            price.trim() === ''
                                ? null
                                : Math.round(Number(price.replace(',', '.')) * 100),
                    }

        /*
         * An Inertia post, unlike the bookmark on a product card.
         *
         * There the answer is a toast and the page is irrelevant; here the page
         * *is* the list, and the thing just added has to appear on it. Coming
         * back with fresh props is exactly what is wanted.
         */
        router.post(`${base}/list-items`, payload, {
            preserveScroll: true,
            onSuccess: () => close(),
            // Server-side rules are the authority — the link check in
            // particular is a security rule, not a hint. Showing its message is
            // what stops a rejected link looking like a button that did nothing.
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(false),
        })
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-lg border border-line px-3 py-2 text-sm hover:border-ink"
            >
                + {t('lists.add_product')}
            </button>
        )
    }

    const nothingFound = searched && !searching && groups.length === 0 && live.length === 0

    function hitRow(key: string, hit: GroupHit | LiveHit, onPick: () => void, badge?: string) {
        return (
            <li key={key}>
                <button
                    type="button"
                    onClick={onPick}
                    className="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-cream"
                >
                    {hit.image ? (
                        <img
                            src={hit.image}
                            alt=""
                            loading="lazy"
                            className="h-12 w-12 shrink-0 object-contain"
                            onError={(e) => {
                                e.currentTarget.style.visibility = 'hidden'
                            }}
                        />
                    ) : (
                        <span className="h-12 w-12 shrink-0 rounded bg-cream" />
                    )}

                    <span className="min-w-0 flex-1">
                        <span className="line-clamp-2 block text-sm">{hit.title}</span>
                        <span className="text-xs text-ink-soft">
                            {hit.price !== null && formatPrice(hit.price, market)}
                            {badge && (hit.price !== null ? ' · ' : '') + badge}
                        </span>
                    </span>
                </button>
            </li>
        )
    }

    return (
        <div className="w-full rounded-card border border-line bg-card p-4">
            {chosen === null ? (
                <>
                    {/*
                      A form, so Enter searches.

                      That is the whole reason it is a form rather than a bare
                      input: submitting is what a search field is for, it costs
                      no key handler, and on a phone the keyboard shows a Search
                      key instead of a newline for `type="search"` inside one.
                    */}
                    <form onSubmit={runSearch} className="flex items-center gap-2">
                        <input
                            ref={field}
                            type="search"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            placeholder={t('lists.add_search_placeholder')}
                            aria-label={t('lists.add_search_placeholder')}
                            className="w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                        />
                        {/* Named as well as pressable: nothing else on screen
                            says that typing here does not search by itself. */}
                        <button
                            type="submit"
                            disabled={searching}
                            className="shrink-0 rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white disabled:opacity-60"
                        >
                            {t('search.submit')}
                        </button>
                        <button
                            type="button"
                            onClick={close}
                            className="shrink-0 rounded-lg border border-line px-3 py-2 text-sm"
                        >
                            {t('lists.cancel')}
                        </button>
                    </form>

                    {searching && (
                        <p className="mt-3 text-sm text-ink-soft">{t('search.searching')}</p>
                    )}

                    {(groups.length > 0 || live.length > 0) && (
                        <ul className="mt-3 space-y-1">
                            {groups.map((g) =>
                                hitRow(
                                    `g${g.id}`,
                                    g,
                                    () => choose({ kind: 'group', hit: g }),
                                    g.merchantCount > 1
                                        ? t('product.across_shops', { count: g.merchantCount })
                                        : (g.brand ?? undefined),
                                ),
                            )}
                            {live.map((l) =>
                                hitRow(
                                    `l${l.source}-${l.externalId}`,
                                    l,
                                    () => choose({ kind: 'live', hit: l }),
                                    l.merchant,
                                ),
                            )}
                        </ul>
                    )}

                    {nothingFound && (
                        <p className="mt-3 text-sm text-ink-soft">
                            {t('lists.add_nothing_found', { term: term.trim() })}
                        </p>
                    )}

                    {error && <p className="mt-3 text-sm text-accent">{error}</p>}

                    {/*
                      Always present, never a consolation prize.

                      Whether the catalogue has the thing is a question only we
                      can answer, so making somebody search before they are
                      allowed to write it down asks them to guess it. This is
                      here from the moment the panel opens.
                    */}
                    <p className="mt-4 border-t border-line pt-3 text-sm text-ink-soft">
                        {t('lists.add_own_intro')}{' '}
                        <button
                            type="button"
                            onClick={() => choose({ kind: 'manual' })}
                            className="font-medium text-accent underline hover:text-accent-dark"
                        >
                            {t('lists.add_own_cta')}
                        </button>
                    </p>
                </>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    {chosen.kind !== 'manual' && (
                        <div className="flex items-center gap-3">
                            {chosen.hit.image && (
                                <img
                                    src={chosen.hit.image}
                                    alt=""
                                    className="h-14 w-14 shrink-0 object-contain"
                                />
                            )}
                            <p className="text-sm text-ink-soft">
                                {chosen.hit.price !== null && formatPrice(chosen.hit.price, market)}
                                {chosen.kind === 'live' && ` · ${chosen.hit.merchant}`}
                            </p>
                        </div>
                    )}

                    {/*
                      Editable for everything we may keep a title for, and
                      absent for a source we may not mirror: there the title is
                      re-fetched at render (invariant #6), so anything typed
                      here would be discarded without saying so.
                    */}
                    {(chosen.kind !== 'live' || chosen.hit.storable) && (
                        <label className="block text-sm font-medium">
                            {t('lists.add_description')}
                            <input
                                required
                                autoFocus
                                maxLength={500}
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                            />
                        </label>
                    )}

                    {chosen.kind === 'live' && !chosen.hit.storable && (
                        <p className="text-sm">
                            <span className="font-medium">{chosen.hit.title}</span>
                            <span className="mt-1 block text-xs text-ink-soft">
                                {t('lists.add_live_title_note', { shop: chosen.hit.merchant })}
                            </span>
                        </p>
                    )}

                    {chosen.kind === 'manual' && (
                        <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                            <label className="block text-sm font-medium">
                                {t('lists.manual_url')}
                                <input
                                    type="url"
                                    inputMode="url"
                                    maxLength={2048}
                                    placeholder="https://"
                                    value={url}
                                    onChange={(e) => setUrl(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                />
                            </label>

                            <label className="block text-sm font-medium">
                                {t('lists.manual_price')}
                                <input
                                    inputMode="decimal"
                                    value={price}
                                    onChange={(e) => setPrice(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                />
                            </label>
                        </div>
                    )}

                    <label className="block text-sm font-medium">
                        {t('suggestions.note_label')}
                        <input
                            maxLength={500}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder={t('lists.add_note_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                        />
                    </label>

                    {chosen.kind === 'manual' && (
                        // Said plainly rather than discovered: nothing is
                        // fetched from the link, so the description is the only
                        // thing that will ever show.
                        <p className="text-xs text-ink-soft">{t('lists.manual_no_preview')}</p>
                    )}

                    {error && <p className="text-sm text-accent">{error}</p>}

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        >
                            {t('lists.manual_save')}
                        </button>
                        <button
                            type="button"
                            onClick={reset}
                            className="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('lists.back')}
                        </button>
                    </div>
                </form>
            )}
        </div>
    )
}
