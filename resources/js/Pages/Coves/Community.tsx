import { Head, Link, usePage } from '@inertiajs/react'
import CommunityCoveCards, { type CommunityCoveCard } from '../../Components/CommunityCoveCards'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Props {
    coves: CommunityCoveCard[]
    sort: 'new' | 'saved'
    links: { prev: string | null; next: string | null }
}

/**
 * Community Coves: every list people published in this market, newest first or
 * most saved first (docs/features/community-coves.md).
 */
export default function Community({ coves, sort, links }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}/coves/community`

    return (
        <>
            <Head title={t('community.index_heading')} />

            <header className="max-w-2xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">
                    <Link href={`/${market.key}/coves`} className="hover:underline">
                        {t('coves.title')}
                    </Link>
                </p>
                <h1 className="mt-1 text-2xl font-semibold sm:text-3xl">{t('community.index_heading')}</h1>
                <p className="mt-2 text-ink-soft">{t('community.index_intro')}</p>
            </header>

            <nav className="mt-6 flex gap-2 text-sm" aria-label={t('community.sort_label')}>
                {(['new', 'saved'] as const).map((key) => (
                    <Link
                        key={key}
                        href={key === 'new' ? base : `${base}?sort=saved`}
                        aria-current={sort === key ? 'page' : undefined}
                        className={`rounded-full border px-3 py-1 ${
                            sort === key ? 'border-ink bg-ink text-white' : 'border-line hover:border-ink'
                        }`}
                    >
                        {t(`community.sort_${key}`)}
                    </Link>
                ))}
            </nav>

            <div className="mt-6">
                {coves.length === 0 ? (
                    <p className="text-ink-soft">{t('community.empty')}</p>
                ) : (
                    <CommunityCoveCards coves={coves} />
                )}
            </div>

            {(links.prev || links.next) && (
                <div className="mt-8 flex justify-between text-sm">
                    {links.prev ? <Link href={links.prev}>← {t('community.previous')}</Link> : <span />}
                    {links.next ? <Link href={links.next}>{t('community.next')} →</Link> : <span />}
                </div>
            )}
        </>
    )
}
