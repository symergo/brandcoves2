import { createInertiaApp } from '@inertiajs/react'
import { createRoot, hydrateRoot } from 'react-dom/client'
import type { ReactElement } from 'react'
import Layout from './Layouts/SiteLayout'

const appName = import.meta.env.VITE_APP_NAME ?? 'Brandcoves'

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

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
