/**
 * Asking a tab what products are on it, and what "all of them" means there.
 *
 * Its own module because both the popup and the service worker need it, and
 * neither may import the other: importing the worker into the popup would
 * register the worker's command handler a second time, in a document Chrome
 * destroys the moment the popup loses focus.
 */

/** The storefronts the extension reads. Amazon hosts match `App\Enums\AmazonLocale`. */
const SUPPORTED = [
  'bol.com',
  'amazon.nl',
  'amazon.com.be',
  'amazon.de',
  'amazon.fr',
  'amazon.es',
  'amazon.it',
  'amazon.co.uk',
];

/**
 * Which scraper a host needs.
 *
 * Injected by file rather than inferred inside the page, so the fallback
 * injection loads one scraper rather than both — they share an isolated world,
 * and two of them is a redeclaration error that kills both.
 */
function scannerFor(host) {
  return host === 'bol.com' ? 'scan-bol.js' : 'scan-amazon.js';
}

function hostOf(tab) {
  try {
    return new URL(tab.url).hostname.replace(/^www\./, '').toLowerCase();
  } catch {
    return null;
  }
}

/** Whether this tab is somewhere the scan could possibly find anything. */
export function isSupportedTab(tab) {
  const host = typeof tab?.url === 'string' ? hostOf(tab) : null;

  return host !== null && SUPPORTED.includes(host);
}

/**
 * The manifest injects the content script on every supported page, but not into
 * tabs that were already open when the extension was installed or reloaded —
 * those have no listener, and the message goes nowhere with an error about a
 * receiving end that does not exist. Rather than ask somebody to reload the
 * page, inject on demand and ask again.
 */
export async function scan(tab) {
  const tabId = typeof tab === 'number' ? tab : tab.id;

  try {
    return await chrome.tabs.sendMessage(tabId, { type: 'giftcoves:scan' });
  } catch {
    const target = typeof tab === 'number' ? await chrome.tabs.get(tabId) : tab;

    await chrome.scripting.executeScript({
      target: { tabId },
      files: [scannerFor(hostOf(target) ?? ''), 'content.js'],
    });

    return chrome.tabs.sendMessage(tabId, { type: 'giftcoves:scan' });
  }
}

/**
 * What "the products on this page" means, which depends on the page.
 *
 * On a shelf — a search, a category, a list — it means all of them, and that is
 * the whole point of the extension. On a single product page it means that
 * product: both sites surround it with dozens of recommendations, and importing
 * those because somebody looked at one pair of headphones would fill the
 * database with things nobody chose. They are still listed, and still one tick
 * away.
 */
export function defaultSelection(page) {
  if (!page.isProductPage) {
    return page.products;
  }

  const own = page.products.filter((product) => product.isPageProduct);

  return own.length > 0 ? own : page.products;
}
