import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ToolIcon from './ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Pages the bar stays off, by page component.
 *
 * The contribute page itself (it would point at where you are), signing in
 * (one thing to do there), and the pages somebody who is not a member was
 * sent to by somebody else: accepting or refusing an invitation, describing
 * yourself for a giver, playing a quiz or This or that, joining a Secret
 * Friend. Those people came to do one thing for someone, and "help us build
 * GiftCoves" is not a question for them yet.
 */
const HIDDEN_ON = new Set([
    'Contribute',
    'Auth/Login',
    'Invites/Accept',
    'Invites/NotWanted',
    'Recipients/SelfDescribe',
    'Quiz/Play',
    'Gift/Taste',
    'Santa/Join',
])

/**
 * One line under the header: feedback, suggestions and votes are welcome
 * (owner, 2026-09-27; docs/features/contribute.md).
 *
 * Visible but quiet: the card background and muted text the market bar uses,
 * small type, one line on a phone (the short sentence below `lg`), and a
 * close button. Closing hides it at once and posts `/contribute-bar`, whose
 * answer carries a cookie the server reads for a year, so it never flashes
 * back on the next page's first paint (App\Support\ContributeBar). A failed
 * post is swallowed: the bar is already gone, and it comes back on the next
 * page, which is the honest result of a choice that was not recorded.
 *
 * Not shown while the adding-mode bar is up: that one describes what the
 * whole site is doing right now, and a second bar under it would bury it.
 */
export default function ContributeBar() {
    const page = usePage<SharedProps>()
    const { contributeBar, market, savingTo } = page.props
    const { t } = useTranslations()
    const [open, setOpen] = useState(true)

    if (!open || !contributeBar || savingTo !== null || HIDDEN_ON.has(page.component)) {
        return null
    }

    const close = () => {
        setOpen(false)

        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content

        void fetch('/contribute-bar', {
            method: 'POST',
            credentials: 'same-origin',
            // Survives a full page load started right after the press, which
            // would otherwise cancel the request and bring the bar back.
            keepalive: true,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
        }).catch(() => {})
    }

    return (
        <aside aria-label={t('contribute.bar_label')} className="border-b border-line bg-card text-sm">
            <div className="mx-auto flex max-w-6xl items-center gap-2 py-1 pr-1 pl-4 sm:pr-3">
                <span className="hidden shrink-0 text-accent sm:inline">
                    <ToolIcon name="suggestions" className="h-4 w-4" />
                </span>
                <p className="min-w-0 flex-1 truncate text-ink-soft">
                    <span className="lg:hidden">{t('contribute.bar_text_short')}</span>
                    <span className="hidden lg:inline">{t('contribute.bar_text')}</span>
                </p>
                <Link
                    href={`/${market.key}/contribute`}
                    className="inline-flex min-h-9 shrink-0 items-center font-medium whitespace-nowrap text-accent-dark hover:text-ink"
                >
                    {t('contribute.bar_link')}
                    <span aria-hidden className="ml-1">
                        →
                    </span>
                </Link>
                <button
                    type="button"
                    onClick={close}
                    aria-label={t('contribute.bar_close')}
                    title={t('contribute.bar_close')}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink"
                >
                    <ToolIcon name="close" className="h-4 w-4" />
                </button>
            </div>
        </aside>
    )
}
