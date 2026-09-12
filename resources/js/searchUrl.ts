/**
 * The address of a search, in the market's own language.
 *
 * The client-side twin of `App\Support\SearchUrl`, and it has to agree with
 * it exactly: the server decides the canonical, and a page the browser
 * navigated to under one URL while the server named another as canonical is
 * a page that exists twice. The rules are therefore the same three lines:
 *
 * - the segment is the market's word (`zoek`, `recherche`, `search`, `buscar`);
 * - only a term of ASCII letters, digits and single spaces gets a path, with
 *   spaces as hyphens; anything else stays on `/search?q=`, exact;
 * - case is folded, because `/zoek/Philips` and `/zoek/philips` are one page.
 *
 * Used wherever the browser navigates to a search: the two search forms, the
 * "clear the filters" link, and the filter rail. Server-rendered links (term
 * pills, popular searches, coves, alert mails) come from the PHP side already
 * in this shape.
 */

const SEGMENTS: Record<string, string> = {
    'be-nl': 'zoek',
    'nl-nl': 'zoek',
    'be-fr': 'recherche',
    en: 'search',
    es: 'buscar',
}

export function normaliseTerm(term: string): string {
    return term.trim().replace(/\s+/g, ' ').toLowerCase()
}

export function isCleanTerm(term: string): boolean {
    return /^[a-z0-9]+(?: [a-z0-9]+)*$/.test(normaliseTerm(term))
}

/**
 * Where to send the browser, split the way `router.get()` wants it: a path,
 * and the parameters that still belong in the query string.
 */
export function searchTarget(
    marketKey: string,
    params: Record<string, unknown>,
): { path: string; query: Record<string, unknown> } {
    const { q, ...rest } = params
    const term = typeof q === 'string' ? normaliseTerm(q) : ''
    const landing = `/${marketKey}/search`

    if (term === '') {
        return { path: landing, query: rest }
    }

    if (isCleanTerm(term)) {
        const segment = SEGMENTS[marketKey] ?? 'search'

        return { path: `/${marketKey}/${segment}/${term.replace(/ /g, '-')}`, query: rest }
    }

    return { path: landing, query: { q: term, ...rest } }
}

/** The same, as one string, for an `<a href>` or a `<Link>`. */
export function searchHref(marketKey: string, term: string, params: Record<string, string> = {}): string {
    const { path, query } = searchTarget(marketKey, { q: term, ...params })
    const qs = new URLSearchParams(
        Object.entries(query).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''),
    ).toString()

    return qs === '' ? path : `${path}?${qs}`
}
