/**
 * Settings, the GiftCoves call, and how to describe what came back.
 *
 * A module shared by the popup and the service worker. It has no listeners and
 * no side effects on load, which is the point: importing the worker into the
 * popup would register its command handler a second time, in a document that
 * is destroyed the moment the popup loses focus.
 */

const DEFAULTS = {
  baseUrl: 'https://giftcoves.com',
  apiKey: '',
  // Used only when the page is not on a market path — a bol homepage, a
  // checkout, a campaign landing page. bol serves no other markets.
  defaultMarket: 'nl-nl',
};

/** The markets bol actually serves. `en` and `es` have no bol country. */
export const MARKETS = [
  { value: 'nl-nl', label: 'Netherlands (nl-nl)' },
  { value: 'be-nl', label: 'Belgium, Dutch (be-nl)' },
  { value: 'be-fr', label: 'Belgium, French (be-fr)' },
];

/**
 * `chrome.storage.local`, not `sync`.
 *
 * The key can write to the catalogue, and `sync` would copy it to every machine
 * the profile is signed into — including ones nobody meant to put a credential
 * on.
 */
export async function settings() {
  return { ...DEFAULTS, ...(await chrome.storage.local.get(Object.keys(DEFAULTS))) };
}

export async function saveSettings(values) {
  await chrome.storage.local.set(values);
}

/** One authenticated POST to the editorial API. */
async function post(path, payload) {
  const { baseUrl, apiKey } = await settings();

  if (!apiKey) {
    throw new Error('No API key yet. Open the extension options and paste one in.');
  }

  let response;

  try {
    response = await fetch(`${baseUrl.replace(/\/+$/, '')}${path}`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${apiKey}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });
  } catch (error) {
    // A fetch that rejects rather than answers is almost always the host not
    // being in host_permissions, which produces no console error a person
    // would find on their own.
    throw new Error(`Could not reach ${baseUrl}. Check the address in options. (${error.message})`);
  }

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(errorMessage(response.status, body));
  }

  return body;
}

/**
 * How many products one request may carry.
 *
 * Matches the server's own ceiling (`BolPageImport::MAX_PRODUCTS`). An Amazon
 * search page yields over 160 products and a bol one over 50, so this is
 * reached routinely rather than exceptionally — which is why the client splits
 * rather than refusing. Kept identical on purpose: a client limit below the
 * server's would hide a raised ceiling, and one above it would produce a 422
 * describing a number nobody chose.
 */
const BATCH = 60;

/**
 * Send a page to GiftCoves, to whichever endpoint its site belongs to.
 *
 * The two are not variants of one call, and the difference is worth keeping in
 * front of whoever reads this next:
 *
 * **bol** takes ids and titles only. Everything the page said about price,
 * stock, picture or link is dropped here and re-fetched from bol's own API,
 * which is what makes an imported product trustworthy and what makes its
 * affiliate link earn — a scraped link would not.
 *
 * **Amazon** has no API configured, so the page is the source and the scraped
 * fields are sent as stated. Never the price: it is not read, not sent, and
 * there is no column for it. Those rows land in the ASIN decision store rather
 * than in the catalogue.
 *
 * Sent in batches, and the results are concatenated so the caller sees one
 * answer per product regardless of how many requests it took. `onProgress` is
 * called between batches because a bol import spends about a second per
 * product asking bol about it, and three minutes of an unchanging button is
 * indistinguishable from a hang.
 */
export async function importPage(page, products, onProgress = () => {}) {
  const batches = [];

  for (let i = 0; i < products.length; i += BATCH) {
    batches.push(products.slice(i, i + BATCH));
  }

  const merged = { market: page.market, locale: page.locale, requested: 0, imported: 0, results: [] };

  for (const [index, batch] of batches.entries()) {
    onProgress(index * BATCH, products.length);

    const body =
      page.site === 'amazon'
        ? await post('/api/editorial/import/amazon', {
            locale: page.locale,
            products: batch.map(({ asin, title, description, imageUrl, category, brand, ean, url }) => ({
              asin,
              title,
              description,
              imageUrl,
              category,
              brand,
              ean,
              url,
            })),
          })
        : await post('/api/editorial/import/bol', {
            market: page.market,
            products: batch.map(({ productId, ean, title }) => ({ productId, ean, title })),
          });

    merged.requested += body.requested ?? 0;
    merged.imported += body.imported ?? 0;
    merged.results.push(...(body.results ?? []));
  }

  return merged;
}

/**
 * Turn a failed response into something a person can act on.
 *
 * The status alone is not actionable — 401 and 403 mean two different fixes,
 * and 422 hides the useful part inside a validation bag — so each says what to
 * do next rather than what went wrong.
 */
function errorMessage(status, body) {
  if (status === 401) {
    return 'The API key was rejected. Check it in the extension options.';
  }

  if (status === 403) {
    return 'That key cannot write to the catalogue. It needs the editorial.write ability.';
  }

  if (status === 429) {
    return 'GiftCoves is rate limiting this key. Wait a minute and try again.';
  }

  const validation = Object.values(body?.errors ?? {})
    .flat()
    .join(' ');

  return validation || body?.message || `GiftCoves answered ${status}.`;
}

/** What each per-product status means, in words rather than in jargon. */
export const STATUS_LABELS = {
  imported: 'imported',
  updated: 'updated',
  unavailable: 'not for sale',
  unresolved: 'not found on bol',
  ungrouped: 'no barcode, no card',
  rate_limited: 'rate limited',
  skipped: 'no title on the page',
};

/** The statuses that mean the product is now in the database. */
export function isSuccess(status) {
  return status === 'imported' || status === 'updated';
}

/** A one-line tally, for a notification or a heading. */
export function summarise(result) {
  const counts = {};

  for (const row of result.results) {
    counts[row.status] = (counts[row.status] ?? 0) + 1;
  }

  return Object.entries(counts)
    .map(([status, count]) => `${count} ${STATUS_LABELS[status] ?? status}`)
    .join(', ');
}
