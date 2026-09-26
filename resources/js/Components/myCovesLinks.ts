import type { ToolKey } from './ToolIcon'

export interface AccountLink {
    href: string
    icon: ToolKey
    label: string
}

/**
 * Your own things, in the order both account menus show them: My Coves, then
 * three of its sections in the order the page shows them (For others, Give
 * together, Saved), then Secret Friend and your people.
 *
 * Since 2026-09-26 My Coves is one page with every section on it, so the
 * section entries are shortcuts rather than the only way in: `?view=` scrolls
 * the page to that section and marks it for a moment (see `Lists/Index.tsx`).
 * They stay `?view=` rather than `#anchors` because the menus mark "you are
 * here" by comparing URLs, and a fragment is not part of `page.url`.
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
                  { href: `${base}/lists?view=shared`, icon: 'shared', label: t('nav.shared_lists') },
                  { href: `${base}/lists?view=group`, icon: 'collab', label: t('nav.group_lists') },
                  { href: `${base}/lists?view=saved`, icon: 'wishlist', label: t('nav.saved_coves') },
                  { href: `${base}/santa`, icon: 'santa', label: t('nav.santa') },
                  { href: `${base}/friends`, icon: 'friends', label: t('nav.friends') },
              ] as AccountLink[])
            : []),
    ]
}
