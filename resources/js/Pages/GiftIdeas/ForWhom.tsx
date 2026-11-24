import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Group {
    recipient: string
    /** "Cadeau-ideeën voor papa": the person's own page. */
    title: string
    /** Null when the planner recorded interest pages for this person but not their own page. */
    url: string | null
    /** "Papa die graag kookt": one link per interest page, written ones first. */
    interests: { label: string; url: string }[]
}

interface Props {
    groups: Group[]
    giftIdeasUrl: string
}

/**
 * Every gift landing page of the market, per person (2026-11-24, owner: "The landing pages have
 * no real entry point"). Linked from the Gift Ideas page and the footer; the pages themselves are
 * GiftLandingController's. Plain links, all of them crawlable: this page is how a search engine
 * walks from the site to the ~430 pages, and how a visitor who knows who the gift is for gets there
 * in one step.
 */
export default function ForWhom({ groups, giftIdeasUrl }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('gift_ideas.for_whom_title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift_ideas.for_whom_title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift_ideas.for_whom_intro')}</p>
            </header>

            <div className="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                {groups.map((group) => (
                    <section key={group.recipient} aria-labelledby={`for-${group.recipient}`}>
                        <h2 id={`for-${group.recipient}`} className="text-lg font-semibold tracking-tight">
                            {group.url ? (
                                <Link href={group.url} className="hover:text-accent">
                                    {group.title}
                                </Link>
                            ) : (
                                group.title
                            )}
                        </h2>
                        {group.interests.length > 0 && (
                            <ul className="mt-2 space-y-1 text-sm">
                                {group.interests.map((page) => (
                                    <li key={page.url}>
                                        <Link href={page.url} className="text-ink-soft hover:text-accent">
                                            {page.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>

            <p className="mt-10 text-sm">
                <Link href={giftIdeasUrl} className="text-accent-dark underline hover:text-ink">
                    {t('gift_ideas.title')}
                </Link>
            </p>
        </>
    )
}
