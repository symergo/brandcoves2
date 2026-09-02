import { createInertiaApp, router } from '@inertiajs/react'
import { createRoot, hydrateRoot } from 'react-dom/client'
import type { ReactElement } from 'react'
import Layout from './Layouts/SiteLayout'
import { reportPageView } from './analytics'

const appName = import.meta.env.VITE_APP_NAME ?? 'GiftCoves'

createInertiaApp({
    /*
      The brand is appended, unless the page has already put it in its own
      title. The Dutch homepage leads with "GiftCoves verlanglijstjes" on
      purpose, and appending the name to that prints it twice in one tab.
    */
    title: (title) => {
        if (!title) {
            return appName
        }

        return title.includes(appName) ? title : `${title} · ${appName}`
    },

    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx')
        const page = pages[`./Pages/${name}.tsx`]

        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}.tsx`)
        }

        const module = (await page()) as {
            default: { layout?: (page: ReactElement) => ReactElement }
        }

        // Every page gets the site chrome unless it opts out. Pages that set
        // their own `layout` (the gift wizard, shared list views) keep it.
        module.default.layout ??= (page) => <Layout>{page}</Layout>

        return module
    },

    setup({ el, App, props }) {
        // Server-rendered markup exists for SEO-critical pages, so hydrate
        // rather than replace it where it is present.
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />)
        } else {
            createRoot(el).render(<App {...props} />)
        }
    },

    progress: {
        color: '#c9503a',
    },
})

/*
  Page views for the pages the browser never reloaded.

  The gtag snippet in app.blade.php reports the document it shipped with, and
  that is the only report it will ever make: Inertia swaps the page component
  and pushes history without a navigation, so a visitor who lands on the
  homepage and reads four guides is one page view and a 100% bounce rate.

  The first fire is skipped rather than sent — `gtag('config', ...)` has already
  counted the landing page, and counting it twice inflates exactly the page that
  matters most. `navigate` fires on the initial page as well, so the URL it
  started on is what gets compared against.

  Nothing here checks consent. `window.gtag` exists only if the shell rendered
  the tag or the banner loaded it, and both of those already required a yes.
*/
let lastReportedUrl = window.location.pathname + window.location.search

/**
 * Keep the canonical link pointing at the page you are actually on.
 *
 * The Blade shell renders <link rel="canonical"> once, and Inertia's <Head>
 * manages only the title — so without this the tag keeps advertising whichever
 * page was loaded from the server, for the whole session.
 *
 * This is not a tidiness fix. **iOS share sheets read the canonical link in
 * preference to the address bar**, so tapping through to a product and sharing
 * it sent the entry page's URL: WhatsApp received a previous link and drew that
 * page's card. It reads exactly like a caching problem, which is where two
 * hours went before the head was suspected. Reported 2026-09-02.
 *
 * og:url is written alongside for coherence rather than need — scrapers fetch
 * the HTML fresh and never see this DOM — because a head where one URL is
 * current and its neighbour is stale is a trap for whoever reads it next.
 */
function updateCanonical(canonical: unknown): void {
    if (typeof canonical !== 'string' || canonical === '') {
        return
    }

    document.querySelector<HTMLLinkElement>('link[rel="canonical"]')?.setAttribute('href', canonical)
    document.querySelector<HTMLMetaElement>('meta[property="og:url"]')?.setAttribute('content', canonical)
}

router.on('navigate', (event) => {
    updateCanonical(event.detail.page.props.canonical)

    const url = window.location.pathname + window.location.search

    if (url === lastReportedUrl) {
        return
    }

    lastReportedUrl = url

    /*
      One frame late, on purpose. Inertia's <Head> writes document.title while
      the new page renders, which is after this event — reading it now would
      attribute every page view to the title of the page being left.
    */
    requestAnimationFrame(reportPageView)
})
