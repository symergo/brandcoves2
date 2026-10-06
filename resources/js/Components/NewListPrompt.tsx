import { useState } from 'react'
import type { Panel } from './ListTools'
import ToolIcon from './ToolIcon'
import { useTranslations } from '../useTranslations'

/**
 * What the one-step create stopped asking, offered once on the new list.
 *
 * The three-step wizard asked for an occasion and for sharing before the list
 * existed. The one-step create (docs/features/one-step-list.md) asks only who
 * the list is for, so those questions moved here: the same panels the tools
 * row has always opened, named in one line the first time the list is shown.
 * One line and not a form, because the add field under it is what this page
 * is for right now; the row of tools above stays for every visit after.
 *
 * Shown on the page the create redirects to (flash `newList`), for the owner,
 * and gone after the next request or a press of "Later".
 */
export default function NewListPrompt({
    kind,
    askName,
    onPanel,
}: {
    kind: string
    /** The person's name when "ask them" is a panel on this list, else null. */
    askName: string | null
    onPanel: (panel: Panel) => void
}) {
    const { t } = useTranslations()
    const [shown, setShown] = useState(true)

    if (!shown) return null

    const open = (panel: Panel) => {
        setShown(false)
        onPanel(panel)
    }

    // Each with its tool's mark, as in the list's Meer menu (2026-10-06).
    const chip = 'inline-flex items-center gap-1.5 rounded-full border border-line bg-card px-3 py-1 text-sm hover:border-ink'

    return (
        <div className="mt-4 rounded-card border border-line bg-card p-4">
            <p className="text-sm text-ink-soft">{t('wizard.next_title')}</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <button type="button" className={chip} onClick={() => open('settings')}>
                    <ToolIcon name="settings" className="h-4 w-4 shrink-0" />
                    {t('wizard.next_occasion')}
                </button>
                <button type="button" className={chip} onClick={() => open('share')}>
                    <ToolIcon name="shared" className="h-4 w-4 shrink-0" />
                    {t(kind === 'mine' ? 'wizard.next_share' : 'wizard.next_share_others')}
                </button>
                {askName !== null && (
                    <button type="button" className={chip} onClick={() => open('ask')}>
                        <ToolIcon name="suggestions" className="h-4 w-4 shrink-0" />
                        {t('wizard.next_ask', { name: askName })}
                    </button>
                )}
                <button type="button" className="text-sm text-ink-soft underline hover:text-ink" onClick={() => setShown(false)}>
                    {t('wizard.next_dismiss')}
                </button>
            </div>
        </div>
    )
}
