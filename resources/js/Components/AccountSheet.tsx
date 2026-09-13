import { Link, router, usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import SignInLink from './SignInLink'
import ToolIcon, { type ToolKey } from './ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * The phone's account menu: a sheet of your own things, behind a person
 * button in the header.
 *
 * Until 2026-09-13 these rows sat at the foot of the hamburger sheet, under
 * two sections and a rule, as an "Account" block: My Lists was four rows from
 * the bottom of a screen-tall panel, and a signed-in visitor looking for their
 * lists or their friends had to know to scroll for them. The owner asked for
 * them to be more visible, as their own menu with a person icon. This is that
 * menu: the header gets a person button beside the hamburger, and this sheet
 * holds everything that is *yours* — lists in their three views, Secret
 * Friend, friends, notifications, the admin, sign out — while the hamburger
 * keeps what the site offers everybody.
 *
 * Signed out it is still a sheet, not a bare sign-in: lists are
 * anonymous-first, and a visitor who built one before signing up needs My
 * Lists here too. Sign in is its last row.
 *
 * The same fixed sheet as the hamburger, with its own top bar and close, so
 * the two behave alike; only one is open at a time, the layout sees to that.
 */
export default function AccountSheet({
    open,
    onClose,
    isHere,
}: {
    open: boolean
    onClose: () => void
    /** "You are here" for a row, as the layout computes it. */
    isHere: (href: string) => boolean
}) {
    const { auth, market, unreadCount } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    useEffect(() => {
        if (!open) return

        const escape = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
        document.addEventListener('keydown', escape)

        return () => document.removeEventListener('keydown', escape)
    }, [open, onClose])

    if (!open) {
        return null
    }

    const label = auth.user ? auth.user.name?.trim() || auth.user.email.split('@')[0] : null

    const row = (href: string, icon: ToolKey, text: string) => (
        <li>
            <Link
                href={href}
                aria-current={isHere(href) ? 'page' : undefined}
                onClick={onClose}
                className={`flex min-h-11 items-center gap-2.5 py-2 ${isHere(href) ? 'font-medium text-accent' : ''}`}
            >
                <span className="shrink-0 text-accent">
                    <ToolIcon name={icon} className="h-5 w-5" />
                </span>
                <span>{text}</span>
            </Link>
        </li>
    )

    return (
        <div id="account-sheet" className="fixed inset-0 z-50 overflow-y-auto bg-cream px-4 pb-4 md:hidden">
            <div className="flex items-center justify-between py-4">
                <p className="flex min-w-0 items-center gap-2 text-base font-semibold text-ink">
                    {label !== null ? (
                        <>
                            <span
                                aria-hidden
                                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white"
                            >
                                {label.slice(0, 1).toUpperCase()}
                            </span>
                            <span className="truncate">{label}</span>
                        </>
                    ) : (
                        t('nav.account')
                    )}
                </p>
                <button
                    type="button"
                    onClick={onClose}
                    className="flex h-11 w-11 items-center justify-center rounded-lg border border-line text-ink"
                >
                    <ToolIcon name="close" className="h-5 w-5" />
                    <span className="sr-only">{t('nav.close')}</span>
                </button>
            </div>

            <nav aria-label={t('nav.account')}>
                {/*
                  Your lists first, in the three views My Lists offers, then
                  the people and the mail. The order is how often each is
                  wanted: a person opening this is usually after a list.
                */}
                <ul className="border-l border-line pl-3">
                    {row(`${base}/lists`, 'wishlist', t('nav.lists'))}
                    {auth.user && row(`${base}/lists?view=shared`, 'shared', t('nav.shared_lists'))}
                    {auth.user && row(`${base}/lists?view=group`, 'collab', t('nav.group_lists'))}
                    {auth.user && row(`${base}/santa`, 'santa', t('nav.santa'))}
                    {auth.user && row(`${base}/friends`, 'friends', t('nav.friends'))}
                    {auth.user &&
                        row(
                            `${base}/notifications`,
                            'alerts',
                            unreadCount > 0 ? `${t('nav.notifications')} (${unreadCount})` : t('nav.notifications'),
                        )}
                    {auth.user?.isAdmin && (
                        <li>
                            <a href="/admin" className="flex min-h-11 items-center gap-2.5 py-2">
                                <span className="shrink-0 text-accent">
                                    <ToolIcon name="admin" className="h-5 w-5" />
                                </span>
                                <span>{t('nav.admin')}</span>
                            </a>
                        </li>
                    )}
                    {auth.user ? (
                        <li>
                            <button
                                type="button"
                                // A POST: a link that ends a session can be
                                // fired by any image tag on any page.
                                onClick={() => {
                                    onClose()
                                    router.post(`${base}/logout`)
                                }}
                                className="flex min-h-11 w-full items-center gap-2.5 py-2 text-left"
                            >
                                <span className="shrink-0 text-accent">
                                    <ToolIcon name="signout" className="h-5 w-5" />
                                </span>
                                <span>{t('nav.sign_out')}</span>
                            </button>
                        </li>
                    ) : (
                        <li>
                            <SignInLink onNavigate={onClose} className="flex min-h-11 items-center gap-2.5 py-2">
                                <span className="shrink-0 text-accent">
                                    <ToolIcon name="signin" className="h-5 w-5" />
                                </span>
                                <span>{t('nav.sign_in')}</span>
                            </SignInLink>
                        </li>
                    )}
                </ul>
            </nav>
        </div>
    )
}
