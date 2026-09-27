import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Badge from '../../Components/Badge'
import Button, { buttonClasses, fieldClasses, rowActionClasses } from '../../Components/Button'
import InfoTip from '../../Components/InfoTip'
import type { ListKind } from '../../Components/ListKindBadge'
import ListName from '../../Components/ListName'
import EmptyState from '../../Components/EmptyState'
import Menu, { MenuItem, MenuSeparator, MoreButtonContent } from '../../Components/Menu'
import { useConfirm } from '../../Components/Modal'
import PageHeader from '../../Components/PageHeader'
import { budgetLabel, DayMonth, InvitePerson, monthDay } from '../../Components/PersonParts'
import SignInLink from '../../Components/SignInLink'
import ToolIcon from '../../Components/ToolIcon'
import type { Cents, SharedProps } from '../../types'
import { formatBudget, formatCountdown, formatDay } from '../../types'
import { useTranslations } from '../../useTranslations'

interface ListLink {
    title: string
    url: string
    /** Set on their lists, for the list-name style; yours carry none. */
    kind?: ListKind
}

/** What a friend on GiftCoves shares with you. Absent on a saved person nobody linked. */
interface FriendPart {
    id: number
    /** `MM-DD`: theirs if they show it, otherwise the note you wrote. Never a year. */
    birthday: string | null
    birthdayIsMine: boolean
    /** Their lists they shared with you or sent you the link to. */
    lists: ListLink[]
    /** Yours they can see. */
    theySee: ListLink[]
    /** How many things you do together: their lists for others, group gifts, Secret Santas. */
    inCommon: number
    /** The next Secret Santa you are both in. Membership only, never the draw. */
    santa: { title: string; date: string | null } | null
}

interface Person {
    key: string
    name: string
    /** In the reader's language when it is one of the closed vocabulary; as typed otherwise. */
    relationship: string | null
    /** The saved person's id, or null for a friend nobody saved. */
    personId: string | null
    next: {
        date: string
        days: number
        kind: 'birthday' | 'occasion'
        /** The list's title, for an occasion. */
        title: string | null
    } | null
    friend: FriendPart | null
    /** What you saved about them; null for a friend nobody saved. */
    known: { interests: string[]; budgetMin: Cents | null; budgetMax: Cents | null } | null
    /** Lists you are making for them. */
    listsForThem: number
    /** `MM-DD`, never a year: the date the row shows. */
    birthday: string | null
    /** A saved person with no account behind them: "Nodig uit op GiftCoves" is offered. */
    invitable: boolean
    urls: { person: string | null; finder: string | null; taste: string | null; ask: string | null; together: string | null }
}

/** How many interests the line under a name names before it says "+N". */
const INTERESTS_SHOWN = 3

interface Props {
    isSignedIn: boolean
    people: Person[]
    settings: { birthday: string | null; friendsSeeBirthday: boolean } | null
    relationships: { value: string; label: string }[]
}

/**
 * "My people": everybody you buy for, on one list.
 *
 * Saved people (yours alone) and friends on GiftCoves were two pages until
 * 2026-09-26, and one of them had no page at all. A visitor needs no difference
 * between them, so this is one list sorted by the nearest date, with a small
 * mark on the people who are on GiftCoves themselves. See
 * docs/features/my-people.md.
 *
 * One column, full width: there is nothing for a column beside the list, and
 * the owner's rule is that content then takes the width.
 */
