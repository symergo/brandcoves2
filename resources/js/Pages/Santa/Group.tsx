import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Member {
    id: number
    name: string
    joined: boolean
    hasList: boolean
    done: boolean
    /* No giftee, ever. See below. */
}

interface Props {
    group: {
        id: string
        title: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        theme: string | null
        drawn: boolean
        inviteUrl: string
    }
    isOrganiser: boolean
    members: Member[]
    me: {
        joinToken: string
        name: string
        done: boolean
        hasList: boolean
        giftee: { name: string } | null
    } | null
}

/**
 * The group.
 *
 * Deliberately aggregate. The organiser sees who is in and how many have
 * finished shopping, and never who drew whom — v1 let the organiser read the
 * pairings outright, which quietly makes one player a spectator of everyone
 * else's game.
 */
export default function SantaGroup({ group, isOrganiser, members, me }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [copied, setCopied] = useState(false)

    const done = members.filter((m) => m.done).length

    async function copyInvite() {
        await navigator.clipboard.writeText(group.inviteUrl)
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }

    return (
        <>
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold">{group.title}</h1>
                <p className="mt-2 text-sm text-ink-soft">
                    {group.budgetMax !== null &&
                        `${t('santa.budget')}: ${formatPrice(group.budgetMax, market)}`}
                    {group.exchangeDate && ` · ${group.exchangeDate}`}
                    {group.theme && ` · ${group.theme}`}
                </p>

                {!group.drawn && (
                    <div className="mt-4 rounded-card border border-line bg-card p-4">
                        <p className="text-sm text-ink-soft">{t('santa.invite_hint')}</p>
                        <button
                            type="button"
                            onClick={copyInvite}
                            className="mt-3 rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {copied ? t('lists.copied') : t('santa.invite')}
                        </button>
                    </div>
                )}
            </header>

            {me && (
                <p className="mt-6 max-w-2xl rounded-card border border-line bg-card p-4 text-sm">
                    <a href={`/${market.key}/santa/${group.id}/me/${me.joinToken}`} className="underline">
                        {group.drawn && me.giftee
                            ? t('santa.you_have', { name: me.giftee.name })
                            : t('santa.not_drawn')}
                    </a>
                </p>
            )}

            <section className="mt-10">
                <h2 className="text-lg font-medium">{t('santa.members')}</h2>

                {group.drawn && (
                    <p className="mt-1 text-sm text-ink-soft">
                        {t('santa.done_count', {
                            done: String(done),
                            total: String(members.length),
                        })}
                    </p>
                )}

                <ul className="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                    {members.map((member) => (
                        <li key={member.id} className="flex items-center gap-4 p-4">
                            <span className="flex-1 text-sm font-medium">{member.name}</span>

                            {/*
                              Aggregate facts only: whether they have a list, and
                              whether they have finished. Never who they drew.
                            */}
                            {!member.hasList && (
                                <span className="text-xs text-ink-soft">
                                    {t('santa.build_yours')}
                                </span>
                            )}
                            {member.done && (
                                <span className="text-xs text-sage">{t('lists.sent')}</span>
                            )}
                        </li>
                    ))}
                </ul>

                {isOrganiser && !group.drawn && (
                    <button
                        type="button"
                        onClick={() => router.post(`/${market.key}/santa/${group.id}/draw`)}
                        disabled={members.length < 2}
                        className="mt-6 rounded-lg bg-accent px-5 py-2.5 font-medium text-white disabled:opacity-50"
                    >
                        {t('santa.draw')}
                    </button>
                )}
            </section>
        </>
    )
}
