import { Link, router, usePage } from '@inertiajs/react'
import AccountMenu from '../Components/AccountMenu'
import AccountSheet from '../Components/AccountSheet'
import AddingToBar from '../Components/AddingToBar'
import { buttonClasses } from '../Components/Button'
import CookieBanner from '../Components/CookieBanner'
import ContributeBar from '../Components/ContributeBar'
import CoveIcon from '../Components/CoveIcon'
import FlashMessage from '../Components/FlashMessage'
import SaveToast from '../Components/SaveToast'
import MarketBar from '../Components/MarketBar'
import MarketButton, { MarketList } from '../Components/MarketSwitcher'
import NavMenu, { type NavMenuGroup } from '../Components/NavMenu'
import ToolIcon from '../Components/ToolIcon'
import { type PropsWithChildren, type ReactNode, useEffect, useState } from 'react'
import { isCleanTerm, searchHref } from '../searchUrl'
import { SignInProvider } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * The desktop header's search field (owner's UX audit, 2026-09-26).
 *
 * From `xl` up the header had no way to search at all: the phone has its
 * magnifier button, and a desktop visitor had to open Discover and pick
 * Search offers, two moves for the site's first action. This is the same
 * search the home page's card and the search page run: a real GET form to
 * `/{market}/search` (so it works without JavaScript), and with JavaScript a
 * clean term goes to its readable address (`/be-nl/zoek/lego`) exactly as
 * the card sends it, so one search never has two URLs.
 *
 * Elastic, because the header's width is fixed (the page column, 1152px, at
 * 1280 and 1440 alike) and what else it holds depends on the language: the
 * field takes the room the menus leave, capped at 24rem. Where that room is
 * under 7rem, a field would be a slot too narrow to read what you typed, so
 * it becomes the phone's magnifier instead, a link to the search page: still
 * one click to search. A container query decides, so the answer is measured
 * rather than guessed per language.
 *
 * It sits beside the logo since the header was regrouped (2026-09-26, Find a
 * gift | Discover | My Coves and one country-and-language button), and that
 * regrouping is what gave French a field: the three flags and the language
 * dropdown had taken about 150px, and Belgian French used to get only the
 * magnifier. Measurements in docs/features/navigation.md.
 */
function HeaderSearch({ marketKey }: { marketKey: string }) {
    const { t } = useTranslations()
    const label = t('nav.search')

    return (
        <div className="@container hidden max-w-sm min-w-9 flex-1 xl:block">
            <form
                action={`/${marketKey}/search`}
                method="get"
                role="search"
                onSubmit={(e) => {
                    const q = new FormData(e.currentTarget).get('q')
                    if (typeof q !== 'string' || !isCleanTerm(q)) return
                    e.preventDefault()
                    router.get(searchHref(marketKey, q))
                }}
                className="relative hidden @min-[7rem]:block"
            >
                {/* The short word ("Search", "Rechercher") rather than the
                    card's sentence: at its narrowest the field is 7rem, and a
                    placeholder cut in half reads as a fault. */}
                <input
                    type="search"
                    name="q"
                    aria-label={label}
                    placeholder={label}
                    className="h-9 w-full rounded-full border border-line bg-cream pr-9 pl-4 text-sm text-ink placeholder:text-ink-soft focus:border-ink"
                />
                <button
                    type="submit"
                    className="absolute inset-y-0 right-0 flex w-9 items-center justify-center rounded-full text-ink-soft hover:text-ink"
                >
                    <ToolIcon name="search" className="h-4 w-4" />
                    <span className="sr-only">{label}</span>
                </button>
            </form>
            <Link
                href={`/${marketKey}/search`}
                aria-label={label}
                title={label}
                className="flex h-9 w-9 items-center justify-center rounded-full border border-line text-ink hover:border-ink @min-[7rem]:hidden"
            >
                <ToolIcon name="search" className="h-4 w-4" />
            </Link>
        </div>
    )
}

