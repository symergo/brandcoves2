/**
 * The products this visitor opened, most recent first, on this device.
 *
 * Kept in `localStorage` and nowhere else: it is a convenience for the person
 * holding the phone, not a fact about them the site needs, and a returning
 * visitor deciding between two things wants the two things back — not an
 * account, not a list. Per market, because a product id is market-scoped
 * (invariant 2) and a Belgian card on the Dutch home page would link to a 404.
 *
 * Twelve is plenty: the band shows six, and the rest are there so the one
 * they want has not fallen off the end after a browse.
 *
 * Every read and write is wrapped, and an empty store is the ordinary answer:
 * a private window, cleared site data, a browser set to block storage, or the
 * SSR container, where `window` does not exist at all.
 */
export interface Viewed {
    id: number
    title: string
    image: string | null
    price: number | null
    url: string
    /** Unix ms, for ordering only. Never shown. */
    at: number
}

const LIMIT = 12

function key(marketKey: string): string {
    return `bc_recent:${marketKey}`
}

export function read(marketKey: string): Viewed[] {
    try {
        const raw = window.localStorage.getItem(key(marketKey))
        const parsed: unknown = raw === null ? [] : JSON.parse(raw)

        return Array.isArray(parsed) ? (parsed as Viewed[]).filter((v) => typeof v?.id === 'number') : []
    } catch {
        return []
    }
}

export function record(marketKey: string, item: Omit<Viewed, 'at'>): void {
    try {
        const rest = read(marketKey).filter((v) => v.id !== item.id)
        const next = [{ ...item, at: Date.now() }, ...rest].slice(0, LIMIT)
        window.localStorage.setItem(key(marketKey), JSON.stringify(next))
    } catch {
        // Storage refused. The band simply stays empty.
    }
}
