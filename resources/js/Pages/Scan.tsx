import { Head } from '@inertiajs/react'
import BarcodeScanner from '../Components/BarcodeScanner'
import { useTranslations } from '../useTranslations'

/**
 * The standalone scanner page.
 *
 * Page chrome only — the scanner itself lives in a component shared with the
 * search-page dialog, because the camera lifecycle and the decoder fallback are
 * subtle enough that a second copy would drift and only break on one surface.
 *
 * No autoStart here: on a dedicated page the button IS the page, and turning a
 * camera on because someone followed a link is not a thing to do uninvited.
 */
export default function Scan() {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('scan.title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('scan.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('scan.subtitle')}</p>
            </header>

            <div className="mt-6">
                <BarcodeScanner />
            </div>
        </>
    )
}
