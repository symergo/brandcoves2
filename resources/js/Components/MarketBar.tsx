import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import FlagIcon, { type FlagCountry } from './FlagIcon'
import { chooseMarket, rememberMarket } from '../marketChoice'
import ToolIcon from './ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/** Session key holding the market whose bar was closed without recording. */
// Where a closed bar is remembered, per market. localStorage since 2026-11-15
// (it was sessionStorage): the bar now also offers the country a visitor is in
// to somebody who chose another market, and that offer must stay away once it
// is clicked away, not come back with every new browser session.
const HIDDEN_KEY = 'bc_market_bar_hidden'

/**
 * One line above the header: which country's shops this page shows, and the
 * other countries one tap away.
 *
 * It replaced a dialog (2026-09-26, the owner's UX audit). On a first visit,
 * and worst on a phone, "Where are you?" covered the page before a word of it
 * could be read. The question still matters: a Belgian browser set to plain
 * "Nederlands" reports `nl-NL` and lands on the Dutch shops, and nothing else
 * on the page says so. But it can be asked without standing in the way. The
 * bar is in the flow, not over it: it pushes the header down by one line and
 * covers nothing, and ignoring it is allowed.
 *
 * ## When it shows, and what closing it does
 *
 * The server decides (App\Support\MarketPreference::bar()), in two cases:
 *
 * - **No choice on file.** The bar says which country we are showing (it
 *   listed the others too until 2026-11-15). When this page is the country the browser language
 *   points at, closing it means "yes, that is right" and records it
 *   (`remember`), by the switcher's own POST, so it does not come back.
 * - **Another country than the one you chose**, or than the one your browser
 *   points at: a friend's shared `/nl-nl/...` list opened in Belgium. The
 *   bar offers your country first (`suggest`), and closing it only hides it
 *   for this browser session. It must not record anything: closing a bar is
 *   not saying "the Netherlands is my home now", and a guess must never turn
 *   itself into a choice (docs/features/market-routing.md).
 *
 * Choosing a country is the switcher's full-page POST, exactly as the header
 * country button does, so there is still one way a choice is written down.
 */
export default function MarketBar() {
    const { marketBar, market, markets } = usePage<SharedProps>().props
    const { t } = useTranslations()

    // Seeded from the server so the bar is in the first paint (no jump), then
    // owned by the client so a press hides it at once.
    const [open, setOpen] = useState(marketBar !== null)

    /*
     * A bar hidden without recording anything stays hidden for this session,
     * per market: someone reading a Dutch list closed it once and should not
     * meet it on every item they open. The browser's own storage, not a cookie, because
     * it is a convenience and the server has no business knowing it. It is
     * read after the first paint, so a hidden bar can show for a frame on a
     * full page load; the alternative was a server-side flag, i.e. state the
     * rule says only the switcher may write.
     */
    useEffect(() => {
        if (marketBar === null || marketBar.remember) return

        try {
            if (window.localStorage.getItem(HIDDEN_KEY) === market.key) setOpen(false)
        } catch {
            // Storage blocked: the bar just shows, which is the safe side.
        }
    }, [marketBar, market.key])

    const current = markets.find((c) => c.languages.some((l) => l.market === market.key)) ?? null

    if (!open || marketBar === null || current === null) {
        return null
    }


    /*
     * The other countries, the suggested one first. A country is entered in
     * the language the visitor is reading where it has it (Belgium in French
     * stays French), the rule the header's flags followed until they became
     * one button on 2026-09-26 that lists every market by name. A suggested
     * market is entered as named, because that is the one they chose.
     */
    const suggestedCountry = marketBar.suggest
        ? markets.find((c) => c.languages.some((l) => l.market === marketBar.suggest))
        : undefined

    // The one country the bar offers: straight to the market the server suggested.
    const go = () => {
        if (marketBar.suggest) chooseMarket(marketBar.suggest)
    }

    const close = () => {
        setOpen(false)

        if (marketBar.remember) {
            rememberMarket(market.key)
            return
        }

        try {
            window.localStorage.setItem(HIDDEN_KEY, market.key)
        } catch {
            // Nothing to do: it comes back on the next page, harmlessly.
        }
    }

    return (
        // The page's own colour, like the Denk mee bar (owner, 2026-10-05:
        // the bars on top should not jump out). It was white, a strip that
        // stood out once the page itself became a soft orange.
        //
        // Except when the visitor is in, or chose, another country than this
        // page's (owner, 2026-11-15: "show a top banner to guide them to the
        // proper country page", asked for a Belgian or Dutch visitor on an
        // English page). Then the bar is the one thing to notice: tinted, saying
        // which country the page is for, with that visitor's country as a
        // filled button. The other countries stay as quiet buttons beside it.
        <aside
            aria-label={t('market_bar.label')}
            className={`border-b text-sm ${suggestedCountry ? 'border-accent/40 bg-accent/10' : 'border-line bg-cream'}`}
        >
            {/* One line on a phone when it guides (owner, 2026-11-15: it stood three lines tall):
                the question, a short button and the close. The full sentence, the country in the
                button and the other countries come back from `sm`, where there is room. */}
            <div
                className="mx-auto flex max-w-[96rem] items-center gap-x-3 gap-y-1 py-1.5 pr-1 pl-4 sm:pr-3"
            >
                {suggestedCountry ? (
                    <p className="flex min-w-0 flex-1 items-center gap-x-2 text-ink sm:flex-none sm:flex-wrap sm:gap-x-3 sm:gap-y-1.5 sm:py-1">
                        <span className="min-w-0 truncate font-medium sm:hidden">
                            {t('market_bar.elsewhere_short', { country: suggestedCountry.name })}
                        </span>
                        <span className="hidden font-medium sm:inline">
                            {t('market_bar.elsewhere', { country: suggestedCountry.name, current: current.name })}
                        </span>
                        <button
                            type="button"
                            onClick={go}
                            aria-label={t('market_bar.go', { country: suggestedCountry.name })}
                            className="flex min-h-8 shrink-0 items-center gap-1.5 rounded-full bg-accent px-3 font-semibold whitespace-nowrap text-white transition hover:bg-accent-dark sm:min-h-9 sm:gap-2 sm:px-4"
                        >
                            <FlagIcon
                                country={suggestedCountry.country as FlagCountry}
                                className="block h-3 w-[18px] rounded-[2px] ring-1 ring-white/60"
                            />
                            <span className="sm:hidden">{t('market_bar.go_short')}</span>
                            <span className="hidden sm:inline">
                                {t('market_bar.go', { country: suggestedCountry.name })}
                            </span>
                        </button>
                    </p>
                ) : (
                    <p className="flex min-w-0 items-center gap-2 text-ink-soft">
                        <FlagIcon
                            country={current.country as FlagCountry}
                            className="block h-3 w-[18px] shrink-0 rounded-[2px] ring-1 ring-line"
                        />
                        <span>{t('market_bar.showing', { country: current.name })}</span>
                    </p>
                )}

                <div className="ml-auto flex items-center gap-1">
                    {/* No other countries here (owner, 2026-11-15: "don't show the other
                        countries"): the bar says which country the page is for and, when it
                        guides, offers the visitor's own. Any other is in the header's switcher. */}
                    <button
                        type="button"
                        onClick={close}
                        aria-label={
                            marketBar.remember
                                ? t('market_bar.keep', { country: current.name })
                                : t('market_bar.hide')
                        }
                        title={
                            marketBar.remember
                                ? t('market_bar.keep', { country: current.name })
                                : t('market_bar.hide')
                        }
                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink"
                    >
                        <ToolIcon name="close" className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </aside>
    )
}
