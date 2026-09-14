/**
 * Settings: where GiftCoves is, which key to use, and the fallback market.
 *
 * Saving also asks Chrome for permission to reach the address, because an
 * address outside the manifest's `host_permissions` produces a fetch that
 * rejects with a network error rather than anything naming the real cause —
 * and the popup would report "could not reach" for a site that is plainly up.
 */

import { MARKETS, saveSettings, settings } from './api.js';

const el = {
  baseUrl: document.getElementById('baseUrl'),
  apiKey: document.getElementById('apiKey'),
  defaultMarket: document.getElementById('defaultMarket'),
  save: document.getElementById('save'),
  status: document.getElementById('status'),
};

function say(message, tone = '') {
  el.status.textContent = message;
  el.status.className = `status ${tone}`.trim();
}

function normalise(raw) {
  const value = raw.trim().replace(/\/+$/, '');

  // A bare hostname is what people type. Assume https rather than refusing:
  // the alternative is an error message about a scheme nobody was thinking
  // about.
  return /^https?:\/\//.test(value) ? value : `https://${value}`;
}

el.save.addEventListener('click', async () => {
  let baseUrl;

  try {
    baseUrl = normalise(el.baseUrl.value);
    new URL(baseUrl);
  } catch {
    say('That address is not a URL.', 'error');

    return;
  }

  const key = el.apiKey.value.trim();

  if (key && !key.startsWith('bc_')) {
    // The prefix is not decoration — it is what makes a leaked key findable in
    // a log or a secret scanner — so a value without it is nearly always
    // something else pasted by mistake.
    say('That does not look like a GiftCoves key. They start with bc_.', 'error');

    return;
  }

  try {
    await chrome.permissions.request({ origins: [`${baseUrl}/*`] });
  } catch {
    // Not fatal: the host may already be in the manifest, in which case Chrome
    // refuses the request as redundant and everything works.
  }

  await saveSettings({
    baseUrl,
    apiKey: key,
    defaultMarket: el.defaultMarket.value,
  });

  say('Saved.', 'done');
});

async function start() {
  const stored = await settings();

  for (const market of MARKETS) {
    el.defaultMarket.append(new Option(market.label, market.value));
  }

  el.baseUrl.value = stored.baseUrl;
  el.apiKey.value = stored.apiKey;
  el.defaultMarket.value = stored.defaultMarket;
}

start();
