import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Who you are signed in as, and how to stop being them.
 *
 * The `logout` route has existed since magic links went in and nothing on the
 * site ever linked to it — so signing out was impossible without clearing
 * cookies by hand. On a site that holds gift lists and Secret Santa pairings,
 * a shared laptop with no way out is not a missing convenience, it is a leak.
 *
 * It also answers "am I signed in?", which the header could not previously be
 * asked: the only difference between the two states was whether a bell was
 * there, and a visitor does not read the absence of an icon.
 */
export default function AccountMenu() {
    const { auth, market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const wrap = useRef<HTMLDivElement>(null)

    useEffect(() => {
        if (!open) return

        const away = (e: MouseEvent) => {
            if (!wrap.current?.contains(e.target as Node)) setOpen(false)
        }
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)

        document.addEventListener('mousedown', away)
        document.addEventListener('keydown', escape)

        return () => {
            document.removeEventListener('mousedown', away)
            document.removeEventListener('keydown', escape)
        }
    }, [open])

    if (auth.user === null) {
        // A button, not a text link. Signing in is the one thing we want a
        // visitor with lists in a cookie to do, and it read as footer furniture.
        return (
            <Link
                href={`/${market.key}/login`}
                className="rounded-lg border border-line px-3 py-1.5 text-sm font-medium hover:border-ink"
            >
                {t('nav.sign_in')}
            </Link>
        )
    }

    // The name if we asked for one, otherwise the part of the address before the
    // @ — which is what people recognise as themselves, and short enough to sit
    // in a header.
    const label = auth.user.name?.trim() || auth.user.email.split('@')[0]

    return (
        <div className="relative" ref={wrap}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                className="flex items-center gap-2 rounded-lg border border-line px-2 py-1.5 text-sm hover:border-ink"
            >
                <span
                    aria-hidden
                    className="flex h-6 w-6 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white"
                >
                    {label.slice(0, 1).toUpperCase()}
                </span>
                <span className="max-w-[9rem] truncate">{label}</span>
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-50 mt-2 w-60 rounded-card border border-line bg-card p-1 shadow-xl"
                >
                    <p className="truncate px-3 py-2 text-xs text-ink-soft" title={auth.user.email}>
                        {auth.user.email}
                    </p>

                    <Link
                        href={`/${market.key}/lists`}
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="block rounded px-3 py-2 text-sm hover:bg-line/40"
                    >
                        {t('nav.lists')}
                    </Link>
                    <Link
                        href={`/${market.key}/gift-cove`}
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="block rounded px-3 py-2 text-sm hover:bg-line/40"
                    >
                        {t('nav.cove')}
                    </Link>
                    <Link
                        href={`/${market.key}/notifications`}
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="block rounded px-3 py-2 text-sm hover:bg-line/40"
                    >
                        {t('nav.notifications')}
                    </Link>

                    {auth.user.isAdmin && (
                        <a
                            href="/admin"
                            role="menuitem"
                            className="block rounded px-3 py-2 text-sm hover:bg-line/40"
                        >
                            {t('nav.admin')}
                        </a>
                    )}

                    <button
                        type="button"
                        role="menuitem"
                        // A POST, because a link that ends a session can be
                        // fired by any image tag on any page on the internet.
                        onClick={() => router.post(`/${market.key}/logout`)}
                        className="mt-1 block w-full rounded border-t border-line px-3 py-2 text-left text-sm hover:bg-line/40"
                    >
                        {t('nav.sign_out')}
                    </button>
                </div>
            )}
        </div>
    )
}
