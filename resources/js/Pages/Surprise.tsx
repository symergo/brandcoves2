import { Head, router, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import SaveToList from '../Components/SaveToList'

interface Find {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    merchantCount: number
    url: string
    /**
     * What the thing is, from the merchant's own description.
     *
     * Null when no offer beneath the group carries one worth printing — feeds
     * supply plenty of rows whose description is "Zwart" — so the card falls
     * back rather than reserving space for a line that is not there.
     */
    blurb: string | null
}

interface Props {
    finds: Find[]
    seen: number[]
}

export default function Surprise({ finds, seen }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * The exclusion list travels in the URL rather than in a session.
     *
     * It makes "show me more" idempotent and back-button-safe, and it means a
     * visitor can reload without being handed the same six things again. It is
     * bounded server-side so a hand-edited URL cannot turn it into an unbounded
     * IN clause.
     */
    const reroll = () => {
        router.get(`/${market.key}/surprise`, { seen }, { preserveScroll: false })
    }

    return (
        <>
            <Head title={t('surprise.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('surprise.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('surprise.subtitle')}</p>
            </header>

            {finds.length === 0 ? (
                <p className="mt-10 text-ink-soft">{t('surprise.empty')}</p>
            ) : (
                <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {finds.map((find) => (
                        <li
                            key={find.id}
                            className="flex flex-col rounded-lg border border-line bg-card p-4"
                        >
                            <a href={find.url} className="block">
                                {find.image && (
                                    <img
                                        src={find.image}
                                        alt=""
                                        className="mx-auto h-40 object-contain"
                                        loading="lazy"
                                    />
                                )}
                                <h2 className="mt-3 line-clamp-2 font-medium">{find.title}</h2>
                            </a>

                            {/*
                              What it is, not why we picked it.

                              This line used to carry the loudest scoring signal
                              — "a corner of the catalogue nobody browses", "a
                              brand you probably have not heard of". Six of
                              those down a grid is six sentences about our
                              ranking and none about the objects, and a visitor
                              looking at something unfamiliar does not need to
                              be told it is unfamiliar. That is the one thing
                              they already know.

                              Clamped rather than trimmed shorter server-side:
                              the excerpt is already cut at a word boundary, and
                              a fixed clamp keeps the cards on one grid line
                              whatever the merchant wrote.
                            */}
                            {find.blurb !== null ? (
                                <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{find.blurb}</p>
                            ) : (
                                find.brand !== null && (
                                    <p className="mt-2 text-sm text-ink-soft">
                                        {t('surprise.by_brand', { brand: find.brand })}
                                    </p>
                                )
                            )}

                            <div className="mt-auto flex items-center justify-between pt-4">
                                <span className="font-semibold">
                                    {find.price === null ? '-' : formatPrice(find.price, market)}
                                </span>
                                <SaveToList groupId={find.id} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {finds.length > 0 && (
                <div className="mt-10">
                    <button
                        type="button"
                        onClick={reroll}
                        className="rounded bg-accent px-5 py-3 font-medium text-white"
                    >
                        {t('surprise.reroll')}
                    </button>
                </div>
            )}
        </>
    )
}
