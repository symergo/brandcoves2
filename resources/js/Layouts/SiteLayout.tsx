import { Link, usePage } from '@inertiajs/react'
import type { PropsWithChildren } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export default function SiteLayout({ children }: PropsWithChildren) {
    const { market, markets, auth, unreadCount } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const nav = [
        { href: `${base}/search`, label: t('nav.search') },
        { href: `${base}/gift`, label: t('nav.gift') },
        { href: `${base}/daily`, label: t('nav.daily') },
        { href: `${base}/surprise`, label: t('nav.surprise') },
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

                    <div className="ml-auto flex items-center gap-3">
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
            </header>

            <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 py-10">
                {children}
            </main>

            <footer className="border-t border-line">
                <div className="mx-auto max-w-6xl px-4 py-6 text-sm text-ink-soft">
                    <p>{t('footer.affiliate')}</p>
                </div>
            </footer>
        </div>
    )
}
