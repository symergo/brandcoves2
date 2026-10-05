import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import FlagIcon, { type FlagCountry } from './FlagIcon'
import { chooseMarket, rememberMarket } from '../marketChoice'
import ToolIcon from './ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/** Session key holding the market whose bar was closed without recording. */
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
 * - **No choice on file.** The bar says which country we are showing and
 *   offers the others. When this page is the country the browser language
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
     * meet it on every item they open. sessionStorage, not a cookie, because
     * it is a convenience and the server has no business knowing it. It is
     * read after the first paint, so a hidden bar can show for a frame on a
     * full page load; the alternative was a server-side flag, i.e. state the
     * rule says only the switcher may write.
     */
    useEffect(() => {
        if (marketBar === null || marketBar.remember) return

        try {
            if (window.sessionStorage.getItem(HIDDEN_KEY) === market.key) setOpen(false)
        } catch {
            // Storage blocked: the bar just shows, which is the safe side.
        }
    }, [marketBar, market.key])

    const current = markets.find((c) => c.languages.some((l) => l.market === market.key)) ?? null

    if (!open || marketBar === null || current === null) {
        return null
    }

    const currentLanguage = current.languages.find((l) => l.market === market.key)

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

    const others = markets
        .filter((c) => c.country !== current.country)
        .sort((a, b) => Number(b === suggestedCountry) - Number(a === suggestedCountry))

    const go = (country: (typeof markets)[number]) => {
        if (country === suggestedCountry && marketBar.suggest) {
            chooseMarket(marketBar.suggest)
            return
        }

        const sameLanguage = country.languages.find((l) => l.language === currentLanguage?.language)
        chooseMarket((sameLanguage ?? country.languages[0]).market)
    }

    const close = () => {
        setOpen(false)

        if (marketBar.remember) {
            rememberMarket(market.key)
            return
        }

        try {
            window.sessionStorage.setItem(HIDDEN_KEY, market.key)
        } catch {
            // Nothing to do: it comes back on the next page, harmlessly.
        }
    }

    return (
        // The page's own colour, like the Denk mee bar (owner, 2026-10-05:
        // the bars on top should not jump out). It was white, a strip that
        // stood out once the page itself became a soft orange.
        <aside
            aria-label={t('market_bar.label')}
            className="border-b border-line bg-cream text-sm"
        >
            <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 py-1.5 pr-1 pl-4 sm:pr-3">
                <p className="flex min-w-0 items-center gap-2 text-ink-soft">
                    <FlagIcon
                        country={current.country as FlagCountry}
                        className="block h-3 w-[18px] shrink-0 rounded-[2px] ring-1 ring-line"
                    />
                    <span>{t('market_bar.showing', { country: current.name })}</span>
                </p>

                <div className="ml-auto flex items-center gap-1">
                    {others.map((country) => (
                        <button
                            key={country.country}
                            type="button"
                            onClick={() => go(country)}
                            className={`flex min-h-9 items-center gap-1.5 rounded-full px-2.5 whitespace-nowrap transition ${
                                country === suggestedCountry
                                    ? 'border border-accent font-medium text-ink'
                                    : 'text-ink-soft hover:text-ink'
                            }`}
                        >
                            <FlagIcon
                                country={country.country as FlagCountry}
                                className="block h-3 w-[18px] rounded-[2px] ring-1 ring-line"
                            />
                            {country.name}
                        </button>
                    ))}

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
