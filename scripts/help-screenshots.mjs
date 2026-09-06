/**
 * Capture the screenshots on the "how lists work" help page.
 *
 * ## Why a committed script and not a folder of images
 *
 * Screenshots go stale silently. The button moves, the copy is rewritten, the
 * palette changes — and the help page keeps showing last year's interface with
 * nothing to report it. A script means re-taking them is one command rather
 * than an afternoon, so it actually gets done.
 *
 * ## What it will not photograph
 *
 * A real account. The local database holds the developer's own lists, with real
 * gift notes on them — see invariant 4 in CLAUDE.md. This signs in as a
 * throwaway user it creates itself and photographs that.
 *
 * ## How the sign-in works
 *
 * There is no password to type: the site is passwordless, and signing in is a
 * dialog opened from a menu rather than a page you can navigate to. So the
 * seeding command prints a real magic link — single use, fifteen minutes, and
 * only ever for an account that exists on a development machine — and this
 * follows it. Set it as SIGNIN_URL, or let the script run the command itself.
 *
 * Usage, with `composer dev` and `docker compose up -d` running:
 *
 *     node scripts/help-screenshots.mjs
 *
 * Images land in `public/help/lists/<language>/`.
 */

import { chromium } from 'playwright'
import { execSync } from 'node:child_process'
import { mkdir } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const SITE = process.env.SITE_URL ?? 'http://localhost:8000'
const EMAIL = 'help-screenshots@giftcoves.test'

/*
 * One market per language, chosen for the catalogue behind it.
 *
 * Screenshots have to be in the reader's language — a Dutch interface on the
 * French page is the mistake that made 14 retired guides embarrassing — and the
 * market decides the language. `es` has no catalogue at all, so there is no
 * product to photograph; the page falls back to the English images there, and
 * says so in `docs/features/list-help.md`.
 */
const MARKETS = [
    { language: 'nl', market: 'be-nl', term: 'koptelefoon' },
    { language: 'fr', market: 'be-fr', term: 'casque' },
    /*
     * `en` is a real market with a thin catalogue whose product titles arrive
     * from Dutch-language feeds — so an English screenshot shows Dutch product
     * names. That is what an English visitor genuinely sees today, so the
     * picture is accurate rather than flattering, and it is the interface the
     * page is teaching. `es` has no catalogue at all and falls back to these.
     */
    { language: 'en', market: 'en', term: 'sony' },
]

/** The viewport is a laptop, not a phone: the save panel needs room to open. */
const VIEWPORT = { width: 1280, height: 1100 }

/**
 * A signed-in session, via a link the seeding command mints.
 *
 * Runs the command itself unless SIGNIN_URL says otherwise, so the usual case
 * is one command with nothing to remember.
 */
function seed(market) {
    const seeded = execSync(`php artisan bc:seed-help-demo --fresh --market=${market}`, {
        cwd: ROOT,
        encoding: 'utf8',
    })

    const link = seeded.match(/https?:\/\/\S*\/auth\/magic\/\S+/)

    if (!link) {
        throw new Error(`No sign-in link in the seeder output:\n${seeded}`)
    }

    return link[0]
}

async function shoot(page, file, clip) {
    await mkdir(dirname(file), { recursive: true })
    await page.screenshot({ path: file, animations: 'disabled', clip })
    console.log('  ', file.replace(ROOT, '').replace(/\\/g, '/'))
}

/**
 * A crop around the given elements, with room to breathe.
 *
 * Full-page screenshots were the first attempt and they are close to useless
 * here: at 1280 wide the thing being pointed at — one small button on one card
 * — is a few pixels in a picture of a whole shop. A help page needs the detail
 * large enough to recognise on the real page afterwards.
 */
