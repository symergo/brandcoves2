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

        title: (title) => (title ? `${title} · Brandcoves` : 'Brandcoves'),

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
)
