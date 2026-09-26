import { usePage } from '@inertiajs/react'
import FlagIcon, { type FlagCountry } from './FlagIcon'
import Menu from './Menu'
import { chooseMarket } from '../marketChoice'
import type { SharedProps, SwitcherCountry } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Where you shop and what you read: one button in the header, one list in the
 * phone's menu.
 *
 * ## One button since 2026-09-26
 *
 * The header had three flags and, in Belgium, a language dropdown beside
 * them (below). The owner replaced them with one button that says where you
 * are, the flag and the language ("🇧🇪 NL ▾"), and opens a short list of the
 * markets. Three reasons: the flags plus the dropdown took about 150px of a
 * header that could not fit a search field in French; a row of three flags
 * read as decoration more than as a control; and the choice is made once, so
 * it does not need to be on screen all the time, only findable.
 *
 * **The list is countries, and languages where a country has two.** A country
 * read in one language is one row (the Netherlands, Europe). Belgium is a
 * heading with Nederlands and Français under it. The rows are counted from the
 * markets rather than hardcoded to Belgium, so a second bilingual country gets
 * its two rows without anyone remembering this rule exists.
 *
 * **English is Europe.** There is one English market and its "country" is EU,
 * so English is a market here, not a language under every country. Padding
 * each country with it would offer a language by quietly moving the visitor
 * to another catalogue.
 *
 * ## What choosing does (unchanged)
 *
 * Every row posts to `/market` (`chooseMarket`), which records the choice in
 * the `bc_market` cookie and redirects. This is the only control that writes
 * that cookie: a guess must never become a choice, and only the switcher
 * chooses (docs/features/market-routing.md). A real form submit and a full page
 * load, because the market changes the catalogue, the currency and the
 * language at once, and a client-side swap would leave the last market's
 * prices on screen while the new copy arrived.
 *
 * ## Before (kept for the reasoning)
 *
 * It replaced one dropdown listing "BE/NL, BE/FR, EU/EN, NL/NL": market keys
 * with a slash in them. Nobody has ever wanted "BE/FR"; they have wanted
 * Belgium, in French. Then came flags for the country and a dropdown for the
 * language, the dropdown shown only where there was a choice to make.
 */

/** The country this page is in, from the market it is on. */
function currentCountry(markets: SwitcherCountry[], marketKey: string): SwitcherCountry | null {
    /*
     * Derived from the market rather than shipped as a prop, so one fact on
     * the wire cannot disagree with itself. Null for `/es/`, which routes but
     * is not offered: the button then shows only the language.
     */
    return markets.find((c) => c.languages.some((l) => l.market === marketKey)) ?? null
}

function Check() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden="true" className="h-4 w-4 shrink-0 text-accent">
            <path d="m5 12 5 5 9-10" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    )
}

/**
 * The header's country-and-language button. A menu button (`Menu`): it opens
 * with Enter, Space or the arrows, the arrows move between the rows, Escape
 * closes and returns focus to it.
 */
export default function MarketButton() {
    const { market, markets } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const country = currentCountry(markets, market.key)
    const language = country?.languages.find((l) => l.market === market.key)

    // "België, Nederlands": the button's spoken name and its tooltip, since
    // what it shows is a flag and two letters.
    const where = [country?.name, language?.name].filter(Boolean).join(', ')

    const row = (marketKey: string, text: string, flag: string | null, indent: boolean) => {
        const chosen = marketKey === market.key

        return (
            <button
                key={marketKey}
                type="button"
                role="menuitemradio"
                aria-checked={chosen}
                tabIndex={-1}
                onClick={() => chooseMarket(marketKey)}
                className={`flex w-full items-center gap-2.5 rounded py-2 pr-3 text-left text-sm outline-none hover:bg-line/40 focus-visible:bg-line/60 focus-visible:ring-2 focus-visible:ring-accent/40 ${
                    indent ? 'pl-11' : 'pl-3'
                } ${chosen ? 'font-medium text-ink' : ''}`}
            >
                {flag !== null && (
                    <FlagIcon country={flag as FlagCountry} className="block h-4 w-6 shrink-0 rounded-[2px] ring-1 ring-line" />
                )}
                <span className="min-w-0 flex-1">{text}</span>
                {chosen && <Check />}
            </button>
        )
    }

    return (
        <Menu
            label={t('nav.market_button', { current: where || market.language.toUpperCase() })}
            width={232}
            buttonClassName="market-button flex h-9 items-center gap-1.5 rounded-lg border border-line px-2 text-sm font-medium text-ink hover:border-ink"
            button={
                <>
                    {country && (
                        <FlagIcon country={country.country as FlagCountry} className="block h-4 w-6 rounded-[2px] ring-1 ring-line" />
                    )}
                    <span>{market.language.toUpperCase()}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true" className="h-3.5 w-3.5 text-ink-soft">
                        <path d="m6 9 6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </>
            }
        >
            {() =>
                markets.map((c) =>
                    c.languages.length === 1 ? (
                        row(c.languages[0].market, c.name, c.country, false)
                    ) : (
                        <div key={c.country} role="group" aria-label={c.name}>
                            {/* The country as a heading over its languages: a
                                choice between the two is the only thing to
                                press here. */}
                            <p aria-hidden="true" className="flex items-center gap-2.5 px-3 pt-2 pb-1 text-sm text-ink-soft">
                                <FlagIcon country={c.country as FlagCountry} className="block h-4 w-6 shrink-0 rounded-[2px] ring-1 ring-line" />
                                {c.name}
                            </p>
                            {c.languages.map((l) => row(l.market, l.name, null, true))}
                        </div>
                    ),
                )
            }
        </Menu>
    )
}

/**
 * The same choice in the phone's menu, laid out rather than behind a button:
 * the menu is already open, and a button inside it would be a second thing to
 * open. Country names are spelled out, since a flag on its own is a guess and
 * the tooltip that names it on a desktop does not exist on a phone. Every row
 * is 44px.
 */
export function MarketList() {
    const { market, markets } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const choice = (marketKey: string, text: string, flag: string | null) => {
        const chosen = marketKey === market.key

        return (
            <button
                key={marketKey}
                type="button"
                aria-pressed={chosen}
                onClick={() => chooseMarket(marketKey)}
                className={`flex min-h-11 items-center gap-2.5 rounded-lg px-2 text-left ${
                    chosen ? 'font-medium text-ink' : 'text-ink-soft hover:text-ink'
                }`}
            >
                {flag !== null && (
                    <FlagIcon country={flag as FlagCountry} className="block h-4 w-6 shrink-0 rounded-[2px] ring-1 ring-line" />
                )}
                <span>{text}</span>
                {chosen && <Check />}
            </button>
        )
    }

    return (
        <section aria-labelledby="market-list-heading" className="text-sm">
            <h2 id="market-list-heading" className="mb-1 text-base font-semibold text-ink">
                {t('nav.choose_market')}
            </h2>
            <ul className="border-l border-line pl-1">
                {markets.map((c) => (
                    <li key={c.country}>
                        {c.languages.length === 1 ? (
                            choice(c.languages[0].market, c.name, c.country)
                        ) : (
                            <div className="flex flex-wrap items-center gap-x-1">
                                <span className="flex min-h-11 items-center gap-2.5 px-2 text-ink-soft">
                                    <FlagIcon country={c.country as FlagCountry} className="block h-4 w-6 shrink-0 rounded-[2px] ring-1 ring-line" />
                                    {c.name}:
                                </span>
                                {c.languages.map((l) => choice(l.market, l.name, null))}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    )
}
