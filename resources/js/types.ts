/** Mirrors the payload in App\Http\Middleware\HandleInertiaRequests::share(). */

export interface MarketSummary {
    key: string
    label: string
    nativeName: string
}

export interface CurrentMarket {
    key: string
    label: string
    language: string
    hrefLang: string
    currency: string
}

export interface AuthUser {
    id: number
    name: string | null
    email: string
    isAdmin: boolean
}

/** Nested translation tree, as returned by Lang::get('site'). */
export type Translations = { [key: string]: string | Translations }

export interface SharedProps {
    auth: { user: AuthUser | null }
    market: CurrentMarket
    markets: MarketSummary[]
    translations: Translations
    translationVersion: string
    unreadCount: number
    flash: { success?: string; error?: string; status?: string }
    [key: string]: unknown
}

/** Prices cross the wire as integer cents, exactly as they are stored. */
export type Cents = number

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
