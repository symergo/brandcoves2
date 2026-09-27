import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { formatPrice } from '../types'
import type { CurrentMarket } from '../types'
import { useTranslations } from '../useTranslations'
import {
    HitList,
    HitRow,
    OwnItemFooter,
    SearchField,
    searchPanel,
    useGroupBadge,
    useListSearch,
    type GroupHit,
    type LiveHit,
} from './ProductSearch'
import TheirWishes, { type Wish } from './TheirWishes'

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
 *
 * The field and the result rows are `ProductSearch`, shared with every other
 * inline search on the site (owner, 2026-09-27: they should all be this one).
 */
export default function AddProduct({
    base,
    listId,
    market,
    defaultOpen = false,
    onListPage = true,
    onClose,
    theirWishes = null,
    initialTerm = '',
    startManual = false,
    autoFocus = true,
}: {
    base: string
    listId: string
    market: CurrentMarket
    /**
     * Open on arrival rather than behind its button. An empty list — the one
     * the wizard has just made — has exactly one thing to do, and a button
     * that says so is a step between the person and it.
     */
    defaultOpen?: boolean
    /**
     * On the list itself, the new row appearing is the confirmation. Anywhere
     * else (the My Coves overview) the list is not on screen, so the server
     * answers with a toast naming it instead.
     */
    onListPage?: boolean
    /** Told when the panel closes, added or cancelled: a dialog around it closes too. */
    onClose?: () => void
    /**
     * On a list about somebody who lets you see a wish list: what is on it,
     * one tap each (wish-list-for-my-people.md). Shown while nothing has been
     * searched, since that is when somebody is still deciding what to add.
     */
    theirWishes?: { name: string; wishes: Wish[]; onList: Set<number> } | null
    /**
     * Start with this search already run, or on "something typed by hand".
     * Find a gift's search card (2026-09-27) looks like this panel before it
     * has a list to add to: the first search or the offline link fetches the
     * list, and the panel takes over from there without asking again.
     */
    initialTerm?: string
    startManual?: boolean
    /**
     * Put the cursor in the field (and the field in view) when it opens on
     * arrival. Off on a page where the panel is one section of several
     * (`/for/{token}`): arriving there must not jump the page down to it or
     * raise a phone's keyboard before anybody has asked to type.
     */
    autoFocus?: boolean
}) {
    const { t } = useTranslations()
    const groupBadge = useGroupBadge()

    const [open, setOpen] = useState(defaultOpen)

    /*
     * The search itself, shared with every inline search (`ProductSearch`).
     *
     * A pasted link the server does not recognise goes straight on the list
     * (owner's call, 2026-09-26): a card asking "add this link?" was a step
     * with only one sensible answer. The server looks a link up in our
     * catalogue and through the connectors first (`LinkRouter`), so a bol,
     * eBay or feed-shop link usually comes back as an ordinary result; what it
     * does not recognise is saved as it is, and the shop's page is read
     * afterwards, in a queued job, and the row fills itself in.
     */
    const found = useListSearch(base, (pasted) => addLink(pasted))
    const { term, setTerm, groups, live, link, linkRefused, searching, searched } = found

    const [chosen, setChosen] = useState<Chosen | null>(null)
    const [title, setTitle] = useState('')
    const [note, setNote] = useState('')
    const [url, setUrl] = useState('')
    const [price, setPrice] = useState('')
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState<string | null>(null)

    /*
     * A picture for something typed by hand (owner's request, 2026-09-26): the
     * pottery from the market, the voucher. Sent with the item in one request,
     * so there is no second step after saving. The server re-encodes it
     * (ImageStore), which drops the GPS position a phone writes into a photo.
     */
    const [photo, setPhoto] = useState<File | null>(null)
    const [photoPreview, setPhotoPreview] = useState<string | null>(null)

    useEffect(() => {
        if (photo === null) {
            setPhotoPreview(null)

            return
        }

        const preview = URL.createObjectURL(photo)
        setPhotoPreview(preview)

        return () => URL.revokeObjectURL(preview)
    }, [photo])

    const field = useRef<HTMLInputElement>(null)

    /*
     * On Enter, not as you type (see `useListSearch` for the cost argument).
     * It also reads better here. A product search is a considered act: people
     * type two or three words and then look. Results reshuffling under a
     * half-typed word is noise, and the row you were reaching for moves.
     */
    function search(raw: string): void {
        setError(null)
        found.search(raw)
    }

    // Not on arrival when the page asked not to (`autoFocus`); always when
    // somebody pressed the button that opens it.
    const arrived = useRef(true)

    useEffect(() => {
        if (open && (autoFocus || !arrived.current)) field.current?.focus()
        arrived.current = false
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open])

    // The start state handed over by Find a gift's search card. Once, on mount.
    useEffect(() => {
        if (startManual) {
            choose({ kind: 'manual' })
        } else if (initialTerm.trim() !== '') {
            setTerm(initialTerm)
            search(initialTerm)
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    /*
     * Opened on arrival (an empty list, which is where the one-step create
     * lands): bring the field into view as well. The focus above scrolls to
     * it, but Inertia puts the page back at the top once the visit finishes,
     * so on a phone the cursor sat in a field a screen and a half down that
     * nobody could see. 'nearest' moves as little as it can, so on a tall
     * screen the page does not move at all.
     */
    useEffect(() => {
        if (!defaultOpen || !autoFocus) return

        const timer = window.setTimeout(() => field.current?.scrollIntoView({ block: 'nearest' }), 60)

        return () => window.clearTimeout(timer)
        // Once, on mount: this is about arriving, not about reopening.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    function reset(): void {
        setChosen(null)
        setTitle('')
        setNote('')
        setUrl('')
        setPrice('')
        setPhoto(null)
        setError(null)
    }

    function close(): void {
        setOpen(false)
        found.clear()
        reset()
        onClose?.()
    }

    /*
     * A barcode the catalogue does not know.
     *
     * The digits in the search box after a scan (or typed) that found nothing.
     * Kept on the hand-written item, so the day a shop we cover sells it the
     * item turns into that product by itself (LinkBarcodeItems).
     */
    const barcode = /^\d{8,14}$/.test(term.trim()) ? term.trim() : null

    /** Save a pasted link as it is. The title comes from the page, read later. */
    function addLink(target: { url: string } | null = link): void {
        if (target === null) return

        setError(null)
        setBusy(true)

        router.post(
            `${base}/list-items`,
            {
                wishlist_id: listId,
                source: 'manual',
                url: target.url,
                title: null,
                on_list_page: onListPage,
            },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                onFinish: () => setBusy(false),
            },
        )
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
        //
        // Not for a barcode: "8712345678906" is not what anybody calls the
        // thing, and the digits are kept separately anyway.
        setTitle(next.kind === 'manual' ? (barcode === null ? term.trim() : '') : next.hit.title)
    }

    function submit(event: React.FormEvent): void {
        event.preventDefault()
        setError(null)
        setBusy(true)

        const common = {
            wishlist_id: listId,
            title: title.trim(),
            note: note.trim() || null,

            /*
             * This panel only ever sits on the list being added to, so the
             * server answers with the new row's id rather than "Saved to
             * Camping" — a banner naming the page you are on, about a row that
             * appears under it a moment later. `Lists/Show` tints that row
             * instead.
             */
            on_list_page: onListPage,
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
                        gtin: barcode,
                        // Only when there is one: a File makes Inertia send the
                        // request as multipart, which the server reads the same.
                        ...(photo !== null ? { photo } : {}),
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

    return (
        <div className={searchPanel}>
            {chosen === null ? (
                <>
                    <SearchField value={term} onChange={setTerm} onSearch={search} busy={searching} inputRef={field} />

                    {searching && <p className="mt-3 text-sm text-ink-soft">{t('search.searching')}</p>}

                    {(groups.length > 0 || live.length > 0) && (
                        <HitList>
                            {groups.map((g) => (
                                <HitRow
                                    key={`g${g.id}`}
                                    image={g.image}
                                    title={g.title}
                                    price={g.price}
                                    badge={groupBadge(g)}
                                    onPick={() => choose({ kind: 'group', hit: g })}
                                />
                            ))}
                            {live.map((l) => (
                                <HitRow
                                    key={`l${l.source}-${l.externalId}`}
                                    image={l.image}
                                    title={l.title}
                                    price={l.price}
                                    badge={l.merchant}
                                    onPick={() => choose({ kind: 'live', hit: l })}
                                />
                            ))}
                        </HitList>
                    )}

                    {found.nothingFound && (
                        <p className="mt-3 text-sm text-ink-soft">
                            {t('lists.add_nothing_found', { term: term.trim() })}
                        </p>
                    )}

                    {/* Rather than an empty result, which invites typing it
                        again instead of writing it in by hand. */}
                    {found.failed && <p className="mt-3 text-sm text-danger">{t('lists.search_failed')}</p>}

                    {linkRefused && (
                        <p className="mt-3 text-sm text-danger">{t('lists.link_refused')}</p>
                    )}

                    {/* Only when the link also matched something; alone, it was added already. */}
                    {link !== null && !searching && (
                        <button
                            type="button"
                            onClick={() => addLink()}
                            disabled={busy}
                            className="mt-2 text-sm text-accent underline hover:text-accent-dark disabled:opacity-50"
                        >
                            {t('lists.add_link_also')}
                        </button>
                    )}

                    {error && <p className="mt-3 text-sm text-danger">{error}</p>}

                    {theirWishes !== null && !searched && !searching && (
                        <TheirWishes
                            base={base}
                            name={theirWishes.name}
                            wishes={theirWishes.wishes}
                            listId={listId}
                            onList={theirWishes.onList}
                            market={market}
                            variant="add"
                        />
                    )}

                    {/* Here from the moment the panel opens, never only after a
                        search has failed. */}
                    <OwnItemFooter onClick={() => choose({ kind: 'manual' })} />
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

                    {chosen.kind === 'manual' && barcode !== null && (
                        <p className="text-xs text-ink-soft">{t('lists.gtin_kept', { gtin: barcode })}</p>
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

                    {chosen.kind === 'manual' && (
                        <div className="text-sm font-medium">
                            {t('lists.photo_label')}
                            <div className="mt-1 flex flex-wrap items-center gap-3">
                                {photoPreview && (
                                    <img src={photoPreview} alt="" className="h-14 w-14 rounded object-cover" />
                                )}
                                <label className="cursor-pointer rounded-lg border border-line px-3 py-1.5 text-sm font-normal hover:border-ink">
                                    {photo ? t('lists.photo_replace') : t('lists.photo_add')}
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
                                        className="sr-only"
                                    />
                                </label>
                                {photo && (
                                    <button
                                        type="button"
                                        onClick={() => setPhoto(null)}
                                        className="text-xs font-normal text-ink-soft underline hover:text-ink"
                                    >
                                        {t('lists.photo_remove')}
                                    </button>
                                )}
                            </div>
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

                    {error && <p className="text-sm text-danger">{error}</p>}

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
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
