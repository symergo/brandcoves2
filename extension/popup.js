/**
 * The popup: what is on this page, and one button that sends it.
 *
 * It scans as soon as it opens and arms the button with everything selected, so
 * the common case really is one click. The list exists for the other case —
 * a shelf where three of the twenty are not wanted — and because a scrape that
 * quietly finds nothing, or finds the wrong things, has to be visible rather
 * than inferred from a disappointing number at the end.
 */

import { MARKETS, STATUS_LABELS, importPage, isSuccess, settings } from './api.js';
import { defaultSelection, isSupportedTab, scan } from './page.js';

const el = {
  status: document.getElementById('status'),
  controls: document.getElementById('controls'),
  marketField: document.getElementById('market-field'),
  market: document.getElementById('market'),
  localeField: document.getElementById('locale-field'),
  locale: document.getElementById('locale'),
  toggleAll: document.getElementById('toggle-all'),
  count: document.getElementById('count'),
  products: document.getElementById('products'),
  send: document.getElementById('send'),
  hint: document.getElementById('hint'),
  options: document.getElementById('options'),
};

/**
 * What each site promises about the data it sends.
 *
 * Shown rather than buried in a README because it is the one thing somebody
 * clicking this button should know: on bol the page only chooses, on Amazon the
 * page is believed — minus the price, always.
 */
const HINTS = {
  bol: 'Only ids and titles are sent. Prices, pictures and links come from bol.',
  amazon: 'Title, description, image, category and barcode. Never the price.',
};

/** The scanned page, and which of its products are still selected. */
let page = null;
let found = [];
const chosen = new Set();
let baseUrl = 'https://giftcoves.com';

el.options.addEventListener('click', (event) => {
  event.preventDefault();
  chrome.runtime.openOptionsPage();
});

function say(message, tone = '') {
  el.status.textContent = message;
  el.status.className = `status ${tone}`.trim();
}

function refreshButton() {
  el.send.disabled = chosen.size === 0;
  el.send.textContent = chosen.size === 1 ? 'Send 1 product' : `Send ${chosen.size} products`;
  el.count.textContent = `${chosen.size} of ${found.length} selected`;
}

function render() {
  el.products.replaceChildren();

  for (const product of found) {
    const row = document.createElement('li');
    row.dataset.id = product.productId;

    const tick = document.createElement('input');
    tick.type = 'checkbox';
    tick.checked = chosen.has(product.productId);
    tick.addEventListener('change', () => {
      tick.checked ? chosen.add(product.productId) : chosen.delete(product.productId);
      el.toggleAll.checked = chosen.size === found.length;
      refreshButton();
    });

    const image = document.createElement('img');
    // A missing preview is normal — some cards are text only — and an empty
    // src would render a broken-image glyph on every one of them.
    image.src = product.imageUrl ?? 'icons/icon48.png';
    image.alt = '';

    const title = document.createElement('div');
    title.className = 'title';
    // Never innerHTML: this text came off somebody else's page.
    title.textContent = product.title ?? '(no title found)';
    title.title = product.title ?? '';

    const detail = document.createElement('small');
    detail.textContent = product.ean ? `EAN ${product.ean}` : product.productId;
    title.append(detail);

    const outcome = document.createElement('span');
    outcome.className = 'outcome';

    row.append(tick, image, title, outcome);
    el.products.append(row);
  }

  refreshButton();
}

el.toggleAll.addEventListener('change', () => {
  chosen.clear();

  if (el.toggleAll.checked) {
    for (const product of found) {
      chosen.add(product.productId);
    }
  }

  for (const tick of el.products.querySelectorAll('input[type=checkbox]')) {
    tick.checked = el.toggleAll.checked;
  }

  refreshButton();
});

/**
 * Show what each product became, in its own row.
 *
 * A tally alone would leave somebody counting cards to work out which six of
 * their twenty did not make it, and the answer is per product: one was not for
 * sale, another could not be matched.
 */
