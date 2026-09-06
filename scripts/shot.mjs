/**
 * One full-page screenshot of a signed-in page, for reviewing a layout.
 *
 *     node scripts/shot.mjs /be-nl/gift-cove out.png
 *
 * Signs in as the throwaway help-demo account, the same way
 * `help-screenshots.mjs` does, so the page shows the signed-in state — which is
 * the one that matters for anything under /lists, /friends or /gift-cove.
 * Local only, like the seeder it leans on.
 */
import { chromium } from 'playwright'
import { execSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const [, , path = '/be-nl/gift-cove', out = 'shot.png', width = '1280'] = process.argv

const seeded = execSync('php artisan bc:seed-help-demo --market=be-nl', { cwd: ROOT, encoding: 'utf8' })
const link = seeded.match(/https?:\/\/\S*\/auth\/magic\/\S+/)?.[0]

if (!link) {
    throw new Error(`No sign-in link in the seeder output:\n${seeded}`)
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: Number(width), height: 900 }, deviceScaleFactor: 1 })

await page.goto(link, { waitUntil: 'networkidle' })
await page.goto(`http://localhost:8000${path}`, { waitUntil: 'networkidle' })
await page.screenshot({ path: out, fullPage: true, animations: 'disabled' })

console.log(out)
await browser.close()
