/**
 * Which shape the visitor last chose for search results.
 *
 * ## Why this is not a cookie, and not on the server
 *
 * It is a preference about *looking*, not about what is being looked at. The
 * market is a cookie because it decides the catalogue and the server has to know
 * it before it renders anything; this decides which of two renderings of the
 * same results you get, and only the browser that made the choice cares.
 *
 * ## Why the URL still wins
 *
 * A shared link carries `?view=store` or does not, and either way it means
 * something the sender chose. Overriding that with the recipient's stored
 * preference would make the same link show two different pages to two people,
 * which is the property a URL exists to not have. So the stored value is
 * consulted only when the URL is silent.
 *
 * Every read and write is wrapped: `localStorage` throws outright in a browser
 * set to block site data, and a preference is never worth a broken page.
 */

const KEY = 'bc_search_view'

export type SearchView = 'grid' | 'store'

export function remember(view: SearchView): void {
    try {
        // 'grid' is the default, so storing it means "I chose the default" -
        // worth keeping, or switching back would not survive the next visit.
        window.localStorage.setItem(KEY, view)
    } catch {
        // Blocked storage. The preference lasts this page and no longer.
    }
}

export function preferred(): SearchView | null {
    try {
        const stored = window.localStorage.getItem(KEY)

        return stored === 'grid' || stored === 'store' ? stored : null
    } catch {
        return null
    }
}
