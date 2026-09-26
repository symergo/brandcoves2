import { Head, Link } from '@inertiajs/react'
import FeedbackForm from '../Components/FeedbackForm'
import ToolIcon, { type ToolKey } from '../Components/ToolIcon'
import { useTranslations } from '../useTranslations'

interface Props {
    guides: { key: string; url: string }[]
    path: string | null
}

/**
 * How GiftCoves works, and where to say it does not.
 *
 * ## One page for the whole site (2026-09-26)
 *
 * The header's "How it works" and the homepage's "How GiftCoves works" both
 * land here. Until then the header's entry opened the list tools' manual,
 * which explains lists and nothing else, and has no form: somebody looking for
 * support found neither the search, nor what a Cove is, nor a way to tell us
 * something was wrong (the owner's report, 2026-09-26). This page now says, in
 * order, what a Cove is, how you find things, how you add anything, how you
 * share and give, and where products come from; then the detailed guides; then
 * the form.
 *
 * ## The order is the odds
 *
 * Explanation first, form last. Most people arriving here are stuck rather than
 * reporting a fault, and a form at the top asks them to describe a problem they
 * would rather just solve.
 *
 * ## The form is the real one
 *
 * `FeedbackForm` is the component `/feedback` rendered, not a copy of it. Two
 * forms posting to one endpoint drift: the honeypot gets added to one, the line
 * explaining what the address is for gets rewritten on the other.
 */
export default function Help({ guides, path }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('help.title')} />

            <div className="mx-auto max-w-3xl py-4 sm:py-8">
                <h1 className="text-3xl font-semibold tracking-tight text-balance text-ink sm:text-4xl">
                    {t('help.title')}
                </h1>
                <p className="mt-3 max-w-2xl text-lg text-ink-soft">{t('help.intro')}</p>

                {/* What a Cove is: the one word on this site nobody knows yet. */}
                <section className="mt-10 rounded-card bg-accent/5 p-6 sm:p-8" aria-labelledby="help-coves">
                    <h2 id="help-coves" className="text-xl font-semibold tracking-tight sm:text-2xl">
                        {t('help.coves_title')}
                    </h2>
                    <div className="mt-3 max-w-2xl space-y-3 text-ink-soft">
                        <p>{t('help.coves_body1')}</p>
                        <p>{t('help.coves_body2')}</p>
                        <p>{t('help.coves_body3')}</p>
                        {/* Making one: the one-step create (docs/features/one-step-list.md). */}
                        <p>{t('help.coves_make')}</p>
                        <p>{t('help.coves_body4')}</p>
                    </div>
                </section>

                <Topic
                    id="help-find"
                    title={t('help.find_title')}
                    items={[
                        { icon: 'search', text: t('help.find_search') },
                        { icon: 'search', text: t('help.find_search_results') },
                        { icon: 'barcode', text: t('help.find_scan') },
                        { icon: 'info', text: t('help.find_country') },
                        { icon: 'whisperer', text: t('help.find_gift') },
                        { icon: 'suggestions', text: t('help.find_ask') },
                        { icon: 'people', text: t('people.help') },
                        { icon: 'people', text: t('help.find_history') },
                        { icon: 'alerts', text: t('help.find_reminders') },
                        { icon: 'taste', text: t('help.find_taste') },
                        { icon: 'taste', text: t('help.find_taste_together') },
                        { icon: 'taste', text: t('help.find_taste_card') },
                        { icon: 'build', text: t('help.find_offline_ideas') },
                        { icon: 'giftlist', text: t('help.find_pages') },
                        { icon: 'giftlist', text: t('help.find_personas') },
                        { icon: 'search', text: t('help.find_filters') },
                        { icon: 'guides', text: t('help.find_browse') },
                        { icon: 'wishlist', text: t('help.find_product') },
                        { icon: 'whisperer', text: t('help.find_crowd') },
                    ]}
                />

                <Topic
                    id="help-add"
                    title={t('help.add_title')}
                    items={[
                        { icon: 'link', text: t('help.add_link') },
                        { icon: 'barcode', text: t('help.add_barcode') },
                        { icon: 'picture', text: t('help.add_photo') },
                        { icon: 'build', text: t('help.add_write') },
                        { icon: 'wishlist', text: t('help.add_overview') },
                    ]}
                />

                <Topic
                    id="help-share"
                    title={t('help.share_title')}
                    items={[
                        { icon: 'shared', text: t('help.share_link') },
                        { icon: 'people', text: t('help.share_people') },
                        { icon: 'suggestions', text: t('help.share_ask') },
                        { icon: 'whisperer', text: t('help.share_like_this') },
                        { icon: 'collab', text: t('help.share_together') },
                        { icon: 'santa', text: t('help.share_santa') },
                        { icon: 'people', text: t('help.share_publish') },
                    ]}
                />

                <section className="mt-10" aria-labelledby="help-honest">
                    <h2 id="help-honest" className="text-xl font-semibold tracking-tight">
                        {t('help.honest_title')}
                    </h2>
                    <p className="mt-2 max-w-2xl text-ink-soft">{t('help.honest_body')}</p>
                    <p className="mt-3 max-w-2xl text-ink-soft">{t('help.honest_lists')}</p>
                </section>

                <h2 className="mt-12 text-xl font-semibold tracking-tight">{t('help.guides_heading')}</h2>

                <ul className="mt-4 grid gap-3 sm:grid-cols-3">
                    {guides.map((guide) => (
                        <li key={guide.key}>
                            <Link
                                href={guide.url}
                                className="block h-full rounded-card border border-line bg-card p-4 transition hover:border-ink"
                            >
                                <span className="font-medium text-ink">{t(`help.${guide.key}_title`)}</span>
                                <span className="mt-1 block text-sm text-ink-soft">
                                    {t(`help.${guide.key}_blurb`)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>

                {/*
                  A rule, not just space. Everything above answers "how does this
                  work"; everything below is for when the answer is "it does not".
                  The heading says so in a few words, because the form is now at
                  the end of a long page and has to be findable by scrolling.
                */}
                <section id="contact" className="mt-12 scroll-mt-8 border-t border-line pt-10" aria-labelledby="help-contact">
                    <h2 id="help-contact" className="text-xl font-semibold tracking-tight">
                        {t('help.contact_title')}
                    </h2>
                    <div className="mt-4">
                        <FeedbackForm path={path} />
                    </div>
                </section>
            </div>
        </>
    )
}

function Topic({ id, title, items }: { id: string; title: string; items: { icon: ToolKey; text: string }[] }) {
    return (
        <section className="mt-10" aria-labelledby={id}>
            <h2 id={id} className="text-xl font-semibold tracking-tight">
                {title}
            </h2>
            <ul className="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                {items.map((item) => (
                    <li key={item.text} className="flex gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                            <ToolIcon name={item.icon} className="h-5 w-5" />
                        </span>
                        <span className="text-ink-soft">{item.text}</span>
                    </li>
                ))}
            </ul>
        </section>
    )
}
