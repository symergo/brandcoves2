import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../useTranslations'

interface Props {
    urls: { search: string; scan: string }
}

/**
 * How to search, and how the scanner works.
 *
 * Two halves, in the order a visitor meets them: the box first, because
 * everyone uses it, and the camera second, because it is the one that raises
 * questions — a page asking for a camera owes an answer about where the images
 * go before it asks.
 *
 * **Every claim here is checkable against the code.** That is the constraint
 * this page is written under, and it is why the scanner section says a miss is
 * expected rather than implying every product is findable: see
 * docs/features/barcode-scanner.md, where "only EAN-grouped products can ever
 * be found" is a designed limitation, not a bug to be papered over. A help page
 * that overpromises turns a working feature into a broken one.
 *
 * Definition lists rather than prose paragraphs. Someone reaching this page has
 * a specific question — "will it find it if I spell it wrong", "does it work on
 * my iPhone" — and is scanning for the line that answers it, not reading.
 */
export default function SearchHelp({ urls }: Props) {
    const { t } = useTranslations()

    /* Each entry is one question the search box actually raises. */
    const searching: { term: string; what: string }[] = [
        { term: t('search_help.what_words_term'), what: t('search_help.what_words') },
        { term: t('search_help.what_typos_term'), what: t('search_help.what_typos') },
        { term: t('search_help.what_accents_term'), what: t('search_help.what_accents') },
        { term: t('search_help.what_language_term'), what: t('search_help.what_language') },
        { term: t('search_help.what_barcode_term'), what: t('search_help.what_barcode') },
        { term: t('search_help.what_amazon_term'), what: t('search_help.what_amazon') },
    ]

    const narrowing: { term: string; what: string }[] = [
        { term: t('search_help.narrow_price_term'), what: t('search_help.narrow_price') },
        { term: t('search_help.narrow_brand_term'), what: t('search_help.narrow_brand') },
        { term: t('search_help.narrow_stock_term'), what: t('search_help.narrow_stock') },
        { term: t('search_help.narrow_sort_term'), what: t('search_help.narrow_sort') },
        { term: t('search_help.narrow_terms_term'), what: t('search_help.narrow_terms') },
    ]

    const scanning: { term: string; what: string }[] = [
        { term: t('search_help.scan_where_term'), what: t('search_help.scan_where') },
        { term: t('search_help.scan_privacy_term'), what: t('search_help.scan_privacy') },
        { term: t('search_help.scan_devices_term'), what: t('search_help.scan_devices') },
        { term: t('search_help.scan_misses_term'), what: t('search_help.scan_misses') },
        { term: t('search_help.scan_misread_term'), what: t('search_help.scan_misread') },
        { term: t('search_help.scan_manual_term'), what: t('search_help.scan_manual') },
    ]

    const section = (heading: string, intro: string, rows: { term: string; what: string }[]) => (
        <section className="mt-10">
            <h2 className="text-xl font-semibold tracking-tight text-ink">{heading}</h2>
            <p className="mt-2 max-w-2xl text-ink-soft">{intro}</p>

            <dl className="mt-6 divide-y divide-line border-y border-line">
                {rows.map((row) => (
                    <div key={row.term} className="py-4 sm:flex sm:gap-6">
                        <dt className="font-medium text-ink sm:w-56 sm:shrink-0">{row.term}</dt>
                        <dd className="mt-1 text-ink-soft sm:mt-0">{row.what}</dd>
                    </div>
                ))}
            </dl>
        </section>
    )

    return (
        <>
            <Head title={t('search_help.seo_title')} />

            <div className="mx-auto max-w-4xl px-4 py-10">
                <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight text-ink">{t('search_help.title')}</h1>
                <p className="mt-3 max-w-2xl text-lg text-ink-soft">{t('search_help.intro')}</p>

                {section(t('search_help.searching_heading'), t('search_help.searching_intro'), searching)}
                {section(t('search_help.narrowing_heading'), t('search_help.narrowing_intro'), narrowing)}
                {section(t('search_help.scanning_heading'), t('search_help.scanning_intro'), scanning)}

                {/*
                  Both entry points, at the bottom, because the whole page is an
                  instruction to go and use one of them. The scanner link is
                  secondary: on a desktop it opens a camera page that most
                  desktops cannot decode with, so the search box leads.
                */}
                <div className="mt-10 flex flex-wrap gap-3">
                    <Link
                        href={urls.search}
                        className="rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark"
                    >
                        {t('search_help.go_search')}
                    </Link>
                    <Link
                        href={urls.scan}
                        className="rounded-lg border border-line px-5 py-3 font-medium text-ink transition hover:border-ink"
                    >
                        {t('search_help.go_scan')}
                    </Link>
                </div>
            </div>
        </>
    )
}
