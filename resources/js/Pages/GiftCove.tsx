import { Head, Link, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Props {
    signedIn: boolean
    mine: { id: string; title: string; items: number; shared: boolean; url: string } | null
    counts: {
        giftLists: number
        people: number
        registries: number
        santa: number
        suggestions: number
    }
    santaGroups: { title: string; drawn: boolean; url: string }[]
    urls: { gift: string; lists: string; santa: string }
}

/**
 * The Gift Cove.
 *
 * Every gifting tool in one place, each with a sentence saying what it is for.
 * They arrived one at a time and were each reachable from somewhere different,
 * so they were individually findable and collectively invisible — nobody could
 * see they were parts of one thing.
 *
 * The explanations are the point. "Secret Santa" needs none; "a list you build
 * for somebody and then hand over to them" needs one, and a tool nobody
 * understands is a tool nobody opens.
 */
export default function GiftCove({ signedIn, mine, counts, santaGroups, urls }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    /*
     * Each card starts its tool, rather than describing it and then dropping
     * you on an index.
     *
     * Six of the nine used to point at `urls.lists`, so reading "a list you
     * build for somebody and then hand over to them" and pressing it got you a
     * page of your existing lists and no clue which button began that. The three
     * that begin with a list *for someone* now open the create form on that
     * shape; the ones that act on a list you already have open that list.
     */
    const forSomeone = `${urls.lists}?new=for_someone`

    const tools = [
        {
            key: 'wishlist',
            href: mine?.url ?? urls.lists,
            badge: mine ? t('gift_cove.items_count', { count: n(mine.items) }) : null,
        },
        { key: 'giftlist', href: forSomeone, badge: counts.giftLists ? n(counts.giftLists) : null },
        { key: 'collab', href: forSomeone, badge: null },
        { key: 'handover', href: forSomeone, badge: null },
        { key: 'santa', href: urls.santa, badge: counts.santa ? n(counts.santa) : null },
        { key: 'registry', href: mine?.url ?? urls.lists, badge: counts.registries ? n(counts.registries) : null },
        { key: 'quiz', href: mine?.url ?? urls.lists, badge: null },
        { key: 'suggestions', href: urls.lists, badge: counts.suggestions ? n(counts.suggestions) : null },
        { key: 'whisperer', href: urls.gift, badge: counts.people ? n(counts.people) : null },
    ]

    return (
        <>
            <Head title={t('gift_cove.title')} />

            <header className="max-w-2xl">
                <h1 className="text-3xl font-semibold tracking-tight">{t('gift_cove.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('gift_cove.intro')}</p>
            </header>

            {/*
              Your own list first, and with its state on it. "3 things saved,
              not shared yet" is a next action; a link labelled "My wishlist" is
              a filing cabinet.
            */}
            {mine && (
                <section className="mt-8 max-w-2xl rounded-card border border-accent/40 bg-accent/5 p-6">
                    <h2 className="text-lg font-medium">{mine.title}</h2>
                    <p className="mt-1 text-sm text-ink-soft">
                        {t('gift_cove.items_count', { count: n(mine.items) })}
                        {' · '}
                        {mine.shared ? t('lists.sharing_on') : t('lists.sharing_off')}
                    </p>
                    <Link
                        href={mine.url}
                        className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {mine.items === 0 ? t('gift_cove.start_list') : t('gift_cove.open_list')}
                    </Link>
                </section>
            )}

            {!signedIn && (
                <p className="mt-6 max-w-2xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                    {t('lists.sign_in_hint')}{' '}
                    <Link href={`${base}/login`} className="underline">
                        {t('nav.sign_in')}
                    </Link>
                </p>
            )}

            <section className="mt-12">
                <h2 className="text-lg font-medium">{t('gift_cove.tools')}</h2>

                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {tools.map((tool) => (
                        <li key={tool.key}>
                            <Link
                                href={tool.href}
                                className="flex h-full flex-col rounded-card border border-line bg-card p-6 transition hover:border-ink"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <h3 className="font-medium">{t(`gift_cove.${tool.key}_title`)}</h3>
                                    {tool.badge && (
                                        <span className="shrink-0 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                            {tool.badge}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-2 text-sm text-ink-soft">
                                    {t(`gift_cove.${tool.key}_body`)}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            {santaGroups.length > 0 && (
                <section className="mt-12">
                    <h2 className="text-lg font-medium">{t('santa.title')}</h2>
                    <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                        {santaGroups.map((group) => (
                            <li key={group.url}>
                                <Link
                                    href={group.url}
                                    className="block rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                >
                                    <span className="font-medium">{group.title}</span>
                                    <span className="mt-1 block text-sm text-ink-soft">
                                        {group.drawn ? t('santa.drawn') : t('santa.not_drawn')}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <p className="mt-12 max-w-2xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                {t('gift_cove.privacy')}
            </p>
        </>
    )
}
