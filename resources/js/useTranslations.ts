import { usePage } from '@inertiajs/react'
import { createElement, Fragment, type ReactNode } from 'react'
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

    /**
     * `t()` for a sentence with an element in it, e.g.
     * `tRich('lists.added_to', { list: <ListName name={…} kind={…} /> })`.
     *
     * Returns React nodes rather than a string, so it goes where text goes in
     * JSX and nowhere else: not in `title=`, `aria-label=` or a share text,
     * which can only hold a string (use `t()` there). Plain values in
     * `replacements` are filled in as `t()` fills them.
     */
    function tRich(key: string, replacements: Record<string, ReactNode>): ReactNode {
        return rich(t(key), replacements)
    }

    return { t, tRich, n, locale: market.hrefLang, language: market.language }
}

/*
 * Quotes that may sit directly around a placeholder, in the four languages,
 * with French guillemets allowed their (non-breaking) space. Kept in step with
 * `App\Support\ListName::QUOTES`, which applies the same rule to e-mail.
 */
const QUOTES: [string, string][] = [
    ['“', '”'],
    ['„', '”'],
    ['„', '“'],
    ['‘', '’'],
    ['«', '»'],
    ['‹', '›'],
    ['"', '"'],
    ["'", "'"],
]

const SPACE = /[   ]$/
const LEADING_SPACE = /^[   ]/

/**
 * Drop a matched pair of quotes around an element placed in a sentence.
 *
 * A sentence like "A new message on “:list”" quotes the name because in plain
 * text the quotes were the only thing marking where a name starts. Where the
 * name is drawn as a list's name (`ListName`) the style does that job, and
 * the quotes would mark it twice. Only a *pair* goes: a lone quote on one side
 * belongs to something else.
 */
function unquote(before: string, after: string): [string, string] {
    for (const [open, close] of QUOTES) {
        const b = before.endsWith(open) ? before.slice(0, -open.length) : SPACE.test(before) && before.slice(0, -1).endsWith(open) ? before.slice(0, -1 - open.length) : null
        const a = after.startsWith(close) ? after.slice(close.length) : LEADING_SPACE.test(after) && after.slice(1).startsWith(close) ? after.slice(1 + close.length) : null

        if (b !== null && a !== null) {
            return [b, a]
        }
    }

    return [before, after]
}

/**
 * Fill `:placeholders` in an already-translated sentence with React nodes.
 *
 * For a sentence that arrives translated from the server — a flash message,
 * a notification, a save confirmation — with `:list` left in so the page can
 * put the list's name there itself (`App\Support\ListName`). Longer tokens
 * are matched first, so `:list` never eats the start of `:listing`.
 */
export function rich(template: string, replacements: Record<string, ReactNode>): ReactNode {
    const tokens = Object.keys(replacements).sort((a, b) => b.length - a.length)

    if (tokens.length === 0) {
        return template
    }

    const pattern = new RegExp(`:(${tokens.map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})(?![A-Za-z_])`, 'g')
    const parts: ReactNode[] = []
    let text = ''
    let last = 0

    for (const match of template.matchAll(pattern)) {
        const value = replacements[match[1]]
        const at = match.index ?? 0

        text += template.slice(last, at)
        last = at + match[0].length

        if (typeof value === 'string' || typeof value === 'number') {
            text += String(value)
            continue
        }

        // An element: close off the text so far, minus any quotes around it.
        const [before, after] = unquote(text, template.slice(last))
        const rest = template.slice(last)

        parts.push(before)
        parts.push(createElement(Fragment, { key: parts.length }, value))
        text = ''
        // `after` is `rest` with a closing quote taken off its front, so move
        // past what was dropped.
        last += rest.length - after.length
    }

    parts.push(text + template.slice(last))

    return createElement(Fragment, null, ...parts.filter((part) => part !== ''))
}
