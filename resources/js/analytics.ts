/**
 * The Google tag, loaded from the client.
 *
 * The blade shell already renders the tag for a visitor who arrived with
 * consent stored, and that is the path almost every page load takes. This
 * exists for the one moment it cannot cover: the click on "Accept" itself.
 * Without it, consent takes effect on the *next* page — so the page somebody
 * was reading when they agreed, which is very often the page they landed on
 * and the most interesting one we have, is never reported at all.
 *
 * Idempotent, because the banner is not the only thing that can call it and a
 * second gtag.js would double every hit.
 */

declare global {
    interface Window {
        dataLayer?: unknown[]
        gtag?: (...args: unknown[]) => void
    }
}

const TAG_ID = 'ga-gtag'

export function loadGoogleTag(measurementId: string): void {
    if (document.getElementById(TAG_ID) !== null) {
        return
    }

    const script = document.createElement('script')
    script.id = TAG_ID
    script.async = true
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`
    document.head.appendChild(script)

    /*
      The queue is filled before the script arrives, exactly as the inline
      snippet in app.blade.php does it. gtag.js drains whatever is already in
      dataLayer when it loads, so the config and the first page view are not
      lost to the round trip.
    */
    window.dataLayer = window.dataLayer || []
    window.gtag = function gtag() {
        window.dataLayer?.push(arguments)
    }
    window.gtag('js', new Date())
    // Thirteen months, matching app.blade.php and the privacy page. See the
    // comment there for why it is not GA4's two-year default.
    window.gtag('config', measurementId, {
        cookie_expires: 33696000,
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
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
