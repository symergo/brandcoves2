import { Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ToolIcon from './ToolIcon'
import { ensureCsrfToken } from '../http'
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
 * One line above the header, on every page: feedback, suggestions and votes
 * are welcome (owner, 2026-09-27; docs/features/contribute.md). It sat under
 * the header, in the card colour, until the owner asked the same day for a
 * better place and "more visible".
 *
 * Visible, not loud: a light accent tint (never the solid accent, which is
 * each page's main action), the icon on every width, one line on a phone (the
 * short sentence below `lg`), and a close button. Closing hides it at once and posts `/contribute-bar`, whose
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

        void ensureCsrfToken()
            .then((token) =>
                fetch('/contribute-bar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    // Survives a full page load started right after the press, which
                    // would otherwise cancel the request and bring the bar back.
                    keepalive: true,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
                }),
            )
            .catch(() => {})
    }

    return (
        <aside aria-label={t('contribute.bar_label')} className="border-b border-accent/20 bg-accent/10 text-sm">
            <div className="mx-auto flex max-w-6xl items-center gap-2 py-1 pr-1 pl-4 sm:pr-3">
                {/*
                  The whole row is the link, not only "Denk mee" (owner,
                  2026-09-27): a one-line bar is one target. Only the close
                  button sits outside it.
                */}
                <Link
                    href={`/${market.key}/contribute`}
                    className="group flex min-h-9 min-w-0 flex-1 items-center gap-2 text-ink hover:text-ink"
                >
                    <span className="shrink-0 text-accent-dark">
                        <ToolIcon name="suggestions" className="h-4 w-4" />
                    </span>
                    {/* Not `flex-1`: "Denk mee" follows the sentence (owner,
                        2026-09-27) instead of being pushed against the close
                        button. The row itself still fills the width, so all
                        of it stays one link. */}
                    <span className="min-w-0 truncate">
                        <span className="lg:hidden">{t('contribute.bar_text_short')}</span>
                        <span className="hidden lg:inline">{t('contribute.bar_text')}</span>
                    </span>
                    <span className="inline-flex shrink-0 items-center font-medium whitespace-nowrap text-accent-dark group-hover:text-ink group-hover:underline">
                        {t('contribute.bar_link')}
                        <span aria-hidden className="ml-1">
                            →
                        </span>
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
