import { Head, useForm, usePage } from '@inertiajs/react'
import type { Cents, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Props {
    group: {
        id: string
        title: string
        token: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        theme: string | null
        drawn: boolean
        members: number
    }
    members: string[]
    you: { name: string | null; email: string | null }
}

/**
 * The page an invite link opens.
 *
 * There was none. `join` was a POST-only route and the invite the organiser
 * shares is that exact URL, so every link sent through a group chat answered
 * with 405 Method Not Allowed — and since no join form existed anywhere either,
 * nobody but the organiser had ever been in a group.
 *
 * No account asked for. Requiring a login before somebody can be in an office
 * Secret Santa is how most of the office does not join; the email is what the
 * draw needs to reach them, and the join token is what gets them back in.
 */
export default function SantaJoin({ group, members, you }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const form = useForm({
        display_name: you.name ?? '',
        email: you.email ?? '',
        exclusions: '',
    })

    const budget =
        group.budgetMax === null
            ? null
            : group.budgetMin === null || group.budgetMin === group.budgetMax
              ? formatPrice(group.budgetMax, market)
              : `${formatPrice(group.budgetMin, market)} – ${formatPrice(group.budgetMax, market)}`

    return (
        <>
            {/* An invite is a private URL. It must never be indexed. */}
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="mx-auto max-w-xl">
                <p className="text-xs tracking-wide text-ink-soft uppercase">{t('santa.title')}</p>
                <h1 className="mt-1 text-2xl font-semibold">{group.title}</h1>

                <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-soft">
                    {budget && (
                        <div>
                            <dt className="inline">{t('santa.budget')}: </dt>
                            <dd className="inline font-medium text-ink">{budget}</dd>
                        </div>
                    )}
                    {group.exchangeDate && (
                        <div>
                            <dt className="inline">{t('santa.exchange_date')}: </dt>
                            <dd className="inline font-medium text-ink">{group.exchangeDate}</dd>
                        </div>
                    )}
                    <div>
                        <dt className="inline">{t('santa.members')}: </dt>
                        <dd className="inline font-medium text-ink">{n(group.members)}</dd>
                    </div>
                </dl>

                {group.theme && <p className="mt-3 text-ink-soft">{group.theme}</p>}

                {/*
                  Who else is in. Not a secret, and it is what tells somebody
                  they have opened the right group rather than a stranger's.
                */}
                {members.length > 0 && (
                    <p className="mt-4 text-sm text-ink-soft">{members.join(', ')}</p>
                )}

                {group.drawn ? (
                    /*
                      Said before they type anything. Joining closes at the draw
                      — a member added afterwards has nobody to buy for and
                      nobody buying for them — and discovering that from a 403
                      after filling in a form is the worst version of it.
                    */
                    <p className="mt-8 rounded-card border border-amber/40 bg-amber/10 p-4">
                        {t('santa.already_drawn')}
                    </p>
                ) : (
                    <form
                        className="mt-8 space-y-4 rounded-card border border-line bg-card p-5"
                        onSubmit={(e) => {
                            e.preventDefault()
                            // A line of names, as people actually write them,
                            // turned into the array the endpoint validates.
                            form.transform((data) => ({
                                ...data,
                                exclusions: data.exclusions
                                    .split(',')
                                    .map((value) => value.trim())
                                    .filter(Boolean),
                            }))

                            form.post(`/${market.key}/santa/${group.id}/join/${group.token}`)
                        }}
                    >
                        <div>
                            <label className="block text-sm font-medium" htmlFor="display_name">
                                {t('santa.your_name')}
                            </label>
                            <input
                                id="display_name"
                                required
                                maxLength={80}
                                value={form.data.display_name}
                                onChange={(e) => form.setData('display_name', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2"
                            />
                            {form.errors.display_name && (
                                <p className="mt-1 text-sm text-accent">{form.errors.display_name}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium" htmlFor="email">
                                {t('santa.your_email')}
                            </label>
                            <input
                                id="email"
                                type="email"
                                required
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2"
                            />
                            <p className="mt-1 text-xs text-ink-soft">{t('santa.email_hint')}</p>
                            {form.errors.email && (
                                <p className="mt-1 text-sm text-accent">{form.errors.email}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium" htmlFor="exclusions">
                                {t('santa.exclusions')}
                            </label>
                            <input
                                id="exclusions"
                                value={form.data.exclusions}
                                onChange={(e) => form.setData('exclusions', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2"
                            />
                            <p className="mt-1 text-xs text-ink-soft">{t('santa.exclusions_hint')}</p>
                        </div>

                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-60"
                        >
                            {t('santa.join')}
                        </button>
                    </form>
                )}
            </div>
        </>
    )
}
