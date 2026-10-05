import { router, usePage } from '@inertiajs/react'
import ScanButton from './ScanButton'
import ToolIcon from './ToolIcon'
import { isCleanTerm, searchHref } from '../searchUrl'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * A search, as a card: the field, the camera, the button.
 *
 * The same card on the home page (where "Recently searched" was) and at the
 * top of Find a gift, at the owner's request (2026-09-13). One component so
 * the two are the same card rather than two forms that drift apart, which is
 * what happened to the hero's search field and the search page's own.
 *
 * A real <form method="get"> rather than a controlled input and a router
 * call: it submits without JavaScript, the browser offers previous searches,
 * and Enter works the way it does in every other search box the visitor has
 * ever used. With JavaScript the same search lands under its readable
 * address (/zoek/term); without it the form lands on ?q=, which answers the
 * same page and names /zoek/term as canonical.
 *
 * The camera sits beside the field because somebody standing in a shop with
 * the product in their hand has the highest intent this site ever sees. The
 * wasm decoder is fetched inside the click handler, so a page nobody scans
 * from loads nothing extra.
 *
 * No heading, and one placeholder everywhere: "Wat zoek je?" (owner,
 * 2026-10-05). It replaced the heading "Zoek een cadeau" and a placeholder
 * that, on a phone only, added "or scan a barcode": the card is the field,
 * and the scan button beside it on a phone says the rest.
 */
export default function SearchCard({ className = '' }: { className?: string }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`
    const placeholder = t('search_card.placeholder')

    return (
        <section aria-label={placeholder} className={`rounded-card border border-line bg-card p-4 sm:p-5 ${className}`}>
            <form
                action={`${base}/search`}
                method="get"
                role="search"
                onSubmit={(e) => {
                    const q = new FormData(e.currentTarget).get('q')
                    if (typeof q !== 'string' || !isCleanTerm(q)) return
                    e.preventDefault()
                    router.get(searchHref(market.key, q))
                }}
                className="flex gap-2"
            >
                <input
                    type="search"
                    name="q"
                    // The placeholder is the label: two strings for one field
                    // drift apart the moment one of them is rewritten.
                    aria-label={placeholder}
                    placeholder={placeholder}
                    className="h-12 min-w-0 flex-1 rounded-card border border-line bg-cream px-4 text-ink placeholder:text-ink-soft focus:border-ink"
                />
                <ScanButton className="h-12 w-12 shrink-0 rounded-card border border-line bg-cream text-ink transition hover:border-ink" />
                <button
                    type="submit"
                    className="flex h-12 w-12 shrink-0 items-center justify-center rounded-card bg-accent text-white transition hover:bg-accent-dark"
                >
                    <ToolIcon name="search" className="h-5 w-5" />
                    <span className="sr-only">{t('nav.search')}</span>
                </button>
            </form>
        </section>
    )
}
