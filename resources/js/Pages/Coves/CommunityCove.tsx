import { Head, Link, usePage } from '@inertiajs/react'
import SaveCove, { type SaveCoveState } from '../../Components/SaveCove'
import SaveToList from '../../Components/SaveToList'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Item {
    key: string
    title: string
    image: string | null
    price: Cents | null
    /** Our product page. Null for a hand-written item, which has none. */
    url: string | null
    groupId: number | null
    inStock: boolean | null
}

interface Props {
    cove: {
        title: string
        /** "For a dad", "Birthday": who it is for in general words, never a name. */
        about: string[]
        /** The owner's first name, only when they chose to show it. */
        by: string | null
        saves: number
        publishedAt: string | null
    }
    items: Item[]
    save: SaveCoveState
    reportUrl: string
    indexUrl: string
}

/**
 * A Community Cove: a list somebody published for anybody to find
 * (docs/features/community-coves.md).
 *
 * Read-only for everyone, the owner included: this is the public face of the
 * list, not the list. No claim buttons, no board, no share link, because none
 * of that is sent; what the page may show is decided on the server
 * (App\Services\Cove\CommunityCoves) and this page draws what it is given.
 */
export default function CommunityCove({ cove, items, save, reportUrl, indexUrl }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    return (
        <>
            <Head title={cove.title} />

            <header className="max-w-2xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">
                    <Link href={indexUrl} className="hover:underline">
                        {t('community.index_heading')}
                    </Link>
                </p>
                <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{cove.title}</h1>
                {cove.about.length > 0 && <p className="mt-2 text-ink-soft">{cove.about.join(' · ')}</p>}
                <p className="mt-1 text-sm text-ink-soft">
                    {cove.by ? t('community.made_by', { name: cove.by }) : t('community.made_by_someone')}
                </p>
                <div className="mt-4">
                    <SaveCove state={save} />
                </div>
            </header>

            <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {items.map((item) => (
                    <li key={item.key} className="flex flex-col rounded-card border border-line bg-card p-4">
                        {item.url ? (
                            <Link href={item.url}>
                                {item.image && (
                                    <img src={item.image} alt="" className="mx-auto h-36 object-contain" loading="lazy" />
                                )}
                                <h2 className="mt-3 line-clamp-2 font-medium">{item.title}</h2>
                            </Link>
                        ) : (
                            <>
                                <h2 className="line-clamp-3 font-medium">{item.title}</h2>
                                <p className="mt-1 text-xs text-ink-soft">{t('community.written_item')}</p>
                            </>
                        )}

                        <div className="mt-auto flex items-center justify-between pt-4">
                            <span className="font-semibold">
                                {item.price === null ? '—' : formatPrice(item.price, market)}
                            </span>
                            {item.groupId !== null && <SaveToList groupId={item.groupId} />}
                        </div>
                    </li>
                ))}
            </ul>

            {/*
              The safety valve for a page written by a visitor: a report goes
              to the feedback queue with this page's address, and an admin can
              hide the Cove in one press.
            */}
            <p className="mt-10 text-sm text-ink-soft">
                {t('community.report_hint')}{' '}
                <a href={reportUrl} className="underline hover:text-ink">
                    {t('community.report')}
                </a>
            </p>
        </>
    )
}
