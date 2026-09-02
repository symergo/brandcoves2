import { Link, router, usePage } from '@inertiajs/react'
import AccountMenu from '../Components/AccountMenu'
import AddingToBar from '../Components/AddingToBar'
import CookieBanner from '../Components/CookieBanner'
import CoveIcon from '../Components/CoveIcon'
import FlashMessage from '../Components/FlashMessage'
import SaveToast from '../Components/SaveToast'
import SignInLink from '../Components/SignInLink'
import MarketSwitcher from '../Components/MarketSwitcher'
import NavMenu, { type NavMenuItem } from '../Components/NavMenu'
import ToolIcon from '../Components/ToolIcon'
import { type PropsWithChildren, useState } from 'react'
import { SignInProvider } from '../signIn'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

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
        label: t('nav.organise'),
        /*
         * The three lists views, then the draw.
         *
         * They are three questions rather than three filters — what am I
         * keeping, what has somebody shown me, what are we choosing together —
         * which is why each carries its own `?view=` rather than being one
         * entry you narrow after arriving. See docs/features/list-taxonomy.md.
         *
         * The Gift Finder is deliberately absent: it suggests things rather
         * than organising them, so it belongs to the homepage CTA and the Gift
         * Cove hub, not under a verb meaning "organise".
         */
        /*
         * Iconed, like Discover.
         *
         * This menu deliberately had none: the entries are tools whose names
         * say what they are, and the fear was a contact sheet. What that missed
         * is that the two menus sit in one panel on a phone, where an iconed
         * block above a bare one reads as one finished list and one unfinished
         * one — and that three of these four names are the same noun with a
         * different adjective, which is exactly the case a mark in the margin
         * helps with.
         *
         * `ToolIcon`, not `CoveIcon`: these are the Gift Cove tools and already
         * have their glyphs there, drawn on the same grid at the same weight as
         * Discover's. Reusing them means the header cannot drift from the tool
         * pages that teach them.
         */
        items: [
            {
                href: `${base}/lists`,
                label: t('nav.lists'),
                icon: <ToolIcon name="wishlist" className="h-5 w-5" />,
            },
            {
                href: `${base}/lists?view=shared`,
                label: t('nav.shared_lists'),
                icon: <ToolIcon name="shared" className="h-5 w-5" />,
            },
            {
                href: `${base}/lists?view=group`,
                label: t('nav.group_lists'),
                icon: <ToolIcon name="collab" className="h-5 w-5" />,
            },
            {
                href: `${base}/santa`,
                label: t('nav.santa'),
                icon: <ToolIcon name="santa" className="h-5 w-5" />,
            },
        ],
    }

    /*
     * The Cove types, then the one thing here that is not one.
     *
     * The menu used to name three surfaces — Daily, "Idea Cove" and Ask — which
     * meant the header used the word "Cove" for the daily column and for the
     * article archive and had no word at all for the personas, whose shelf at
     * `/gift-ideas` was reachable from nothing. A reader could not learn from
     * the header that Cove is one thing with several shapes, because the header
     * only showed two of the shapes and called one of them by the name of the
     * whole.
     *
     * So: one entry per kind, named for the kind, and All Coves under them for
     * the overview. Every entry carries a hint — five labels differing by one
     * word cannot be told apart on first opening, and the hint slot has been on
     * `NavMenuItem` since it was written.
     *
     * Ask others sits below the Coves rather than among them. It is a way of
     * *finding* something when you cannot describe it, which is why it belongs
     * under Discover at all, but its content comes from other visitors rather
     * than from us — it is not something we published, and listing it as a
     * fourth Cove type would say that it is.
     */
    const discover = {
        href: `${base}/discover-cove`,
        label: t('nav.discover'),
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
        { href: `${base}/search`, label: t('nav.search') },
        { href: `${base}/feedback`, label: t('nav.feedback') },
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
    const sections: { href: string; label: string; items: NavMenuItem[] }[] = [organise, discover]

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

            <header className="border-b border-line">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4">
                    <Link href={base} className="flex items-center gap-2 text-lg font-semibold tracking-tight">
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
                        className="hidden items-center gap-5 text-sm text-ink-soft sm:flex"
                        aria-label={t('nav.main')}
                    >
                        <NavMenu
                            href={organise.href}
                            label={organise.label}
                            items={organise.items}
                            current={isCurrent(organise.href)}
                            isCurrent={isCurrent}
                            submenuLabel={t('nav.submenu', { section: organise.label })}
                        />

                        <NavMenu
                            href={discover.href}
                            label={discover.label}
                            items={discover.items}
                            current={isCurrent(discover.href)}
                            isCurrent={isCurrent}
                            submenuLabel={t('nav.submenu', { section: discover.label })}
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
                        <MarketSwitcher id="header" />

                        {auth.user && unreadCount > 0 && (
                            <Link
                                href={`${base}/notifications`}
                                className="relative text-sm hover:text-ink"
                                aria-label={`${t('nav.notifications')} (${unreadCount})`}
                            >
                                <span aria-hidden>🔔</span>
                                <span className="absolute -top-2 -right-2 rounded-full bg-accent px-1.5 text-[11px] leading-4 font-semibold text-white">
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
                            className={`text-sm ${isCurrent(`${base}/lists`) ? 'font-medium text-ink' : 'text-ink-soft hover:text-ink'}`}
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
                {menuOpen && (
                    <div id="mobile-menu" className="border-t border-line px-4 py-4 sm:hidden">
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
                                        className={`flex items-center justify-between py-1 text-base font-semibold ${
                                            isHere(section.href) ? 'text-accent' : 'text-ink'
                                        }`}
                                    >
                                        {section.label}
                                        <span aria-hidden className="text-xs text-ink-soft">
                                            →
                                        </span>
                                    </Link>

                                    {/* Indented under a rule, which is the
                                        cheapest way to say "these belong to
                                        that" without a control to expand. */}
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
                                                    className={`flex gap-2.5 py-2 ${
                                                        isHere(item.href) ? 'font-medium text-accent' : ''
                                                    }`}
                                                >
                                                    {item.icon ? (
                                                        <span className="mt-0.5 shrink-0 text-accent">
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
                                </div>
                            ))}

                            {/* Search and Feedback belong to no section, and
                                saying so by leaving them ungrouped is more
                                honest than inventing a third heading for two
                                links. */}
                            <div className="mb-4 flex flex-col gap-1 border-b border-line pb-4">
                                {nav.map((item) => (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        aria-current={isHere(item.href) ? 'page' : undefined}
                                        onClick={() => setMenuOpen(false)}
                                        className={`py-1 ${isHere(item.href) ? 'font-medium text-accent' : ''}`}
                                    >
                                        {item.label}
                                    </Link>
                                ))}
                            </div>

                            <p className="pb-1 text-xs font-medium tracking-wide text-ink-soft uppercase">
                                {t('nav.account')}
                            </p>

                            {/*
                              My Lists is deliberately not repeated here.
                              It was, because the wide header carries it beside
                              the account menu — but on a phone it sat four rows
                              under the identical link inside Organise, which
                              reads as two different destinations.
                            */}
                            <div className="flex flex-col gap-1">
                                {auth.user ? (
                                    <>
                                        <Link
                                            href={`${base}/notifications`}
                                            onClick={() => setMenuOpen(false)}
                                            className="py-1"
                                        >
                                            {t('nav.notifications')}
                                            {unreadCount > 0 && ` (${unreadCount})`}
                                        </Link>

                                        {/* The same reachability problem as the
                                            wide header had, and worse on a phone,
                                            which is the device most likely to be
                                            handed to someone else. */}
                                        <span className="text-xs text-ink-soft">
                                            {auth.user.name?.trim() || auth.user.email}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setMenuOpen(false)
                                                router.post(`${base}/logout`)
                                            }}
                                            className="py-1 text-left"
                                        >
                                            {t('nav.sign_out')}
                                        </button>
                                    </>
                                ) : (
                                    <SignInLink onNavigate={() => setMenuOpen(false)}>
                                        {t('nav.sign_in')}
                                    </SignInLink>
                                )}
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
                        {/* Under its own name, not `nav.brand_coves`: with
                            Brand Coves withheld from the header there is no
                            header entry for this to agree with, and a footer
                            link is not the place to introduce a name the rest
                            of the site is not yet using. */}
                        <Link href={`/${market.key}/brands`} className="hover:text-accent">
                            {t('brand.index_title')}
                        </Link>
                        {/* Same name as the header uses. Two links to one page
                            under two different words is the exact confusion the
                            Cove naming pass set out to remove. */}
                        <Link href={`/${market.key}/guides`} className="hover:text-accent">
                            {t('nav.smart')}
                        </Link>
                        <Link href={`/${market.key}/${market.coveSegment}`} className="hover:text-accent">
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
                                className="hover:text-accent"
                            >
                                {t('legal.cookies')}
                            </button>
                        )}
                    </nav>

                    <p>{t('footer.affiliate')}</p>
                </div>
            </footer>

            <CookieBanner />
        </div>
    )
}
