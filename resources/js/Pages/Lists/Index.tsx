import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { kindIcons, type ListKind } from '../../Components/ListKindBadge'
import ToolIcon, { type ToolKey } from '../../Components/ToolIcon'
import ListPills from '../../Components/ListPills'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import AddProduct from '../../Components/AddProduct'
import ListWizard, { hasListDraft, type WizardOffer } from '../../Components/ListWizard'
import InfoTip from '../../Components/InfoTip'
import NewListButton from '../../Components/NewListButton'
import { buttonClasses } from '../../Components/Button'
import Menu, { MenuItem } from '../../Components/Menu'
import ShareRow from '../../Components/ShareRow'

interface ListSummary {
    id: string
    title: string
    kind: string
    isDefault: boolean
    visibility: string
    itemCount: number
    covers: string[]
    url: string
    recipient: { id: string; name: string } | null
    /**
     * Suggestions waiting on a decision. Null on a list somebody else owns —
     * that message is addressed to them, not to me.
     */
    suggestions: number | null
    /**
     * Somebody else's list that I have been let into, rather than one of mine.
     *
     * My Lists shows both now, so the card has to carry the difference: what I
     * may do with the two is not the same, and a list I merely have access to
     * can be changed out from under me by the person who owns it.
     */
    sharedWithMe: boolean
    /** May somebody who is not the owner put things on it? From `summarise()`. */
    linkCanAdd: boolean
    /** Who owns it. Null on my own rows, where the answer is me. */
    ownerName: string | null
    /** `viewer` or `editor`, on a list shared with me. */
    role: string | null
    /** Which section of the page it goes under; decided by the server. */
    section: Exclude<ListsView, 'saved'>
    /** Your own list's link once it is shared; null while private or not yours. */
    shareUrl?: string | null
}

/** The page's sections, and the `?view=` values that scroll to them. */
type ListsView = 'mine' | 'shared' | 'group' | 'saved'

/** A Cove somebody saved into My Coves; see docs/features/saved-coves.md. */
interface SavedCoveRow {
    id: string
    title: string
    /** A Cove kind, or `community` for a list somebody published (community-coves.md). */
    kind: string
    url: string
    image: string | null
    savedAt: string | null
    /** Where Remove (DELETE) and Make it my list (POST) go; they differ per kind. */
    saveUrl: string
    copyUrl: string
}

/**
 * The page's own props, plus everything the list wizard needs — people,
 * friends, occasions — in the shape the Gift Cove already sends it.
 */
interface Props extends WizardOffer {
    lists: ListSummary[]
    /** The section a `?view=` link asked for; null on the plain URL. */
    view: ListsView | null
    savedCoves: SavedCoveRow[]
    isSignedIn: boolean
}

/**
 * A row's action: its words beside the icon on a wide screen, the icon alone
 * on a phone (owner, 2026-09-27: "on mobile, replace the buttons with icons").
 * The words stay for screen readers either way.
 */
const rowAction =
    'inline-flex h-9 min-w-9 items-center justify-center gap-1.5 rounded-lg border border-line px-2 text-sm transition hover:border-ink sm:h-auto sm:min-w-0 sm:px-3 sm:py-1.5'

/**
 * One list, as one row: the way My people draws a person (owner, 2026-09-27:
 * "design the My Coves list in the same way as the My People list").
 *
 * A picture where My people has the initial (the first product, or the kind's
 * mark on an empty list), then the name, how many items and for whom, and the
 * pills; on the right what you do to it: add, share, and ⋯ with "Vraag het aan
 * anderen" and "Instellingen". Until that day each list was a card with a
 * strip of product pictures, three to a row, which the owner found
 * inconsistent with My people beside it.
 *
 * "Shared" means two different things here and must not be confused: `shared`
 * is *I have published this outward*, `theirs` is *this is not mine at all*.
 * Share and ⋯ are for your own lists only; somebody else's is theirs to share
 * and set up. Adding needs a list you may add to.
 */
