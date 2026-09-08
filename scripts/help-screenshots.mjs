/**
 * Capture the screenshots on the "how lists work" help pages.
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
 * follows it. It also prints the share link of the demo list, which a second,
 * signed-out browser opens to photograph what a visitor sees.
 *
 * ## Ten pictures, not three (2026-09-08)
 *
 * The help grew from one page to nine, and the owner missed pictures on the
 * eight new ones. So besides the save flow this now photographs the list
 * wizard, adding a product, the share panel, a shared list as a visitor sees
 * it, the Secret Santa page, the friends page and following a search. Each
 * is found by the button's own label, read from the language file, so a
 * renamed button fails loudly here rather than silently photographing the
 * wrong thing.
 *
 * Usage, with `composer dev` and `docker compose up -d` running, and the
 * development database migrated:
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
    { language: 'en', market: 'en', term: 'sony' },
]

/** The viewport is a laptop, not a phone: the save panel needs room to open. */
const VIEWPORT = { width: 1280, height: 1100 }

/**
 * A signed-in session, via a link the seeding command mints, and the demo
 * list's share link.
 */
function seed(market, term) {
    const seeded = execSync(`php artisan bc:seed-help-demo --fresh --shared --market=${market} --like=${term}`, {
        cwd: ROOT,
        encoding: 'utf8',
    })

    const signIn = seeded.match(/https?:\/\/\S*\/auth\/magic\/\S+/)
    const share = seeded.match(/https?:\/\/\S*\/l\/\S+/)

    if (!signIn || !share) {
        throw new Error(`No sign-in or share link in the seeder output:\n${seeded}`)
    }

    return { signIn: signIn[0], share: share[0] }
}

/**
 * The interface's own words, so a button is found by what it says.
 *
 * `php -r` rather than a copy of the labels here: a copy is the thing that
 * goes stale.
 */
function labels(language) {
    const json = execSync(`php -r "echo json_encode(include 'lang/${language}/site.php');"`, {
        cwd: ROOT,
        encoding: 'utf8',
        maxBuffer: 16 * 1024 * 1024,
    })
    const all = JSON.parse(json)

    return (key) => {
        const value = key.split('.').reduce((carry, part) => carry?.[part], all)
        if (typeof value !== 'string') throw new Error(`No label ${key} in ${language}`)
        return value
    }
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

/** The top of a page, for pages whose first screen is the instruction. */
const top = (height) => ({ x: 0, y: 0, width: VIEWPORT.width, height })

/** Scroll an element up the page so a panel opening under it stays in view. */
async function raise(page, locator) {
    await locator.scrollIntoViewIfNeeded()
    await page.evaluate(() => window.scrollBy(0, -120))
    await page.waitForTimeout(400)
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
    const L = labels(language)

    /*
     * Re-seeded and re-signed-in per market, because the panel shows an
     * account's lists regardless of which market they belong to. One market's
     * lists at a time is the only way the picture stays in one language.
     */
    const links = seed(market, term)
    await page.goto(links.signIn, { waitUntil: 'networkidle' })
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
    await raise(page, cards)

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

    // 10. Following a search, while the results are still on screen.
    const watch = page.getByRole('button', { name: L('search.watch'), exact: true }).first()
    await raise(page, watch)
    await watch.click()
    const confirm = page.getByRole('button', { name: L('search.watch_confirm'), exact: true }).first()
    await confirm.waitFor({ state: 'visible', timeout: 5000 })
    await page.waitForTimeout(300)
    // The whole panel, not the two buttons: the hint above the field is the
    // sentence the help page is pointing at.
    const watchPanel = page.locator('#watch-ceiling').locator('xpath=ancestor::div[contains(@class, "max-w-md")]').first()
    await shoot(page, out('10-watch-search'), await around(page, [watch, watchPanel], 28))

    // 3. Where everything saved turns up.
    await page.goto(`${SITE}/${market}/lists`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    await shoot(page, out('3-your-lists'), top(470))

    // 4. The wizard, at its first question.
    // The list with things on it, not the empty one: an empty list opens the
    // add-product panel by itself and has no button to photograph. The card
    // of a list with items carries their thumbnails.
    const withItems = page.locator('main a[href*="/lists/"]:has(img)')
    const listHref = await ((await withItems.count()) > 0 ? withItems : page.locator('main a[href*="/lists/"]'))
        .first()
        .getAttribute('href')
    await page.getByRole('button', { name: L('lists.new_list'), exact: true }).first().click()
    const wizard = page.locator('section[aria-labelledby="wizard-title"]')
    await wizard.waitFor({ state: 'visible', timeout: 5000 })
    await page.waitForTimeout(300)
    await shoot(page, out('4-wizard'), await around(page, [wizard], 12))

    // 5. Adding a product from the list itself.
    await page.goto(`${SITE}${listHref}`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    const add = page.getByRole('button', { name: L('lists.add_product') }).first()
    await raise(page, add)
    const addBox = await add.boundingBox()
    await add.click()
    await page.waitForTimeout(500)
    await shoot(page, out('5-add-product'), { x: 0, y: Math.max(0, addBox.y - 24), width: VIEWPORT.width, height: 480 })

    // 6. The share panel.
    await page.reload({ waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    const shareTab = page.locator('button[aria-controls="list-tools-panel"]', { hasText: L('lists.share') }).first()
    await raise(page, shareTab)
    await shareTab.click()
    const panel = page.locator('#list-tools-panel')
    await panel.waitFor({ state: 'visible', timeout: 5000 })
    await page.waitForTimeout(400)
    await shoot(page, out('6-share'), await around(page, [shareTab, panel], 16))

    // 7. The shared list, as a visitor with no account sees it.
    const visitor = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2, colorScheme: 'light' })
    const guest = await visitor.newPage()
    await guest.goto(links.share, { waitUntil: 'networkidle' })
    await guest.waitForTimeout(800)
    const claim = guest.getByRole('button', { name: L('lists.claim'), exact: true }).first()
    await claim.scrollIntoViewIfNeeded()
    await guest.evaluate(() => window.scrollBy(0, -160))
    await guest.waitForTimeout(300)
    const tiles = guest.locator('main ul li')
    await shoot(guest, out('7-shared-list'), await around(guest, [tiles.nth(0), tiles.nth(1)], 16))
    await visitor.close()

    // 8. Secret Santa, the page you start a group from.
    await page.goto(`${SITE}/${market}/santa`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    await shoot(page, out('8-santa'), top(520))

    // 9. Friends.
    await page.goto(`${SITE}/${market}/friends`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(600)
    await shoot(page, out('9-friends'), top(520))
}

await browser.close()
console.log('done')
