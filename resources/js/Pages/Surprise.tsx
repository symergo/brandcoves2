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
    /** Which signal scored loudest — the card explains itself with it. */
    why: string
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
                              A checkable claim, not an assertion. "Almost no
                              shop stocks it" is something a reader can verify
                              on the product page; "surprising!" is something
                              they have to take on faith, and they will not.
                            */}
                            <p className="mt-2 text-sm text-ink-soft">{t(`surprise.why.${find.why}`)}</p>

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