/**
 * A beam along the top edge while a page is on its way.
 *
 * The search box already has one (the scanner sweep under the field), and it
 * was the only loading signal on the site: every other Inertia visit showed
 * nothing between the click and the new page, so a slow one read as a link
 * that did nothing and got clicked again. Same idiom, site-wide — the beam and
 * its reduced-motion fallback are the ones `app.css` defines for the search
 * field.
 *
 * Shown only after 150 ms. Most visits land inside that, and a beam that
 * flashes for a frame on every click is noise rather than a signal.
 */
function NavigationBeam() {
    const [visible, setVisible] = useState(false)

    useEffect(() => {
        let timer: number | undefined

        const start = () => {
            window.clearTimeout(timer)
            timer = window.setTimeout(() => setVisible(true), 150)
        }
        const stop = () => {
            window.clearTimeout(timer)
            setVisible(false)
        }

        const offStart = router.on('start', start)
        const offFinish = router.on('finish', stop)

        return () => {
            window.clearTimeout(timer)
            offStart()
            offFinish()
        }
    }, [])

    if (!visible) return null

    return (
        <div className="pointer-events-none fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden" aria-hidden="true">
            <div className="h-full w-1/4 bg-accent animate-scan" />
        </div>
    )
}

/**
 * The site chrome, and the one sign-in dialog underneath it.
 *
 * The provider wraps the chrome rather than only the page, because the header's
 * own "Sign in" and the mobile menu's are two of its callers. See
 * resources/js/signIn.tsx for why there is one dialog rather than one per
 * caller.
 */
export default function SiteLayout({ children }: PropsWithChildren) {
    return (
        <SignInProvider>
            <Chrome>{children}</Chrome>
        </SignInProvider>
    )
}