export default function PeopleIndex({ isSignedIn, people, settings, relationships }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    // Which of the two "add" forms is open. One at a time: two forms open at
    // once is a page of fields for a job that takes one.
    const [adding, setAdding] = useState<'person' | 'invite' | null>(null)

    const person = useForm({ name: '', relationship: '', day: '', month: '' })
    const invite = useForm({ email: '', day: '', month: '' })

    const field = fieldClasses()

    return (
        <>
            <Head title={t('people.title')} />

            {/*
              The same structure as Mijn Coves (owner, 2026-09-27): the title
              on the left, the buttons on the right, the way to the other page
              first and this page's own actions after it.
            */}
            <PageHeader
                title={t('people.title')}
                note={isSignedIn ? t('people.intro_tip') : undefined}
                actions={
                    <>
                        <Link href={`${base}/lists`} className={buttonClasses('secondary', 'md')}>
                            <ToolIcon name="wishlist" className="h-4 w-4" />
                            {t('lists.title')}
                        </Link>
                        {isSignedIn && (
                            <>
                                <Button
                                    variant={adding === 'person' ? 'secondary' : 'primary'}
                                    aria-expanded={adding === 'person'}
                                    aria-controls="people-add"
                                    onClick={() => setAdding(adding === 'person' ? null : 'person')}
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                                        {t('people.add_person')}
                                    </span>
                                </Button>
                                <Button
                                    variant="secondary"
                                    aria-expanded={adding === 'invite'}
                                    aria-controls="people-add"
                                    onClick={() => setAdding(adding === 'invite' ? null : 'invite')}
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <ToolIcon name="friends" className="h-4 w-4 shrink-0" />
                                        {t('people.invite')}
                                    </span>
                                </Button>
                            </>
                        )}
                    </>
                }
            >
                <p className="mt-2 text-ink-soft">{t('people.intro')}</p>
            </PageHeader>

            {!isSignedIn ? (
                <EmptyState
                    className="mt-8"
                    icon="people"
                    action={
                        <SignInLink hint={t('people.guest')} className={buttonClasses('primary', 'md')}>
                            {t('nav.sign_in')}
                        </SignInLink>
                    }
                >
                    {t('people.guest')}
                </EmptyState>
            ) : (
                <>
                    {/*
                      The two ways to add somebody sit in the header since
                      2026-09-27, after "Mijn Coves": somebody only you see, or
                      somebody on GiftCoves. Their forms open here.
                    */}
                    {adding === 'person' && (
                        <form
                            id="people-add"
                            onSubmit={(e) => {
                                e.preventDefault()
                                // The same endpoint and rules as every other place a
                                // person is saved. A birthday is stored under the
                                // placeholder year 2000 (Recipient::BIRTHDAY_YEAR).
                                person.transform((data) => {
                                    const md = monthDay(data.month, data.day)

                                    return {
                                        name: data.name.trim(),
                                        relationship: data.relationship === '' ? null : data.relationship,
                                        ...(md === null ? {} : { birthday: `2000-${md}` }),
                                    }
                                })
                                person.post(`${base}/recipients`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        person.reset()
                                        setAdding(null)
                                    },
                                })
                            }}
                            className="mt-4 grid gap-4 rounded-card border border-line bg-card p-5 sm:grid-cols-3"
                        >
                            <p className="flex flex-wrap items-center text-sm font-medium sm:col-span-3">
                                {t('people.add_person')}
                                <InfoTip>{t('people.add_person_tip')}</InfoTip>
                            </p>
                            <label className="block text-xs font-medium">
                                {t('people.name')}
                                <input
                                    required
                                    maxLength={80}
                                    value={person.data.name}
                                    onChange={(e) => person.setData('name', e.target.value)}
                                    className={field}
                                />
                            </label>
                            <label className="block text-xs font-medium">
                                {t('people.relationship')}
                                <select
                                    value={person.data.relationship}
                                    onChange={(e) => person.setData('relationship', e.target.value)}
                                    className={field}
                                >
                                    <option value="">{t('people.relationship_none')}</option>
                                    {relationships.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-xs font-medium">
                                {t('people.birthday_optional')}
                                <DayMonth
                                    day={person.data.day}
                                    month={person.data.month}
                                    onDay={(v) => person.setData('day', v)}
                                    onMonth={(v) => person.setData('month', v)}
                                />
                            </label>
                            <div className="sm:col-span-3">
                                <Button type="submit" busy={person.processing}>
                                    {t('people.add')}
                                </Button>
                                {person.errors.name && (
                                    <p className="mt-2 text-sm text-danger" role="alert">
                                        {person.errors.name}
                                    </p>
                                )}
                            </div>
                        </form>
                    )}

                    {adding === 'invite' && (
                        <form
                            id="people-add"
                            onSubmit={(e) => {
                                e.preventDefault()
                                // The friends' own endpoint: it answers the same
                                // whether or not the address has an account, see
                                // App\Services\Social\FriendInvites.
                                invite.transform((data) => ({
                                    email: data.email,
                                    birthday: monthDay(data.month, data.day),
                                }))
                                invite.post(`${base}/friends`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        invite.reset()
                                        setAdding(null)
                                    },
                                })
                            }}
                            className="mt-4 grid gap-4 rounded-card border border-line bg-card p-5 sm:grid-cols-3"
                        >
                            <p className="flex flex-wrap items-center text-sm font-medium sm:col-span-3">
                                {t('people.invite')}
                                <InfoTip>{t('people.invite_tip')}</InfoTip>
                            </p>
                            <label className="block text-xs font-medium sm:col-span-2">
                                {t('friends.email')}
                                <input
                                    type="email"
                                    required
                                    value={invite.data.email}
                                    onChange={(e) => invite.setData('email', e.target.value)}
                                    className={field}
                                />
                            </label>
                            <label className="block text-xs font-medium">
                                {t('friends.their_birthday_optional')}
                                <DayMonth
                                    day={invite.data.day}
                                    month={invite.data.month}
                                    onDay={(v) => invite.setData('day', v)}
                                    onMonth={(v) => invite.setData('month', v)}
                                />
                            </label>
                            <div className="sm:col-span-3">
                                <Button type="submit" busy={invite.processing}>
                                    {t('people.invite_button')}
                                </Button>
                                {invite.errors.email && (
                                    <p className="mt-2 text-sm text-danger" role="alert">
                                        {invite.errors.email}
                                    </p>
                                )}
                            </div>
                        </form>
                    )}

                    {people.length === 0 ? (
                        <EmptyState className="mt-8" icon="people">
                            {t('people.empty')}
                        </EmptyState>
                    ) : (
                        <ul className="mt-8 divide-y divide-line rounded-card border border-line bg-card">
                            {people.map((p) => (
                                <PersonRow
                                    key={p.key}
                                    person={p}
                                    base={base}
                                />
                            ))}
                        </ul>
                    )}

                    {settings !== null && <Settings settings={settings} base={base} />}
                </>
            )}
        </>
    )
}

