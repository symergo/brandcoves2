import { Link, router, usePage } from '@inertiajs/react'
import AccountMenu from '../Components/AccountMenu'
import AddingToBar from '../Components/AddingToBar'
import { buttonClasses } from '../Components/Button'
import CookieBanner from '../Components/CookieBanner'
import CoveIcon from '../Components/CoveIcon'
import FlashMessage from '../Components/FlashMessage'
import SaveToast from '../Components/SaveToast'
import SignInLink from '../Components/SignInLink'
import MarketSwitcher from '../Components/MarketSwitcher'
import NavMenu, { type NavMenuItem } from '../Components/NavMenu'
import ToolIcon from '../Components/ToolIcon'
import { type PropsWithChildren, type ReactNode, useEffect, useState } from 'react'
import { SignInProvider } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

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
    const { market, auth, unreadCount, analytics } = page.props
    const { t } = useTranslations()
    const base = `/${market.key}`
    const [menuOpen, setMenuOpen] = useState(false)

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
     * Editorial first, tools second.
     *
     * The two Cove surfaces lead, because they are the only things here that
     * are *ours* — everything else is a way of querying a catalogue that any
     * competitor also has. Search, gifting and Surprise follow as the three
     * things you can do.
     *
     * The labels say "Cove", not "Guides" or "Daily Picks". That is the name
     * the homepage, the subscription mails and the page titles already use;
     * the header was the last surface still calling them something else.
     */
    /*
     * Three verbs, two of which open.
     *
     * Five flat entries described five surfaces and left nine gifting tools and
     * the whole discovery half reachable only from inside a page you had to know
     * to open. Grouping under what you came to *do* — organise, discover,
     * search — means the header describes intents rather than URLs, and the
     * dropdowns are where the surfaces live.
     *
     * Each verb still points at a hub that explains its section, so the label is
     * a real destination and not just a menu handle. See NavMenu for why the
     * chevron is a separate control.
     *
     * Scan is deliberately absent, unchanged: it is a way of *entering a query*,
     * not a section, and the scan button in the search field already opens it.
     */
    const organise = {
        href: `${base}/gift-cove`,
        /*
         * "Make a list", not "Organise" (changed 2026-09-12). The verb named
         * the section and told a newcomer nothing about what to press; the
         * hub it opens starts with the list wizard, so the label now names
         * the thing you do there. The home page keeps `nav.organise` as the
         * heading of its band, where a "Make a new list" button already sits
         * under it and a heading saying the same thing would be the button
         * twice.
         */
        label: t('nav.make_list'),
        // The wish list mark, which is what the entry makes. Added 2026-09-12
        // with Discover's compass, at the owner's request.
        icon: <ToolIcon name="wishlist" className="h-4 w-4" />,
        /*
         * No submenu, since 2026-09-12, at the owner's request.
         *
         * Four entries hung under it: the three list views (mine, shared
         * with me, group) and Secret Friend. The hub it opens carries all
         * four as cards with a sentence each, and a menu that repeats the
         * page it leads to is a second copy of that page with less on it.
         * The entry is one thing to press now, which is also what its new
         * label promises: "Make a list", not "here are your list views".
         *
         * Two things follow. On the wide header the entry renders as a plain
         * link (NavMenu draws no chevron for an empty list), and on the phone
         * panel the section is a heading with nothing indented under it. My
         * Lists had been dropped from the panel's account block because it
         * was in this menu; it is back there now, below.
         */
        items: [] as NavMenuItem[],
    }

    const discover = {
        href: `${base}/discover-cove`,
        /*
         * "Find a gift", not "Discover" (changed 2026-09-12, at the owner's
         * request, the same day Organise became "Make a list"). Discover
         * said what the Coves are for; this says what the visitor came to
         * do, and pairs with the other entry: make a list, find a gift.
         * The variable keeps its old name, as `organise` does.
         */
        label: t('nav.find_gift'),
        icon: <CoveIcon name="compass" className="h-4 w-4" />,
        items: [
            /*
             * The Gift Whisperer, first. It was kept out of the header on
             * the grounds that it suggests rather than organises; under a
             * menu called "Find a gift" it is the most direct answer to the
             * label, and the owner asked for it here (2026-09-12). The
             * "Find a present" band on the Gift Cove hub, which was its
             * other door, went the same day.
             */
            {
                href: `${base}/gift`,
                label: t('gift_cove.whisperer_title'),
                hint: t('nav.hint_whisperer'),
                icon: <ToolIcon name="whisperer" className="h-5 w-5" />,
            },
            /*
             * Search, second (moved in from the loose links on 2026-09-12,
             * at the owner's request). It sat outside both menus because it
             * reads as a control rather than a section; under a menu called
             * "Find a gift" it is the second most direct answer to the label,
             * after the Whisperer, and the search field on every page is
             * still the way most people reach it.
             */
            {
                href: `${base}/search`,
                // "Search offers", not "Search": under this menu it says
                // what is searched, as the entries around it do.
                label: t('nav.search_offers'),
                hint: t('nav.hint_search'),
                icon: <ToolIcon name="search" className="h-5 w-5" />,
            },
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
            {
                href: `${base}/guides`,
                label: t('nav.smart'),
                hint: t('nav.hint_smart'),
                icon: <CoveIcon name="idea" className="h-5 w-5" />,
            },
            {
                href: `${base}/gift-ideas`,
                label: t('nav.gift_coves'),
                hint: t('nav.hint_gift_coves'),
                icon: <CoveIcon name="persona" className="h-5 w-5" />,
            },
            /*
             * Brand Coves (`/brands`) and Shop Coves (`/shops`) are withheld
             * from this menu for now, deliberately — not removed.
             *
             * Both pages exist, are linked from All Coves, and are in the
             * sitemap; their copy, icons (`brand`, `shop`) and `nav.*_coves`
             * keys are all in place. Restoring them is putting two entries back
             * in this list, between Gift Coves and All Coves.
             */
            {
                href: `${base}/coves`,
                label: t('nav.all_coves'),
                hint: t('nav.hint_all_coves'),
                icon: <CoveIcon name="all" className="h-5 w-5" />,
            },
            {
                href: `${base}/ask`,
                label: t('ask.title'),
                hint: t('nav.hint_ask'),
                icon: <CoveIcon name="ask" className="h-5 w-5" />,
            },
        ],
    }

    /*
     * The flat links, beside the two section menus.
     *
     * Feedback earns a place in the header rather than the footer because it is
     * the only route a visitor has to report the thing this catalogue gets
     * wrong most — a stale price, a dead link, a product filed under the wrong
     * brand. In the footer it is found by people looking for it; here it is
     * found by people who have just hit the problem, which is the only moment
     * the report gets written.
     */
    const nav = [
        // Search was here until 2026-09-12; it is under Find a gift now.
        /*
          Help, not Feedback.

          The menu offered the report form and nothing else, so "how do I do
          this" had no entry anywhere in the chrome while "this is broken" had a
          top-level one. `/help` answers both and carries the same form.
        */
        { href: `${base}/help`, label: t('help.link'), icon: <ToolIcon name="help" className="h-5 w-5" /> },
    ]

    /*
     * The phone gets the same sections, as sections.
     *
     * It used to get them flattened — `[organise, ...organise.items, discover,
     * ...discover.items, ...nav]` — on the reasoning that a dropdown inside an
     * already-expanded panel is a second thing to open. That half is still
     * right and nothing here collapses. What it produced, though, was fourteen
     * links in one column at one weight, where "Organise" and "Secret Friend"
     * and "Feedback" are the same size and the same distance apart. A reader
     * cannot tell from that which two of them are hubs, which four belong
     * under the first, or that the list has an end.
     *
     * So the hub is a heading you can press, its surfaces are indented under a
     * rule, and the two loose links, the account block and the market switcher
     * are three groups after them. Same links, same order, same single tap to
     * any of them.
     */
    /*
     * Discover first, then Make a list (swapped 2026-09-12, at the owner's
     * request). Discover is the editorial half, the only part that is ours,
     * and the one with a menu left under it; Make a list is now a single
     * link, and a menu followed by a link reads better than the reverse.
     * The phone panel follows the same order.
     */
    const sections: { href: string; label: string; icon: ReactNode; items: NavMenuItem[] }[] = [discover, organise]

    /*
     * "You are here", in a menu where three entries share a path.
     *
     * `isCurrent` compares paths, deliberately, so a product opened from Search
     * still reads as Search. The three list views differ only by `?view=`,
     * which breaks that both ways: a path match lights all three at once, and
     * comparing the full URL lights none of the entries that carry no query.
     *
     * So: if any entry in the menu matches the URL exactly, that one is the
     * answer and nothing else is. Otherwise fall back to the prefix match. On
     * `/lists?view=shared` that marks Shared Lists alone rather than it and My
     * Lists together; on `/lists/{id}` nothing matches exactly, so My Lists
     * lights by prefix, which is right.
     */
    const exact = (href: string) => (page.url ?? '') === href
    const anyExact = [
        ...sections.flatMap((section) => [section.href, ...section.items.map((item) => item.href)]),
        ...nav.map((item) => item.href),
    ].some(exact)

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

            <header className="border-b border-line">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4">
                    {/*
                      The name at the mark's height. It was 18px beside a 28px
                      mark, which made the mark the logo and the word its
                      caption; the owner wanted the name as big as the icon
                      (2026-09-08). 28px, with the line height pulled in so the
                      header does not grow.
                    */}
                    <Link href={base} className="flex items-center gap-2 text-[1.75rem] leading-none font-semibold tracking-tight">
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

                    <nav
                        className="hidden items-center gap-4 text-sm text-ink-soft md:flex"
                        aria-label={t('nav.main')}
                    >
                        <NavMenu
                            href={discover.href}
                            label={discover.label}
                            icon={discover.icon}
                            items={discover.items}
                            current={isCurrent(discover.href)}
                            isCurrent={isCurrent}
                            submenuLabel={t('nav.submenu', { section: discover.label })}
                        />

                        <NavMenu
                            href={organise.href}
                            label={organise.label}
                            icon={organise.icon}
                            items={organise.items}
                            current={isCurrent(organise.href)}
                            isCurrent={isCurrent}
                            submenuLabel={t('nav.submenu', { section: organise.label })}
                        />

                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                aria-current={isCurrent(item.href) ? 'page' : undefined}
                                className={
                                    isCurrent(item.href)
                                        ? 'font-medium text-ink underline decoration-accent decoration-2 underline-offset-8'
                                        : 'hover:text-ink'
                                }
                            >
                                <span className="inline-flex items-center gap-1.5">
                                    <span className="text-accent">{item.icon}</span>
                                    {item.label}
                                </span>
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
                    {/*
                      Search, one tap from any page. It was a text link inside
                      the menu — two taps from everywhere but the home — for the
                      site's primary action. Both controls are 44px: the floor
                      the stylesheet applies to fields below `sm` applied to
                      nothing here.
                    */}
                    <div className="ml-auto flex items-center gap-1 md:hidden">
                        <Link
                            href={`${base}/search`}
                            aria-label={t('nav.search')}
                            aria-current={isCurrent(`${base}/search`) ? 'page' : undefined}
                            className="flex h-11 w-11 items-center justify-center rounded-lg text-ink hover:bg-line/40"
                        >
                            <ToolIcon name="search" className="h-5 w-5" />
                        </Link>
                        <button
                            type="button"
                            className="flex h-11 w-11 items-center justify-center rounded-lg border border-line text-ink"
                            aria-expanded={menuOpen}
                            aria-controls="mobile-menu"
                            onClick={() => setMenuOpen(!menuOpen)}
                        >
                            <ToolIcon name={menuOpen ? 'close' : 'menu'} className="h-5 w-5" />
                            <span className="sr-only">{t('nav.main')}</span>
                        </button>
                    </div>

                    <div className="ml-auto hidden items-center gap-3 md:flex">
                        <MarketSwitcher id="header" />

                        {auth.user && unreadCount > 0 && (
                            <Link
                                href={`${base}/notifications`}
                                className="relative text-sm hover:text-ink"
                                aria-label={`${t('nav.notifications')} (${unreadCount})`}
                            >
                                <span aria-hidden>🔔</span>
                                <span className="absolute -top-2 -right-2 rounded-full bg-accent px-1.5 text-2xs leading-4 font-semibold text-white">
                                    {unreadCount > 9 ? '9+' : unreadCount}
                                </span>
                            </Link>
                        )}

                        {/*
                          Lists are anonymous-first — that is the whole design:
                          save a product, build a list, share it, all before
                          signing up. Hiding this link behind `auth.user` meant a
                          visitor who had done exactly that had no way back to
                          their own list, and the feature looked absent.
                        */}
                        <Link
                            href={`${base}/lists`}
                            aria-current={isCurrent(`${base}/lists`) ? 'page' : undefined}
                            className={`hidden text-sm lg:block ${isCurrent(`${base}/lists`) ? 'font-medium text-ink' : 'text-ink-soft hover:text-ink'}`}
                        >
                            {t('nav.lists')}
                        </Link>

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
                {menuOpen && (
                    <div
                        id="mobile-menu"
                        className="fixed inset-0 z-50 overflow-y-auto bg-cream px-4 pb-4 md:hidden"
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

                        <nav className="text-sm" aria-label={t('nav.main')}>
                            {sections.map((section) => (
                                <div key={section.href} className="mb-4 border-b border-line pb-4">
                                    {/*
                                      The hub is the heading and the heading is
                                      a link. Both halves matter: the page
                                      explains a section that is not
                                      self-evident, and a heading that cannot be
                                      pressed puts it out of reach on the one
                                      device where there is no hover to reveal
                                      anything.
                                    */}
                                    <Link
                                        href={section.href}
                                        aria-current={isHere(section.href) ? 'page' : undefined}
                                        onClick={() => setMenuOpen(false)}
                                        className={`flex min-h-11 items-center justify-between py-1 text-base font-semibold ${
                                            isHere(section.href) ? 'text-accent' : 'text-ink'
                                        }`}
                                    >
                                        <span className="flex items-center gap-2.5">
                                            <span className="shrink-0 text-accent">{section.icon}</span>
                                            {section.label}
                                        </span>
                                        <span aria-hidden className="text-xs text-ink-soft">
                                            →
                                        </span>
                                    </Link>

                                    {/* Indented under a rule, which is the
                                        cheapest way to say "these belong to
                                        that" without a control to expand.
                                        Nothing at all under a section with no
                                        items: a rule beside empty space reads
                                        as a list that failed to load. */}
                                    {section.items.length > 0 && (
                                    <ul className="mt-1 border-l border-line pl-3">
                                        {section.items.map((item) => (
                                            <li key={item.href}>
                                                <Link
                                                    href={item.href}
                                                    aria-current={isHere(item.href) ? 'page' : undefined}
                                                    // Close on navigate: an
                                                    // Inertia visit keeps the
                                                    // layout mounted, so a menu
                                                    // left open would cover the
                                                    // page just arrived at.
                                                    onClick={() => setMenuOpen(false)}
                                                    className={`flex min-h-11 items-center gap-2.5 py-2 ${
                                                        isHere(item.href) ? 'font-medium text-accent' : ''
                                                    }`}
                                                >
                                                    {item.icon ? (
                                                        <span className="shrink-0 text-accent">
                                                            {item.icon}
                                                        </span>
                                                    ) : null}
                                                    <span>
                                                        <span className="block">{item.label}</span>
                                                        {/* Kept on a phone for
                                                            the reason they exist
                                                            at all: four Cove
                                                            entries differing by
                                                            one word cannot be
                                                            told apart the first
                                                            time. */}
                                                        {item.hint ? (
                                                            <span className="block text-xs text-ink-soft">
                                                                {item.hint}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                    )}
                                </div>
                            ))}

                            {/*
                              The loose links, drawn like the section headings
                              above: an icon in the accent, a label, 44px, and
                              an arrow. This group was "Search and help", a
                              heading over two indented rows; Search moved
                              under Find a gift on 2026-09-12, and a heading
                              over one row that repeats the heading's word is
                              a box around nothing. So each remaining link is
                              its own heading-weight row, the shape Make a
                              list already has now that it carries no items.
                            */}
                            {nav.map((item) => (
                                <div key={item.href} className="mb-4 border-b border-line pb-4">
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
                            ))}

                            <div>
                                <p className="flex min-h-11 items-center py-1 text-base font-semibold text-ink">
                                    {t('nav.account')}
                                    {auth.user && (
                                        <span className="ml-2 truncate text-sm font-normal text-ink-soft">
                                            {auth.user.name?.trim() || auth.user.email}
                                        </span>
                                    )}
                                </p>
                                <ul className="mt-1 border-l border-line pl-3">
                                    {/*
                                      For everybody, signed in or not: lists
                                      are anonymous-first, and a visitor who
                                      built one before signing up needs a way
                                      back to it. This row left the panel on
                                      2026-08-31 because the Make-a-list menu
                                      carried it; that menu is gone, so it is
                                      the panel's only route to My Lists.
                                    */}
                                    <li>
                                        <Link
                                            href={`${base}/lists`}
                                            aria-current={isHere(`${base}/lists`) ? 'page' : undefined}
                                            onClick={() => setMenuOpen(false)}
                                            className={`flex min-h-11 items-center gap-2.5 py-2 ${isHere(`${base}/lists`) ? 'font-medium text-accent' : ''}`}
                                        >
                                            <span className="shrink-0 text-accent"><ToolIcon name="wishlist" className="h-5 w-5" /></span>
                                            <span>{t('nav.lists')}</span>
                                        </Link>
                                    </li>
                                    {auth.user ? (
                                        <>
                                            <li>
                                            <Link
                                                href={`${base}/friends`}
                                                aria-current={isHere(`${base}/friends`) ? 'page' : undefined}
                                                onClick={() => setMenuOpen(false)}
                                                className={`flex min-h-11 items-center gap-2.5 py-2 ${isHere(`${base}/friends`) ? 'font-medium text-accent' : ''}`}
                                            >
                                                <span className="shrink-0 text-accent"><ToolIcon name="friends" className="h-5 w-5" /></span>
                                                <span>{t('nav.friends')}</span>
                                            </Link>
                                            </li>
                                            <li>
                                            <Link
                                                href={`${base}/notifications`}
                                                aria-current={isHere(`${base}/notifications`) ? 'page' : undefined}
                                                onClick={() => setMenuOpen(false)}
                                                className={`flex min-h-11 items-center gap-2.5 py-2 ${isHere(`${base}/notifications`) ? 'font-medium text-accent' : ''}`}
                                            >
                                                <span className="shrink-0 text-accent"><ToolIcon name="alerts" className="h-5 w-5" /></span>
                                                <span>{t('nav.notifications')}{unreadCount > 0 && ` (${unreadCount})`}</span>
                                            </Link>
                                            </li>
                                            {auth.user.isAdmin && (
                                                <li>
                                                    <a href="/admin" className="flex min-h-11 items-center gap-2.5 py-2">
                                                        <span className="shrink-0 text-accent"><ToolIcon name="admin" className="h-5 w-5" /></span>
                                                        <span>{t('nav.admin')}</span>
                                                    </a>
                                                </li>
                                            )}
                                            <li>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setMenuOpen(false)
                                                        router.post(`${base}/logout`)
                                                    }}
                                                    className="flex min-h-11 items-center gap-2.5 py-2 w-full text-left"
                                                >
                                                    <span className="shrink-0 text-accent"><ToolIcon name="signout" className="h-5 w-5" /></span>
                                                    <span>{t('nav.sign_out')}</span>
                                                </button>
                                            </li>
                                        </>
                                    ) : (
                                        <li>
                                            <SignInLink onNavigate={() => setMenuOpen(false)} className="flex min-h-11 items-center gap-2.5 py-2">
                                                <span className="shrink-0 text-accent"><ToolIcon name="signin" className="h-5 w-5" /></span>
                                                <span>{t('nav.sign_in')}</span>
                                            </SignInLink>
                                        </li>
                                    )}
                                </ul>
                            </div>
                        </nav>

                        {/*
                          Country names spelled out here, unlike the header.
                          There is room in an open menu, and a flag on its own is
                          a guess — the tooltip that carries the name on a
                          desktop does not exist on the device this menu is for.
                        */}
                        <MarketSwitcher
                            id="mobile"
                            withNames
                            className="mt-4 flex flex-col gap-3"
                        />

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
                            {/* Same name as the header uses. Two links to one page
                                under two different words is the exact confusion the
                                Cove naming pass set out to remove. */}
                            <Link href={`/${market.key}/guides`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('nav.smart')}
                            </Link>
                            <Link href={`/${market.key}/${market.coveSegment}`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('nav.daily')}
                            </Link>
                            <Link href={`/${market.key}/surprise`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('nav.surprise')}
                            </Link>
                            {/* What this market searches for: the hub that
                                replaced the related-search chips, and the only
                                place a crawler reaches them from any page. */}
                            <Link href={`/${market.key}/popular-searches`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('popular_searches.title')}
                            </Link>
                            {/* The search help under its short name, and Help,
                                which gathers the how-to pages and the report
                                form. Both, rather than one "Help": somebody
                                looking for "how do I search" scans for that
                                phrase. */}
                            <Link href={`/${market.key}/search-help`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('search_help.footer_link')}
                            </Link>
                            <Link href={`/${market.key}/help`} className="inline-flex min-h-10 items-center hover:text-ink sm:min-h-0">
                                {t('help.link')}
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
                    <p className="mt-3 border-t border-line/60 pt-3 text-2xs">
                        {t('footer.copyright', { year: String(new Date().getFullYear()) })} {t('footer.affiliate')}
                    </p>
                </div>
            </footer>

            <CookieBanner />
        </div>
    )
}
