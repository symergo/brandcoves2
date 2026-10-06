import { useForm, usePage } from '@inertiajs/react'
import Button, { fieldClasses } from './Button'
import InfoTip from './InfoTip'
import Modal from './Modal'
import type { Cents, SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/*
 * Small pieces My people and a person's page share: the day-and-month
 * birthday picker and the budget in words. Moved here from People/Index when
 * the person's page started editing the same facts (2026-09-27), so the two
 * pages cannot come to spell a birthday or a budget differently.
 */

/** `MM-DD`, or null when only half of it has been chosen. */
export function monthDay(month: string, day: string): string | null {
    return month === '' || day === '' ? null : `${month}-${day}`
}

/**
 * A day and a month as two lists, and never a year.
 *
 * There is no browser control for a date without a year, and a year about
 * somebody else is their age written down by a third party. Day 1 to 31 in
 * any month: nobody picks 30 February from two lists, and the server refuses
 * a date that does not exist.
 */
export function DayMonth({
    day,
    month,
    onDay,
    onMonth,
}: {
    day: string
    month: string
    onDay: (v: string) => void
    onMonth: (v: string) => void
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const days = Array.from({ length: 31 }, (_, i) => String(i + 1).padStart(2, '0'))
    const monthName = new Intl.DateTimeFormat(market.hrefLang, { month: 'long' })

    return (
        <span className="mt-1 flex gap-2">
            <select
                value={day}
                onChange={(e) => onDay(e.target.value)}
                aria-label={t('friends.day')}
                className={fieldClasses('', { inline: true })}
            >
                <option value="">--</option>
                {days.map((d) => (
                    <option key={d} value={d}>
                        {Number(d)}
                    </option>
                ))}
            </select>
            <select
                value={month}
                onChange={(e) => onMonth(e.target.value)}
                aria-label={t('friends.month')}
                className={fieldClasses('min-w-0 flex-1', { inline: true })}
            >
                <option value="">--</option>
                {Array.from({ length: 12 }, (_, i) => (
                    <option key={i} value={String(i + 1).padStart(2, '0')}>
                        {monthName.format(new Date(2000, i, 1))}
                    </option>
                ))}
            </select>
        </span>
    )
}

type Translate = (key: string, replacements?: Record<string, string | number>) => string

/** "tot €50", "vanaf €20" or "€20 tot €50"; null when there is no budget. */
export function budgetLabel(min: Cents | null, max: Cents | null, t: Translate, money: (cents: Cents) => string): string | null {
    if (min !== null && max !== null && min > 0) {
        return t('people.budget_between', { min: money(min), max: money(max) })
    }

    if (max !== null) {
        return t('people.budget_to', { amount: money(max) })
    }

    return min !== null && min > 0 ? t('people.budget_from', { amount: money(min) }) : null
}

/**
 * "Nodig uit op GiftCoves" for a saved person nobody linked (2026-09-27).
 *
 * The friends' own invitation (`POST /friends`, the email with all its limits
 * and the no-invitations list), with the saved person's id added, so the
 * connection it makes lands on this person instead of a second row. The server
 * checks the person is yours and not linked yet (FriendInvites::mayLink); the
 * answer is the same sentence whether or not the address has an account.
 *
 * The birthday starts as the one you saved for them, so it is not typed twice.
 *
 * A popup of its own since 2026-10-06 (owner's rule: a form opened from a
 * button or a menu item is a popup); it opened in the page until then.
 */
export function InvitePerson({
    url,
    personId,
    name,
    birthday,
    onDone,
}: {
    /** `/{market}/friends`. */
    url: string
    personId: string
    name: string
    /** `MM-DD`, the saved person's. */
    birthday: string | null
    onDone: () => void
}) {
    const { t } = useTranslations()
    const form = useForm({
        email: '',
        month: birthday?.slice(0, 2) ?? '',
        day: birthday?.slice(3) ?? '',
    })

    return (
        <Modal
            title={
                <span className="inline-flex flex-wrap items-center">
                    {t('people.invite')}
                    <InfoTip>{t('people.invite_person_tip', { name })}</InfoTip>
                </span>
            }
            label={t('people.invite')}
            onClose={onDone}
        >
        <form
            onSubmit={(e) => {
                e.preventDefault()
                form.transform((data) => ({
                    email: data.email,
                    birthday: monthDay(data.month, data.day),
                    recipient_id: personId,
                }))
                form.post(url, { preserveScroll: true, onSuccess: onDone })
            }}
            className="mt-4 grid gap-4 sm:grid-cols-3"
        >
            <label className="block text-sm font-medium sm:col-span-2">
                {t('friends.email')}
                <input
                    type="email"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    className={fieldClasses()}
                />
            </label>
            <label className="block text-sm font-medium">
                {t('friends.their_birthday_optional')}
                <DayMonth
                    day={form.data.day}
                    month={form.data.month}
                    onDay={(v) => form.setData('day', v)}
                    onMonth={(v) => form.setData('month', v)}
                />
            </label>
            <div className="sm:col-span-3">
                <Button type="submit" busy={form.processing}>
                    {t('people.invite_button')}
                </Button>
                {form.errors.email && (
                    <p className="mt-2 text-sm text-danger" role="alert">
                        {form.errors.email}
                    </p>
                )}
            </div>
        </form>
        </Modal>
    )
}