/**
 * The one line of what you know about somebody: "Koken, Tuinieren, Lezen +2 ·
 * tot €50 · 2 lijsten voor hen". A part that is empty is left out rather than
 * shown as "geen budget": the owner's rule is no empty blocks, and a line of
 * blanks is one.
 */
function summaryOf(
    person: Person,
    t: (key: string, replacements?: Record<string, string | number>) => string,
    money: (cents: Cents) => string,
): string {
    const parts: string[] = []
    const known = person.known

    if (known !== null && known.interests.length > 0) {
        const shown = known.interests.slice(0, INTERESTS_SHOWN).join(', ')
        const rest = known.interests.length - INTERESTS_SHOWN
        parts.push(rest > 0 ? `${shown} +${rest}` : shown)
    }

    const budget = known === null ? null : budgetLabel(known.budgetMin, known.budgetMax, t, money)

    if (budget !== null) {
        parts.push(budget)
    }

    if (person.listsForThem > 0) {
        parts.push(
            person.listsForThem === 1 ? t('people.lists_for_them_one') : t('people.lists_for_them', { count: person.listsForThem }),
        )
    }

    // "Samen met": listed on their page; a count is enough here.
    if (person.friend !== null && person.friend.inCommon > 0) {
        parts.push(t('people.in_common', { count: person.friend.inCommon }))
    }

    return parts.join(' · ')
}

/**
 * One person: who, when, what you know, and the way to a gift.
 *
 * One button, Cadeau vinden, because it is what people come to this page for;
 * the other tools for the person (This or that, Ask others, This or that
 * together) sit in a Meer menu, the same menu the list page uses. Four equal
 * buttons on every row made a page of buttons where the names should lead
 * (owner, 2026-09-27).
 *
 * The name, and the whole left part of the row, is the way to the person's
 * page: there was a "Hun pagina" button for that, and a button that repeats
 * what pressing the name does is one more thing to read on every row.
 *
 * A friend's lists show without a click. They are the reason a friend is on
 * this page at all, and "Hun lijsten (2)" behind a toggle hid them. What is
 * about the connection (what they see of yours, your note of their birthday,
 * removing them) is in the ⋯ since 2026-09-27; it was a "Details" toggle.
 */
