import { Head, Link, usePage } from '@inertiajs/react'
import ProductCard, { type GroupCard } from '../../Components/ProductCard'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface PageLink {
    label: string
    url: string
}

interface BudgetOption {
    min: Cents | null
    max: Cents | null
    param: string
    url: string
}

interface Props {
    heading: string
    intro: string
    isRecipientPage: boolean
    products: GroupCard[]
    budget: {
        current: string | null
        anyUrl: string
        options: BudgetOption[]
    }
    moreFor: {
        title: string
        links: PageLink[]
        /** The recipient's own page, from a recipient × interest page. */
        hub: string | null
    }
    sameInterest: { title: string; links: PageLink[] } | null
    finderUrl: string
}

/**
 * A gift landing page: "Gift ideas for dad who loves cooking".
 *
 * The heading is the phrase people search for, the products come from the
 * suggestion engine, and the links underneath go only to pages that exist
 * (the server resolves them). Nothing here is written by a model. See
 * docs/features/gift-landing-pages.md.
 */
export default function Landing({ heading, intro, products, budget, moreFor, sameInterest, finderUrl }: Props) {
    const { market, seoTitle } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const bandLabel = (option: BudgetOption): string => {
        if (option.min === null && option.max !== null) {
            return t('gift_landing.budget_under', { max: formatPrice(option.max, market) })
        }

        if (option.max === null && option.min !== null) {
            return t('gift_landing.budget_over', { min: formatPrice(option.min, market) })
        }

        return t('gift_landing.budget_between', {
            min: formatPrice(option.min ?? 0, market),
            max: formatPrice(option.max ?? 0, market),
        })
    }

    const chip = (active: boolean) =>
        `rounded-full border px-3 py-1 text-sm transition ${
            active ? 'border-ink bg-ink text-white' : 'border-line bg-card text-ink hover:border-ink/40'
        }`

    return (
        <>
            <Head title={seoTitle ?? heading} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{heading}</h1>
                <p className="mt-2 text-ink-soft">{intro}</p>
            </header>

            {/* A budget narrows this page; it does not make a new one. */}
            <nav aria-label={t('gift_landing.budget')} className="mt-6 flex flex-wrap items-center gap-2">
                <span className="text-sm text-ink-soft">{t('gift_landing.budget')}</span>
                <Link href={budget.anyUrl} className={chip(budget.current === null)} preserveScroll>
                    {t('gift_landing.budget_any')}
                </Link>
                {budget.options.map((option) => (
                    <Link
                        key={option.param}
                        href={option.url}
                        className={chip(budget.current === option.param)}
                        preserveScroll
                    >
                        {bandLabel(option)}
                    </Link>
                ))}
            </nav>

            {products.length === 0 ? (
                <p className="mt-8 text-ink-soft">{t('gift.no_results')}</p>
            ) : (
                <ul className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {products.map((product) => (
                        <li key={product.id} className="flex">
                            <ProductCard group={product} />
                        </li>
                    ))}
                </ul>
            )}

            <section className="mt-10 max-w-2xl rounded-card border border-line bg-card p-5">
                <h2 className="font-medium">{t('gift_landing.finder_title')}</h2>
                <p className="mt-1 text-sm text-ink-soft">{t('gift_landing.finder_body')}</p>
                <Link href={finderUrl} className="mt-3 inline-block text-sm font-medium text-accent-dark underline hover:text-ink">
                    {t('gift_landing.finder_link')}
                </Link>
            </section>

            {(moreFor.links.length > 0 || moreFor.hub) && (
                <section className="mt-10">
                    <h2 className="text-lg font-semibold">
                        {moreFor.hub ? (
                            <Link href={moreFor.hub} className="hover:text-accent hover:underline">
                                {moreFor.title}
                            </Link>
                        ) : (
                            moreFor.title
                        )}
                    </h2>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {moreFor.links.map((link) => (
                            <li key={link.url}>
                                <Link href={link.url} className={chip(false)}>
                                    {link.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {sameInterest && sameInterest.links.length > 0 && (
                <section className="mt-8">
                    <h2 className="text-lg font-semibold">{sameInterest.title}</h2>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {sameInterest.links.map((link) => (
                            <li key={link.url}>
                                <Link href={link.url} className={chip(false)}>
                                    {link.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    )
}
