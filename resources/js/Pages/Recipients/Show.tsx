import { Head, Link, router, usePage } from '@inertiajs/react'
import { type FormEvent, useState } from 'react'
import InfoTip from '../../Components/InfoTip'
import NextSteps, { type NextStepCard } from '../../Components/NextSteps'
import SaveToList from '../../Components/SaveToList'
import ToolIcon from '../../Components/ToolIcon'
import type { Cents, SavingTo, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface PastGift {
    /** `noted`: written down here; `claimed` / `sent`: your own claim on a list for them. */
    source: 'noted' | 'claimed' | 'sent'
    title: string
    groupId: number | null
    year: number | null
    image: string | null
    url: string | null
    /** Set on a noted gift: what "remove" deletes. */
    recordId: number | null
}

interface Unmarked {
    id: number
    title: string
    image: string | null
}

interface Highlight {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    url: string
}

interface Props {
    person: { id: string; name: string; relationship: string | null }
    history: PastGift[]
    unmarked: Unmarked[]
    nextSteps: NextStepCard[]
    highlight: Highlight | null
    recipientList: SavingTo | null
    urls: { finder: string; taste: string; gifts: string }
    thisYear: number
}

/** How far back the year picker goes. Older gifts can still be noted; they just say little. */
const YEARS_BACK = 10

/**
 * A saved person's page: what you gave them, and what could come next.
 *
 * Only the owner reaches it. The history is theirs: what they wrote down, and
 * their own claims on lists for this person, never anybody else's. See
 * docs/features/gift-history.md.
 */
export default function RecipientShow({ person, history, unmarked, nextSteps, highlight, recipientList, urls, thisYear }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [title, setTitle] = useState('')
    const [year, setYear] = useState(thisYear)
    const [busy, setBusy] = useState(false)

    const add = (e: FormEvent) => {
        e.preventDefault()

        if (title.trim() === '' || busy) {
            return
        }

        setBusy(true)
        router.post(
            urls.gifts,
            { title: title.trim(), year },
            {
                preserveScroll: true,
                onSuccess: () => setTitle(''),
                onFinish: () => setBusy(false),
            },
        )
    }

    const markGiven = (itemId: number) => {
        router.post(urls.gifts, { item_id: itemId, year: thisYear }, { preserveScroll: true })
    }

    const remove = (recordId: number) => {
        router.delete(`${urls.gifts}/${recordId}`, { preserveScroll: true })
    }

    const years = Array.from({ length: YEARS_BACK + 1 }, (_, i) => thisYear - i)

    return (
        <>
            <Head title={t('gift_history.page_title', { name: person.name })} />

            <header className="max-w-2xl">
                <h1 className="text-xl font-semibold sm:text-2xl">{t('gift_history.page_title', { name: person.name })}</h1>
                <p className="mt-1 text-ink-soft">{t('gift_history.intro', { name: person.name })}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <Link
                        href={urls.finder}
                        className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        <ToolIcon name="whisperer" className="h-4 w-4" />
                        {t('gift_history.finder', { name: person.name })}
                    </Link>
                    <Link
                        href={urls.taste}
                        className="inline-flex items-center gap-2 rounded border border-line px-4 py-2 text-sm hover:border-ink/40"
                    >
                        <ToolIcon name="taste" className="h-4 w-4" />
                        {t('gift_history.taste', { name: person.name })}
                    </Link>
                </div>
            </header>

            {/*
              The idea a reminder email linked to. The email only opens this
              page; the save is the press here, so a mail scanner opening every
              link cannot add anything to the list.
            */}
            {highlight && (
                <section className="mt-8 max-w-2xl rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="text-sm font-medium text-ink-soft">{t('gift_history.from_email')}</h2>
                    <div className="mt-3 flex items-center gap-4">
                        {highlight.image && (
                            <img src={highlight.image} alt="" className="h-20 w-20 shrink-0 object-contain" loading="lazy" />
                        )}
                        <div className="min-w-0 flex-1">
                            <Link href={highlight.url} className="line-clamp-2 font-medium">
                                {highlight.title}
                            </Link>
                            {highlight.price !== null && (
                                <p className="mt-1 text-sm font-semibold">{formatPrice(highlight.price, market)}</p>
                            )}
                        </div>
                        <SaveToList groupId={highlight.id} into={recipientList ?? undefined} />
                    </div>
                </section>
            )}

            <section className="mt-10 max-w-2xl">
                <h2 className="flex items-center gap-1.5 text-lg font-medium">
                    {t('gift_history.history_title')}
                    <InfoTip>{t('gift_history.history_hint', { name: person.name })}</InfoTip>
                </h2>

                {history.length === 0 ? (
                    <p className="mt-3 text-sm text-ink-soft">{t('gift_history.history_empty', { name: person.name })}</p>
                ) : (
                    <ul className="mt-3 divide-y divide-line rounded-card border border-line bg-card">
                        {history.map((gift) => (
                            <li
                                key={`${gift.source}:${gift.recordId ?? gift.groupId ?? gift.title}`}
                                className="flex items-center gap-3 px-4 py-3 text-sm"
                            >
                                {gift.image ? (
                                    <img src={gift.image} alt="" className="h-10 w-10 shrink-0 object-contain" loading="lazy" />
                                ) : (
                                    <span className="h-10 w-10 shrink-0" aria-hidden />
                                )}
                                <span className="min-w-0 flex-1">
                                    {gift.url ? (
                                        <Link href={gift.url} className="line-clamp-2 font-medium hover:underline">
                                            {gift.title}
                                        </Link>
                                    ) : (
                                        <span className="line-clamp-2 font-medium">{gift.title}</span>
                                    )}
                                    <span className="block text-xs text-ink-soft">
                                        {gift.year !== null && t('gift_history.given_in', { year: gift.year })}
                                        {gift.source !== 'noted' && (
                                            <>
                                                {gift.year !== null && ' · '}
                                                {t(gift.source === 'sent' ? 'gift_history.source_sent' : 'gift_history.source_claimed')}
                                            </>
                                        )}
                                    </span>
                                </span>
                                {gift.recordId !== null && (
                                    <button
                                        type="button"
                                        className="shrink-0 text-xs text-ink-soft underline hover:text-ink"
                                        onClick={() => remove(gift.recordId as number)}
                                    >
                                        {t('gift_history.remove')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <form onSubmit={add} className="mt-4 flex flex-wrap items-end gap-2">
                    <label className="min-w-0 flex-1">
                        <span className="block text-xs text-ink-soft">{t('gift_history.add_title')}</span>
                        <input
                            type="text"
                            value={title}
                            maxLength={200}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder={t('gift_history.add_placeholder')}
                            className="mt-1 w-full rounded border border-line bg-card px-3 py-2 text-sm"
                        />
                    </label>
                    <label>
                        <span className="block text-xs text-ink-soft">{t('gift_history.add_year')}</span>
                        <select
                            value={year}
                            onChange={(e) => setYear(Number(e.target.value))}
                            className="mt-1 rounded border border-line bg-card px-2 py-2 text-sm"
                        >
                            {years.map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button
                        type="submit"
                        disabled={busy || title.trim() === ''}
                        className="rounded bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {t('gift_history.add_button')}
                    </button>
                </form>
            </section>

            {unmarked.length > 0 && (
                <section className="mt-10 max-w-2xl">
                    <h2 className="flex items-center gap-1.5 text-sm font-medium text-ink-soft">
                        {t('gift_history.from_lists_title', { name: person.name })}
                        <InfoTip>{t('gift_history.from_lists_hint')}</InfoTip>
                    </h2>
                    <ul className="mt-3 divide-y divide-line rounded-card border border-line">
                        {unmarked.map((item) => (
                            <li key={item.id} className="flex items-center gap-3 px-4 py-2 text-sm">
                                {item.image ? (
                                    <img src={item.image} alt="" className="h-8 w-8 shrink-0 object-contain" loading="lazy" />
                                ) : (
                                    <span className="h-8 w-8 shrink-0" aria-hidden />
                                )}
                                <span className="min-w-0 flex-1 truncate">{item.title}</span>
                                <button
                                    type="button"
                                    className="shrink-0 text-xs text-accent underline"
                                    onClick={() => markGiven(item.id)}
                                >
                                    {t('gift_history.mark_given')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <NextSteps steps={nextSteps} name={person.name} into={recipientList} />
        </>
    )
}
