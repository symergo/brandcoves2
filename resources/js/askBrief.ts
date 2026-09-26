/**
 * What Find a gift already knows, carried to the ask form without a URL.
 *
 * Find a gift keeps its answers out of addresses (find-a-gift.md: who it is
 * for may sit in a URL, the answers may not), so "Ask other people" carries
 * only `?relationship=` or `?person=` in the link, and the rest — the
 * interests ticked, the budget typed — waits here, in this tab's
 * `sessionStorage`, for the ask form to pick up once and forget.
 *
 * Only the fields the ask form has, and only the ones it can take: interests
 * from the fixed list (a typed word has no chip on the form), the taste, what
 * matters, the age group and a budget in euros. Never a name.
 *
 * Every access is guarded: Safari in private mode throws on storage access,
 * and a convenience that could not be read must never break the form. Without
 * it the form is filled from the server alone, or not at all.
 */
export interface AskBrief {
    interests?: string[]
    vibe?: string | null
    values?: string[]
    budget_max?: string
    age_band?: string
}

const KEY = 'bc_ask_brief'

/** How long a stashed brief stays good: the length of one click, generously. */
const TTL_MS = 10 * 60 * 1000

export function stashAskBrief(brief: AskBrief): void {
    try {
        window.sessionStorage.setItem(KEY, JSON.stringify({ at: Date.now(), brief }))
    } catch {
        // Nothing to do: the form fills from the server instead.
    }
}

/** The stashed brief, once; it is removed as it is read. */
export function takeAskBrief(): AskBrief | null {
    try {
        const raw = window.sessionStorage.getItem(KEY)
        window.sessionStorage.removeItem(KEY)

        if (raw === null) return null

        const parsed = JSON.parse(raw) as { at?: number; brief?: AskBrief }

        if (typeof parsed.at !== 'number' || Date.now() - parsed.at > TTL_MS || !parsed.brief) {
            return null
        }

        return parsed.brief
    } catch {
        return null
    }
}
