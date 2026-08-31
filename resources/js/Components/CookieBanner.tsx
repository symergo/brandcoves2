import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { loadGoogleTag, reportPageView } from '../analytics'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * The one question this site has to ask before it may measure anything.
 *
 * Everything else stored here is strictly necessary for something the visitor
 * asked for and is exempt under Article 5(3) of the ePrivacy Directive.
 * Google Analytics is not, so it does not load until this is answered — see
 * App\Support\CookieConsent for why the gate is a server-read cookie rather
 * than a client-side check.
 *
 * **It appears only where there is a tag to consent to.** `analytics.id` is
 * null on staging and in local development, and a banner asking permission for
 * something that was never going to load is theatre that teaches people to
 * dismiss the ones that mean something.
 *
 * **Both answers are one click, and they look alike.** A prominent Accept
 * beside a greyed-out Decline is the pattern the EDPB has repeatedly called a
 * consent that was not freely given. The accent goes on Accept because it is
 * the affirmative action, not because refusing should feel like a mistake.
 *
 * **It does not block the page.** No overlay, no scroll lock, nothing over the
 * content — a bar at the bottom that can be ignored. Ignoring it is a valid
 * outcome: nothing is stored until an answer arrives, so a visitor who never
 * looks at it is a visitor we never measured, which is the correct default.
 */
export default function CookieBanner() {
    const { analytics } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const market = usePage<SharedProps>().props.market

    /*
      Seeded from the server, then owned by the client. The prop is what the
      request arrived with; once an answer is posted the bar has to go away
      immediately rather than on the next full page load, and the visitor must
      not see it flash back on the next Inertia navigation while the props
      catch up.
    */
    const [answered, setAnswered] = useState(analytics.consent !== null)

    /*
      The footer's Cookies link reopens the question. An event rather than a
      prop or a store: the link and the bar are on opposite sides of the page,
      the message carries nothing, and one listener is less machinery than
      threading state through the layout for a control most people never touch.
    */
    useEffect(() => {
        const reopen = () => setAnswered(false)
        window.addEventListener('bc:cookie-settings', reopen)

        return () => window.removeEventListener('bc:cookie-settings', reopen)
    }, [])

    if (analytics.id === null || answered) {
        return null
    }

    function answer(choice: 'granted' | 'denied') {
        setAnswered(true)

        /*
          preserveScroll and preserveState, because answering a question about
          cookies is not a navigation. The post exists to carry the Set-Cookie;
          the page must not move, reload, or lose what the visitor had typed
          into whatever form they were halfway through.
        */
        router.post('/consent', { choice }, { preserveScroll: true, preserveState: true })

        if (choice === 'granted' && analytics.id !== null) {
            /*
              Immediately, rather than from the next page's shell. Consent given
              on a landing page is most useful as a measurement of that landing
              page, and waiting for the next request throws exactly that away.
            */
            loadGoogleTag(analytics.id)
            reportPageView()
        }
    }

    return (
        <div
            role="dialog"
            aria-live="polite"
            aria-label={t('cookies.title')}
            className="fixed inset-x-0 bottom-0 z-50 border-t border-line bg-card/95 shadow-lg backdrop-blur"
        >
            <div className="mx-auto flex max-w-4xl flex-col gap-3 px-4 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                <p className="text-ink-soft">
                    {t('cookies.body')}{' '}
                    <Link href={`/${market.key}/privacy`} className="underline hover:text-accent">
                        {t('cookies.more')}
                    </Link>
                </p>

                <div className="flex shrink-0 gap-2">
                    <button
                        type="button"
                        onClick={() => answer('denied')}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink-soft"
                    >
                        {t('cookies.decline')}
                    </button>
                    <button
                        type="button"
                        onClick={() => answer('granted')}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('cookies.accept')}
                    </button>
                </div>
            </div>
        </div>
    )
}
