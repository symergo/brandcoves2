import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { kindIcons } from '../../Components/ListKindBadge'
import ToolIcon, { type ToolKey } from '../../Components/ToolIcon'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import SignInLink from '../../Components/SignInLink'
import ListWizard, { hasListDraft, type WizardOffer } from '../../Components/ListWizard'
import InfoTip from '../../Components/InfoTip'
import NewListButton from '../../Components/NewListButton'
import { buttonClasses, rowActionClasses } from '../../Components/Button'
import EmptyState from '../../Components/EmptyState'
import ListRow, { ListRowMeta, ListRowTitle, pictureBox } from '../../Components/ListRow'
import ListSummaryRow, { type ListSummary as RowSummary } from '../../Components/ListSummaryRow'
import Menu, { MenuItem, MoreButtonContent } from '../../Components/Menu'
import PageHeader from '../../Components/PageHeader'

/**
 * A row of Mijn Coves (`ListSummaryRow`), plus the section of the page it
 * goes under, which the server decides.
 */
interface ListSummary extends RowSummary {
    section: Exclude<ListsView, 'saved'>
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

            {/*
              "Maak een Cove" is the page's one action; Mijn mensen, the other
              half of "who and what", sits before it as a secondary button
              (owner, 2026-09-27), and Mijn mensen has the mirror of it. A
              "find things to add" button stood here until 2026-09-06: the
              empty state offers that link at the moment it is the only thing
              to do, and two buttons for one intention made the header compete
              with the page under it. The button is the home page's
              `NewListButton`, which opens the same `ListWizard` as the Gift
              Cove (one-step-list.md); signed out it is the sign-in, which
              remembers the answer and replays it on return.
            */}
            <PageHeader
                title={heading}
                actions={
                    <>
                        <Link
                            href={`/${market.key}/people`}
                            className={buttonClasses('secondary', 'md')}
                            aria-label={t('people.title')}
                            title={t('people.title')}
                        >
                            <ToolIcon name="people" className="h-4 w-4" />
                            <span className="hidden sm:inline">{t('people.title')}</span>
                        </Link>
                        <NewListButton open={creating} onToggle={() => setCreating((v) => !v)} controls="new-list-wizard" />
                    </>
                }
            />


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
                        className="mt-2 inline-block text-sm text-accent-dark underline"
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
                !isSignedIn ? (
                    /*
                      Keeping anything needs an account now, so "find things to
                      add" led to a bookmark that opened the sign-in dialog
                      anyway — a loop that named its precondition at the last
                      step. Say it here.
                    */
                    <EmptyState
                        className="mt-10"
                        title={t('lists.sign_in_to_keep')}
                        action={
                            <SignInLink hint={t('lists.sign_in_hint')} className={buttonClasses('primary', 'md')}>
                                {t('nav.sign_in')}
                            </SignInLink>
                        }
                    >
                        {t('lists.sign_in_hint')}
                    </EmptyState>
                ) : (
                    <EmptyState
                        className="mt-10"
                        icon="wishlist"
                        title={t('lists.empty')}
                        action={
                            <Link href={`/${market.key}/search`} className={buttonClasses('primary', 'md')}>
                                {t('lists.find_things')}
                            </Link>
                        }
                    >
                        {t('lists.empty_hint')}
                    </EmptyState>
                )
            ) : (
                <>
                    {filled.map((section) => (
                        <section key={section.key} id={`section-${section.key}`} aria-labelledby={`heading-${section.key}`} className={sectionClass(section.key)}>
                            <SectionHeading id={`heading-${section.key}`} label={section.label} count={section.lists.length} hint={section.hint} icon={sectionIcons[section.key]} />
                            <ul className="mt-3 divide-y divide-line rounded-card border border-line bg-card">
                                {section.lists.map((list) => (
                                    <li key={list.id}>
                                        <ListSummaryRow list={list} friends={friends} />
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
 * The Saved section: the Coves this person bookmarked (a Daily, a guide, a
 * list somebody published), each with the way back to it, "Make it my list"
 * (a copy into a list of their own) and a way to let go. Only drawn when
 * there is at least one.
 *
 * Rows like every other section since 2026-09-27 (owner: "when saving a daily
 * cove to my coves, the layout is different then the other lists"). They were
 * picture cards three to a row, the only section of the page that was not a
 * list of rows. The Cove's picture is the row's thumbnail, its kind the line
 * under the name; "Make it my list" is the row's action and letting go is in
 * the ⋯, in red, since it is the one that loses something.
 */
function SavedCoves({ coves }: { coves: SavedCoveRow[] }) {
    return (
        <ul className="mt-3 divide-y divide-line rounded-card border border-line bg-card">
            {coves.map((cove) => (
                <li key={cove.id}>
                    <SavedCoveListRow cove={cove} />
                </li>
            ))}
        </ul>
    )
}

function SavedCoveListRow({ cove }: { cove: SavedCoveRow }) {
    const { t } = useTranslations()
    const rowAction = rowActionClasses()

    return (
        <>
            <ListRow
                href={cove.url}
                slots={2}
                thumb={
                    cove.image ? (
                        <img src={cove.image} alt="" loading="lazy" className={pictureBox} />
                    ) : (
                        <span aria-hidden className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-accent">
                            <ToolIcon name="gift" className="h-7 w-7" duo />
                        </span>
                    )
                }
                actions={
                    <>
                        <button
                            type="button"
                            onClick={() => router.post(cove.copyUrl)}
                            aria-label={t('saved_coves.copy')}
                            title={t('saved_coves.copy')}
                            className={rowAction}
                        >
                            <ToolIcon name="copy" className="h-4 w-4 shrink-0" />
                            <span className="hidden sm:inline">{t('saved_coves.copy')}</span>
                        </button>
                        <Menu
                            label={t('people.list_actions', { name: cove.title })}
                            button={<MoreButtonContent word={t('people.more')} />}
                            buttonClassName={rowAction}
                        >
                            {(close) => (
                                <MenuItem
                                    danger
                                    icon={<ToolIcon name="trash" className="h-4 w-4" />}
                                    onSelect={() => {
                                        // No "are you sure": saving it again is one press on the Cove.
                                        close()
                                        router.delete(cove.saveUrl, { preserveScroll: true })
                                    }}
                                >
                                    {t('saved_coves.unsave')}
                                </MenuItem>
                            )}
                        </Menu>
                    </>
                }
            >
                <ListRowTitle>{cove.title}</ListRowTitle>
                <ListRowMeta>{t(`home.cove_kind_${cove.kind}`)}</ListRowMeta>
            </ListRow>
        </>
    )
}
