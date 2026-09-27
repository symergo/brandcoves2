import { Link, usePage } from '@inertiajs/react'
import { useRef, useState, type ReactNode, type RefObject } from 'react'
import { formatPrice, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import ScanButton from './ScanButton'
import ToolIcon from './ToolIcon'

/**
 * The site's one inline product search: the field, and the rows it answers
 * with.
 *
 * ## Why this is its own file
 *
 * The owner asked (2026-09-27) that every search which puts something on a
 * list, or picks a product for something, look and behave like the one on a
 * list page (`AddProduct`). Until then there were five: the list page's,
 * Find a gift's, the person's own page (`/for/{token}`), a shared list's
 * "suggest something" and an answer's product picker on Ask others. Each had
 * drawn its own field (one with the barcode button, one without; a magnifier
 * here, a word there) and its own results (rows in one, grids of cards in
 * three). Copies drift the moment one of them gains a feature, which is how
 * pasted links and the barcode reached some and not others.
 *
 * So the pieces live here and the callers keep only what really differs:
 * what a press on a result does. On a list page it opens the "edit the
 * wording, then Zet erop" step; without a list it is the save picker
 * (`SaveToList`); on a shared list it is "suggest"; on an answer it is "add
 * to my answer".
 */

/** A catalogue product, as `/list-search` answers. */
export interface GroupHit {
    id: number
    title: string
    image: string | null
    price: number | null
    brand: string | null
    merchantCount: number
}

/** A result from a shop we do not mirror. */
export interface LiveHit {
    source: string
    externalId: string
    title: string
    image: string | null
    price: number | null
    merchant: string
    /** Whether we may keep a title of our own for it. See invariant #6. */
    storable: boolean
}

/** A pasted link nothing in the catalogue matched, as the server read it. */
export interface PastedLink {
    url: string
    host: string
}

/** The frame the search sits in, the same wherever it appears. */
export const searchPanel = 'w-full rounded-card border border-line bg-card p-4'

/**
 * The search half of a list's add panel, without the list.
 *
 * On Enter, not as you type: a typeahead fires a search per keystroke, and the
 * live half costs real requests to shops ("koptelefoon" is eleven searches for
 * one intention). The request id guards against an earlier answer landing
 * after a later one: two presses of Enter on a slow connection.
 *
 * `onLinkOnly` is for a caller that can act on a pasted link by itself (a list
 * page adds it straight away). Without it, the link is kept for the caller to
 * offer.
 */
export function useListSearch(base: string, onLinkOnly?: (link: PastedLink) => void) {
    const [term, setTerm] = useState('')
    const [groups, setGroups] = useState<GroupHit[]>([])
    const [live, setLive] = useState<LiveHit[]>([])
    const [link, setLink] = useState<PastedLink | null>(null)
    const [linkRefused, setLinkRefused] = useState(false)
    const [searching, setSearching] = useState(false)
    const [searched, setSearched] = useState(false)
    const [failed, setFailed] = useState(false)
    const latest = useRef(0)

    /*
     * The query is a parameter, not read from state: a scan sets the field and
     * searches in the same tick, and `term` would still be the old value then.
     */
    function search(raw: string): void {
        const q = raw.trim()

        setFailed(false)
        setLink(null)
        setLinkRefused(false)

        if (q.length < 2) {
            latest.current++
            setGroups([])
            setLive([])
            setSearched(false)
            setSearching(false)

            return
        }

        const id = ++latest.current

        setSearching(true)

        fetch(`${base}/list-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
            .then((r) => {
                // A refusal (signed out, throttled) must not read as "nothing found".
                if (!r.ok) throw new Error(String(r.status))

                return r.json()
            })
            .then((data: { groups?: GroupHit[]; live?: LiveHit[]; link?: PastedLink | null; linkRefused?: boolean }) => {
                if (id !== latest.current) return

                const found = (data.groups ?? []).length + (data.live ?? []).length

                if (data.link && found === 0 && onLinkOnly) {
                    onLinkOnly(data.link)

                    return
                }

                setGroups(data.groups ?? [])
                setLive(data.live ?? [])
                setLink(data.link ?? null)
                setLinkRefused(data.linkRefused ?? false)
                setSearched(true)
            })
            .catch(() => {
                if (id !== latest.current) return

                // An empty result and a failed request read identically
                // otherwise, and the second invites typing it again.
                setFailed(true)
                setGroups([])
                setLive([])
                setSearched(true)
            })
            .finally(() => {
                if (id === latest.current) setSearching(false)
            })
    }

    function clear(): void {
        latest.current++
        setTerm('')
        setGroups([])
        setLive([])
        setLink(null)
        setLinkRefused(false)
        setSearched(false)
        setSearching(false)
        setFailed(false)
    }

    const nothingFound =
        searched && !searching && !failed && groups.length === 0 && live.length === 0 && link === null && !linkRefused

    return { term, setTerm, groups, live, link, linkRefused, searching, searched, failed, nothingFound, search, clear }
}

/**
 * The field: the words, the barcode, the magnifier.
 *
 * A form, so Enter searches and a phone's keyboard shows a Search key. Inside
 * another form (an answer on Ask others) a form may not nest, so `nested`
 * draws the same thing with an Enter handler instead.
 *
 * The barcode fills the field and searches rather than leaving: navigating to
 * /search would take whatever is being added to with it.
 */
export function SearchField({
    value,
    onChange,
    onSearch,
    busy = false,
    placeholder,
    inputRef,
    nested = false,
}: {
    value: string
    onChange: (value: string) => void
    /** Given the words to search for, which a scan sets in the same tick. */
    onSearch: (term: string) => void
    busy?: boolean
    /** The list page's "Zoek, of plak een link…" unless the page says otherwise. */
    placeholder?: string
    inputRef?: RefObject<HTMLInputElement | null>
    nested?: boolean
}) {
    const { t } = useTranslations()
    const words = placeholder ?? t('lists.add_search_placeholder')

    const body = (
        <>
            <input
                ref={inputRef}
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={
                    nested
                        ? (e) => {
                              if (e.key === 'Enter') {
                                  e.preventDefault()
                                  onSearch(value)
                              }
                          }
                        : undefined
                }
                placeholder={words}
                aria-label={words}
                className="w-full min-w-0 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
            />
            <ScanButton
                className="shrink-0 rounded-lg border border-line px-3 py-2"
                onScan={(gtin) => {
                    onChange(gtin)
                    onSearch(gtin)
                }}
            />
            {/* The magnifier, as on every search field of the site; the word
                stays for screen readers. */}
            <button
                type={nested ? 'button' : 'submit'}
                onClick={nested ? () => onSearch(value) : undefined}
                disabled={busy}
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent text-white transition hover:bg-accent-dark disabled:opacity-50"
            >
                <ToolIcon name="search" className="h-5 w-5" />
                <span className="sr-only">{t('search.submit')}</span>
            </button>
        </>
    )

    if (nested) {
        return (
            <div role="search" className="flex items-center gap-2">
                {body}
            </div>
        )
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault()
                onSearch(value)
            }}
            className="flex items-center gap-2"
        >
            {body}
        </form>
    )
}

/** The rows a search answers with. */
export function HitList({ children }: { children: ReactNode }) {
    return <ul className="mt-3 space-y-1">{children}</ul>
}

/**
 * One result: the picture, two lines of title, the price and one more fact.
 *
 * Either the whole row is the press (`onPick`: a list page, where choosing
 * leads to the wording step), or the row carries its own control at the end
 * (`action`: the save picker, "suggest", "add to my answer"), with the title
 * linking to the product when there is a page for it.
 */
export function HitRow({
    image,
    title,
    price,
    badge,
    onPick,
    href,
    action,
}: {
    image: string | null
    title: string
    price: number | null
    /** The one fact after the price: the brand, how many shops, which shop. */
    badge?: string | null
    onPick?: () => void
    href?: string
    action?: ReactNode
}) {
    const { market } = usePage<SharedProps>().props

    const picture = image ? (
        <img
            src={image}
            alt=""
            loading="lazy"
            className="h-12 w-12 shrink-0 object-contain"
            onError={(e) => {
                e.currentTarget.style.visibility = 'hidden'
            }}
        />
    ) : (
        <span className="h-12 w-12 shrink-0 rounded bg-cream" />
    )

    const words = (
        <>
            <span className="line-clamp-2 block text-sm">{title}</span>
            <span className="text-xs text-ink-soft">
                {price !== null && formatPrice(price, market)}
                {badge ? (price !== null ? ' · ' : '') + badge : null}
            </span>
        </>
    )

    if (onPick) {
        return (
            <li>
                <button
                    type="button"
                    onClick={onPick}
                    className="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-cream"
                >
                    {picture}
                    <span className="min-w-0 flex-1">{words}</span>
                </button>
            </li>
        )
    }

    return (
        <li className="flex items-center gap-3 rounded-lg p-2">
            {picture}
            {href ? (
                <Link href={href} className="min-w-0 flex-1 hover:underline">
                    {words}
                </Link>
            ) : (
                <span className="min-w-0 flex-1">{words}</span>
            )}
            {action && <span className="shrink-0">{action}</span>}
        </li>
    )
}

/** What follows the price on a catalogue row: how many shops, else the brand. */
export function useGroupBadge(): (hit: GroupHit) => string | null {
    const { t } = useTranslations()

    return (hit) => (hit.merchantCount > 1 ? t('product.across_shops', { count: hit.merchantCount }) : hit.brand)
}

/**
 * "Voeg een offline artikel toe", under the results.
 *
 * Always present, never a consolation prize: whether the catalogue has the
 * thing is a question only we can answer, so making somebody search first
 * asks them to guess it.
 */
export function OwnItemFooter({ onClick, disabled = false }: { onClick: () => void; disabled?: boolean }) {
    const { t } = useTranslations()

    return (
        <p className="mt-4 border-t border-line pt-3 text-sm">
            <button
                type="button"
                onClick={onClick}
                disabled={disabled}
                className="font-medium text-accent underline hover:text-accent-dark disabled:opacity-50"
            >
                {t('lists.add_own_cta')}
            </button>
        </p>
    )
}
