import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Props {
    guides: { title: string; intro: string | null; url: string; publishedAt: string | null }[]
}

export default function GuidesIndex({ guides }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('guides.seo_title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('guides.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('guides.subtitle')}</p>
            </header>

            {guides.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('guides.empty')}</p>
            ) : (
                <ul className="mt-8 grid gap-4 sm:grid-cols-2">
                    {guides.map((guide) => (
                        <li key={guide.url} className="rounded-lg border border-line p-5">
                            <Link href={guide.url} className="text-lg font-medium hover:underline">
                                {guide.title}
                            </Link>
                            {guide.intro && (
                                <p className="mt-2 line-clamp-3 text-sm text-ink-soft">{guide.intro}</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    )
}
