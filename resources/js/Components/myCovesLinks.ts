import type { ToolKey } from './ToolIcon'

export interface AccountLink {
    href: string
    icon: ToolKey
    label: string
}

/**
 * Your own things, in the order both account menus show them: My Coves, the
 * Coves you saved, then Secret Friend and your people.
 *
 * One entry for all your lists (owner, 2026-09-26: "mijn coves zijn
 * verlanglijsten, cadeaulijsten en groepslijsten in 1"). My Coves is one page
 * with every kind on it, so the menu entries per kind ("Voor anderen", "Samen
 * geven") only repeated it. Saved Coves keeps its entry: those are somebody
 * else's Coves, not lists of yours. It stays `?view=saved` rather than an
 * anchor because the menus mark "you are here" by comparing URLs, and a
 * fragment is not part of `page.url`.
 *
 * One list for `AccountMenu` (desktop) and `AccountSheet` (phone). Until
 * 2026-09-26 each had its own, and the desktop one lacked For others, Group
 * lists and Secret Friend, so on a desktop no menu reached them at all.
 * Notifications, Admin and signing in or out are drawn by each menu itself:
 * they carry a count, a condition or a button.
 */
export function myCovesLinks(base: string, t: (key: string) => string, signedIn: boolean): AccountLink[] {
    return [
        { href: `${base}/lists`, icon: 'wishlist', label: t('nav.lists') },
        ...(signedIn
            ? ([
                  { href: `${base}/lists?view=saved`, icon: 'wishlist', label: t('nav.saved_coves') },
                  { href: `${base}/santa`, icon: 'santa', label: t('nav.santa') },
                  { href: `${base}/friends`, icon: 'friends', label: t('nav.friends') },
              ] as AccountLink[])
            : []),
    ]
}