function PersonRow({ person, base }: { person: Person; base: string }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const dateLabel = (iso: string) => formatDay(iso, market)
    const friend = person.friend
    /*
     * What the ⋯ opened under the row: your note of their birthday, or the
     * lists of yours they can see. These sat behind a "Details" toggle beside
     * the ⋯ until 2026-09-27, a second "more" on the same row; folded into the
     * ⋯, where the rest of what you do to a person already was.
     */
    const [open, setOpen] = useState<'birthday' | 'theySee' | null>(null)
    const [confirm, confirmDialog] = useConfirm()
    const [note, setNote] = useState({
        day: friend?.birthdayIsMine ? (friend.birthday?.slice(3) ?? '') : '',
        month: friend?.birthdayIsMine ? (friend.birthday?.slice(0, 2) ?? '') : '',
    })
    const [saving, setSaving] = useState(false)

    const link = 'inline-flex min-h-11 items-center rounded-lg border border-line px-2.5 py-1.5 sm:px-3 text-sm hover:border-ink sm:min-h-0'
    // Mijn Coves' row action, outlined: the header's "Iemand toevoegen" is this page's one filled button.
    const rowAction = rowActionClasses()

    const summary = summaryOf(person, t, (cents) => formatBudget(cents, market))
    const invitable = person.invitable && person.personId !== null
    // A saved person's two icons sit in the row's top right corner on a phone;
    // a friend nobody saved has a wide "Bewaar wat je over … weet" button
    // instead, which keeps its own line.
    const iconsOnTop = person.urls.finder !== null && !(person.personId === null && person.friend !== null)
    const [inviting, setInviting] = useState(false)
    const more = friend !== null || invitable || [person.urls.taste, person.urls.ask, person.urls.together].some((url) => url !== null)

    const main = (
        <>
            {/* The initial, so a long list is scannable by shape as well as by name. */}
            <span
                aria-hidden
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent/10 font-semibold text-accent"
            >
                {person.name.slice(0, 1).toUpperCase()}
            </span>
            <span className="block min-w-0">
                <span className="flex flex-wrap items-center gap-x-2">
                    <span className={`font-medium ${person.urls.person !== null ? 'group-hover:underline' : ''}`}>{person.name}</span>
                    {person.relationship !== null && person.relationship.toLowerCase() !== person.name.toLowerCase() && (
                        <span className="text-sm text-ink-soft">{person.relationship}</span>
                    )}
                    {friend !== null && <Badge tone="accent">{t('people.on_giftcoves')}</Badge>}
                    {/*
                      You are both in a Secret Santa (owner, 2026-09-27): the
                      group's name and, while it is ahead, its day. Who drew
                      whom is never on this page.
                    */}
                    {friend?.santa && (
                        <span
                            className="inline-flex max-w-full items-center gap-1 rounded-full border border-line px-2 py-0.5 text-xs text-ink-soft"
                            title={t('people.santa_mark_tip', { title: friend.santa.title })}
                        >
                            <ToolIcon name="santa" className="h-3.5 w-3.5 shrink-0 text-accent" />
                            <span className="truncate">{friend.santa.title}</span>
                            {friend.santa.date && <span className="shrink-0">· {dateLabel(friend.santa.date)}</span>}
                        </span>
                    )}
                </span>
                {person.next !== null && (
                    <span className="mt-0.5 flex flex-wrap items-center text-sm text-ink-soft">
                        {person.next.kind === 'birthday' ? (
                            <>
                                <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />
                                {t('people.birthday')}
                            </>
                        ) : (
                            person.next.title
                        )}
                        {' · '}
                        {dateLabel(person.next.date)}
                        {/* Non-breaking: in a flex row a plain space at the edge of an item is dropped ("oktober ·over"). */}
                        {' · '}
                        <span className={person.next.days <= 14 ? 'font-medium text-ink' : ''}>
                            {formatCountdown(person.next.days, t)}
                        </span>
                    </span>
                )}
                {summary !== '' && <span className="mt-0.5 block text-sm text-ink-soft">{summary}</span>}
            </span>
        </>
    )

    return (
        <li className="p-4">
            <div className="relative flex flex-col gap-3 sm:flex-row sm:items-center">
                {person.urls.person !== null ? (
                    <Link href={person.urls.person} className={`group flex min-w-0 flex-1 items-center gap-3 rounded-lg ${iconsOnTop ? 'pr-24 sm:pr-0' : ''}`}>
                        {main}
                    </Link>
                ) : (
                    <div className="flex min-w-0 flex-1 items-center gap-3">{main}</div>
                )}

                <div className={iconsOnTop ? 'absolute top-0 right-0 flex items-center gap-1.5 sm:static sm:flex-wrap sm:justify-end sm:gap-2' : 'flex flex-wrap items-center gap-1.5 sm:justify-end sm:gap-2'}>
                    {person.urls.finder !== null && (
                        <Link
                            href={person.urls.finder}
                            aria-label={t('people.find_gift')}
                            title={t('people.find_gift')}
                            className={rowAction}
                        >
                            <ToolIcon name="whisperer" className="h-4 w-4 shrink-0" />
                            <span className="hidden sm:inline">{t('people.find_gift')}</span>
                        </Link>
                    )}
                    {more && (
                        <Menu
                            label={t('people.more_label', { name: person.name })}
                            button={<MoreButtonContent word={t('people.more')} />}
                            buttonClassName={rowAction}
                        >
                            {(close) => (
                                <>
                                    {person.urls.taste !== null && (
                                        <MenuItem href={person.urls.taste} icon={<ToolIcon name="taste" className="h-4 w-4" />}>
                                            {t('people.taste')}
                                        </MenuItem>
                                    )}
                                    {person.urls.ask !== null && (
                                        <MenuItem href={person.urls.ask} icon={<ToolIcon name="board" className="h-4 w-4" />}>
                                            {t('people.ask')}
                                        </MenuItem>
                                    )}
                                    {person.urls.together !== null && (
                                        <MenuItem href={person.urls.together} icon={<ToolIcon name="taste" className="h-4 w-4" />}>
                                            {t('people.together')}
                                        </MenuItem>
                                    )}
                                    {/*
                                      Somebody you saved who is not on GiftCoves:
                                      the invitation names them, so when they join
                                      they stay this one row (2026-09-27).
                                    */}
                                    {invitable && (
                                        <MenuItem
                                            onSelect={() => {
                                                close()
                                                setInviting(true)
                                            }}
                                            icon={<ToolIcon name="friends" className="h-4 w-4" />}
                                        >
                                            {t('people.invite')}
                                        </MenuItem>
                                    )}
                                    {friend !== null && (
                                        <>
                                            <MenuItem
                                                onSelect={() => {
                                                    close()
                                                    setOpen('birthday')
                                                }}
                                                icon={<ToolIcon name="cake" className="h-4 w-4" />}
                                            >
                                                {friend.birthday === null ? t('friends.add_birthday') : t('friends.edit_birthday')}
                                            </MenuItem>
                                            {friend.theySee.length > 0 && (
                                                <MenuItem
                                                    onSelect={() => {
                                                        close()
                                                        setOpen('theySee')
                                                    }}
                                                    icon={<ToolIcon name="wishlist" className="h-4 w-4" />}
                                                >
                                                    {t('friends.they_see', { name: person.name })} ({friend.theySee.length})
                                                </MenuItem>
                                            )}
                                            <MenuSeparator />
                                            {/*
                                              It removes the connection for both
                                              people, which is not what a single
                                              stray tap should mean, so it asks.
                                              A saved person linked to them stays.
                                            */}
                                            <MenuItem
                                                danger
                                                onSelect={async () => {
                                                    close()

                                                    if (
                                                        await confirm({
                                                            message: t('friends.remove_confirm', { name: person.name }),
                                                            confirmLabel: t('friends.unfriend'),
                                                            danger: true,
                                                        })
                                                    ) {
                                                        router.delete(`${base}/friends/${friend.id}`, { preserveScroll: true })
                                                    }
                                                }}
                                                icon={<ToolIcon name="friends" className="h-4 w-4" />}
                                            >
                                                {t('friends.unfriend')}
                                            </MenuItem>
                                        </>
                                    )}
                                </>
                            )}
                        </Menu>
                    )}
                    {/*
                      A friend nobody saved yet: the way to the rest. Saving
                      makes a person linked to their account, so what they say
                      about themselves on their own link outranks your guess.
                    */}
                    {person.personId === null && friend !== null && (
                        <span className="flex flex-wrap items-center">
                            <Button
                                size="sm"
                                variant="secondary"
                                busy={saving}
                                onClick={() => {
                                    setSaving(true)
                                    router.post(
                                        `${base}/recipients`,
                                        { name: person.name, friend_id: friend.id },
                                        { preserveScroll: true, onFinish: () => setSaving(false) },
                                    )
                                }}
                            >
                                {t('people.save_known', { name: person.name })}
                            </Button>
                            <InfoTip>{t('people.save_known_tip')}</InfoTip>
                        </span>
                    )}
                </div>
            </div>

            {invitable && inviting && person.personId !== null && (
                <div className="sm:ml-13">
                    <InvitePerson
                        url={`${base}/friends`}
                        personId={person.personId}
                        name={person.name}
                        birthday={person.birthday}
                        onDone={() => setInviting(false)}
                    />
                </div>
            )}

            {friend !== null && friend.lists.length > 0 && (
                <div className="mt-3 sm:ml-13">
                    <p className="text-xs font-medium text-ink-soft">{t('friends.they_share', { name: person.name })}</p>
                    <ul className="mt-1.5 flex flex-wrap gap-2">
                        {friend.lists.map((list) => (
                            <li key={list.url}>
                                {/* A real anchor: a shared list is the sort of thing people open in a tab. */}
                                <a href={list.url} className={link}>
                                    <ListName name={list.title} kind={list.kind ?? null} />
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {friend !== null && open === 'theySee' && friend.theySee.length > 0 && (
                <div className="mt-4 border-t border-line pt-4 sm:ml-13">
                    {/*
                      Drawn like their lists above (2026-09-27): a list name is
                      a ListName wherever it appears.
                    */}
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-medium text-ink-soft">{t('friends.they_see', { name: person.name })}</p>
                        <button
                            type="button"
                            onClick={() => setOpen(null)}
                            aria-label={t('nav.close')}
                            className="flex h-8 w-8 items-center justify-center rounded-full text-ink-soft hover:bg-line/40 hover:text-ink"
                        >
                            <ToolIcon name="close" className="h-4 w-4" />
                        </button>
                    </div>
                    <ul className="mt-1.5 flex flex-wrap gap-2">
                        {friend.theySee.map((list) => (
                            <li key={list.url}>
                                <a href={list.url} className={link}>
                                    <ListName name={list.title} kind={list.kind ?? null} />
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {friend !== null && open === 'birthday' && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        router.patch(
                            `${base}/friends/${friend.id}`,
                            { birthday: monthDay(note.month, note.day) },
                            { preserveScroll: true, onSuccess: () => setOpen(null) },
                        )
                    }}
                    className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4 sm:ml-13"
                >
                    <label className="text-xs font-medium">
                        {t('friends.their_birthday')}
                        {/* Whose date it is: theirs as they show it, or your own note. */}
                        {friend.birthday !== null && friend.birthdayIsMine && (
                            <span className="ml-1 font-normal text-ink-soft">({t('friends.your_note')})</span>
                        )}
                        <DayMonth
                            day={note.day}
                            month={note.month}
                            onDay={(v) => setNote((n) => ({ ...n, day: v }))}
                            onMonth={(v) => setNote((n) => ({ ...n, month: v }))}
                        />
                    </label>
                    <Button type="submit">{t('friends.save')}</Button>
                    <Button type="button" variant="secondary" onClick={() => setOpen(null)}>
                        {t('people.cancel')}
                    </Button>
                </form>
            )}
            {confirmDialog}
        </li>
    )
}

/**
 * Your own side: what your friends see of you. Read once, so it sits last.
 *
 * Day and month, like every other birthday on the site (2026-09-27). It asked a
 * full date with a year until then, the one place that did, and friends only
 * ever saw the day and the month of it.
 */
function Settings({ settings, base }: { settings: { birthday: string | null; friendsSeeBirthday: boolean }; base: string }) {
    const { t } = useTranslations()
    const prefs = useForm({
        month: settings.birthday?.slice(0, 2) ?? '',
        day: settings.birthday?.slice(3) ?? '',
        friends_see_birthday: settings.friendsSeeBirthday,
    })

    return (
        <section className="mt-10 rounded-card border border-line bg-card p-5">
            <h2 className="font-medium">{t('friends.settings_title')}</h2>

            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    // `MM-DD`, or null while either half is empty.
                    prefs.transform((data) => ({
                        birthday: monthDay(data.month, data.day),
                        friends_see_birthday: data.friends_see_birthday,
                    }))
                    prefs.patch(`${base}/friends/settings`, { preserveScroll: true })
                }}
                className="mt-4 flex flex-wrap items-end gap-x-6 gap-y-4"
            >
                <label className="block text-xs font-medium">
                    {t('friends.my_birthday')}
                    <DayMonth
                        day={prefs.data.day}
                        month={prefs.data.month}
                        onDay={(v) => prefs.setData('day', v)}
                        onMonth={(v) => prefs.setData('month', v)}
                    />
                    {(prefs.errors as Record<string, string>).birthday && (
                        <span className="mt-1 block text-danger" role="alert">
                            {(prefs.errors as Record<string, string>).birthday}
                        </span>
                    )}
                </label>

                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={prefs.data.friends_see_birthday}
                        onChange={(e) => prefs.setData('friends_see_birthday', e.target.checked)}
                    />
                    {t('friends.see_birthday')}
                    <InfoTip>{t('friends.see_birthday_hint')}</InfoTip>
                </label>

                <Button type="submit" busy={prefs.processing}>
                    {t('friends.save')}
                </Button>
            </form>
        </section>
    )
}
