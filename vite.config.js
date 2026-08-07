import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            // Server-side rendering. Crawlers get real HTML rather than an
            // empty div and a JSON blob.
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    ssr: {
        // Bundle dependencies into ssr.js instead of leaving them as bare
        // imports. Vite externalises them by default, which assumes a
        // node_modules sits next to the bundle — the SSR container has none, so
        // it crash-looped on "Cannot find package '@inertiajs/react'".
        //
        // Shipping the ~200 MB of node_modules instead would work and is what
        // most setups do; a self-contained 30 KB bundle is the better trade.
        noExternal: true,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
