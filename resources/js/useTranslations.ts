import { usePage } from '@inertiajs/react'
import type { SharedProps } from './types'

/**
 * Site copy for the current market's language.
 *
 * The market decides the catalogue; its language decides the words. `be-nl` and
 * `nl-nl` are two markets sharing one set of strings, so translations are keyed
 * by language rather than by market.
 */
export function useTranslations() {
    const { translations, market } = usePage<SharedProps>().props

    /**
     * Look up a dotted key, e.g. `t('home.cta_gift')`.
     *
     * Returns the key itself when a string is missing, rather than an empty
     * space. A visible `home.cta_gift` in the UI is an obvious bug; a blank
     * button is one you ship without noticing.
     */
    function t(key: string, replacements: Record<string, string | number> = {}): string {
        const value = key
            .split('.')
            .reduce<unknown>((carry, part) => {
                if (carry !== null && typeof carry === 'object' && part in carry) {
                    return (carry as Record<string, unknown>)[part]
                }
                return undefined
            }, translations)

        if (typeof value !== 'string') {
            if (import.meta.env.DEV) {
                console.warn(`[i18n] missing translation: ${key} (${market.language})`)
            }
            return key
        }

        return Object.entries(replacements).reduce(
            (carry, [token, replacement]) => carry.replaceAll(`:${token}`, String(replacement)),
            value,
        )
    }

    /**
     * Numbers follow the MARKET, not the language: nl-BE and nl-NL agree on
     * words and disagree on how a thousands separator is written.
     */
    function n(value: number): string {
        return new Intl.NumberFormat(market.hrefLang).format(value)
    }

    return { t, n, locale: market.hrefLang, language: market.language }
}
