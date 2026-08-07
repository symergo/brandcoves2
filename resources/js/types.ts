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
    flash: { success?: string; error?: string }
    [key: string]: unknown
}

/** Prices cross the wire as integer cents, exactly as they are stored. */
export type Cents = number

export function formatPrice(cents: Cents, market: CurrentMarket): string {
    return new Intl.NumberFormat(market.hrefLang, {
        style: 'currency',
        currency: market.currency,
    }).format(cents / 100)
}
