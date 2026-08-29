/**
 * A write that does not navigate.
 *
 * `router.post` makes every save a full Inertia round trip: the page's props
 * are rebuilt — a forty-result search re-run — to move one row, and the answer
 * arrives as a whole new page. For a bookmark on a product card that is the
 * wrong shape twice over. It is slow, and it repaints the grid underneath the
 * thing the person just pressed.
 *
 * So the save control talks to the server directly. The pattern is already
 * established here: `Daily/Edition`, `Discover` and `MarketSwitcher` all reach
 * for the CSRF token in the document head and `fetch` with it. This is that,
 * written once.
 */
export function csrfToken(): string {
    return (
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''
    )
}

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message)
        this.name = 'HttpError'
    }
}

/**
 * POST/DELETE as JSON, and insist on JSON back.
 *
 * `Accept: application/json` is load-bearing rather than polite: it is what
 * `WishlistItemController` reads to decide between answering with a row and
 * answering with a redirect, and it is also what turns Laravel's guest
 * redirect into a 401 we can act on instead of a 302 to the login page that
 * `fetch` would follow silently and report as a success.
 */
export async function send<T>(
    url: string,
    method: 'POST' | 'DELETE' | 'PATCH',
    body?: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        // Same-origin by default in modern browsers, but stated: the session
        // cookie is the whole authorisation story for these endpoints.
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    })

    if (!response.ok) {
        // A validation failure says something useful; a 500 says nothing the
        // visitor can act on, so the caller falls back to its own wording.
        const detail = await response
            .json()
            .then((data: { message?: string }) => data.message)
            .catch(() => undefined)

        throw new HttpError(response.status, detail ?? `${method} ${url} failed`)
    }

    return response.json() as Promise<T>
}
