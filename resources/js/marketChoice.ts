import { ensureCsrfToken } from './http'

/**
 * Record a market choice and go there: a real form POST to `/market`.
 *
 * Shared by the header's switcher and the first-visit prompt, so there is one
 * way a choice is written down. A form submit rather than fetch or an Inertia
 * visit, because this is a full page load on purpose: the market changes the
 * catalogue, the currency and the language at once, and anything short of a
 * document load risks the previous market's prices sitting under the new copy.
 *
 * POST, not a link to `/{market}`, so the choice is remembered as well as
 * acted on; see App\Support\MarketPreference for why a GET would be wrong.
 *
 * `path` is where the visitor is now. The server decides what to do with it:
 * the same page in another language of the same country, the page itself when
 * the chosen market is the one they are already on, and the market home for
 * everything else.
 */
export async function chooseMarket(marketKey: string): Promise<void> {
    // A page from the anonymous page cache carries no token; see http.ts.
    const token = await ensureCsrfToken()

    const form = document.createElement('form')
    form.method = 'post'
    form.action = '/market'
    form.hidden = true

    for (const [name, value] of [
        ['market', marketKey],
        ['_token', token],
        ['path', window.location.pathname],
    ]) {
        const field = document.createElement('input')
        field.type = 'hidden'
        field.name = name
        field.value = value
        form.append(field)
    }

    document.body.append(form)
    form.submit()
}

/**
 * Record a market choice and stay where you are: the same POST, by fetch.
 *
 * For the market bar's close button, which means "keep the market you are
 * already on". Nothing about the page changes, so reloading it would only
 * lose the visitor's place. Asked for as JSON, the controller answers 204 with
 * the cookie. A failure is swallowed: the bar is already gone, and it simply
 * comes back on the next page, which is the honest outcome of a choice that
 * was not recorded.
 */
export function rememberMarket(marketKey: string): void {
    const body = new FormData()
    body.append('market', marketKey)

    void ensureCsrfToken()
        .then((token) =>
            fetch('/market', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            }),
        )
        .catch(() => {})
}
