/**
 * Draws the raster favicons from public/icons/giftcoves.svg.
 *
 *   node scripts/favicons.mjs
 *
 * The SVG is the mark; these are copies of it for the places that will not
 * take an SVG: favicon.ico (16, 32, 48) for browsers that ask for it first,
 * giftcoves-192.png for Chrome on Android, and giftcoves-512.png for the home
 * screen (apple-touch-icon) and the default social card. Each size is drawn
 * from the SVG at that size rather than shrunk from one big picture, so the
 * small ones stay sharp. Rerun after changing the SVG; they went teal-on-red
 * out of step once already (2026-10-05).
 *
 * Writes the PNGs; favicon.ico is packed from them by scripts/favicons-ico.py,
 * which this runs.
 */
import { chromium } from 'playwright'
import { readFileSync, mkdirSync } from 'node:fs'
import { execFileSync } from 'node:child_process'

const svg = readFileSync('public/icons/giftcoves.svg', 'utf8')
const out = 'storage/app/favicons'
mkdirSync(out, { recursive: true })

const browser = await chromium.launch()
const page = await browser.newPage()

for (const size of [16, 32, 48, 192, 512]) {
    await page.setViewportSize({ width: size, height: size })
    await page.setContent(
        `<body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg style="display:block;width:${size}px;height:${size}px" `)}</body>`,
    )
    const target = size >= 192 ? `public/icons/giftcoves-${size}.png` : `${out}/favicon-${size}.png`
    await page.locator('svg').screenshot({ path: target, omitBackground: true })
    console.log(target)
}

await browser.close()

execFileSync('python', ['scripts/favicons-ico.py', out, 'public/favicon.ico'], { stdio: 'inherit' })
