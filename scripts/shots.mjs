import { chromium, devices } from 'playwright'
import { mkdirSync } from 'node:fs'

/*
 * Screenshot the site at phone width, and say which elements overflow it.
 *
 * Written because a round of mobile fixes had to be reasoned from markup
 * rather than seen — and the bug that mattered (Safari zooming a sub-16px
 * field, which then makes the page wider than the viewport) is invisible to
 * grep by construction. There was no wide element until somebody tapped one.
 *
 * The overflow report is the useful half. A screenshot shows *that* something
 * is wrong; `scrollWidth > clientWidth` plus the list of offending nodes says
 * *which* one, which is the part that is otherwise guesswork.
 *
 *   node scripts/shots.mjs                    # every page, iPhone 13 width
 *   node scripts/shots.mjs home gift-cove     # just these
 *
 * Needs the dev server up (`composer dev`) and a browser: `npx playwright
 * install chromium`. Tokens come from the local database and are passed in
 * through the environment so this file holds none.
 */

const BASE = process.env.SHOTS_BASE ?? 'http://localhost:8000'
const MARKET = process.env.SHOTS_MARKET ?? 'be-nl'
const OUT = process.env.SHOTS_OUT ?? 'storage/app/shots'

/** Tokens for the pages that need one. Absent → that page is skipped. */
const T = {
    mine: process.env.SHOTS_LIST_MINE,
    forSomeone: process.env.SHOTS_LIST_FOR_SOMEONE,
    group: process.env.SHOTS_LIST_GROUP,
    recipient: process.env.SHOTS_RECIPIENT,
}

/**
 * A page behind `auth` needs a session, and the only way in is a magic link.
 * `SHOTS_MAGIC` is a token minted locally; visiting it once leaves the cookie
 * on the context, and every later page is signed in.
 */
const MAGIC = process.env.SHOTS_MAGIC
const OWNED = process.env.SHOTS_LIST_ID

const PAGES = [
    ['home', `/${MARKET}`],
    ['gift-cove', `/${MARKET}/gift-cove`],
    ['how-it-works', `/${MARKET}/gift-cove/how-it-works`],
    ['discover', `/${MARKET}/discover-cove`],
    ['search', `/${MARKET}/search?q=koptelefoon`],
    ['shared-mine', T.mine && `/${MARKET}/l/${T.mine}`],
    ['shared-gift', T.forSomeone && `/${MARKET}/l/${T.forSomeone}`],
    ['shared-group', T.group && `/${MARKET}/l/${T.group}`],
    ['self-describe', T.recipient && `/${MARKET}/for/${T.recipient}`],

    // Signed in, so these come last — the magic link is consumed just before.
    ['my-lists', MAGIC && `/${MARKET}/lists`],
    ['my-list', MAGIC && OWNED && `/${MARKET}/lists/${OWNED}`],
].filter(([, url]) => Boolean(url))

const only = process.argv.slice(2)
const wanted = only.length > 0 ? PAGES.filter(([n]) => only.includes(n)) : PAGES

/**
 * Elements sticking out past the viewport.
 *
 * Runs in the page. Skips anything inside a deliberately scrollable ancestor —
 * a tab strip with `overflow-x-auto` is *meant* to be wider than its box, and
 * reporting it every time would bury the real ones.
 */
async function overflowing(page) {
    return page.evaluate(() => {
        const limit = document.documentElement.clientWidth
        const out = []

        for (const el of document.querySelectorAll('body *')) {
            const box = el.getBoundingClientRect()

            if (box.width === 0 || box.right <= limit + 1) {
                continue
            }

            let scrollable = false
            for (let p = el.parentElement; p; p = p.parentElement) {
                const o = getComputedStyle(p).overflowX
                if (o === 'auto' || o === 'scroll' || o === 'hidden') {
                    scrollable = true
                    break
                }
            }

            if (scrollable) {
                continue
            }

            out.push({
                tag: el.tagName.toLowerCase(),
                cls: (el.getAttribute('class') ?? '').slice(0, 70),
                text: (el.textContent ?? '').trim().slice(0, 40),
                right: Math.round(box.right),
            })
        }

        return {
            limit,
            scrollWidth: document.documentElement.scrollWidth,
            nodes: out.slice(0, 8),
        }
    })
}

mkdirSync(OUT, { recursive: true })

const browser = await chromium.launch()
const context = await browser.newContext({
    ...devices['iPhone 13'],
    // Chromium, so no Safari zoom to reproduce — but the 16px floor is still
    // what stops the layout being wider than the viewport, and that IS
    // measurable here.
    locale: 'nl-BE',
})

const page = await context.newPage()
let bad = 0

if (MAGIC) {
    // One use, and it expires in fifteen minutes — mint a fresh one per run.
    await page.goto(`${BASE}/${MARKET}/auth/magic/${MAGIC}`, { waitUntil: 'networkidle' })
    console.log(`signed in via magic link → ${new URL(page.url()).pathname}`)
}

for (const [name, url] of wanted) {
    const res = await page.goto(BASE + url, { waitUntil: 'networkidle' })

    // A redirect to the login page is a page too, and worth seeing rather than
    // silently screenshotting as though it were the target.
    const landed = new URL(page.url()).pathname

    const report = await overflowing(page)
    const wide = report.scrollWidth > report.limit + 1

    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true })

    console.log(
        `${wide ? 'OVERFLOW' : 'ok      '} ${name.padEnd(14)} ${String(res?.status()).padEnd(4)} ` +
        `${report.scrollWidth}/${report.limit}px  ${landed}`
    )

    if (wide) {
        bad++
        for (const n of report.nodes) {
            console.log(`           ${n.tag} right=${n.right}  "${n.text}"  .${n.cls}`)
        }
    }
}

await browser.close()
console.log(`\n${wanted.length} pages, ${bad} overflowing. Screenshots in ${OUT}/`)
