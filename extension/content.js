/**
 * The bridge between the page and the extension.
 *
 * Nothing here decides anything except which scraper the page is for. The
 * scrapers themselves are `scan-bol.js` and `scan-amazon.js`, loaded into the
 * same isolated world just before this file.
 *
 * The listener returns `true` so the channel stays open for the asynchronous
 * reply. Without it Chrome closes the port the moment this function returns and
 * the popup receives undefined, which looks exactly like a page with no
 * products on it.
 */

function scanThisPage() {
  const host = location.hostname.replace(/^www\./, '').toLowerCase();

  if (host === 'bol.com' && typeof globalThis.giftcovesScanBol === 'function') {
    return globalThis.giftcovesScanBol();
  }

  if (host.startsWith('amazon.') && typeof globalThis.giftcovesScanAmazon === 'function') {
    return globalThis.giftcovesScanAmazon();
  }

  throw new Error('This page is not a bol.com or Amazon page the extension knows.');
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== 'giftcoves:scan') {
    return undefined;
  }

  try {
    sendResponse({ ok: true, ...scanThisPage() });
  } catch (error) {
    // Reported rather than thrown: a scrape that breaks on one unusual
    // template must say so in the popup, not fail silently as an empty page.
    sendResponse({ ok: false, error: String(error?.message ?? error) });
  }

  return true;
});
