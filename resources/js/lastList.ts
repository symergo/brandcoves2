/**
 * The list you last saved something to.
 *
 * The bookmark on a product card saves without asking — that is the whole point
 * of it, and the chevron beside it is there for the times you want to choose.
 * What it saved *into* was always the default list, so somebody spending an
 * evening filling a list for their father had two choices: press the chevron and
 * pick, on every single card, or press the bookmark forty times and then move
 * forty items. The fast control was fast in the wrong direction.
 *
 * So an unqualified save goes wherever the last one went. Nothing else about the
 * control changes: the toast still names the list it landed in, and the chevron
 * still opens the picker.
 *
 * ## Not adding mode, and never over it
 *
 * `savingTo` — "I am filling Camping" — is a deliberate, server-side, visible
 * state that already routes unqualified saves, and it wins here. This is the
 * quiet version for people who never turned that on: a guess, made from what you
 * did last, and overridden by anything you actually said. Same rule as
 * `MarketPreference`: a chosen destination beats a remembered one.
 *
 * ## Per browser, per account, and disposable
 *
 * `localStorage`, keyed by user id, because the answer is about a person's habit
 * on one device rather than a fact about their account — and because a shared
 * laptop must not have one household member's saves land in another's list. A
 * signed-out visitor cannot save at all, so there is nothing to remember for
 * them.
 *
 * Everything here is guarded: Safari in private mode throws on `localStorage`
 * access rather than returning null, and a save control that crashes because a
 * *convenience* could not be read would be a bad trade. Every failure falls back
 * to the server's default list, which is exactly the behaviour this replaces.
 */
export interface LastList {
    id: string
    title: string
}

const KEY = 'bc.lastList'

/** In memory too, so forty cards on a page do not each hit storage. */
let cache: Record<string, LastList> | null = null

const listeners = new Set<() => void>()

function read(): Record<string, LastList> {
    if (cache !== null) return cache

    cache = {}

    try {
        const raw = window.localStorage.getItem(KEY)
        const parsed: unknown = raw === null ? null : JSON.parse(raw)

        if (parsed !== null && typeof parsed === 'object') {
            for (const [who, value] of Object.entries(parsed as Record<string, unknown>)) {
                if (
                    value !== null &&
                    typeof value === 'object' &&
                    typeof (value as LastList).id === 'string' &&
                    typeof (value as LastList).title === 'string'
                ) {
                    cache[who] = { id: (value as LastList).id, title: (value as LastList).title }
                }
            }
        }
    } catch {
        // Unreadable, unparseable or forbidden. All three mean "no memory".
    }

    return cache
}

function write(): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(cache ?? {}))
    } catch {
        // Full, or private mode. The in-memory copy still serves this visit.
    }

    listeners.forEach((fn) => fn())
}

export function subscribe(listener: () => void): () => void {
    listeners.add(listener)

    return () => {
        listeners.delete(listener)
    }
}

/**
 * Where an unqualified save should go, for this person.
 *
 * Null on the server and until the first client render — see `serverSnapshot`.
 * The control renders "Save to a list" in that first pass exactly as it did
 * before, and names the remembered list once it knows one.
 */
export function snapshot(userId: number | null): LastList | null {
    if (userId === null || typeof window === 'undefined') return null

    return read()[String(userId)] ?? null
}

/** Server-rendered markup cannot know; the client fills it in. */
export function serverSnapshot(): LastList | null {
    return null
}

export function remember(userId: number | null, list: LastList): void {
    if (userId === null || typeof window === 'undefined') return

    const store = read()
    const held = store[String(userId)]

    if (held?.id === list.id && held.title === list.title) return

    store[String(userId)] = list
    write()
}

/**
 * Forget it — the list is gone, or was never ours.
 *
 * Called when a save into the remembered list 404s, which is what a deleted
 * list looks like from here. The caller retries without it rather than showing
 * a failure: a stale bookmark is our problem, not the reader's.
 */
export function forget(userId: number | null): void {
    if (userId === null || typeof window === 'undefined') return

    const store = read()

    if (store[String(userId)] === undefined) return

    delete store[String(userId)]
    write()
}