function ListCard({ list }: { list: ListSummary }) {
    const { t, n } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const shared = list.visibility !== 'private'
    const theirs = list.sharedWithMe
    const [adding, setAdding] = useState(false)
    const [sharing, setSharing] = useState(false)
    const canAdd = !theirs || list.role === 'editor'

    return (
        <div className="relative flex gap-3 p-4 sm:items-center">
            <Link
                href={list.url}
                className={`group flex min-w-0 flex-1 items-center gap-3 ${canAdd || !theirs ? 'pr-32 sm:pr-0' : ''}`}
            >
                {list.covers.length > 1 ? (
                    /*
                      Up to four products as a small 2x2 collage in one square:
                      what is in it at a glance (the cards' strong point) while
                      every name starts at the same place.
                    */
                    <span className="grid h-12 w-12 shrink-0 grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line">
                        {list.covers.slice(0, 4).map((src, i) => (
                            <img
                                key={i}
                                src={src}
                                alt=""
                                loading="lazy"
                                className="h-full w-full bg-cream object-contain"
                                onError={(e) => {
                                    e.currentTarget.style.visibility = 'hidden'
                                }}
                            />
                        ))}
                    </span>
                ) : list.covers.length === 1 ? (
                    <img
                        src={list.covers[0]}
                        alt=""
                        loading="lazy"
                        className="h-12 w-12 shrink-0 rounded-lg border border-line bg-cream object-contain p-1"
                        onError={(e) => {
                            e.currentTarget.style.visibility = 'hidden'
                        }}
                    />
                ) : (
                    <span aria-hidden className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <ToolIcon name={kindIcons[(list.kind as ListKind) ?? 'mine'] ?? 'list'} className="h-5 w-5" />
                    </span>
                )}
                <span className="min-w-0">
                    <span className="block font-medium group-hover:underline">{list.title}</span>
                    <span className="mt-0.5 block text-sm text-ink-soft">
                        {list.itemCount === 1 ? t('lists.one_item') : t('lists.items', { count: n(list.itemCount) })}
                        {/*
                          Who the list is for: on a list about somebody, the
                          recipient; on a wish list somebody shared with me, its
                          owner, because that is the person I shop for.
                        */}
                        {list.kind !== 'mine' && list.recipient && ` · ${list.recipient.name}`}
                        {theirs && list.kind === 'mine' && list.ownerName && ` · ${list.ownerName}`}
                    </span>
                    {/* Somebody else's wish list is how I shop for them: say so. */}
                    {theirs && list.kind === 'mine' && list.ownerName && (
                        <span className="mt-0.5 block text-sm text-accent">{t('lists.shop_for', { name: list.ownerName })}</span>
                    )}
                    <span className="mt-1.5 flex flex-wrap items-center gap-1.5 text-2xs">
                        <ListPills
                            kind={list.kind as ListKind}
                            role={theirs ? 'contributor' : 'owner'}
                            ownerName={theirs ? list.ownerName : null}
                            canAdd={list.visibility !== 'private' && list.linkCanAdd}
                        />
                        {list.isDefault && <span className="rounded-full bg-line/60 px-2 py-0.5">{t('lists.default_badge')}</span>}
                        {!theirs && (
                            <span
                                className={
                                    shared ? 'rounded-full bg-sage/15 px-2 py-0.5 text-sage' : 'rounded-full bg-line/60 px-2 py-0.5 text-ink-soft'
                                }
                            >
                                {shared ? t('lists.shared_short') : t('lists.private_short')}
                            </span>
                        )}
                        {/* Somebody put something forward and it is waiting on you. */}
                        {list.suggestions !== null && list.suggestions > 0 && (
                            <span className="rounded-full bg-accent/15 px-2 py-0.5 font-medium text-accent">
                                {list.suggestions === 1
                                    ? t('suggestions.one_waiting')
                                    : t('suggestions.waiting', { count: n(list.suggestions) })}
                            </span>
                        )}
                    </span>
                </span>
            </Link>

            {(canAdd || !theirs) && (
                <div className="absolute top-3 right-3 flex items-center gap-1.5 sm:static sm:gap-2">
                    {canAdd && (
                        <button
                            type="button"
                            onClick={() => setAdding(true)}
                            aria-label={t('lists.add_product')}
                            title={t('lists.add_product')}
                            className={`${rowAction} border-accent bg-accent text-white hover:bg-accent-dark`}
                        >
                            <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                            <span className="hidden sm:inline">{t('people.add')}</span>
                        </button>
                    )}
                    {!theirs && (
                        <>
                            <button
                                type="button"
                                onClick={() => setSharing(true)}
                                aria-label={`${t('lists.share')}: ${list.title}`}
                                title={t('lists.share')}
                                className={rowAction}
                            >
                                <ToolIcon name="shared" className="h-4 w-4 shrink-0" />
                                <span className="hidden sm:inline">{t('lists.share')}</span>
                            </button>
                            <Menu
                                label={t('people.list_actions', { name: list.title })}
                                button={
                                    <>
                                        <ToolIcon name="more" className="h-4 w-4 shrink-0" />
                                        <span className="hidden sm:inline">{t('people.more')}</span>
                                    </>
                                }
                                buttonClassName={rowAction}
                            >
                                {() => (
                                    <>
                                        <MenuItem href={`/${market.key}/ask?list=${list.id}`} icon={<ToolIcon name="board" className="h-4 w-4" />}>
                                            {t('lists.ask_others')}
                                        </MenuItem>
                                        <MenuItem href={`${list.url}?panel=settings`} icon={<ToolIcon name="settings" className="h-4 w-4" />}>
                                            {t('lists.settings')}
                                        </MenuItem>
                                    </>
                                )}
                            </Menu>
                        </>
                    )}
                </div>
            )}
            {adding && <AddToListDialog list={list} onClose={() => setAdding(false)} />}
            {sharing && <ShareListDialog list={list} onClose={() => setSharing(false)} />}
        </div>
    )
}

