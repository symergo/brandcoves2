/**
 * The service worker: the keyboard shortcut, and reading the page on demand.
 *
 * The network call lives in `api.js`, shared with the popup. It runs from the
 * extension's own origin, which is why the server needs no cross-origin
 * configuration: a request to a host listed in `host_permissions` is exempt
 * from CORS, so GiftCoves never has to trust a browser at all.
 */

import { importPage, isSuccess, settings, summarise } from './api.js';
import { defaultSelection, scan } from './page.js';

/** Scan, send, report. What the keyboard shortcut runs. */
async function exportTab(tab) {
  const page = await scan(tab);

  if (!page?.ok) {
    throw new Error(page?.error ?? 'Could not read the page.');
  }

  if (page.site === 'amazon' && !page.locale) {
    throw new Error('GiftCoves does not cover this Amazon storefront.');
  }

  // The same default the popup ticks, so the shortcut is the popup's button
  // without the popup rather than a second, more aggressive behaviour.
  const products = defaultSelection(page);

  if (products.length === 0) {
    throw new Error('No products on this page.');
  }

  // A bol page off a market path has no market of its own; the configured
  // default is the fallback, exactly as in the popup.
  const market = page.market ?? (await settings()).defaultMarket;

  return importPage({ ...page, market }, products);
}

/*
 * The popup does its own scanning and sending, but it cannot do either before
 * it opens. This is the path for somebody who wants neither: one keystroke,
 * everything on the page, no dialogue.
 */
chrome.commands.onCommand.addListener(async (command, tab) => {
  if (command !== 'export-page' || !tab?.id) {
    return;
  }

  // A shortcut has no window to report into, so the badge and a notification
  // are the entire feedback. Both, because a badge alone is easy to miss and a
  // notification alone disappears.
  try {
    const result = await exportTab(tab);
    const landed = result.results.filter((row) => isSuccess(row.status)).length;

    await flash(tab.id, String(landed), '#166534');
    await notify('Sent to GiftCoves', summarise(result));
  } catch (error) {
    await flash(tab.id, '!', '#991b1b');
    await notify('GiftCoves import failed', String(error?.message ?? error));
  }
});

async function flash(tabId, text, colour) {
  await chrome.action.setBadgeBackgroundColor({ tabId, color: colour });
  await chrome.action.setBadgeText({ tabId, text });

  // Cleared on a timer rather than left in place: the badge describes one
  // import, and a stale number on a page somebody has since navigated away
  // from is worse than no number at all.
  setTimeout(() => chrome.action.setBadgeText({ tabId, text: '' }), 8000);
}

async function notify(title, message) {
  await chrome.notifications.create({
    type: 'basic',
    iconUrl: 'icons/icon128.png',
    title,
    message,
  });
}