function Chrome({ children }: PropsWithChildren) {
    const page = usePage<SharedProps>()
    const { market, auth, unreadCount, analytics, amazonAssociate } = page.props
    const { t } = useTranslations()
    const base = `/${market.key}`
    const [menuOpen, setMenuOpen] = useState(false)
    /*
     * The phone's account sheet, behind a person button beside the hamburger
     * (owner's request, 2026-09-13): your lists, friends and the rest were
     * an "Account" block at the foot of the hamburger sheet, four rows from
     * the bottom of a screen-tall panel. See Components/AccountSheet.
     */
    const [accountOpen, setAccountOpen] = useState(false)

    /*
     * While the phone panel is open: Escape closes it, the page under it does
     * not scroll, and any navigation closes it — including the back button,
     * which fires no click. It used to close on link clicks only, so a back
     * press left the panel open over the page just arrived at.
     */
    useEffect(() => {
        if (!menuOpen) return

        const escape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setMenuOpen(false)
        }
        document.addEventListener('keydown', escape)
        const offNavigate = router.on('navigate', () => setMenuOpen(false))

        const previous = document.body.style.overflow
        document.body.style.overflow = 'hidden'

        return () => {
            document.removeEventListener('keydown', escape)
            offNavigate()
            document.body.style.overflow = previous
        }
    }, [menuOpen])

    /*
     * Which section you are in.
     *
     * Five nav entries all rendered identically, so the header said nothing
     * about where you had arrived — and on a site whose sections overlap
     * (Search, the Cove, Daily Picks all end in products) that is the
     * difference between exploring and being lost. Prefix match, so a product
     * opened from Search still reads as Search.
     */
    const path = (page.url ?? '').split('?')[0]
    const isCurrent = (href: string) => path === href || path.startsWith(`${href}/`)

    /*
     * Find a gift | Discover ▾ | My Coves, then one country-and-language
     * button and the account (owner, 2026-09-26; docs/features/navigation.md).
     *
     * **Find a gift** is a plain link: it is one flow, and its page is where
     * every way in starts. **Discover** is the one menu, in two labelled
     * groups, "Every day" (the Daily Cove, Surprise) and "Coves" (the kinds
     * we and other people make), then All Coves under a rule. Its label goes
     * to the Discover page, which explains all of it; see NavMenu for why the
     * chevron is a separate control. **My Coves** is a plain link too: lists
     * work before signing up, so a visitor with no account must reach theirs
     * without opening a menu that is about having one.
     *
     * Gone from the top row: Search (the header's own field is search), Ask
     * others (the last step of Find a gift, and on the Discover page), and How
     * it works, which is in the footer, on the home page, and in the account
     * menu as Help. Scan stays absent: the scan button in the search field
     * opens it.
     */
    const gift = {
        href: `${base}/gift`,
        label: t('nav.gift'),
        icon: <ToolIcon name="whisperer" className="h-5 w-5" />,
    }

    const discover = {
        href: `${base}/discover-cove`,
        label: t('nav.discover'),
        icon: <CoveIcon name="compass" className="h-5 w-5" />,
        groups: [
            {
                label: t('nav.every_day'),
                items: [
                    {
                        href: `${base}/${market.coveSegment}`,
                        label: t('nav.daily'),
                        hint: t('nav.hint_daily'),
                        icon: <CoveIcon name="daily" className="h-5 w-5" />,
                    },
                    {
                        href: `${base}/surprise`,
                        label: t('nav.surprise'),
                        hint: t('nav.hint_surprise'),
                        icon: <CoveIcon name="surprise" className="h-5 w-5" />,
                    },
                ],
            },
            {
                label: t('nav.coves'),
                items: [
                    // Named as the page is named ("Gift ideas, by person").
                    {
                        href: `${base}/gift-ideas`,
                        label: t('nav.gift_ideas'),
                        hint: t('nav.hint_gift_coves'),
                        icon: <CoveIcon name="persona" className="h-5 w-5" />,
                    },
                    {
                        href: `${base}/guides`,
                        label: t('nav.smart'),
                        hint: t('nav.hint_smart'),
                        icon: <CoveIcon name="idea" className="h-5 w-5" />,
                    },
                    // The same mark All Coves gives this band: what you read
                    // there comes from people, like Ask others.
                    {
                        href: `${base}/coves/community`,
                        label: t('community.index_heading'),
                        hint: t('nav.hint_community'),
                        icon: <CoveIcon name="ask" className="h-5 w-5" />,
                    },
                    /*
                     * The brands index. There is no page listing brands and
                     * shops together; /brands links on to the shops (/shops),
                     * and All Coves shows both bands.
                     */
                    {
                        href: `${base}/brands`,
                        label: t('nav.brands_shops'),
                        hint: t('nav.hint_brands_shops'),
                        icon: <CoveIcon name="brand" className="h-5 w-5" />,
                    },
                ],
            },
        ] as NavMenuGroup[],
        footer: { href: `${base}/coves`, label: t('nav.all_coves') },
    }

    const myCoves = {
        href: `${base}/lists`,
        label: t('nav.lists'),
        icon: <ToolIcon name="wishlist" className="h-5 w-5" />,
    }

    const help = {
        href: `${base}/help`,
        label: t('nav.help'),
        icon: <ToolIcon name="help" className="h-5 w-5" />,
    }

    const discoverHrefs = [
        discover.href,
        ...discover.groups.flatMap((group) => group.items.map((item) => item.href)),
        discover.footer.href,
    ]

    /*
     * "You are here", in a menu where two entries share a path.
     *
     * `isCurrent` compares paths, deliberately, so a product opened from a
     * Cove still reads as that Cove. My Coves and Saved Coves differ only by
     * `?view=`, which breaks that both ways: a path match lights both at
     * once, and comparing the full URL lights none of the entries that carry
     * no query.
     *
     * So: if any entry matches the URL exactly, that one is the answer and
     * nothing else is. Otherwise fall back to the prefix match.
     */
    const exact = (href: string) => (page.url ?? '') === href
    const anyExact = [gift.href, ...discoverHrefs, myCoves.href, help.href, `${base}/lists?view=saved`].some(exact)

    // A plain entry in the wide header: Find a gift, My Coves.
    const flatLink = (item: { href: string; label: string; icon: ReactNode }) => (
        <Link
            href={item.href}
            aria-current={isCurrent(item.href) ? 'page' : undefined}
            className={`inline-flex items-center gap-1.5 whitespace-nowrap ${
                isCurrent(item.href)
                    ? 'font-medium text-ink underline decoration-accent decoration-2 underline-offset-8'
                    : 'hover:text-ink'
            }`}
        >
            <span className="shrink-0 text-accent">{item.icon}</span>
            {item.label}
        </Link>
    )

    // A heading-weight row in the phone menu: Find a gift, My Coves, Help.
    const phoneRow = (item: { href: string; label: string; icon: ReactNode }) => (
        <div className="mb-4 border-b border-line pb-4">
            <Link
                href={item.href}
                aria-current={isHere(item.href) ? 'page' : undefined}
                onClick={() => setMenuOpen(false)}
                className={`flex min-h-11 items-center justify-between py-1 text-base font-semibold ${
                    isHere(item.href) ? 'text-accent' : 'text-ink'
                }`}
            >
                <span className="flex items-center gap-2.5">
                    <span className="shrink-0 text-accent">{item.icon}</span>
                    {item.label}
                </span>
                <span aria-hidden className="text-xs text-ink-soft">
                    →
                </span>
            </Link>
        </div>
    )

    const isHere = (href: string) => (anyExact ? exact(href) : isCurrent(href))

    return (
        <div className="flex min-h-screen flex-col">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-accent focus:px-3 focus:py-2 focus:text-white"
            >
                {t('nav.skip')}
            </a>

            <NavigationBeam />

            {/* Above the header and in the flow, so it covers nothing: which
                country's shops this is, the others one tap away. Replaced the
                first-visit dialog on 2026-09-26; see Components/MarketBar. */}
            <MarketBar />

            {/*
              Feedback, suggestions and votes are welcome (owner, 2026-09-27):
              one short line, closable for a year; see Components/ContributeBar.
              Above the header, in the market bar's slot, not under it (owner:
              "find a better place", "more visible", "above the page"). Under
              the header it read as part of the page; up here it reads as the
              site asking, and the two bars never show together, so the top of
              the page holds one question at a time.
            */}
            <ContributeBar />

            <header className="border-b border-line">
                {/* Tighter from xl (2026-09-26): gap-4 here rather than
                    gap-6. The row is the page column (1152px) at 1280 and
                    1440 alike, and every language has to fit in it with a
                    search field (docs/features/navigation.md). */}
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4 xl:gap-4">
                    {/*
                      The name at the mark's height. It was 18px beside a 28px
                      mark, which made the mark the logo and the word its
                      caption; the owner wanted the name as big as the icon
                      (2026-09-08). 28px, with the line height pulled in so the
                      header does not grow.
                    */}
                    <Link href={base} className="flex shrink-0 items-center gap-2 text-[1.75rem] leading-none font-semibold tracking-tight">
                        {/*
                          Decorative, so it is hidden from screen readers: the
                          word next to it already names the link, and a reader
                          announcing "GiftCoves GiftCoves" is worse than no
                          image at all.

                          Fixed width and height rather than CSS alone, so the
                          header does not reflow while the mark loads.
                        */}
                        <img
                            src="/icons/giftcoves.svg"
                            alt=""
                            aria-hidden="true"
                            width={28}
                            height={28}
                            className="h-7 w-7 rounded-md"
                        />
                        GiftCoves
                    </Link>

                    {/* Beside the logo since 2026-09-26, and not on the search
                        page, whose own field is the first thing on it: two
                        search boxes a few pixels apart ask which one is the
                        real one. */}
                    {page.component !== 'Search' && <HeaderSearch marketKey={market.key} />}

                    <nav
                        className="ml-auto hidden items-center gap-4 text-sm text-ink-soft xl:flex"
                        aria-label={t('nav.main')}
                    >
                        {flatLink(gift)}
                        <NavMenu
                            href={discover.href}
                            label={discover.label}
                            icon={discover.icon}
                            groups={discover.groups}
                            footer={discover.footer}
                            current={discoverHrefs.some(isCurrent)}
                            isCurrent={isCurrent}
                            submenuLabel={t('nav.submenu', { section: discover.label })}
                        />
                        {/*
                          Lists are anonymous-first — that is the whole design:
                          save a product, build a list, share it, all before
                          signing up. Hiding this link behind `auth.user` meant a
                          visitor who had done exactly that had no way back to
                          their own list, and the feature looked absent.
                        */}
                        {flatLink(myCoves)}
                    </nav>

                    {/*
                      The mobile entry point.

                      Below `sm` the nav was simply `hidden`, with nothing in its
                      place — so on a phone the whole site was the page you
                      happened to land on, and there was no way to reach Search,
                      the Cove or the market switcher at all.
                    */}
                    {/*
                      Search, one tap from any page. It was a text link inside
                      the menu — two taps from everywhere but the home — for the
                      site's primary action. Both controls are 44px: the floor
                      the stylesheet applies to fields below `sm` applied to
                      nothing here.
                    */}
                    <div className="ml-auto flex items-center gap-1 xl:hidden">
                        <Link
                            href={`${base}/search`}
                            aria-label={t('nav.search')}
                            aria-current={isCurrent(`${base}/search`) ? 'page' : undefined}
                            className="flex h-11 w-11 items-center justify-center rounded-lg text-ink hover:bg-line/40"
                        >
                            <ToolIcon name="search" className="h-5 w-5" />
                        </Link>
                        {/*
                          Your own things, behind a person: the initial in
                          the accent when signed in, the sign-in mark when
                          not. Its sheet is `AccountSheet`; the hamburger
                          beside it keeps what the site offers everybody.
                        */}
                        <button
                            type="button"
                            className="flex h-11 w-11 items-center justify-center rounded-lg text-ink hover:bg-line/40"
                            aria-expanded={accountOpen}
                            aria-controls="account-sheet"
                            onClick={() => {
                                setMenuOpen(false)
                                setAccountOpen(!accountOpen)
                            }}
                        >
                            {auth.user ? (
                                <span
                                    aria-hidden
                                    className="flex h-7 w-7 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white"
                                >
                                    {(auth.user.name?.trim() || auth.user.email).slice(0, 1).toUpperCase()}
                                </span>
                            ) : (
                                <ToolIcon name="signin" className="h-5 w-5" />
                            )}
                            <span className="sr-only">{t('nav.account')}</span>
                        </button>
                        <button
                            type="button"
                            className="flex h-11 w-11 items-center justify-center rounded-lg border border-line text-ink"
                            aria-expanded={menuOpen}
                            aria-controls="mobile-menu"
                            onClick={() => {
                                setAccountOpen(false)
                                setMenuOpen(!menuOpen)
                            }}
                        >
                            <ToolIcon name={menuOpen ? 'close' : 'menu'} className="h-5 w-5" />
                            <span className="sr-only">{t('nav.main')}</span>
                        </button>
                    </div>

                    <div className="hidden shrink-0 items-center gap-2 xl:flex">
                        {/* One button for country and language since
                            2026-09-26, where three flags and a language
                            dropdown were; see Components/MarketSwitcher. */}
                        <MarketButton />

                        {auth.user && unreadCount > 0 && (
                            <Link
                                href={`${base}/notifications`}
                                className="relative inline-flex text-sm hover:text-ink"
                                aria-label={`${t('nav.notifications')} (${unreadCount})`}
                            >
                                <ToolIcon name="bell" className="h-5 w-5" />
                                <span className="absolute -top-2 -right-2 rounded-full bg-accent px-1.5 text-2xs leading-4 font-semibold text-white">
                                    {unreadCount > 9 ? '9+' : unreadCount}
                                </span>
                            </Link>
                        )}

                        <AccountMenu />
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
                {/*
                  A sheet, not a block in the flow.

                  It used to open under the header and push the page down, at
                  about two screens tall, with the only close control at the
                  top — scrolled out of reach by the time the switcher at the
                  bottom was read. Fixed under the header now, scrolling on its
                  own while the page behind holds still, with a close at the
                  bottom as well as the top.
                */}
                <AccountSheet open={accountOpen} onClose={() => setAccountOpen(false)} isHere={isHere} />

                {menuOpen && (
                    <div
                        id="mobile-menu"
                        className="fixed inset-0 z-50 overflow-y-auto bg-cream px-4 pb-4 xl:hidden"
                    >
                        {/* Its own top bar, so the sheet does not have to know
                            how tall the header under it is. */}
                        <div className="mb-2 flex h-[4.75rem] items-center justify-between border-b border-line">
                            {/* The mark and the name, as the header writes
                                them, so the sheet's top bar reads as the same
                                header and not a page of its own. The name
                                stood here alone until 2026-09-08, and once it
                                was 28px the missing mark was the first thing
                                the owner saw. */}
                            <span className="flex items-center gap-2 text-[1.75rem] leading-none font-semibold tracking-tight">
                                <img
                                    src="/icons/giftcoves.svg"
                                    alt=""
                                    aria-hidden="true"
                                    width={28}
                                    height={28}
                                    className="h-7 w-7 rounded-md"
                                />
                                GiftCoves
                            </span>
                            <button
                                type="button"
                                onClick={() => setMenuOpen(false)}
                                aria-label={t('nav.close')}
                                className="flex h-11 w-11 items-center justify-center rounded-lg border border-line text-ink"
                            >
                                <ToolIcon name="close" className="h-5 w-5" />
                            </button>
                        </div>

                        {/*
                          The same entries as the wide header, in its order
                          (2026-09-26): Find a gift, Discover with both its
                          groups, My Coves; then country and language, then
                          Help. Nothing collapses: a dropdown inside an open
                          panel is a second thing to open.
                        */}
                        <nav className="text-sm" aria-label={t('nav.main')}>
                            {phoneRow(gift)}

                            <div className="mb-4 border-b border-line pb-4">
                                {/*
                                  The hub is the heading and the heading is a
                                  link. Both halves matter: the page explains a
                                  section that is not self-evident, and a
                                  heading that cannot be pressed puts it out of
                                  reach on the one device where there is no
                                  hover to reveal anything.
                                */}
                                <Link
                                    href={discover.href}
                                    aria-current={isHere(discover.href) ? 'page' : undefined}
                                    onClick={() => setMenuOpen(false)}
                                    className={`flex min-h-11 items-center justify-between py-1 text-base font-semibold ${
                                        isHere(discover.href) ? 'text-accent' : 'text-ink'
                                    }`}
                                >
                                    <span className="flex items-center gap-2.5">
                                        <span className="shrink-0 text-accent">{discover.icon}</span>
                                        {discover.label}
                                    </span>
                                    <span aria-hidden className="text-xs text-ink-soft">
                                        →
                                    </span>
                                </Link>

                                {/* Each group under its small heading, its
                                    entries indented under a rule: the cheapest
                                    way to say "these belong to that" without a
                                    control to expand. */}
                                {discover.groups.map((group) => (
                                    <div key={group.label} className="mt-2">
                                        <p className="pl-3 text-2xs font-semibold tracking-wide text-ink-soft uppercase">
                                            {group.label}
                                        </p>
                                        <ul aria-label={group.label} className="mt-1 border-l border-line pl-3">
                                            {group.items.map((item) => (
                                                <li key={item.href}>
                                                    <Link
                                                        href={item.href}
                                                        aria-current={isHere(item.href) ? 'page' : undefined}
                                                        // Close on navigate: an Inertia visit keeps the
                                                        // layout mounted, so a menu left open would cover
                                                        // the page just arrived at.
                                                        onClick={() => setMenuOpen(false)}
                                                        className={`flex min-h-11 items-center gap-2.5 py-2 ${
                                                            isHere(item.href) ? 'font-medium text-accent' : ''
                                                        }`}
                                                    >
                                                        <span className="shrink-0 text-accent">{item.icon}</span>
                                                        <span>
                                                            <span className="block">{item.label}</span>
                                                            {/* Kept on a phone for the reason they
                                                                exist at all: entries differing by one
                                                                word cannot be told apart the first
                                                                time. */}
                                                            {item.hint ? (
                                                                <span className="block text-xs text-ink-soft">{item.hint}</span>
                                                            ) : null}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}

                                <Link
                                    href={discover.footer.href}
                                    aria-current={isHere(discover.footer.href) ? 'page' : undefined}
                                    onClick={() => setMenuOpen(false)}
                                    className="mt-2 flex min-h-11 items-center pl-3 font-medium text-accent"
                                >
                                    {discover.footer.label} →
                                </Link>
                            </div>

                            {phoneRow(myCoves)}
                        </nav>

                        {/*
                          Where you shop and what you read, laid out: the menu
                          is already open, so a button in it would be a second
                          thing to open. It is also the only way to change the
                          market on a phone.
                        */}
                        <div className="mb-4 border-b border-line pb-4">
                            <MarketList />
                        </div>

                        {phoneRow(help)}

                        {/* A way out at the end as well as the start: the ✕ in
                            the header is a screen away by the time the switcher
                            has been read. */}
                        <button
                            type="button"
                            onClick={() => setMenuOpen(false)}
                            className={buttonClasses('secondary', 'lg', 'mt-6 w-full')}
                        >
                            {t('nav.close')}
                        </button>
                    </div>
                )}
            </header>

            {/*
              The adding-mode bar sits directly under the header, outside
              `<main>`, because it is chrome rather than page content — it
              describes what the whole site is doing right now, not what this
              page is about. Nothing renders when the mode is off.
            */}
            <AddingToBar />

            {/*
              Vertical rhythm is lighter on a phone.

              `py-10` is 40px top and bottom, chosen against a desktop
              viewport where it is breathing room. On a 360px screen it
              is most of the space above the fold spent on nothing, and
              it reads as a page that starts late rather than as one
              that is well spaced.
            */}
            <main id="main" className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:py-10">
                {/*
                  Above the page, not inside it: a controller reports an outcome
                  by redirecting back, and the page it lands on should not have
                  to know that happened.

                  Saves no longer come through here — a confirmation that
                  renders at the top of the document is unreadable from the
                  bottom of a results grid, which is where saving happens. They
                  go to `SaveToast` instead; see resources/js/saveToast.ts.
                */}
                <FlashMessage />
                {children}
            </main>

            {/* Fixed to the viewport, so it is mounted once and outside the flow. */}
            <SaveToast />

            <footer className="border-t border-line">
                {/*
                  Two short rows and a small print line, in the smaller size.

                  It was one wrapped row of eleven links at body size with 44px
                  rows on a phone, which came to 305px, a third of a screen of
                  grey words under every page (2026-09-08). The links are the
                  same eleven, in two groups now: where to go, and what the law
                  wants reachable from every page. On a desktop the two groups
                  share one row, the legal one at the right edge; on a phone
                  they stack, 40px a row so a thumb still has a target. The
                  small print sits under a hairline with the name on the left,
                  which is the one place on the site the name is written
                  without being the header.

                  The brand and Cove indexes live here rather than in the nav,
                  not because they matter less but because their job is
                  different: the nav is for someone deciding what to do, and
                  these are for a crawler that has landed on an arbitrary page
                  and needs a route into the two largest indexable URL spaces
                  on the site. A footer link on every page is exactly that.
                */}
                <div className="mx-auto max-w-6xl px-4 py-5 text-xs text-ink-soft sm:py-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                        <nav aria-label={t('footer.explore')} className="flex flex-wrap gap-x-4 gap-y-0 sm:gap-y-1">
                            {/* Under its own name, not `nav.brand_coves`: with
                                Brand Coves withheld from the header there is no
                                header entry for this to agree with, and a footer
                                link is not the place to introduce a name the rest
                                of the site is not yet using. */}
                            <Link href={`/${market.key}/brands`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('brand.index_title')}
                            </Link>
                            {/* Only what the header lacks (2026-09-26). Shop
                                Smarter, Daily Cove and Surprise Cove are one
                                tap away in the header on every page; a footer
                                copy of them was a second list to keep in step. */}
                            {/* What this market searches for: the hub that
                                replaced the related-search chips, and the only
                                place a crawler reaches them from any page. */}
                            <Link href={`/${market.key}/popular-searches`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('popular_searches.title')}
                            </Link>
                            {/* The search tips under their short name, and How it
                                works, which gathers the how-to pages and the
                                report form. Both: somebody looking for "how do I
                                search" scans for that phrase. How it works under
                                the header's name for it, so /help has one name. */}
                            <Link href={`/${market.key}/search-help`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('search_help.footer_link')}
                            </Link>
                            <Link href={`/${market.key}/help`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('nav.how_it_works')}
                            </Link>
                            {/* Feedback, the ideas board and suggestions
                                (2026-09-27). Here as well as in the bar under
                                the header, because the bar can be closed. */}
                            <Link href={`/${market.key}/contribute`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('contribute.title')}
                            </Link>
                        </nav>

                        {/* Belgian law wants the operator's details reachable
                            from every page. The footer is that. */}
                        <nav aria-label={t('legal.about')} className="flex flex-wrap gap-x-4 gap-y-0 sm:shrink-0 sm:justify-end sm:gap-y-1">
                            <Link href={`/${market.key}/about`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('legal.about')}
                            </Link>
                            <Link href={`/${market.key}/privacy`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('legal.privacy')}
                            </Link>
                            <Link href={`/${market.key}/terms`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('legal.terms')}
                            </Link>
                            {/* Withdrawing consent has to be as easy as giving it
                                was, and the honest way to offer that is to put the
                                question back rather than to bury a toggle in a
                                settings page. A button, not a Link: it reopens the
                                banner where the visitor already is. Hidden where
                                there is no tag, so it does not advertise a choice
                                this environment never asked anyone to make. */}
                            {analytics.id !== null && (
                                <button
                                    type="button"
                                    onClick={() => window.dispatchEvent(new Event('bc:cookie-settings'))}
                                    className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0"
                                >
                                    {t('legal.cookies')}
                                </button>
                            )}
                        </nav>
                    </div>

                    {/*
                      The copyright line before the disclosure. The year is the
                      one the page is rendered in; a notice that says 2026 in
                      2028 reads as an abandoned site. The name stood in front
                      of this for an hour on 2026-09-08 and came out again: the
                      copyright line already says it, and the header is where
                      the name belongs.
                    */}
                    {/*
                      The Amazon sentence is Amazon's own, required verbatim by
                      the Associates programme, and it sits here rather than on
                      the terms page because the programme wants it with the
                      links: a Cove's prose can carry an Amazon link, so any
                      page can. Only in markets that have a tag, since the
                      others show no Amazon link at all.
                    */}
                    <p className="mt-3 border-t border-line/60 pt-3 text-2xs">
                        {/*
                          The year on the site's clock, not the machine's: the
                          server renders in UTC and the browser in local time,
                          and in the first hour of 1 January they disagree,
                          which React reports as a hydration mismatch.
                        */}
                        {t('footer.copyright', {
                            year: new Intl.DateTimeFormat('en', { year: 'numeric', timeZone: 'Europe/Brussels' }).format(new Date()),
                        })}{' '}
                        {t('footer.affiliate')}
                        {amazonAssociate && ` ${t('footer.amazon')}`}
                    </p>
                </div>
            </footer>

            <CookieBanner />
        </div>
    )
}