export default function ListsIndex({ lists, view, recipients, friends, occasions, myLists, isSignedIn, savedCoves }: Props) {
    const page = usePage<SharedProps>()
    const { market } = page.props
    const { t } = useTranslations()

    /*
     * The Gift Cove describes nine tools and six of its cards used to land here,
     * on an index, leaving the reader to work out which button started the thing
     * they had just read about. `?new=for_someone` opens the wizard with that
     * question answered instead.
     */
    const intent = new URLSearchParams(page.url.split('?')[1] ?? '').get('new')
    const initialKind =
        intent === 'mine' || intent === 'for_someone' || intent === 'group' || intent === 'santa' ? intent : undefined
    const [creating, setCreating] = useState(intent !== null)

    /*
     * A draft the wizard remembered across a sign-in is finished here too.
     * The magic link lands wherever it lands; if that is this page, the
     * wizard has to be open for the draft to be replayed.
     */
    useEffect(() => {
        if (isSignedIn && hasListDraft()) {
            setCreating(true)
        }
    }, [isSignedIn])

    /*
     * One page, four sections, nothing hidden (owner's call, 2026-09-26).
     *
     * Until then these were four views behind `?view=`, and the plain page
     * showed only my own wish lists: somebody with two wish lists and two
     * lists for other people saw two and thought the others had gone. Now
     * every section is on the page, each heading carries its count, and a
     * section with nothing in it is left out rather than shown empty: a
     * heading over nothing reads as a thing that failed to load.
     *
     * The split is still by whom the lists are for (2026-09-13): what I want,
     * what I am giving, what we give together, and the Coves I kept.
     */
    const sections: { key: ListsView; label: string; hint: string; lists: ListSummary[] }[] = [
        { key: 'mine', label: t('lists.section_mine'), hint: t('wizard.kind_mine_body'), lists: [] },
        { key: 'shared', label: t('lists.section_shared'), hint: t('lists.shared_subtitle'), lists: [] },
        { key: 'group', label: t('lists.section_group'), hint: t('lists.group_subtitle'), lists: [] },
    ]
    for (const section of sections) {
        section.lists = lists.filter((l) => l.section === section.key)
    }
    const filled = sections.filter((s) => s.lists.length > 0)

    /*
     * A `?view=` link (the account menu, a mail, an old bookmark) scrolls to
     * its section and marks it for a moment, so the link still lands where it
     * said it would. When that section is empty there is nothing to scroll
     * to, so the page says so in one line at the top instead.
     */
    const asked = view !== null && (view === 'saved' ? savedCoves.length > 0 : filled.some((s) => s.key === view)) ? view : null
    const [marked, setMarked] = useState<ListsView | null>(asked)

    useEffect(() => {
        if (asked === null) {
            return
        }

        document.getElementById(`section-${asked}`)?.scrollIntoView({ block: 'start' })
        setMarked(asked)
        const timer = window.setTimeout(() => setMarked(null), 2500)

        return () => window.clearTimeout(timer)
    }, [asked])

    const missing =
        isSignedIn && view !== null && asked === null
            ? { mine: t('lists.empty'), shared: t('lists.shared_empty'), group: t('lists.group_empty'), saved: t('saved_coves.empty') }[view]
            : null

    const sectionClass = (key: ListsView) =>
        `mt-10 scroll-mt-24 rounded-card transition-shadow duration-700 ${marked === key ? 'ring-2 ring-accent/40 ring-offset-8 ring-offset-cream' : ''}`

    const heading = t('lists.title')

    return (
        <>
            <Head title={heading} />

            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{heading}</h1>
                </div>
                <div className="flex flex-wrap gap-2">
                    {/*
                      "New list" is the only action in this header, deliberately.

                      A "find things to add" button stood beside it, on the
                      reasoning that nothing goes into a list from this page —
                      every save starts at a product — so somebody arriving here
                      needed a way onward. It was removed on 2026-09-06: the
                      empty state already offers exactly that link, at the moment
                      it is the only thing to do, and the site header carries
                      search on every page. Two buttons for one intention made
                      the header compete with the page under it.
                    */}
                    {/*
                      One button for everybody. What it opens is the same
                      `ListWizard` the Gift Cove uses, which since 2026-09-26 is
                      one question (who it is for) and a Create button; see
                      docs/features/one-step-list.md. Signed out, the button is
                      the sign-in, which remembers the answer and replays it on
                      return.

                      The button is the home page's `NewListButton` since
                      2026-09-12. It was a plain "New list" here, smaller and
                      without the glyph, and the two looked like different things
                      that turned out to open the same form.
                    */}
                    {/*
                      The other half of "who and what": Mijn mensen, as a
                      secondary button before the page's own action (owner,
                      2026-09-27). Mijn mensen has the mirror of it.
                    */}
                    <Link href={`/${market.key}/people`} className={buttonClasses('secondary', 'md')}>
                        <ToolIcon name="people" className="h-4 w-4" />
                        {t('people.title')}
                    </Link>
                    <NewListButton
                        open={creating}
                        onToggle={() => setCreating((v) => !v)}
                        controls="new-list-wizard"
                    />
                </div>
            </header>

            {/*
              Lists work before signup, so this is a nudge rather than a wall.
              Shown only when there is something to lose.
            */}
            {!isSignedIn && lists.length > 0 && (
                <div className="mt-6 rounded-card border border-amber/40 bg-amber/10 p-4">
                    <p className="font-medium">{t('lists.sign_in_to_keep')}</p>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.sign_in_hint')}</p>
                    <SignInLink
                        hint={t('lists.sign_in_hint')}
                        className="mt-2 inline-block text-sm text-accent underline"
                    >
                        {t('nav.sign_in')}
                    </SignInLink>
                </div>
            )}

            {creating && (
                <div id="new-list-wizard" className="mt-6">
                    <ListWizard
                        signedIn={isSignedIn}
                        recipients={recipients}
                        friends={friends}
                        occasions={occasions}
                        myLists={myLists}
                        initialKind={initialKind}
                        onCancel={() => setCreating(false)}
                    />
                </div>
            )}

            {/*
              A link asked for a section that has nothing in it. One line, in
              that section's own words: "nobody shared a list with you" is not
              "you have no lists", and a menu entry that lands on a page
              without the thing it named should say why.
            */}
            {missing !== null && (
                <p role="status" className="mt-6 rounded-card border border-line bg-card px-4 py-3 text-sm text-ink-soft">
                    {missing}
                </p>
            )}

            {filled.length === 0 && savedCoves.length === 0 ? (
                <div className="mt-10 rounded-card border border-line bg-card p-8 text-center">
                    {!isSignedIn ? (
                        <>
                            {/*
                              Keeping anything needs an account now, so "find
                              things to add" led to a bookmark that opened the
                              sign-in dialog anyway — a loop that named its
                              precondition at the last step. Say it here.
                            */}
                            <p className="font-medium">{t('lists.sign_in_to_keep')}</p>
                            <p className="mt-1 text-sm text-ink-soft">{t('lists.sign_in_hint')}</p>
                            <SignInLink
                                hint={t('lists.sign_in_hint')}
                                className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                            >
                                {t('nav.sign_in')}
                            </SignInLink>
                        </>
                    ) : (
                        <>
                            <p className="font-medium">{t('lists.empty')}</p>
                            <p className="mt-1 text-sm text-ink-soft">{t('lists.empty_hint')}</p>
                            <Link
                                href={`/${market.key}/search`}
                                className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                            >
                                {t('lists.find_things')}
                            </Link>
                        </>
                    )}
                </div>
            ) : (
                <>
                    {filled.map((section) => (
                        <section key={section.key} id={`section-${section.key}`} aria-labelledby={`heading-${section.key}`} className={sectionClass(section.key)}>
                            <SectionHeading id={`heading-${section.key}`} label={section.label} count={section.lists.length} hint={section.hint} icon={sectionIcons[section.key]} />
                            <ul className="mt-3 divide-y divide-line rounded-card border border-line bg-card">
                                {section.lists.map((list) => (
                                    <li key={list.id}>
                                        <ListCard list={list} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                    {savedCoves.length > 0 && (
                        <section id="section-saved" aria-labelledby="heading-saved" className={sectionClass('saved')}>
                            <SectionHeading
                                id="heading-saved"
                                label={t('lists.section_saved')}
                                count={savedCoves.length}
                                hint={t('saved_coves.subtitle')}
                            />
                            <SavedCoves coves={savedCoves} />
                        </section>
                    )}
                </>
            )}


            {/*
              Under the lists rather than over them.

              Somebody who already has lists does not need to be told how they
              work, and putting the explanation above their own content makes
              the page about the instructions. Somebody who has none reaches it
              in a couple of lines because the empty state is short.
            */}
            <p className="mt-12 border-t border-line pt-6 text-sm text-ink-soft">
                <Link href={`/${market.key}/lists-help`} className="underline hover:text-ink">
                    {t('lists_help.link')}
                </Link>
            </p>
        </>
    )
}

/**
 * The mark of the kind each section holds, the same drawing a list's name
 * carries in a sentence (`ListName`) and its badge carries on a card, so a
 * kind looks the same everywhere it is shown with an icon. "For others" holds
 * lists about somebody (`for_someone`).
 */
const sectionIcons: Partial<Record<string, ToolKey>> = {
    mine: kindIcons.mine,
    shared: kindIcons.for_someone,
    group: kindIcons.group,
}

/**
 * A section's heading: its name, how many are in it, and the explanation
 * behind the info icon (the site standard since 2026-09-07). The count is
 * what tells somebody at a glance that their four lists are all here.
 */
function SectionHeading({ id, label, count, hint, icon }: { id: string; label: string; count: number; hint: string; icon?: ToolKey }) {
    const { n } = useTranslations()

    return (
        /* `flex-wrap` is what puts the opened note under the heading rather
           than beside it: see InfoTip. */
        <div className="flex flex-wrap items-center gap-1">
            <h2 id={id} className="flex items-center gap-1.5 text-lg font-semibold">
                {icon && <ToolIcon name={icon} className="h-5 w-5 text-accent" />}
                {label} <span className="font-normal text-ink-soft">({n(count)})</span>
            </h2>
            <InfoTip>{hint}</InfoTip>
        </div>
    )
}

/**
 * The Saved section: the Coves this person bookmarked, each with the way back
 * to it, "Make it my list" (a copy into a list of their own) and a way to let
 * go. Only drawn when there is at least one.
 */
function SavedCoves({ coves }: { coves: SavedCoveRow[] }) {
    const { t } = useTranslations()

    return (
        <ul className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {coves.map((cove) => (
                <li key={cove.id} className="flex flex-col rounded-card border border-line bg-card">
                    <Link href={cove.url} className="group block p-4">
                        <div className="aspect-[4/3] overflow-hidden rounded-lg bg-cream">
                            {cove.image && (
                                <img src={cove.image} alt="" loading="lazy" className="h-full w-full object-contain transition group-hover:scale-105" />
                            )}
                        </div>
                        <span className="mt-3 block text-2xs font-medium tracking-wide text-ink-soft uppercase">
                            {t(`home.cove_kind_${cove.kind}`)}
                        </span>
                        <span className="mt-1 block font-medium group-hover:text-accent">{cove.title}</span>
                    </Link>
                    <div className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3">
                        <button
                            type="button"
                            onClick={() => router.post(cove.copyUrl)}
                            className="text-sm font-medium text-accent-dark underline hover:text-ink"
                        >
                            {t('saved_coves.copy')}
                        </button>
                        <button
                            type="button"
                            onClick={() => router.delete(cove.saveUrl, { preserveScroll: true })}
                            className="text-sm text-ink-soft hover:text-danger"
                        >
                            {t('saved_coves.unsave')}
                        </button>
                    </div>
                </li>
            ))}
        </ul>
    )
}

/**
 * The add panel of a list page, over the overview, for one list.
 *
 * A native <dialog> through showModal(), as SignInDialog does: focus stays
 * inside, Escape closes it, and the page behind is inert. The panel opens at
 * once; adding or cancelling closes the dialog, and the server answers with a
 * toast naming the list (`onListPage={false}`), since the list is not on screen.
 */
/**
 * Share, as a popup over the overview (owner, 2026-09-27: "share button should
 * show popup interface"), rather than a trip to the list page. The part people
 * come for: is it shared, the link to copy or send, and switching sharing on.
 * Who may add, group options and handing a list over stay on the list page,
 * one link away ("Meer deelopties"). Switching on is the list page's own
 * PATCH; the page's props come back with the link and the popup stays open.
 */
function ShareListDialog({ list, onClose }: { list: ListSummary; onClose: () => void }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const ref = useRef<HTMLDialogElement>(null)
    const [busy, setBusy] = useState(false)

    useEffect(() => {
        const el = ref.current

        if (el !== null && !el.open) {
            el.showModal()
        }
    }, [])

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onClick={(e) => {
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            aria-label={`${t('lists.share')}: ${list.title}`}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[min(32rem,calc(100vw-2rem))] overflow-y-auto rounded-card border border-line bg-card p-6 backdrop:bg-ink/40"
        >
            <div className="flex items-start justify-between gap-3">
                <h2 className="text-lg font-semibold">{list.title}</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('nav.close')}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink"
                >
                    <ToolIcon name="close" className="h-4 w-4" />
                </button>
            </div>

            <p className="mt-2 text-sm font-medium">{list.shareUrl ? t('lists.sharing_on') : t('lists.sharing_off')}</p>

            {list.shareUrl ? (
                <div className="mt-3">
                    <ShareRow url={list.shareUrl} text={t('lists.share_text', { title: list.title })} />
                </div>
            ) : (
                <>
                    <p className="mt-1 text-sm text-ink-soft">{t('lists.share_hint')}</p>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true)
                            router.patch(
                                `/${market.key}/lists/${list.id}`,
                                { visibility: 'link' },
                                { preserveScroll: true, preserveState: true, onFinish: () => setBusy(false) },
                            )
                        }}
                        className="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                    >
                        {t('lists.enable_sharing')}
                    </button>
                </>
            )}

            <Link href={`${list.url}?panel=share`} className="mt-5 block text-sm font-medium text-accent-dark hover:text-ink">
                {t('lists.more_share_options')} →
            </Link>
        </dialog>
    )
}

function AddToListDialog({ list, onClose }: { list: ListSummary; onClose: () => void }) {
    const { market } = usePage<SharedProps>().props
    const ref = useRef<HTMLDialogElement>(null)

    useEffect(() => {
        const el = ref.current

        if (el !== null && !el.open) {
            el.showModal()
        }
    }, [])

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onClick={(e) => {
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            aria-label={list.title}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[min(36rem,calc(100vw-2rem))] overflow-y-auto rounded-card border border-line bg-card p-6 backdrop:bg-ink/40"
        >
            <h2 className="text-lg font-semibold">{list.title}</h2>
            <div className="mt-3">
                <AddProduct base={`/${market.key}`} listId={list.id} market={market} defaultOpen onListPage={false} onClose={onClose} />
            </div>
        </dialog>
    )
}