function report(result) {
  // bol answers with `productId`, Amazon with `asin`. One row shape here.
  const byId = new Map(result.results.map((row) => [row.productId ?? row.asin, row]));

  for (const row of el.products.querySelectorAll('li')) {
    const outcome = byId.get(row.dataset.id);
    const cell = row.querySelector('.outcome');

    if (!outcome) {
      continue;
    }

    cell.className = `outcome ${isSuccess(outcome.status) ? 'imported' : 'failed'}`;
    cell.textContent = STATUS_LABELS[outcome.status] ?? outcome.status;

    // Where the product ended up. For bol that is its own product page; for
    // Amazon it is the page of the product we already sell, when the barcode
    // matched one — which is the whole reason for scraping a barcode.
    const groupUrl = outcome.group?.url;
    const matched = outcome.matchedGroupId;

    if (groupUrl || matched) {
      const link = document.createElement('a');
      link.href = `${baseUrl.replace(/\/+$/, '')}${groupUrl ?? `/nl-nl/p/${matched}`}`;
      link.target = '_blank';
      link.rel = 'noreferrer';
      link.textContent = groupUrl ? 'imported' : 'matched';
      cell.replaceChildren(link);
    }
  }
}

el.send.addEventListener('click', async () => {
  const products = found.filter((product) => chosen.has(product.productId));

  el.send.disabled = true;
  say(`Sending ${products.length}…`);

  try {
    // The market select is the one thing the popup may override, so it is read
    // back rather than taken from the page.
    const result = await importPage(
      { ...page, market: el.market.value },
      products,
      // A bol import spends about a second per product asking bol about it, so
      // a shelf of sixty is a minute of silence without this.
      (done, total) => (done > 0 ? say(`Sending ${done} of ${total}…`) : undefined),
    );

    report(result);

    const landed = result.results.filter((row) => isSuccess(row.status)).length;

    say(`${landed} of ${result.requested} saved.`, landed > 0 ? 'done' : 'error');
  } catch (error) {
    say(String(error?.message ?? error), 'error');
    el.send.disabled = false;
  }
});

async function start() {
  const stored = await settings();
  baseUrl = stored.baseUrl;

  for (const market of MARKETS) {
    el.market.append(new Option(market.label, market.value));
  }

  if (!stored.apiKey) {
    say('No API key yet. Open Settings and paste one in.', 'error');

    return;
  }

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

  if (!isSupportedTab(tab)) {
    say('Open a bol.com or Amazon page first.', 'error');

    return;
  }

  try {
    page = await scan(tab);
  } catch (error) {
    say(`Could not read the page: ${error?.message ?? error}`, 'error');

    return;
  }

  if (!page?.ok) {
    say(page?.error ?? 'Could not read the page.', 'error');

    return;
  }

  el.hint.textContent = HINTS[page.site] ?? '';
  found = page.products;

  if (page.site === 'amazon') {
    // Amazon rows are per storefront, not per market: which storefront is a
    // fact about the data rather than a preference, so it is shown and not
    // offered as a choice.
    el.marketField.hidden = true;
    el.localeField.hidden = false;
    el.locale.textContent = page.locale ?? 'unsupported';

    if (!page.locale) {
      say('GiftCoves does not cover this Amazon storefront.', 'error');

      return;
    }
  } else {
    // The page's own market beats the configured default, which is only a
    // fallback for a bol page that is not on a market path at all. A chosen
    // market beats a guessed one, and the select is the choosing.
    el.market.value = page.market ?? stored.defaultMarket;
  }

  if (found.length === 0) {
    say('No products found on this page.', 'error');

    return;
  }

  for (const product of defaultSelection(page)) {
    chosen.add(product.productId);
  }

  el.toggleAll.checked = chosen.size === found.length;

  // The count of what could not be identified is shown rather than swallowed:
  // a shelf of fifty that offers thirty-two should say why, not just look short.
  const aside = page.unidentified > 0 ? ` ${page.unidentified} could not be identified.` : '';

  say(
    page.isProductPage && chosen.size < found.length
      ? `This product, plus ${found.length - chosen.size} recommended.${aside}`
      : `${found.length} products on this page.${aside}`,
  );

  el.controls.hidden = false;
  render();
}

start();