async function around(page, locators, pad = 20) {
    const boxes = []
    for (const locator of locators) {
        const box = await locator.boundingBox()
        if (box) boxes.push(box)
    }

    if (boxes.length === 0) return undefined

    const left = Math.min(...boxes.map((b) => b.x)) - pad
    const top = Math.min(...boxes.map((b) => b.y)) - pad
    const right = Math.max(...boxes.map((b) => b.x + b.width)) + pad
    const bottom = Math.max(...boxes.map((b) => b.y + b.height)) + pad

    const size = page.viewportSize()

    return {
        x: Math.max(0, left),
        y: Math.max(0, top),
        width: Math.min(right, size.width) - Math.max(0, left),
        height: Math.min(bottom, size.height) - Math.max(0, top),
    }
}

const browser = await chromium.launch()
const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 2, // Retina, so the images are not soft on a good screen.
    colorScheme: 'light',
})
const page = await context.newPage()

for (const { language, market, term } of MARKETS) {
    console.log(`${language} (${market}):`)

    /*
     * Re-seeded and re-signed-in per market, because the panel shows an
     * account's lists regardless of which market they belong to. One market's
     * lists at a time is the only way the picture stays in one language.
     */
    await page.goto(seed(market), { waitUntil: 'networkidle' })
    const out = (name) => resolve(ROOT, 'public', 'help', 'lists', language, `${name}.png`)

    await page.goto(`${SITE}/${market}/search?q=${encodeURIComponent(term)}`, {
        waitUntil: 'networkidle',
    })
    await page.waitForTimeout(800) // let the images arrive, or the shot has holes

    /*
     * The save control lives on every product card, and `main` is what keeps
     * this off the account menu in the header — which also carries
     * aria-haspopup="menu" and comes first in the document. The first run of
     * this script photographed that menu three times.
     */
    if (!(await page.locator('main button[aria-haspopup="menu"][aria-expanded]').count())) {
        console.log('   ! no save control on this page — is the catalogue empty in this market?')
        continue
    }

    const allCards = page.locator('main article:has(button[aria-haspopup="menu"])')

    /*
     * Start at a card whose picture actually arrived.
     *
     * Not `:has(img)`: the markup carries an `img` either way, and the first
     * result for "koptelefoon" on this machine has one whose file 404s. So the
     * tag is there, the picture is not, and the crop opened on an empty square
     * that reads as a broken page rather than as an instruction. `naturalWidth`
     * is the only thing that knows the difference.
     */
    const firstLoaded = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('main article')].filter((c) =>
            c.querySelector('button[aria-haspopup="menu"]'),
        )
        const index = cards.findIndex((c) => {
            const img = c.querySelector('img')
            return img && img.naturalWidth > 1
        })
        return index < 0 ? 0 : index
    })

    const cards = allCards.nth(firstLoaded)
    const alsoNext = allCards.nth(firstLoaded + 1)

    /*
     * Bring the card up the page before opening anything. The panel opens
     * downward from the button and flips only when it has to, so a card sitting
     * low in the window produced a screenshot of a panel with its last option —
     * "new list", the one the page is pointing at — cut off by the fold.
     */
    await cards.scrollIntoViewIfNeeded()
    await page.evaluate(() => window.scrollBy(0, -120))
    await page.waitForTimeout(400)

    // 1. Where the button is. Two cards, so it reads as "every one of these".
    const pair = (await alsoNext.count()) > 0 ? [cards, alsoNext] : [cards]
    await shoot(page, out('1-find'), await around(page, pair))

    // 2. The panel it opens: the lists to choose from, and the way to start a
    //    new one. This is the step people ask about.
    await cards.locator('button[aria-haspopup="menu"][aria-expanded]').first().click()
    const menu = page.locator('[role="menu"]').first()
    await menu.waitFor({ state: 'visible', timeout: 5000 })
    await page.waitForTimeout(300)
    await shoot(page, out('2-choose-list'), await around(page, [cards, menu]))
    await page.keyboard.press('Escape')

    // 3. Where everything saved turns up.
    await page.goto(`${SITE}/${market}/lists`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    await shoot(page, out('3-your-lists'), { x: 0, y: 0, width: VIEWPORT.width, height: 470 })
}

await browser.close()
console.log('done')
