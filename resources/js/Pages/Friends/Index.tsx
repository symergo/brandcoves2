import { Head, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface FriendList {
    title: string
    url: string
}

interface Friend {
    id: number
    name: string
    since: string | null
    /**
     * `MM-DD` — their published date if they show it, otherwise the one you
     * wrote down, otherwise null.
     *
     * Never a year, whichever it came from: what a friend needs is when to buy
     * something, and a year is somebody's age on a page other people read. The
     * only year on this site is your own, on your own settings.
     *
     * Which of the two it is matters for the label, so the server sends
     * `birthdayIsMine` rather than leaving the page to guess.
     */
    birthday: string | null
    birthdayIsMine: boolean
    /** What they share with you. */
    lists: FriendList[]
    /** What you share with them. The same set under every name today, because
     *  a list is shown to friends or it is not; the server repeats it per
     *  person because that is the question somebody actually has here. */
    theySee: FriendList[]
}

interface Props {
    friends: Friend[]
    settings: {
        birthday: string | null
        friendsSeeBirthday: boolean
    }
}

/**
 * The people you share lists with.
 *
 * Sharing has been a link since invitations were dropped, which made it
 * frictionless and left both ends anonymous to each other: somebody sends you
 * their registry, you claim something off it, and neither of you ends up with
 * any record that the other exists. This is that record — and it is the answer
 * to "where is that link again", which is otherwise a hunt through a chat
 * history.
 *
 * Three sections, in the order somebody needs them: the people, then how to add
 * one, then what those people see of you. The settings sit last on purpose —
 * they are read once and the list is read every time.
 *
 * **Which lists your friends see is not settled here.** That switch lives on
 * each list's own settings panel, once. It was mirrored here for a day and the
 * two copies promptly disagreed: this page sent the effective value and the
 * list panel sent the raw column, so the same untouched wish list read as on
 * here and off there — and a click meant to turn it on turned it off. Each
 * friend row above still says what that person can see of yours, which is the
 * question somebody actually brings to this page; changing the answer is a job
 * for the list.
 */
export default function Friends({ friends, settings }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    // `day` and `month` separately, because there is no browser control for
    // "a date without a year" and a `type="date"` would demand one.
    const add = useForm({ email: '', day: '', month: '' })
    const prefs = useForm({
        birthday: settings.birthday ?? '',
        friends_see_birthday: settings.friendsSeeBirthday,
    })

    // Which row has its birthday field open. One at a time: this is a small
    // correction, not a form, and ten date inputs down the page is chrome.
    const [editing, setEditing] = useState<number | null>(null)

    const dateFormat = new Intl.DateTimeFormat(market.hrefLang, {
        day: 'numeric',
        month: 'long',
    })

    /*
     * `MM-DD` as a sentence, in the reader's language.
     *
     * Formatted against 2000 because it is a leap year: 29 February is a
     * birthday people have, and every non-leap year turns it into 1 March. The
     * year is never rendered — `dateFormat` asks for day and month only — so
     * the choice of it is invisible and exists purely to make the date legal.
     */
    function birthdayLabel(monthDay: string): string {
        return dateFormat.format(new Date(`2000-${monthDay}T00:00:00`))
    }

    /**
     * The twelve months, named by the reader's own locale.
     *
     * Built from the same 2000 that `birthdayLabel` uses, so a translator never
     * has to supply twelve strings the browser already knows.
     */
    const months = Array.from({ length: 12 }, (_, i) => ({
        value: String(i + 1).padStart(2, '0'),
        label: new Intl.DateTimeFormat(market.hrefLang, { month: 'long' }).format(new Date(2000, i, 1)),
    }))

    /** 1 to 31, whatever the month. Nobody picks 30 February from two lists. */
    const days = Array.from({ length: 31 }, (_, i) => String(i + 1).padStart(2, '0'))

    /** `MM-DD`, or null when only half of it has been chosen. */
    function monthDay(month: string, day: string): string | null {
        return month === '' || day === '' ? null : `${month}-${day}`
    }

    return (
        <>
            <Head title={t('friends.title')} />

            <h1 className="text-xl font-semibold sm:text-2xl">{t('friends.title')}</h1>
            <p className="mt-2 text-sm text-ink-soft">{t('friends.intro')}</p>

            {friends.length === 0 ? (
                <p className="mt-8 rounded-card border border-line bg-card p-5 text-sm text-ink-soft">
                    {t('friends.empty')}
                </p>
            ) : (
                <ul className="mt-8 divide-y divide-line rounded-card border border-line bg-card">
                    {/*
                      One line per person, and the detail opens on a click.

                      A friend list is a list of *people*: the thing you scan is
                      names, and everything under a name — two groups of lists,
                      a birthday field, a remove button — is what you want after
                      you have found the one you were looking for. Expanded by
                      default, twenty friends was a page you had to scroll past
                      rather than read.

                      A native `<details>`, not a `useState`: it gives the
                      keyboard behaviour, the open/closed semantics a screen
                      reader announces, and find-in-page opening the section
                      that matches, none of which a div and a click handler get
                      right for free.
                    */}
                    {friends.map((friend) => (
                        <li key={friend.id}>
                            <details className="group">
                                <summary className="flex cursor-pointer list-none items-center gap-2 p-4 hover:bg-line/20">
                                    {/* The marker is ours, so it can point the
                                        right way in both states without relying
                                        on a browser's default triangle. */}
                                    <span
                                        aria-hidden
                                        className="text-ink-soft transition group-open:rotate-90"
                                    >
                                        ▸
                                    </span>

                                    <span className="min-w-0 flex-1 truncate font-medium">
                                        {friend.name}
                                        {friend.birthday !== null && (
                                            <span className="ml-2 text-sm font-normal text-ink-soft">
                                                <span aria-hidden className="mr-1">
                                                    🎂
                                                </span>
                                                {birthdayLabel(friend.birthday)}
                                                {friend.birthdayIsMine && (
                                                    <span className="ml-1 text-xs">
                                                        ({t('friends.your_note')})
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </span>

                                    {/*
                                      What is inside, without opening it.

                                      Two counts rather than one: the closed
                                      line has to answer "is there anything of
                                      theirs here" and "can they see anything of
                                      mine", which are the two questions the
                                      groups inside answer. The arrows mean the
                                      same thing they do below.
                                    */}
                                    <span className="shrink-0 text-xs text-ink-soft">
                                        <span aria-hidden>←</span> {friend.lists.length}
                                        <span className="mx-1">·</span>
                                        <span aria-hidden>→</span> {friend.theySee.length}
                                    </span>
                                </summary>

                                <div className="px-4 pb-4">
                                    {friend.lists.length > 0 && (
                                        <div>
                                            {/*
                                              Whose these are, stated rather than
                                              implied by position. Two groups of
                                              chips under one name are
                                              indistinguishable without it, and
                                              the arrow is the part read at a
                                              glance: theirs comes towards you,
                                              yours goes out.
                                            */}
                                            <p className="text-xs font-medium">
                                                <span aria-hidden className="mr-1">
                                                    ←
                                                </span>
                                                {t('friends.they_share', { name: friend.name })}
                                            </p>
                                            <ul className="mt-1.5 flex flex-wrap gap-2">
                                                {friend.lists.map((list) => (
                                                    <li key={list.url}>
                                                        {/*
                                                          A real anchor, not an
                                                          Inertia link: a shared
                                                          list lives under its
                                                          share token and is the
                                                          sort of thing people
                                                          middle-click into a tab.
                                                        */}
                                                        <a
                                                            href={list.url}
                                                            className="inline-block rounded-lg border border-line px-3 py-1.5 text-sm hover:border-ink"
                                                        >
                                                            {list.title}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    {friend.theySee.length > 0 && (
                                        <div className="mt-3">
                                            <p className="text-xs font-medium text-ink-soft">
                                                <span aria-hidden className="mr-1">
                                                    →
                                                </span>
                                                {t('friends.they_see', { name: friend.name })}
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

                                    {/*
                                      The two actions, inside rather than on the
                                      line. A button in a `<summary>` fights the
                                      toggle for the same click, and neither of
                                      these is something you do while scanning
                                      names.
                                    */}
                                    <div className="mt-4 flex items-center gap-4 text-xs text-ink-soft">
                                        <button
                                            type="button"
                                            onClick={() => setEditing(editing === friend.id ? null : friend.id)}
                                            className="underline hover:text-ink"
                                        >
                                            {friend.birthday === null
                                                ? t('friends.add_birthday')
                                                : t('friends.edit_birthday')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                /*
                                                  Confirmed here rather than on
                                                  the server, because it is not
                                                  destructive of anything but the
                                                  row — following their link
                                                  again re-creates it — but it
                                                  removes the connection for BOTH
                                                  people, which is not what a
                                                  single stray tap should mean.
                                                */
                                                if (
                                                    !window.confirm(
                                                        t('friends.remove_confirm', { name: friend.name }),
                                                    )
                                                ) {
                                                    return
                                                }

                                                router.delete(`${base}/friends/${friend.id}`, {
                                                    preserveScroll: true,
                                                })
                                            }}
                                            className="underline hover:text-ink"
                                        >
                                            {t('friends.remove')}
                                        </button>
                                    </div>

                                    {editing === friend.id && (
                                        <form
                                            onSubmit={(e) => {
                                                e.preventDefault()
                                                const fields = new FormData(e.currentTarget)

                                                router.patch(
                                                    `${base}/friends/${friend.id}`,
                                                    {
                                                        birthday: monthDay(
                                                            String(fields.get('month') ?? ''),
                                                            String(fields.get('day') ?? ''),
                                                        ),
                                                    },
                                                    { preserveScroll: true, onSuccess: () => setEditing(null) },
                                                )
                                            }}
                                            className="mt-3 flex flex-wrap items-end gap-2"
                                        >
                                            {/*
                                              Two lists, not a date picker.

                                              There is no browser control for a
                                              date without a year, and
                                              `type="date"` would demand one —
                                              which is exactly the thing nobody
                                              should be typing about somebody
                                              else. Leaving both blank clears the
                                              note.
                                            */}
                                            <label className="text-xs font-medium">
                                                {t('friends.their_birthday')}
                                                <span className="mt-1 flex gap-2">
                                                    <select
                                                        name="day"
                                                        defaultValue={
                                                            friend.birthdayIsMine
                                                                ? (friend.birthday?.slice(3) ?? '')
                                                                : ''
                                                        }
                                                        className="rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                                    >
                                                        <option value="">--</option>
                                                        {days.map((day) => (
                                                            <option key={day} value={day}>
                                                                {Number(day)}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <select
                                                        name="month"
                                                        defaultValue={
                                                            friend.birthdayIsMine
                                                                ? (friend.birthday?.slice(0, 2) ?? '')
                                                                : ''
                                                        }
                                                        className="rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                                    >
                                                        <option value="">--</option>
                                                        {months.map((month) => (
                                                            <option key={month.value} value={month.value}>
                                                                {month.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </span>
                                            </label>
                                            <button
                                                type="submit"
                                                className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                            >
                                                {t('friends.save')}
                                            </button>
                                        </form>
                                    )}
                                </div>
                            </details>
                        </li>
                    ))}
                </ul>
            )}

            <section className="mt-10 rounded-card border border-line bg-card p-5">
                <h2 className="font-medium">{t('friends.add_title')}</h2>
                <p className="mt-1 text-sm text-ink-soft">{t('friends.add_hint')}</p>

                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        // The two selects become one `MM-DD`, or nothing.
                        add.transform((data) => ({
                            email: data.email,
                            birthday: monthDay(data.month, data.day),
                        }))
                        add.post(`${base}/friends`, {
                            preserveScroll: true,
                            onSuccess: () => add.reset(),
                        })
                    }}
                    className="mt-4 flex flex-wrap items-end gap-3"
                >
                    <label className="text-xs font-medium">
                        {t('friends.email')}
                        <input
                            type="email"
                            required
                            value={add.data.email}
                            onChange={(e) => add.setData('email', e.target.value)}
                            className="mt-1 block w-64 max-w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                        />
                    </label>

                    {/*
                      Day and month, never a year.

                      A year here would be somebody's age, written down by a
                      third party who was not asked and may be wrong. If they
                      want one recorded they can give it on their own settings,
                      which is the only place it belongs.
                    */}
                    <label className="text-xs font-medium">
                        {t('friends.their_birthday_optional')}
                        <span className="mt-1 flex gap-2">
                            <select
                                value={add.data.day}
                                onChange={(e) => add.setData('day', e.target.value)}
                                aria-label={t('friends.day')}
                                className="rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                            >
                                <option value="">--</option>
                                {days.map((day) => (
                                    <option key={day} value={day}>
                                        {Number(day)}
                                    </option>
                                ))}
                            </select>
                            <select
                                value={add.data.month}
                                onChange={(e) => add.setData('month', e.target.value)}
                                aria-label={t('friends.month')}
                                className="rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                            >
                                <option value="">--</option>
                                {months.map((month) => (
                                    <option key={month.value} value={month.value}>
                                        {month.label}
                                    </option>
                                ))}
                            </select>
                        </span>
                    </label>

                    <button
                        type="submit"
                        disabled={add.processing}
                        className="rounded-lg bg-accent px-5 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                    >
                        {t('friends.add')}
                    </button>
                </form>

                {add.errors.email && (
                    <p className="mt-2 text-sm text-danger" role="alert">
                        {add.errors.email}
                    </p>
                )}
            </section>

            <section className="mt-6 rounded-card border border-line bg-card p-5">
                <h2 className="font-medium">{t('friends.settings_title')}</h2>

                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        // An empty date input is "", which is not a date and is
                        // not null either. The server wants one or the other,
                        // and "" fails `nullable|date` on every save by
                        // somebody who has not given a birthday.
                        prefs.transform((data) => ({
                            ...data,
                            birthday: data.birthday === '' ? null : data.birthday,
                        }))
                        prefs.patch(`${base}/friends/settings`, { preserveScroll: true })
                    }}
                    className="mt-4 space-y-4"
                >
                    {/*
                      A full date, and the only year on the site.

                      Yours to give: you are the one person who gets to decide
                      whether this site knows how old you are. Friends still see
                      only the day and the month.
                    */}
                    <label className="block text-xs font-medium">
                        {t('friends.my_birthday')}
                        <input
                            type="date"
                            value={prefs.data.birthday}
                            onChange={(e) => prefs.setData('birthday', e.target.value)}
                            className="mt-1 block rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                        />
                    </label>

                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={prefs.data.friends_see_birthday}
                            onChange={(e) => prefs.setData('friends_see_birthday', e.target.checked)}
                            className="mt-1"
                        />
                        <span>
                            {t('friends.see_birthday')}
                            <span className="block text-xs text-ink-soft">{t('friends.see_birthday_hint')}</span>
                        </span>
                    </label>

                    <button
                        type="submit"
                        disabled={prefs.processing}
                        className="rounded-lg bg-accent px-5 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                    >
                        {t('friends.save')}
                    </button>
                </form>

            </section>
        </>
    )
}
