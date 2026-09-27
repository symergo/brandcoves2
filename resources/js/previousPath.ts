/**
 * The page the visitor was on before this one, on this site.
 *
 * For the feedback form on /help, which prefills "which page is this about".
 * The server used to read it from the Referer header, and still does for a
 * visitor with a session, but a page from the anonymous page cache is the same
 * for everybody and cannot carry one visitor's Referer. So the browser answers:
 * the page an Inertia visit last left, else the document's referrer when it is
 * this site.
 */
let left: string | null = null

/**
 * Called from app.tsx when an Inertia GET visit starts, while the address
 * bar still shows the page being left.
 */
export function rememberLeaving(path: string): void {
    left = path
}

export function previousPath(): string | null {
    if (typeof window === 'undefined') {
        return null
    }

    if (left !== null && left !== window.location.pathname) {
        return left
    }

    if (document.referrer === '') {
        return null
    }

    try {
        const referrer = new URL(document.referrer)

        return referrer.host === window.location.host && referrer.pathname !== window.location.pathname
            ? referrer.pathname
            : null
    } catch {
        return null
    }
}
