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
export function chooseMarket(marketKey: string): void {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content

    const form = document.createElement('form')
    form.method = 'post'
    form.action = '/market'
    form.hidden = true

    for (const [name, value] of [
        ['market', marketKey],
        ['_token', token ?? ''],
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
