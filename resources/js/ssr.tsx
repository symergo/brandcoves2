import { createInertiaApp } from '@inertiajs/react'
import createServer from '@inertiajs/react/server'
import ReactDOMServer from 'react-dom/server'
import type { ReactElement } from 'react'
import Layout from './Layouts/SiteLayout'

/**
 * Server-side rendering.
 *
 * Without this the HTML a crawler receives is an empty <div id="app"> plus a
 * JSON blob. Google will often execute the JS and eventually index it, but
 * "eventually, if the render budget allows" is a poor foundation for a site
 * whose entire growth model is search — and every other crawler (Bing, social
 * card scrapers, LLM crawlers) is far less forgiving.
 *
 * Runs as its own container in production: `php artisan inertia:start-ssr`.
 * If it dies, Laravel falls back to client rendering — the site stays up and
 * only loses the pre-rendered HTML.
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,

        // Must match app.tsx exactly: a title that differs between the
        // server-rendered HTML and the hydrated client is a visible flicker.
        title: (title) => {
            if (!title) {
                return 'GiftCoves'
            }

            return title.includes('GiftCoves') ? title : `${title} · GiftCoves`
        },

        resolve: async (name) => {
            const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true })
            const module = pages[`./Pages/${name}.tsx`] as {
                default: { layout?: (page: ReactElement) => ReactElement }
            }

            if (!module) {
                throw new Error(`Inertia page not found: ./Pages/${name}.tsx`)
            }

            module.default.layout ??= (child) => <Layout>{child}</Layout>

            return module
        },

        setup: ({ App, props }) => <App {...props} />,
    }),
    /*
     * One Node process per core instead of one in all. Rendering is
     * synchronous JavaScript, so a single process renders one page at a time
     * and every other request waits behind it: a crawler burst queued up here
     * while PHP sat waiting on the answer. The cluster forks
     * `availableParallelism()` workers (6 on the VPS, measured 2026-09-27) at
     * about 60 MB each, and staging runs its own, so about 0.8 GB together
     * against 7 GB free. Port 13714 is the default; named so it is not lost
     * when the options object is edited.
     */
    { port: 13714, cluster: true },
)
