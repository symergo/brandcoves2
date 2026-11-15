import { Head, Link, usePage } from '@inertiajs/react'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

/**
 * One list, read-only, opened from the link in a mail about it without signing
 * in (App\Http\Controllers\ListFromMailController). Titles, current prices and
 * links to the products; nothing that changes anything, and nothing about who
 * claimed what. "Sign in" comes back to the full list.
 */
interface Item {
    id: string
    title: string
    image: string | null
    price: number | null
    inStock: boolean | null
    url: string | null
}

interface Props {
    list: { title: string; items: Item[] }
    loginUrl: string
}

export default function FromMail({ list, loginUrl }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    return (
        <>
            <Head title={list.title} />
            <div className="mx-auto max-w-3xl px-4 py-8">
                <h1 className="text-2xl font-semibold text-ink">{list.title}</h1>

                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-soft">
                    <p className="min-w-0 flex-1">{t('list_from_mail.note')}</p>
                    <a
                        href={loginUrl}
                        className="flex min-h-9 shrink-0 items-center rounded-full bg-accent px-4 font-semibold text-white hover:bg-accent-dark"
                    >
                        {t('list_from_mail.sign_in')}
                    </a>
                </div>

                {list.items.length === 0 ? (
                    <p className="mt-8 text-ink-soft">{t('list_from_mail.empty')}</p>
                ) : (
                    <ul className="mt-6 divide-y divide-line">
                        {list.items.map((item) => {
                            const body = (
                                <>
                                    {item.image ? (
                                        <img
                                            src={item.image}
                                            alt=""
                                            loading="lazy"
                                            width={64}
                                            height={64}
                                            className="h-16 w-16 shrink-0 rounded-md bg-white object-contain"
                                        />
                                    ) : (
                                        <span className="h-16 w-16 shrink-0 rounded-md bg-line/40" aria-hidden />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-ink">{item.title}</span>
                                        {item.price !== null && (
                                            <span className="mt-1 block font-semibold text-ink">
                                                {formatPrice(item.price, market)}
                                            </span>
                                        )}
                                        {item.inStock === false && (
                                            <span className="mt-1 block text-xs text-ink-soft">
                                                {t('list_from_mail.sold_out')}
                                            </span>
                                        )}
                                    </span>
                                </>
                            )

                            return (
                                <li key={item.id}>
                                    {item.url ? (
                                        <Link href={item.url} className="flex items-center gap-4 py-3 hover:bg-cream">
                                            {body}
                                        </Link>
                                    ) : (
                                        <div className="flex items-center gap-4 py-3">{body}</div>
                                    )}
                                </li>
                            )
                        })}
                    </ul>
                )}
            </div>
        </>
    )
}
