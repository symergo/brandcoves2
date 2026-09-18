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

/**
 * Report a click through to a shop as a Google Ads conversion.
 *
 * Armed once per document, from `app.tsx`. One delegated listener rather than a
 * handler on every outbound link: those are rendered by the product page, the
 * live-offer cards, the Amazon call to action, the list cards, the board and the
 * brand page, and a seventh will be written one day by somebody who has never
 * read this file. A listener that recognises the link cannot be forgotten.
 *
 * **What counts as a click-out**: `rel="sponsored"`, which every outbound
 * affiliate link on this site carries, or a path through the `/go/` redirector.
 *
 * ## Why this is not Google's snippet
 *
 * The snippet Ads hands you cancels the click, fires the conversion, and sends
 * the browser to the shop from `event_callback`. That is written for a link that
 * navigates the current tab. Every outbound link here is `target="_blank"`, so
 * cancelling the click and assigning `window.location` would replace the page
 * the visitor is reading instead of opening a tab — and a navigation started
 * from a callback, rather than from the click, is what popup blockers exist to
 * stop. So nothing is cancelled and nothing is redirected: the browser opens the
 * tab as it always did, and the conversion goes out beside it.
 *
 * `transport_type: 'beacon'` for the same-tab case, so a request that starts as
 * the page is being replaced still arrives.
 *
 * Consent is not checked here. Under Consent Mode the tag is always present; a
 * visitor who has not accepted is reported without storage, which is the state
 * the defaults in app.blade.php put it in.
 */
let clickOutInstalled = false

export function installClickOutConversion(sendTo: string | null): void {
    if (clickOutInstalled || sendTo === null || sendTo === '') {
        return
    }

    clickOutInstalled = true

    const report = (event: Event): void => {
        const anchor = (event.target as Element | null)?.closest?.('a') ?? null

        if (anchor === null || !isClickOut(anchor)) {
            return
        }

        window.gtag?.('event', 'conversion', {
            send_to: sendTo,
            transport_type: 'beacon',
        })
    }

    // Capture, so a component that stops the event still reports; auxclick as
    // well as click, because a middle-click into a new tab is a click-out too.
    document.addEventListener('click', report, { capture: true })
    document.addEventListener('auxclick', report, { capture: true })
}

function isClickOut(anchor: Element): boolean {
    if ((anchor.getAttribute('rel') ?? '').split(/\s+/).includes('sponsored')) {
        return true
    }

    return (anchor.getAttribute('href') ?? '').includes('/go/')
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
