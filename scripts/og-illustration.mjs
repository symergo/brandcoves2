/**
 * Draws the homepage illustration as a PNG for the default social card.
 *
 *   node scripts/og-illustration.mjs
 *
 * Writes resources/og/shared-cove.png, which App\Services\Seo\OgImage places on
 * the right of the default card. GD, which draws the cards, cannot read SVG, so
 * the drawing is turned into pixels here, once, and the PNG is committed.
 *
 * The SVG is read out of Components/SharedCoveIllustration.tsx rather than
 * copied into this file, so there is one drawing. Rerun this after changing it.
 *
 * Recoloured for the card, which is dark teal rather than the site's cream:
 * lines in the card's sand, the washes in the buoy's amber (the site's accent
 * at 20% disappears on teal), the dashed paths in sand at half strength.
 */
import { chromium } from 'playwright'
import { readFileSync, mkdirSync } from 'node:fs'

const SAND = '#EFE6D6'
const AMBER = '#F2A93B'
const WIDTH = 520 // px; the card is 1200 wide. Twice that is drawn, for sharp edges.

const source = readFileSync('resources/js/Components/SharedCoveIllustration.tsx', 'utf8')
const body = source.slice(source.indexOf('<svg'), source.indexOf('</svg>') + '</svg>'.length)

const svg = body
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
    .replace(/<svg[\s\S]*?>/, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 290 176" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">')
    .replace(/fill=\{BUOY\}/g, `fill="${AMBER}"`)
    .replace(/className="fill-accent\/20"/g, `fill="${AMBER}" fill-opacity="0.22"`)
    .replace(/className="fill-accent\/10"/g, `fill="${AMBER}" fill-opacity="0.12"`)
    .replace(/className="stroke-ink-soft"/g, `stroke="${SAND}" stroke-opacity="0.55"`)
    .replace(/strokeDasharray=/g, 'stroke-dasharray=')

if (/className|\{/.test(svg)) {
    throw new Error('The illustration uses something this script does not translate yet:\n' + svg)
}

const height = Math.round((WIDTH * 176) / 290)

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: WIDTH, height }, deviceScaleFactor: 2 })
await page.setContent(`<body style="margin:0;background:transparent;color:${SAND}">${svg.replace('<svg ', `<svg width="${WIDTH}" height="${height}" `)}</body>`)
mkdirSync('resources/og', { recursive: true })
await page.locator('svg').screenshot({ path: 'resources/og/shared-cove.png', omitBackground: true })
await browser.close()

console.log(`resources/og/shared-cove.png, ${WIDTH * 2}×${height * 2}`)
