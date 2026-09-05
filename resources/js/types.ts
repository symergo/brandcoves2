/** Mirrors the payload in App\Http\Middleware\HandleInertiaRequests::share(). */

/** One language a country is read in, and the market that serves it. */
export interface SwitcherLanguage {
    language: string
    name: string
    market: string
}

/** One flag on the switcher: a country, and everything it can be read in. */
export interface SwitcherCountry {
    country: string
    name: string
    languages: SwitcherLanguage[]
}

export interface CurrentMarket {
    key: string
    label: string
    language: string
    hrefLang: string
    currency: string
    /**
     * The Daily Cove's URL segment in this market's language —
     * `cadeau-van-de-dag`, `cadeau-du-jour`, `gift-of-the-day`.
     *
     * Always build the link from this rather than a literal: the route refuses
     * another market's word, so a hardcoded `/daily` or a stale translation
     * table produces a 404 rather than a redirect.
     */
    coveSegment: string
}

export interface AuthUser {
    id: number
    name: string | null
    email: string
    isAdmin: boolean
}

/** Nested translation tree, as returned by Lang::get('site'). */
export type Translations = { [key: string]: string | Translations }

/**
 * A list the save picker can offer, as shared on every response for a signed-in
 * visitor. Deliberately without membership: which list holds a given product
 * comes from `savedItems`, because that is a fact about the product.
 */
export interface ListOption {
    id: string
    title: string
    kind: 'mine' | 'for_someone' | 'group'
    recipient: string | null
}

/** The list currently being filled, when the visitor is in adding mode. */
export interface SavingTo {
    id: string
    title: string
}

export interface SharedProps {
    auth: { user: AuthUser | null; googleEnabled: boolean }
    /**
     * `id` is null wherever the Google tag is switched off (staging, local),
     * and that null is what stops the cookie banner appearing there.
     * `consent` is null until the visitor has been asked — which is not the
     * same as a no, and is the only state that shows the banner.
     */
    analytics: { id: string | null; consent: 'granted' | 'denied' | null }
    /**
     * This page's canonical URL, written into the head on every client-side
     * navigation — the Blade shell renders that tag once and Inertia's <Head>
     * manages only the title. iOS share sheets read it in preference to the
     * address bar, so a stale one shares the wrong page. See `app.tsx`.
     */
    canonical: string
    /**
     * The <title> the server chose, so it matches the og:title Blade rendered.
     * Null on pages that set no metadata; those set their own `<Head title>`.
     */
    seoTitle: string | null
    market: CurrentMarket
    markets: SwitcherCountry[]
    translations: Translations
    translationVersion: string
    unreadCount: number
    savingTo: SavingTo | null
    /** Empty while signed out — the picker is unreachable there anyway. */
    lists: ListOption[]
    flash: { success?: string; error?: string; status?: string }
    [key: string]: unknown
}

/** Prices cross the wire as integer cents, exactly as they are stored. */
export type Cents = number

/**
 * An occasion's date, in the reader's market.
 *
 * The year is dropped when it is this one — "14 Jun" is how somebody says a
 * date three months out, and "14 Jun 2026" on a wedding this summer is noise.
 *
 * Here rather than in a page, because two surfaces render the same date: the
 * shared list a guest opens, and the owner's own Gift Cove. A private copy in
 * each is a formatting difference waiting to appear between the page you set it
 * on and the page other people read it from.
 */
export function formatOccasionDate(iso: string, market: CurrentMarket): string {
    const date = new Date(iso)

    // Same reasoning as `formatPrice`: `Intl` throws on a malformed locale, and
    // a throw inside render blanks the page rather than one badge.
    try {
        return new Intl.DateTimeFormat(market.hrefLang, {
            day: 'numeric',
            month: 'short',
            ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
        }).format(date)
    } catch {
        return iso
    }
}

export function formatPrice(cents: Cents, market: CurrentMarket): string {
    /*
     * Defensive, because the failure mode is disproportionate.
     *
     * `Intl.NumberFormat` throws on a missing or malformed currency, and a
     * throw inside render unmounts the whole tree — so one bad price does not
     * show a wrong number, it shows a blank page. Caught in the act: passing
     * `market.currency` here instead of `market` blanked the Secret Santa page
     * for every group that had a budget.
     *
     * A plain number is a poor price. It is a far better outcome than no page.
     */
    try {
        return new Intl.NumberFormat(market.hrefLang, {
            style: 'currency',
            currency: market.currency,
        }).format(cents / 100)
    } catch {
        return (cents / 100).toFixed(2)
    }
}
