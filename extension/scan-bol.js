/**
 * Read the products off a bol.com page.
 *
 * ## What this deliberately does not do
 *
 * It does not scrape prices, links or pictures as *facts*. It collects an id
 * and a title per product and nothing else travels to the server, because a
 * scraped price is wrong within the hour, a scraped title carries the page's
 * promotional wrapping, and a scraped affiliate link earns nothing. The server
 * re-fetches every product from bol's own API. The image and price picked up
 * here are for the popup's own preview and are never sent.
 *
 * ## Why the id comes out of the href
 *
 * Every other handle on a bol product — class names, `data-test` attributes,
 * the card markup — is redesign-fodder and changes without warning, and a
 * scraper pinned to them fails silently by finding nothing. The number in
 * `/p/<slug>/<id>/` is the product's address. It has survived every redesign
 * because it is what the site's own URLs are made of, and a page that stopped
 * containing it would not be a bol product page any more.
 *
 * Loaded as a content script before `content.js`, sharing its isolated world;
 * `scan-amazon.js` is its opposite number.
 */

/*
 * Wrapped so its names stay its own.
 *
 * Two content scripts injected into the same page share one isolated world and
 * therefore one global scope, so a top-level `const clean` here and another in
 * `scan-amazon.js` is a redeclaration error that kills BOTH scripts — and the
 * failure arrives as a page that scans empty, not as anything naming a
 * collision. Only the entry point is published, on `globalThis`.
 */
