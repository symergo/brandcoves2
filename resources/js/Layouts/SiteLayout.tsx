import { Link, usePage } from '@inertiajs/react'
import type { PropsWithChildren } from 'react'
import type { SharedProps } from '../types'

export default function SiteLayout({ children }: PropsWithChildren) {
    const { market, markets, auth } = usePage<SharedProps>().props
    const base = `/${market.key}`

    const nav = [
        { href: `${base}/search`, label: 'Search' },
        { href: `${base}/gift`, label: 'Gift Finder' },
        { href: `${base}/daily`, label: 'Daily Picks' },
        { href: `${base}/guides`, label: 'Guides' },
    ]

    return (
        <div className="flex min-h-screen flex-col">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-accent focus:px-3 focus:py-2 focus:text-white"
            >
                Skip to content
            </a>

            <header className="border-b border-line">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4">
                    <Link href={base} className="text-lg font-semibold tracking-tight">
                        Brandcoves
                    </Link>

                    <nav className="hidden gap-5 text-sm text-ink-soft sm:flex" aria-label="Main">
                        {nav.map((item) => (
                            <Link key={item.href} href={item.href} className="hover:text-ink">
                                {item.label}
                            </Link>
                        ))}
                    </nav>

                    <div className="ml-auto flex items-center gap-3">
                        {/*
                          A plain <select> with a full page load. Switching market
                          changes the catalogue, the currency and the URL — a
                          client-side swap would leave stale prices on screen.
                        */}
                        <label className="sr-only" htmlFor="market-switcher">
                            Choose your market
                        </label>
                        <select
                            id="market-switcher"
                            className="rounded border border-line bg-card px-2 py-1 text-sm"
                            value={market.key}
                            onChange={(e) => {
                                window.location.href = `/${e.target.value}`
                            }}
                        >
                            {markets.map((m) => (
                                <option key={m.key} value={m.key}>
                                    {m.label}
                                </option>
                            ))}
                        </select>

                        {auth.user ? (
                            <Link href={`${base}/lists`} className="text-sm hover:text-ink">
                                My lists
                            </Link>
                        ) : (
                            <Link href="/login" className="text-sm hover:text-ink">
                                Sign in
                            </Link>
                        )}
                    </div>
                </div>
            </header>

            <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 py-10">
                {children}
            </main>

            <footer className="border-t border-line">
                <div className="mx-auto max-w-6xl px-4 py-6 text-sm text-ink-soft">
                    <p>
                        Brandcoves compares offers across shops. We may earn a commission on
                        purchases made through our links — it never changes what you pay.
                    </p>
                </div>
            </footer>
        </div>
    )
}
