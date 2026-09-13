import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import FlagIcon, { type FlagCountry } from './FlagIcon'
import { chooseMarket } from '../marketChoice'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * "Where do you shop?", asked once, of a visitor we have never seen.
 *
 * The bare domain guesses a market from `Accept-Language`, and the guess is
 * wrong in the one way that matters most here: a Belgian browser set to plain
 * "Nederlands" reports `nl-NL` and lands on the Dutch catalogue, with the
 * wrong shops, prices and delivery, and nothing on the page says so. The flags
 * in the header are the fix, if you notice them. This asks instead, once, with
 * the three flags large enough to be the whole question (owner's request,
 * 2026-09-13).
 *
 * ## When it shows
 *
 * `askMarket` comes from the server: no `bc_market` cookie, and a user agent
 * that is not a crawler (App\Support\Crawlers). A crawler is never asked,
 * because the answer is a cookie it will not keep and the dialog would sit in
 * every rendered page it indexes. Once answered it is a cookie for a year, so
 * the question is asked once per browser, not once per page.
 *
 * ## What an answer does
 *
 * The same POST the switcher makes, so the choice is recorded the only way a
 * choice may be (see App\Support\MarketPreference). Another country lands on
 * that market's home, the landing page for it; the market you are already on
 * keeps you on the page you opened, which matters when that page is a friend's
 * shared list. Escape means "keep what you guessed", and records that too, so
 * closing the dialog is also an answer and it does not come back.
 */
export default function MarketPrompt() {
    const { askMarket, market, markets } = usePage<SharedProps>().props
    const { t } = useTranslations()

    // Seeded from the server, then owned by the client: once pressed, the
    // dialog goes at once rather than on the full page load that follows.
    const [open, setOpen] = useState(askMarket)

    const current = markets.find((c) => c.languages.some((l) => l.market === market.key)) ?? null
    const currentLanguage = current?.languages.find((l) => l.market === market.key)

    const keep = () => {
        setOpen(false)
        chooseMarket(market.key)
    }

    useEffect(() => {
        if (!open) return

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') keep()
        }

        document.addEventListener('keydown', onKey)

        return () => document.removeEventListener('keydown', onKey)
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open])

    if (!open || current === null) {
        return null
    }

    /*
     * A country, in the language the visitor is already reading where that
     * country offers it: Belgium in French stays French, Belgium in Dutch
     * stays Dutch, and the Netherlands is Dutch either way. The same rule the
     * switcher's flags follow.
     */
    const pick = (country: (typeof markets)[number]) => {
        const sameLanguage = country.languages.find((l) => l.language === currentLanguage?.language)

        setOpen(false)
        chooseMarket((sameLanguage ?? country.languages[0]).market)
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-4 sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-labelledby="market-prompt-title"
        >
            <div className="w-full max-w-lg rounded-card border border-line bg-card p-6 shadow-lg">
                <h2 id="market-prompt-title" className="text-lg font-semibold">
                    {t('market_prompt.title')}
                </h2>
                <p className="mt-1 text-sm text-ink-soft">{t('market_prompt.intro')}</p>

                {/*
                  Three cards, one per country, the flag large and the name
                  under it. The guessed one is marked and holds focus, so
                  Enter keeps it and one press on another flag changes it.
                */}
                <ul className="mt-5 grid grid-cols-3 gap-3">
                    {markets.map((country) => {
                        const guessed = country.country === current.country

                        return (
                            <li key={country.country}>
                                <button
                                    type="button"
                                    autoFocus={guessed}
                                    onClick={() => pick(country)}
                                    aria-pressed={guessed}
                                    className={`flex w-full flex-col items-center gap-2 rounded-card border bg-card p-4 transition hover:border-ink ${
                                        guessed ? 'border-accent ring-2 ring-accent/30' : 'border-line'
                                    }`}
                                >
                                    <FlagIcon
                                        country={country.country as FlagCountry}
                                        className="block h-8 w-12 rounded-[3px] ring-1 ring-line"
                                    />
                                    <span className="text-sm font-medium">{country.name}</span>
                                </button>
                            </li>
                        )
                    })}
                </ul>

                <p className="mt-4 text-xs text-ink-soft">{t('market_prompt.guess', { name: current.name })}</p>
            </div>
        </div>
    )
}
