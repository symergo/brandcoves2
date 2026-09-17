/**
 * The answer to the cookie question, told to a tag that is already loaded.
 *
 * Under Consent Mode (adopted 2026-09-17, see app.blade.php) the shell renders
 * gtag.js on every page with every storage type denied. So accepting does not
 * load anything — it lifts the denial, and the tag begins keeping the cookie it
 * was refusing to write a moment earlier. Declining says so explicitly, which
 * matters for somebody who had accepted earlier in the same page's life and has
 * just withdrawn.
 *
 * This replaced a loader. Before Consent Mode the script did not exist until
 * somebody agreed, so the banner had to fetch it; now fetching it again would
 * mean two copies of gtag.js and two of every hit.
 *
 * ad_personalization is deliberately absent from the granted set: the privacy
 * page promises that nothing measured here becomes an advertising audience, and
 * conversion measurement does not need it. See the note in app.blade.php.
 */

declare global {
    interface Window {
        dataLayer?: unknown[]
        gtag?: (...args: unknown[]) => void
    }
}

export function updateConsent(granted: boolean): void {
    window.gtag?.('consent', 'update', {
        ad_storage: granted ? 'granted' : 'denied',
        ad_user_data: granted ? 'granted' : 'denied',
        ad_personalization: 'denied',
        analytics_storage: granted ? 'granted' : 'denied',
    })
}

/**
 * Report a page the browser never reloaded.
 *
 * Inertia swaps the page component and pushes history without a navigation, so
 * a visitor who lands on the homepage and reads four guides is one page view
 * and a 100% bounce rate unless somebody says otherwise.
 */
export function reportPageView(): void {
    window.gtag?.('event', 'page_view', {
        page_location: window.location.href,
        page_path: window.location.pathname + window.location.search,
        page_title: document.title,
    })
}

/**
 * Report a new account, once.
 *
 * The auth callbacks flash how a just-created account signed in, and the page
 * that follows the redirect carries it as `flash.signUp`. GA4 knows the event
 * as `sign_up`, so it is marked as a key event in the property's admin
 * screen rather than defined here.
 *
 * Reported once per page lifetime, whatever the page props say: the flash is
 * gone from the server after one request, but Inertia restores page props from
 * history on back/forward, and a visitor who signs up and then presses back
 * would otherwise register a second conversion.
 *
 * Consent is not checked here either. `window.gtag` exists only if the shell
 * rendered the tag or the banner loaded it, and both required a yes.
 */
let signUpReported = false

export function reportSignUp(method: string): void {
    if (signUpReported) {
        return
    }
    signUpReported = true
    window.gtag?.('event', 'sign_up', { method })
}