(() => {
  /** `/nl/nl/p/sony-mdr-zx110/9200000032872507/` → `9200000032872507`. */
  const PRODUCT_PATH = /\/p\/(?:[^/?#]+\/)?(\d{8,})(?:[/?#]|$)/;

  /** bol's own market segments. A page outside these is not a market we serve. */
  const MARKET_PATH = /^\/(nl|be)\/(nl|fr)(?:\/|$)/;

  /**
   * Anchor text that is a control rather than a product name.
   *
   * bol wraps several of these in the same product link — a compare checkbox, a
   * "view all sellers" line, a bare star rating — and each would otherwise become
   * the product's title and then the term the server searches bol with.
   */
  const NOT_A_TITLE = /^(?:\s*|[\d.,\s€%+-]*|bekijk.*|vergelijk.*|meer\s.*|voir.*|comparer.*|\+\s*\d+.*)$/i;

  /**
   * A price with no space in front of it, which a product name never has.
   *
   * bol's colour swatches are product links whose text is the variant name with
   * the price run straight onto it — "Blanc47,92", "Zwart29,99". Measured on a
   * live results page on 2026-09-14. Left in, it becomes the search term the
   * server tries to match the product by, and it matches nothing.
   */
  const GLUED_PRICE = /[a-z]\d+[.,]\d{2}\s*$/i;

  /**
   * The market this page belongs to, in our own vocabulary.
   *
   * `market`, never `locale`: `be-nl` and `nl-nl` are the same language and
   * different markets, with different tax, shipping and partner accounts.
   * Returns null off a market path so the caller falls back to the configured
   * default rather than guessing one.
   */
  function marketFromPath(pathname) {
    const match = MARKET_PATH.exec(pathname);

    if (!match) {
      return null;
    }

    return { 'nl/nl': 'nl-nl', 'be/nl': 'be-nl', 'be/fr': 'be-fr' }[`${match[1]}/${match[2]}`] ?? null;
  }

  function productIdFrom(href) {
    if (!href) {
      return null;
    }

    try {
      const match = PRODUCT_PATH.exec(new URL(href, location.origin).pathname);

      return match ? match[1] : null;
    } catch {
      return null;
    }
  }

  /**
   * Collapse whitespace, and undo percent-encoding where a page has leaked it.
   *
   * Some bol cards label the link with a slug taken straight out of a URL, so the
   * title arrives as `Het%20waren%20toch%20mijn%20ouders` — seen on a live
   * category page on 2026-09-14. Sent as-is it becomes the search term the server
   * tries to match the product by, and it matches nothing. Decoded in a try
   * because a stray `%` that is not an escape throws, and a title containing a
   * per-cent sign is a perfectly ordinary thing to want.
   */
  function clean(text) {
    let value = (text ?? '').replace(/\s+/g, ' ').trim();

    if (/%[0-9A-Fa-f]{2}/.test(value)) {
      try {
        value = decodeURIComponent(value).replace(/\s+/g, ' ').trim();
      } catch {
        /* not an escape sequence after all; keep what we had */
      }
    }

    return value;
  }

  function usableTitle(text) {
    const value = clean(text);

    if (value.length < 3 || value.length > 400) {
      return null;
    }

    return NOT_A_TITLE.test(value) || GLUED_PRICE.test(value) ? null : value;
  }

  /**
   * The best name available for the product behind this link, and how much that
   * source is worth.
   *
   * ## Why an image's alt text is not a candidate
   *
   * It reads like the best one — it is long, descriptive and always present — and
   * it is wrong. bol writes alt text to describe the *picture*: "Casque
   * supra-auriculaire Sony noir, tourné vers la gauche avec coussinets visibles".
   * That is a good alt attribute and a terrible product name, and because it is
   * the longest string on the card it wins any "prefer the longest" rule. Then it
   * becomes the term the server searches bol with, and the product is reported
   * as not found while sitting right there on the page. Measured on a live
   * results page on 2026-09-14, in both Dutch and French.
   *
   * So the sources are ranked by how deliberately each is a *name*, lowest first,
   * and a picture's description is not on the list at all.
   */
  function titleFor(anchor) {
    const card = anchor.closest('li, article, [data-test*="product"], [class*="product-item"]');

    const candidates = [
      // bol sets this on the link it considers the product's title link.
      anchor.getAttribute('title'),
      anchor.getAttribute('aria-label'),
      // The title link's own text. `innerText`, not `textContent`: a card's
      // badge is a child element with no whitespace around it, so textContent
      // returns "The Witchexclusieve bol editie" for a book called The Witch —
      // and that glued string becomes the term the server searches bol with.
      // innerText respects rendering and separates them. Seen on a live
      // campaign page, 2026-09-14.
      anchor.innerText ?? anchor.textContent,
      // Last, because finding the card means guessing at structure, and bol's
      // current cards carry neither a heading nor a data-test hook.
      card?.querySelector('[data-test*="title"], h2, h3')?.textContent,
    ];

    for (let rank = 0; rank < candidates.length; rank++) {
      const title = usableTitle(candidates[rank]);

      if (title) {
        return { title, rank };
      }
    }

    return { title: null, rank: Number.MAX_SAFE_INTEGER };
  }

  /** For the popup's preview only. Never sent to the server. */
  function previewFor(anchor) {
    const card = anchor.closest('li, article, [data-test*="product"], [class*="product-item"]');
    const image = anchor.querySelector('img') ?? card?.querySelector('img');
    const source = image?.getAttribute('src') ?? image?.getAttribute('data-src') ?? null;

    return source && source.startsWith('http') ? source : null;
  }

  /**
   * Barcodes the page states about itself.
   *
   * A product page carries one, in its structured data and in its specifications
   * table; a listing page carries none. Worth the effort because a barcode is an
   * exact identity that costs the server one API call, where a title costs a
   * search and a comparison — and because it is the identity that lets an
   * imported product join the same card as the same product from another shop.
   *
   * Returns a map of product id → EAN. Structured data on a product page
   * describes the product the page is about, so it is keyed to the id in the URL.
   */
  function barcodesOnPage() {
    const found = new Map();
    const pageId = productIdFrom(location.href);

    const record = (id, value) => {
      const digits = clean(value).replace(/\D+/g, '');

      if (id && digits.length === 13 && !found.has(id)) {
        found.set(id, digits);
      }
    };

    // Structured data. Walked rather than indexed: bol nests the product inside
    // a @graph on some templates and puts it at the top level on others.
    for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
      let data;

      try {
        data = JSON.parse(script.textContent ?? '');
      } catch {
        continue;
      }

      const stack = [data];
      // A bound, not a limit anyone should reach: structured data on a bol page
      // is a few hundred nodes. It is here because this walks JSON written by
      // somebody else, and an unbounded walk over hostile input is a hung tab.
      let budget = 20000;

      while (stack.length && budget-- > 0) {
        const node = stack.pop();

        if (!node || typeof node !== 'object') {
          continue;
        }

        if (!Array.isArray(node)) {
          const gtin = node.gtin13 ?? node.gtin ?? node.gtin14 ?? node.ean;

          if (gtin) {
            record(productIdFrom(node.url) ?? pageId, String(gtin));
          }
        }

        for (const value of Object.values(node)) {
          if (value && typeof value === 'object') {
            stack.push(value);
          }
        }
      }
    }

    // The specifications table, where bol prints the EAN in plain sight. Only
    // meaningful on a product page, where the whole page is about one product.
    if (pageId && !found.has(pageId)) {
      for (const label of document.querySelectorAll('dt, th')) {
        if (!/\bean\b/i.test(label.textContent ?? '')) {
          continue;
        }

        const value = label.nextElementSibling;

        if (value) {
          record(pageId, value.textContent ?? '');
        }
      }
    }

    return found;
  }

  /**
   * Every product this page links to, in the order they appear.
   *
   * Document order matters: it is the order a person just read, so the popup's
   * list matches the shelf they are looking at rather than whatever order the
   * markup happens to yield.
   */
  function giftcovesScanPage() {
    const barcodes = barcodesOnPage();
    const pageId = productIdFrom(location.href);
    const byId = new Map();

    for (const anchor of document.querySelectorAll('a[href]')) {
      const productId = productIdFrom(anchor.getAttribute('href'));

      if (!productId) {
        continue;
      }

      const { title, rank } = titleFor(anchor);
      const existing = byId.get(productId);

      if (existing) {
        /*
         * The same product is linked several times per card — the image, the
         * heading, a colour swatch, a cross-sell tile — and those links disagree
         * about what the product is called. Keep the one from the most
         * deliberate source rather than the longest: length is exactly the
         * measure that picks a picture's description over a product's name.
         */
        if (title && rank < existing.titleRank) {
          existing.title = title;
          existing.titleRank = rank;
        }

        existing.imageUrl ??= previewFor(anchor);

        continue;
      }

      byId.set(productId, {
        productId,
        title,
        titleRank: rank,
        ean: barcodes.get(productId) ?? null,
        imageUrl: previewFor(anchor),
        // On a product page, one of these is the product the page is about and
        // the rest are recommendations. The popup ticks the first and leaves the
        // rest for somebody to choose — "the products on this page" means
        // something different on a shelf and on a single product.
        isPageProduct: productId === pageId,
      });
    }

    // A product page links to itself through the breadcrumb and the gallery, so
    // it is normally picked up above — but not on every template, and the page's
    // own product is the one thing that must never be missed. Its `h1` is the
    // most reliable title on the site.
    if (pageId) {
      const heading = usableTitle(document.querySelector('h1')?.textContent);
      const own = byId.get(pageId);

      if (own) {
        own.title = heading ?? own.title;
      } else {
        byId.set(pageId, {
          productId: pageId,
          title: heading,
          titleRank: 0,
          ean: barcodes.get(pageId) ?? null,
          imageUrl: document.querySelector('.product-image img, [data-test*="image"] img')?.src ?? null,
          isPageProduct: true,
        });
      }
    }

    const all = [...byId.values()];

    /*
     * A product with neither a name nor a barcode is dropped, not listed.
     *
     * The server has exactly two ways back from a page id to a catalogue record
     * and both need one of those, so such a row cannot resolve however patiently
     * it is retried. Listing it anyway would put a permanent "not found on bol"
     * beside a product that is plainly on the screen, and turn a clean import of
     * thirty-two into an apparent failure of fifty-one.
     *
     * On a French results page this is nineteen of them: bol links each colour
     * swatch to its own product id, and the swatch's text is the colour and the
     * price rather than a name. They are real, distinct products — we just have
     * nothing to identify them with from a listing page. Counted so the popup can
     * say so, rather than silently shrinking the list.
     */
    const products = all.filter((product) => product.title !== null || product.ean !== null);

    // The page's own product first; everything else keeps document order, which
    // is the order somebody just read the shelf in.
    products.sort((a, b) => Number(b.isPageProduct) - Number(a.isPageProduct));

    return {
      site: 'bol',
      market: marketFromPath(location.pathname),
      pageUrl: location.href,
      pageTitle: document.title,
      isProductPage: pageId !== null,
      unidentified: all.length - products.length,
      products,
    };
  }

  globalThis.giftcovesScanBol = giftcovesScanPage;
})();
