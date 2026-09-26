import type { ToolKey } from './ToolIcon'

export interface AccountLink {
    href: string
    icon: ToolKey
    label: string
}

/**
 * Your own things, in the order both account menus show them: My Coves and
 * its views, Secret Friend, then your people.
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
                  { href: `${base}/santa`, icon: 'santa', label: t('nav.santa') },
                  { href: `${base}/friends`, icon: 'friends', label: t('nav.friends') },
              ] as AccountLink[])
            : []),
    ]
}
