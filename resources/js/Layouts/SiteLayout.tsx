import { Link, usePage } from '@inertiajs/react'
import { type PropsWithChildren, useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export default function SiteLayout({ children }: PropsWithChildren) {
    const { market, markets, auth, unreadCount } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`
    const [menuOpen, setMenuOpen] = useState(false)

    const nav = [
        { href: `${base}/search`, label: t('nav.search') },
        { href: `${base}/gift`, label: t('nav.gift') },
        { href: `${base}/daily`, label: t('nav.daily') },
        { href: `${base}/surprise`, label: t('nav.surprise') },
        { href: `${base}/scan`, label: t('nav.scan') },
        { href: `${base}/guides`, label: t('nav.guides') },
    ]

    return (
        <div className="flex min-h-screen flex-col">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-accent focus:px-3 focus:py-2 focus:text-white"
            >
                {t('nav.skip')}
            </a>

            <header className="border-b border-line">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4">
                    <Link href={base} className="text-lg font-semibold tracking-tight">
                        Brandcoves
                    </Link>

                    <nav className="hidden gap-5 text-sm text-ink-soft sm:flex" aria-label={t('nav.main')}>
                        {nav.map((item) => (
                            <Link key={item.href} href={item.href} className="hover:text-ink">
                                {item.label}
                            </Link>
                        ))}
                    </nav>

                    {/*
                      The mobile entry point.

                      Below `sm` the nav was simply `hidden`, with nothing in its
                      place — so on a phone the whole site was the page you
                      happened to land on, and there was no way to reach Search,
                      the Cove or the market switcher at all.
                    */}
                    <button
                        type="button"
                        className="ml-auto rounded border border-line px-3 py-2 text-sm sm:hidden"
                        aria-expanded={menuOpen}
                        aria-controls="mobile-menu"
                        onClick={() => setMenuOpen(!menuOpen)}
                    >
                        <span aria-hidden>{menuOpen ? '✕' : '☰'}</span>
                        <span className="sr-only">{t('nav.main')}</span>
                    </button>

                    <div className="ml-auto hidden items-center gap-3 sm:flex">
                        {/*
                          A plain <select> with a full page load. Switching market
                          changes the catalogue, the currency and the language —
                          a client-side swap would leave stale prices on screen.
                        */}
                        <label className="sr-only" htmlFor="market-switcher">
                            {t('nav.choose_market')}
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
                            <>
                                <Link
                                    href={`${base}/notifications`}
                                    className="relative text-sm hover:text-ink"
                                    aria-label={
                                        unreadCount > 0
                                            ? `${t('nav.notifications')} (${unreadCount})`
                                            : t('nav.notifications')
                                    }
                                >
                                    <span aria-hidden>🔔</span>
                                    {unreadCount > 0 && (
                                        <span className="absolute -top-2 -right-2 rounded-full bg-accent px-1.5 text-[11px] leading-4 font-semibold text-white">
                                            {unreadCount > 9 ? '9+' : unreadCount}
                                        </span>
                                    )}
                                </Link>
                                <Link href={`${base}/lists`} className="text-sm hover:text-ink">
                                    {t('nav.lists')}
                                </Link>
                            </>
                        ) : (
                            <Link href="/login" className="text-sm hover:text-ink">
                                {t('nav.sign_in')}
                            </Link>
                        )}
                    </div>
                </div>

                {/*
                  The mobile panel.

                  Everything the wide header has, stacked: the sections, the
                  account links, and the market switcher — which matters most,
                  because on a phone it was previously unreachable and it is the
                  control that changes the catalogue, the currency and the
                  language.
                */}
                {menuOpen && (
                    <div id="mobile-menu" className="border-t border-line px-4 py-4 sm:hidden">
                        <nav className="flex flex-col gap-3 text-sm" aria-label={t('nav.main')}>
                            {nav.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className="hover:text-ink"
                                    // Close on navigate: an Inertia visit keeps
                                    // the layout mounted, so a menu left open
                                    // would cover the page just arrived at.
                                    onClick={() => setMenuOpen(false)}
                                >
                                    {item.label}
                                </Link>
                            ))}

                            <span className="border-t border-line pt-3">
                                {auth.user ? (
                                    <span className="flex flex-col gap-3">
                                        <Link
                                            href={`${base}/notifications`}
                                            onClick={() => setMenuOpen(false)}
                                        >
                                            {t('nav.notifications')}
                                            {unreadCount > 0 && ` (${unreadCount})`}
                                        </Link>
                                        <Link href={`${base}/lists`} onClick={() => setMenuOpen(false)}>
                                            {t('nav.lists')}
                                        </Link>
                                    </span>
                                ) : (
                                    <Link href="/login" onClick={() => setMenuOpen(false)}>
                                        {t('nav.sign_in')}
                                    </Link>
                                )}
                            </span>
                        </nav>

                        <label className="mt-4 block text-xs text-ink-soft" htmlFor="market-switcher-mobile">
                            {t('nav.choose_market')}
                        </label>
                        <select
                            id="market-switcher-mobile"
                            className="mt-1 w-full rounded border border-line bg-card px-2 py-2 text-sm"
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
                    </div>
                )}
            </header>

            <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 py-10">
                {children}
            </main>

            <footer className="border-t border-line">
                <div className="mx-auto max-w-6xl px-4 py-6 text-sm text-ink-soft">
                    {/*
                      The brand and Cove indexes live here rather than in the nav.

                      Not because they matter less, but because their job is
                      different: the nav is for someone deciding what to do, and
                      these are for a crawler that has landed on an arbitrary page
                      and needs a route into the two largest indexable URL spaces
                      on the site. A footer link on every page is exactly that.
                    */}
                    <nav aria-label={t('footer.explore')} className="mb-4 flex flex-wrap gap-x-5 gap-y-2">
                        <Link href={`/${market.key}/brands`} className="hover:text-accent">
                            {t('brand.index_title')}
                        </Link>
                        <Link href={`/${market.key}/guides`} className="hover:text-accent">
                            {t('nav.guides')}
                        </Link>
                        <Link href={`/${market.key}/daily`} className="hover:text-accent">
                            {t('nav.daily')}
                        </Link>
                        <Link href={`/${market.key}/surprise`} className="hover:text-accent">
                            {t('nav.surprise')}
                        </Link>

                        {/* Belgian law wants the operator's details reachable
                            from every page. The footer is that. */}
                        <Link href={`/${market.key}/about`} className="hover:text-accent">
                            {t('legal.about')}
                        </Link>
                        <Link href={`/${market.key}/privacy`} className="hover:text-accent">
                            {t('legal.privacy')}
                        </Link>
                        <Link href={`/${market.key}/terms`} className="hover:text-accent">
                            {t('legal.terms')}
                        </Link>
                    </nav>

                    <p>{t('footer.affiliate')}</p>
                </div>
            </footer>
        </div>
    )
}
