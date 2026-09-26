import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import Button, { buttonClasses } from './Button'
import ToolIcon from './ToolIcon'
import { send } from '../http'
import { useTranslations } from '../useTranslations'

export interface GiftProfileCardProps {
    /** "Emma's gift profile", or "A gift profile" when no name was typed. */
    title: string
    /** "coffee, walking, around €30 to €60" */
    summary: string
    /** This or that, to make one of your own. */
    makeOwn: string
    /** Set only for the card's maker, who may take it down. */
    remove: string | null
}

/**
 * The top of the Gift Finder when it was opened from somebody's gift profile
 * card (docs/features/gift-profile-card.md).
 *
 * The answers below are already filled in from the card, so the one thing to
 * do is look at ideas, and the one thing to offer is a card of your own. The
 * maker, recognised by their key in the session or by their account, also
 * gets the button that takes the card down.
 */
export default function GiftProfileCardBanner({ card, onSeeIdeas }: { card: GiftProfileCardProps; onSeeIdeas: () => void }) {
    const { t } = useTranslations()
    const [removing, setRemoving] = useState(false)
    const [failed, setFailed] = useState(false)

    const remove = () => {
        if (!card.remove) {
            return
        }

        setRemoving(true)
        setFailed(false)
        send<{ redirect: string }>(card.remove, 'DELETE', {})
            .then(({ redirect }) => router.visit(redirect))
            .catch(() => {
                setFailed(true)
                setRemoving(false)
            })
    }

    return (
        <section className="mt-6 max-w-2xl rounded-card border border-accent/40 bg-accent/5 p-5 sm:p-6">
            <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-card text-accent">
                    <ToolIcon name="taste" className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <h2 className="font-medium">{card.title}</h2>
                    {card.summary !== '' && <p className="mt-1 text-lg">{card.summary}</p>}
                    <p className="mt-2 text-sm text-ink-soft">{t('gift.card.prefilled')}</p>
                </div>
            </div>

            <div className="mt-4 flex flex-wrap gap-3">
                <Button onClick={onSeeIdeas}>{t('gift.card.see_ideas')}</Button>
                <Link href={card.makeOwn} className={buttonClasses('secondary')}>
                    {t('gift.card.make_own')}
                </Link>
                {card.remove && (
                    <Button variant="ghost" busy={removing} onClick={remove}>
                        {t('gift.card.remove')}
                    </Button>
                )}
            </div>

            {failed && (
                <p role="alert" className="mt-3 text-sm text-danger">
                    {t('gift.taste.save_failed')}
                </p>
            )}
        </section>
    )
}
