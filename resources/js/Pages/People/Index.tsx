import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Button, { buttonClasses } from '../../Components/Button'
import InfoTip from '../../Components/InfoTip'
import type { ListKind } from '../../Components/ListKindBadge'
import ListName from '../../Components/ListName'
import Menu, { MenuItem } from '../../Components/Menu'
import { budgetLabel, DayMonth, InvitePerson, monthDay } from '../../Components/PersonParts'
import SignInLink from '../../Components/SignInLink'
import ToolIcon from '../../Components/ToolIcon'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
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

    const dateFormat = new Intl.DateTimeFormat(market.hrefLang, { day: 'numeric', month: 'long' })

    function when(days: number): string {
        if (days === 0) {
            return t('people.today')
        }

        return days === 1 ? t('people.tomorrow') : t('people.in_days', { count: days })
    }

    const field = 'mt-1 block w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal'

    return (
        <>
            <Head title={t('people.title')} />

            <header className="flex flex-wrap items-center gap-x-1 gap-y-3">
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{t('people.title')}</h1>
                {isSignedIn && <InfoTip>{t('people.intro_tip')}</InfoTip>}
                {/* The mirror of Mijn Coves' "Mijn mensen" button (owner, 2026-09-27). */}
                <Link href={`${base}/lists`} className={`ml-auto ${buttonClasses('secondary', 'md')}`}>
                    <ToolIcon name="wishlist" className="h-4 w-4" />
                    {t('lists.title')}
                </Link>
            </header>
            <p className="mt-2 text-ink-soft">{t('people.intro')}</p>

            {!isSignedIn ? (
                <div className="mt-8 rounded-card border border-line bg-card p-6">
                    <p className="text-ink-soft">{t('people.guest')}</p>
                    <SignInLink
                        hint={t('people.guest')}
                        className="mt-4 inline-flex min-h-11 items-center rounded-lg bg-accent px-5 py-2 font-medium text-white hover:bg-accent-dark sm:min-h-0"
                    >
                        {t('nav.sign_in')}
                    </SignInLink>
                </div>
            ) : (
                <>
                    {/*
                      The two ways to add somebody, side by side because they
                      answer the same question, "who is missing here", in two
                      ways: somebody only you see, or somebody on GiftCoves.
                    */}
                    <div className="mt-6 flex flex-wrap gap-2">
                        <Button
                            variant={adding === 'person' ? 'secondary' : 'primary'}
                            aria-expanded={adding === 'person'}
                            aria-controls="people-add"
                            onClick={() => setAdding(adding === 'person' ? null : 'person')}
                        >
                            {t('people.add_person')}
                        </Button>
                        <Button
                            variant="secondary"
                            aria-expanded={adding === 'invite'}
                            aria-controls="people-add"
                            onClick={() => setAdding(adding === 'invite' ? null : 'invite')}
                        >
                            {t('people.invite')}
                        </Button>
                    </div>

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
                        <p className="mt-8 rounded-card border border-line bg-card p-5 text-sm text-ink-soft">
                            {t('people.empty')}
                        </p>
                    ) : (
                        <ul className="mt-8 divide-y divide-line rounded-card border border-line bg-card">
                            {people.map((p) => (
                                <PersonRow
                                    key={p.key}
                                    person={p}
                                    base={base}
                                    when={when}
                                    dateLabel={(iso) => dateFormat.format(new Date(`${iso}T00:00:00`))}
                                    birthdayLabel={(md) => dateFormat.format(new Date(`2000-${md}T00:00:00`))}
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
 * this page at all, and "Hun lijsten (2)" behind a toggle hid them. What stays
 * behind "Details" is about the connection: what they see of yours, your note
 * of their birthday, removing them.
 */
function PersonRow({
    person,
    base,
    when,
    dateLabel,
    birthdayLabel,
}: {
    person: Person
    base: string
    when: (days: number) => string
    dateLabel: (iso: string) => string
    birthdayLabel: (md: string) => string
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const friend = person.friend
    const [open, setOpen] = useState(false)
    const [editing, setEditing] = useState(false)
    const [note, setNote] = useState({
        day: friend?.birthdayIsMine ? (friend.birthday?.slice(3) ?? '') : '',
        month: friend?.birthdayIsMine ? (friend.birthday?.slice(0, 2) ?? '') : '',
    })
    const [saving, setSaving] = useState(false)

    const link = 'inline-flex min-h-11 items-center rounded-lg border border-line px-2.5 py-1.5 sm:px-3 text-sm hover:border-ink sm:min-h-0'

    const summary = summaryOf(person, t, (cents) => formatPrice(cents, market))
    const invitable = person.invitable && person.personId !== null
    const [inviting, setInviting] = useState(false)
    const more = invitable || [person.urls.taste, person.urls.ask, person.urls.together].some((url) => url !== null)

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
                    {friend !== null && (
                        <span className="rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                            {t('people.on_giftcoves')}
                        </span>
                    )}
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
                            {when(person.next.days)}
                        </span>
                    </span>
                )}
                {summary !== '' && <span className="mt-0.5 block text-sm text-ink-soft">{summary}</span>}
            </span>
        </>
    )

    return (
        <li className="p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                {person.urls.person !== null ? (
                    <Link href={person.urls.person} className="group flex min-w-0 flex-1 items-center gap-3 rounded-lg">
                        {main}
                    </Link>
                ) : (
                    <div className="flex min-w-0 flex-1 items-center gap-3">{main}</div>
                )}

                <div className="flex flex-wrap items-center gap-1.5 sm:justify-end sm:gap-2">
                    {person.urls.finder !== null && (
                        <Link href={person.urls.finder} className={`${link} border-accent bg-accent text-white hover:bg-accent-dark`}>
                            {t('people.find_gift')}
                        </Link>
                    )}
                    {more && (
                        <Menu
                            label={t('people.more_label', { name: person.name })}
                            button={
                                <>
                                    <ToolIcon name="more" className="h-4 w-4 shrink-0" />
                                    <span>{t('people.more')}</span>
                                    <ToolIcon name="chevron" className="h-3.5 w-3.5 shrink-0 text-ink-soft" />
                                </>
                            }
                            buttonClassName={`${link} gap-1.5`}
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
                    {friend !== null && (
                        <button
                            type="button"
                            aria-expanded={open}
                            onClick={() => setOpen((v) => !v)}
                            className={`${link} text-ink-soft`}
                        >
                            {t('people.details')}
                            <ToolIcon name="chevron" className={`ml-1 h-4 w-4 transition ${open ? 'rotate-180' : ''}`} />
                        </button>
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

            {friend !== null && open && (
                <div className="mt-4 space-y-4 border-t border-line pt-4 sm:ml-13">
                    {friend.theySee.length > 0 && (
                        <div>
                            <p className="text-xs font-medium text-ink-soft">
                                <span aria-hidden className="mr-1">
                                    →
                                </span>
                                {t('friends.they_see', { name: person.name })}
                            </p>
                            <ul className="mt-1.5 flex flex-wrap gap-2">
                                {friend.theySee.map((list) => (
                                    <li key={list.url}>
                                        <a
                                            href={list.url}
                                            className="inline-block rounded-lg border border-dashed border-line px-3 py-1.5 text-sm text-ink-soft hover:border-ink hover:text-ink"
                                        >
                                            {list.title}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {friend.birthday !== null && (
                        <p className="flex flex-wrap items-center text-sm text-ink-soft">
                            <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />
                            {birthdayLabel(friend.birthday)}
                            {friend.birthdayIsMine && <span className="ml-1 text-xs">({t('friends.your_note')})</span>}
                        </p>
                    )}

                    <div className="flex items-center gap-4 text-xs text-ink-soft">
                        <button type="button" onClick={() => setEditing((v) => !v)} className="underline hover:text-ink">
                            {friend.birthday === null ? t('friends.add_birthday') : t('friends.edit_birthday')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                // It removes the connection for both people, which
                                // is not what a single stray tap should mean. A
                                // saved person linked to them stays.
                                if (!window.confirm(t('friends.remove_confirm', { name: person.name }))) {
                                    return
                                }

                                router.delete(`${base}/friends/${friend.id}`, { preserveScroll: true })
                            }}
                            className="underline hover:text-ink"
                        >
                            {t('friends.remove')}
                        </button>
                    </div>

                    {editing && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault()
                                router.patch(
                                    `${base}/friends/${friend.id}`,
                                    { birthday: monthDay(note.month, note.day) },
                                    { preserveScroll: true, onSuccess: () => setEditing(false) },
                                )
                            }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <label className="text-xs font-medium">
                                {t('friends.their_birthday')}
                                <DayMonth
                                    day={note.day}
                                    month={note.month}
                                    onDay={(v) => setNote((n) => ({ ...n, day: v }))}
                                    onMonth={(v) => setNote((n) => ({ ...n, month: v }))}
                                />
                            </label>
                            <Button type="submit">{t('friends.save')}</Button>
                        </form>
                    )}
                </div>
            )}
        </li>
    )
}

/**
 * Your own side: what your friends see of you. Read once, so it sits last.
 * The one place a year may be given, and only about yourself.
 */
function Settings({ settings, base }: { settings: { birthday: string | null; friendsSeeBirthday: boolean }; base: string }) {
    const { t } = useTranslations()
    const prefs = useForm({
        birthday: settings.birthday ?? '',
        friends_see_birthday: settings.friendsSeeBirthday,
    })

    return (
        <section className="mt-10 rounded-card border border-line bg-card p-5">
            <h2 className="font-medium">{t('friends.settings_title')}</h2>

            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    // "" is not a date and not null either; the server wants one or the other.
                    prefs.transform((data) => ({ ...data, birthday: data.birthday === '' ? null : data.birthday }))
                    prefs.patch(`${base}/friends/settings`, { preserveScroll: true })
                }}
                className="mt-4 flex flex-wrap items-end gap-x-6 gap-y-4"
            >
                <label className="block text-xs font-medium">
                    {t('friends.my_birthday')}
                    <input
                        type="date"
                        value={prefs.data.birthday}
                        onChange={(e) => prefs.setData('birthday', e.target.value)}
                        className="mt-1 block rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                    />
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
