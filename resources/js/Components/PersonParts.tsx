import { usePage } from '@inertiajs/react'
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
                className="rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
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
                className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
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
