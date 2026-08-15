import { usePage } from '@inertiajs/react'
import FlagIcon, { type FlagCountry } from './FlagIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Two controls, because a visitor thinks in two things: where they buy, and
 * what they read.
 *
 * It replaced one dropdown listing "BE/NL, BE/FR, EU/EN, NL/NL". Those are
 * market keys with a slash in them — nobody has ever wanted "BE/FR", they have
 * wanted Belgium, in French — and the list grows multiplicatively with every
 * country added while saying less each time.
 *
 * **The country is flags, the language is a dropdown.** There are three
 * countries, so they all fit on one row and the current one can be *seen*
 * rather than opened; a language is a word, not a picture, and a `<select>`
 * cannot hold an SVG anyway, which settles which control gets the flags.
 *
 * **The dropdown appears only where there is a choice to make** — Belgium, and
 * nowhere else today. A select holding one option is a control that cannot be
 * operated. It is counted from the country's markets rather than hardcoded to
 * BE, so a second bilingual country would get its dropdown without anyone
 * remembering that this rule exists.
 *
 * **English is the European flag.** There is one English market and its country
 * is EU, so English is a market choice here and not a language choice; it is
 * always one click away because that flag is always on screen. Padding every
 * country's language list with it would have offered a language by quietly
 * moving the visitor to another catalogue.
 *
 * **Clicking a flag keeps your language where the country has it.** Belgium in
 * French → the Netherlands lands on Dutch, because there is no French market
 * there; Belgium in Dutch → the Netherlands stays Dutch.
 *
 * A full page load, both controls, exactly as the single dropdown did before.
 * Switching either changes the catalogue, the currency and the language, and a
 * client-side swap would leave the last market's prices on screen while the new
 * copy arrived.
 */
export default function MarketSwitcher({
    id,
    withNames = false,
    className,
}: {
    id: string
    withNames?: boolean
    className?: string
}) {
    const { market, markets } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Which country we are in is derived from the market we are on rather than
     * shipped as a third prop — one fact on the wire cannot disagree with
     * itself. The fallback is unreachable for a published market and exists so
     * that `/es/`, which routes but is not in this list, renders a switcher
     * instead of throwing.
     */
    const current = markets.find((c) => c.languages.some((l) => l.market === market.key)) ?? markets[0]

    if (!current) {
        return null
    }

    const currentLanguage = current.languages.find((l) => l.market === market.key)

    const go = (marketKey: string) => {
        window.location.href = `/${marketKey}`
    }

    const chooseCountry = (country: string) => {
        const next = markets.find((c) => c.country === country)

        if (!next) {
            return
        }

        const sameLanguage = next.languages.find((l) => l.language === currentLanguage?.language)

        go((sameLanguage ?? next.languages[0]).market)
    }

    return (
        <div className={className ?? 'flex items-center gap-3'}>
            <fieldset className="min-w-0">
                <legend className="sr-only">{t('nav.choose_market')}</legend>

                <div className="flex items-center gap-1.5">
                    {markets.map((country) => (
                        <label
                            key={country.country}
                            title={country.name}
                            className="flex cursor-pointer items-center gap-1.5"
                        >
                            <input
                                type="radio"
                                name={`${id}-country`}
                                value={country.country}
                                checked={country.country === current.country}
                                onChange={() => chooseCountry(country.country)}
                                className="peer sr-only"
                            />
                            <span className="block rounded-[3px] opacity-50 ring-1 ring-line transition peer-checked:opacity-100 peer-checked:ring-2 peer-checked:ring-accent peer-focus-visible:ring-2 peer-focus-visible:ring-ink">
                                <FlagIcon country={country.country as FlagCountry} className="block h-4 w-6 rounded-[2px]" />
                            </span>
                            {withNames ? (
                                <span className="text-sm text-ink-soft peer-checked:text-ink">{country.name}</span>
                            ) : (
                                <span className="sr-only">{country.name}</span>
                            )}
                        </label>
                    ))}
                </div>
            </fieldset>

            {current.languages.length > 1 && (
                <>
                    <label className="sr-only" htmlFor={`${id}-language`}>
                        {t('nav.choose_language')}
                    </label>
                    <select
                        id={`${id}-language`}
                        className="min-w-0 rounded border border-line bg-card px-2 py-1 text-sm"
                        value={currentLanguage?.market ?? current.languages[0].market}
                        onChange={(e) => go(e.target.value)}
                    >
                        {current.languages.map((language) => (
                            <option key={language.language} value={language.market}>
                                {language.name}
                            </option>
                        ))}
                    </select>
                </>
            )}
        </div>
    )
}
