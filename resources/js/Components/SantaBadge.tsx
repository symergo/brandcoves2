import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

/**
 * The pill that says "this is a Secret Friend group", beside the group's title.
 *
 * A group page opened from a link is read the way a list card is: title first,
 * and nothing else said what kind of thing it was. Lists carry `ListKindBadge`
 * for exactly this; this is the same pill in the same accent, with the Secret
 * Friend mark the Gift Cove's tool grid already uses, so the group is named the
 * way the rest of the site names it. Added 2026-09-13 at the owner's request.
 *
 * Accent, like a group gift's badge: of the four things a person can be in,
 * this is the one with other people in it.
 */
export default function SantaBadge({ className = '' }: { className?: string }) {
    const { t } = useTranslations()

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full border border-accent/40 bg-accent/10 px-2 py-0.5 text-2xs font-medium text-ink ${className}`}
        >
            <ToolIcon name="santa" className="h-3.5 w-3.5 text-accent" />
            {t('santa.title')}
        </span>
    )
}
