import { Link, router, usePage } from '@inertiajs/react'
import type { ReactNode } from 'react'
import Menu from './Menu'
import SignInLink from './SignInLink'
import ToolIcon, { type ToolKey } from './ToolIcon'
import { myCovesLinks } from './myCovesLinks'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

const itemClass =
    'flex w-full items-center gap-2.5 rounded px-3 py-2 text-left text-sm outline-none hover:bg-line/40 focus-visible:bg-line/60 focus-visible:ring-2 focus-visible:ring-accent/40'

function Row({ icon, children }: { icon: ToolKey; children: ReactNode }) {
    return (
        <>
            <span className="shrink-0 text-accent">
                <ToolIcon name={icon} className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">{children}</span>
        </>
    )
}

/**
 * Who you are signed in as, your own things, and how to stop being them.
 *
 * The `logout` route has existed since magic links went in and nothing on the
 * site ever linked to it — so signing out was impossible without clearing
 * cookies by hand. On a site that holds gift lists and Secret Santa pairings,
 * a shared laptop with no way out is not a missing convenience, it is a leak.
 *
 * It also answers "am I signed in?", which the header could not previously be
 * asked: the only difference between the two states was whether a bell was
 * there, and a visitor does not read the absence of an icon.
 *
 * The rows, since 2026-09-26 (owner): My Coves, My people, Saved Coves, Secret
 * Friend (`myCovesLinks`, shared with the phone's `AccountSheet`), then
 * Notifications with its count, Help, Admin, Sign out. Help moved here from
 * the header's top row.
 *
 * Built on `Menu` since the same day, so it behaves like the site's other
 * menus from the keyboard: the arrows move between rows, Escape closes and
 * returns focus to the button, Tab out closes it.
 */
export default function AccountMenu() {
    const { auth, market, unreadCount } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    if (auth.user === null) {
        // A button, not a text link. Signing in is the one thing we want a
        // visitor with lists in a cookie to do, and it read as footer furniture.
        //
        // It opens the dialog rather than the login page: the header is on
        // every page, so whatever the visitor was reading when they decided to
        // sign in is exactly what a navigation would throw away.
        return (
            <SignInLink className="inline-block whitespace-nowrap rounded-lg border border-line px-3 py-1.5 text-sm font-medium hover:border-ink">
                {t('nav.sign_in')}
            </SignInLink>
        )
    }

    // The name if we asked for one, otherwise the part of the address before the
    // @ — which is what people recognise as themselves, and short enough to sit
    // in a header.
    const label = auth.user.name?.trim() || auth.user.email.split('@')[0]
    const email = auth.user.email
    const isAdmin = auth.user.isAdmin

    return (
        <Menu
            label={`${t('nav.account')}: ${label}`}
            width={240}
            buttonClassName="account-button flex items-center gap-2 rounded-lg border border-line px-2 py-1.5 text-sm hover:border-ink"
            button={
                <>
                    <span
                        aria-hidden
                        className="flex h-6 w-6 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white"
                    >
                        {label.slice(0, 1).toUpperCase()}
                    </span>
                    <span className="max-w-[9rem] truncate">{label}</span>
                </>
            }
        >
            {(close) => (
                <>
                    <p className="truncate px-3 py-2 text-xs text-ink-soft" title={email}>
                        {email}
                    </p>

                    {/* The same links as the phone sheet, from one list: see myCovesLinks. */}
                    {myCovesLinks(base, t, true).map((link) => (
                        <Link key={link.href} href={link.href} role="menuitem" tabIndex={-1} onClick={close} className={itemClass}>
                            <Row icon={link.icon}>{link.label}</Row>
                        </Link>
                    ))}
                    <Link href={`${base}/notifications`} role="menuitem" tabIndex={-1} onClick={close} className={itemClass}>
                        <Row icon="alerts">
                            {unreadCount > 0 ? `${t('nav.notifications')} (${unreadCount})` : t('nav.notifications')}
                        </Row>
                    </Link>
                    <Link href={`${base}/help`} role="menuitem" tabIndex={-1} onClick={close} className={itemClass}>
                        <Row icon="help">{t('nav.help')}</Row>
                    </Link>

                    {/* A plain link: /admin is Filament, not an Inertia page. */}
                    {isAdmin && (
                        <a href="/admin" role="menuitem" tabIndex={-1} className={itemClass}>
                            <Row icon="admin">{t('nav.admin')}</Row>
                        </a>
                    )}

                    <div role="separator" className="my-1 border-t border-line" />
                    <button
                        type="button"
                        role="menuitem"
                        tabIndex={-1}
                        // A POST, because a link that ends a session can be
                        // fired by any image tag on any page on the internet.
                        onClick={() => {
                            close()
                            router.post(`${base}/logout`)
                        }}
                        className={itemClass}
                    >
                        <Row icon="signout">{t('nav.sign_out')}</Row>
                    </button>
                </>
            )}
        </Menu>
    )
}
