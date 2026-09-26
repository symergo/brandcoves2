import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Button from './Button'
import ShareRow from './ShareRow'
import ToolIcon from './ToolIcon'
import { formatPrice, type Cents, type SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export interface TasteTogetherState {
    /** The link to send, while it is open. */
    url: string | null
    open: boolean
    /** How many different people played. Never who, never when. */
    players: number
    max: number
    /** What they found between them, worked out on the server from every run. */
    profile: {
        interests: string[]
        avoid: string[]
        budgetMin: Cents | null
        budgetMax: Cents | null
        answered: number
    } | null
    thin: boolean
    applied: boolean
    urls: { open: string; stop: string; apply: string }
}

/**
 * "Find out together what :name likes", on the list for that person, for
 * its owner (docs/features/taste-together.md).
 *
 * Make a link, send it round, and read back a count and the combined result,
 * never who chose what. "Add this to :name" writes it to the person the way
 * This or that's "Save for" does; the players never write anything.
 */
export default function TasteTogetherPanel({
    name,
    state,
    className = 'mt-6',
}: {
    name: string
    state: TasteTogetherState
    /** Spacing from the caller: the list page opens it as a panel, with a close button in the corner. */
    className?: string
}) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const [busy, setBusy] = useState<string | null>(null)

    const act = (key: string, method: 'post' | 'delete', url: string) => {
        setBusy(key)
        router[method](url, {}, { preserveScroll: true, onFinish: () => setBusy(null) })
    }

    const interest = (v: string) => {
        const label = t(`gift.interests.${v}`)
        return label === `gift.interests.${v}` ? v : label
    }

    const profile = state.profile
    const found: string[] = []

    if (profile) {
        if (profile.interests.length > 0) {
            found.push(profile.interests.map(interest).join(', '))
        }
        if (profile.budgetMin !== null && profile.budgetMax !== null) {
            found.push(
                t('gift.taste.budget', {
                    min: formatPrice(profile.budgetMin, market),
                    max: formatPrice(profile.budgetMax, market),
                }),
            )
        }
    }

    const players =
        state.players === 0
            ? t('gift.together.players_none')
            : state.players === 1
              ? t('gift.together.players_one')
              : t('gift.together.players_many', { count: state.players })

    return (
        <section className={`rounded-card border border-line bg-card p-4 sm:p-5 ${className}`}>
            <div className="flex items-start gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                    <ToolIcon name="taste" className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <h2 className="font-medium">{t('gift.together.panel_title', { name })}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('gift.together.panel_hint', { name })}</p>
                </div>
            </div>

            {state.open && state.url ? (
                <div className="mt-4">
                    <ShareRow url={state.url} text={t('gift.together.share_text', { name })} />
                </div>
            ) : (
                <div className="mt-4">
                    {state.players > 0 && <p className="text-sm text-ink-soft">{t('gift.together.stopped_note')}</p>}
                    <Button
                        size="sm"
                        className="mt-2"
                        busy={busy === 'open'}
                        disabled={busy !== null}
                        onClick={() => act('open', 'post', state.urls.open)}
                    >
                        {t(state.players > 0 ? 'gift.together.make_new' : 'gift.together.make')}
                    </Button>
                </div>
            )}

            <p className="mt-3 text-sm">
                {players}
                {state.open && state.players >= state.max && ` ${t('gift.together.full_note', { max: state.max })}`}
            </p>

            {profile && (
                <div className="mt-3 rounded-lg bg-accent/5 p-4">
                    <h3 className="text-sm font-medium text-ink-soft">{t('gift.together.result')}</h3>
                    {found.length > 0 ? (
                        <p className="mt-1 text-lg">{found.join(', ')}</p>
                    ) : (
                        <p className="mt-1 text-ink-soft">{t('gift.together.nothing')}</p>
                    )}
                    {profile.avoid.length > 0 && (
                        <p className="mt-1 text-sm text-ink-soft">
                            {t('gift.taste.not_into', { list: profile.avoid.map(interest).join(', ') })}
                        </p>
                    )}
                    {state.thin && found.length > 0 && <p className="mt-2 text-sm text-ink-soft">{t('gift.taste.thin')}</p>}

                    {found.length > 0 && (
                        <div className="mt-3">
                            <Button
                                size="sm"
                                variant="secondary"
                                busy={busy === 'apply'}
                                disabled={busy !== null}
                                onClick={() => act('apply', 'post', state.urls.apply)}
                            >
                                {t('gift.together.apply', { name })}
                            </Button>
                            <p className="mt-2 text-xs text-ink-soft">
                                {state.applied ? t('gift.together.applied', { name }) : t('gift.together.apply_hint', { name })}
                            </p>
                        </div>
                    )}
                </div>
            )}

            {state.open && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="mt-3"
                    busy={busy === 'stop'}
                    disabled={busy !== null}
                    onClick={() => act('stop', 'delete', state.urls.stop)}
                >
                    {t('gift.together.stop')}
                </Button>
            )}
        </section>
    )
}
