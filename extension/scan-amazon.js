/**
 * Read the products off an Amazon page.
 *
 * ## Why this one keeps the facts and the bol scraper throws them away
 *
 * The bol scraper collects an id and a title and nothing else, because the
 * server asks bol's API for everything that matters. There is no Amazon API
 * configured here, so this page is the only source there is: the title,
 * description, image, category and barcode below are stored as stated.
 *
 * **The price is deliberately not read.** Not omitted for lack of a selector —
 * `.a-price .a-offscreen` is right there. A price scraped today and read next
 * month is exactly the thing the Associates agreement's refresh rule exists to
 * prevent, and it is the one field a person would act on and be wrong about.
 * The owner asked for everything except the price on 2026-09-14. If that
 * changes, read `docs/features/amazon-compliance.md` first, and note that
 * nothing downstream has a column to put one in.
 *
 * ## Where the ASIN comes from
 *
 * `data-asin` on search results, and the `/dp/<asin>/` segment of the URL on a
 * product page. Both are Amazon's own identifier rather than markup, which is
 * what makes them survive the redesigns that break every class name.
 */

/*
 * Wrapped so its names stay its own — see the same note in `scan-bol.js`. Two
 * content scripts in one isolated world share a global scope, and a duplicate
 * top-level `const` kills both with an error that reads like an empty page.
 */
(() => {
  /** `/dp/B0BTJD6LCL/ref=…`, `/gp/product/B0BTJD6LCL`, `/gp/aw/d/B0BTJD6LCL`. */
  const ASIN_PATH = /\/(?:dp|gp\/product|gp\/aw\/d|gp\/offer-listing)\/([A-Z0-9]{10})(?:[/?#]|$)/;

  const ASIN = /^[A-Z0-9]{10}$/;

  /**
   * The storefronts GiftCoves knows, longest host first.
   *
   * Longest first matters: `amazon.com.be` ends with neither `amazon.com` nor
   * `amazon.be`, but a careless `endsWith` over an unordered list would match
   * the wrong one for some hosts. `amazon.com` is absent because
   * `App\Enums\AmazonLocale` has no US case — this site serves European
   * markets, and inventing a locale the server will reject helps nobody.
   */
  const LOCALES = ['amazon.com.be', 'amazon.co.uk', 'amazon.nl', 'amazon.de', 'amazon.fr', 'amazon.es', 'amazon.it'];

  /**
   * Detail-table labels that carry a barcode, across the storefront languages.
   *
   * Amazon prints these labels wrapped in bidirectional control characters
   * (`ASIN ‏ : ‎`), so the label is stripped to letters before it is tested.
   *
   * **Anchored at the start, not a loose word match.** The labels on one page
   * are a paragraph of prose each — "Aanbevolen leeftijd van fabrikant",
   * "Stopgezet door fabrikant" — and a `\b(fabrikant)\b` search happily
   * returned "Nee" as the brand of a pair of Sony headphones and "18 - 99 jaar"
   * as the brand of a LEGO set. Measured on amazon.nl, 2026-09-14.
   */
  const BARCODE_LABEL = /^(ean|upc|gtin|isbn ?13|barcode|code ?barres|strichcode)/i;

  /** The brand, in the storefront languages. Exact labels, for the reason above. */
  const BRAND_LABEL = /^(merknaam|merk|brand|marke|marque|marca)$/i;

  /** The manufacturer, which is the brand often enough to be worth a fallback. */
  const MAKER_LABEL = /^(fabrikant|manufacturer|hersteller|fabricant|fabricante)$/i;

  /** Sponsored results prefix the image's alt text; it is not part of the name. */
  const SPONSORED = /^(?:gesponsorde advertentie|sponsored ad|gesponserte anzeige|annonce sponsorisée|anuncio patrocinado|annuncio sponsorizzato)\s*[-–—]\s*/i;

  /**
   * Link text that is a control rather than a product name.
   *
   * A list page links the same product from its picture, its name, its price,
   * its rating and its "add to basket" button, and all but one of those would
   * become the product's title. Prices and star counts are covered by the
   * digits-and-punctuation arm.
   */
  const NOT_A_TITLE =
    /^(?:\s*|[\d.,\s€£%+-]*|.*\b(?:winkelwagen|basket|cart|warenkorb|panier|carrito|carrello)\b.*|(?:bekijk|zie|see|view|show|voir|ver|mehr|meer|more|más|plus|opties|options)\b.*|\d+[.,]?\d*\s*(?:van|out of|von|sur|de|su)\s*\d.*)$/i;

  function clean(text) {
    return (text ?? '').replace(/\s+/g, ' ').trim();
  }

  function localeFromHost(hostname) {
    const host = hostname.replace(/^www\./, '').toLowerCase();

    return LOCALES.find((locale) => host === locale) ?? null;
  }

  function asinFrom(href) {
    if (!href) {
      return null;
    }

    try {
      const match = ASIN_PATH.exec(new URL(href, location.origin).pathname);

      return match ? match[1] : null;
    } catch {
      return null;
    }
  }

  function absolute(url) {
    if (!url) {
      return null;
    }

    try {
      const resolved = new URL(url, location.origin);

      // https only, and checked here at the boundary. The server checks again;
      // this stops a data: URL ever being offered as a preview in the popup.
      return resolved.protocol === 'https:' ? resolved.href : null;
    } catch {
      return null;
    }
  }

  /**
   * Every row of every details table, as label/value pairs.
   *
   * Amazon uses at least four layouts for the same information depending on
   * the category and the storefront — a bulleted list, two different tables,
   * and a key/value grid — so all of them are read and the first usable answer
   * wins. Cheaper than deciding which layout this page is.
   */
  function detailRows() {
    const rows = [];

    // The bulleted layout: "Label ‏ : ‎ Value" inside one list item.
    for (const item of document.querySelectorAll('#detailBullets_feature_div li')) {
      const label = clean(item.querySelector('.a-text-bold')?.textContent);
      const spans = item.querySelectorAll('span .a-list-item span, span span');
      const value = clean(spans.length > 1 ? spans[spans.length - 1].textContent : '');

      if (label) {
        rows.push([label, value]);
      }
    }

    // The tabular layouts.
    for (const row of document.querySelectorAll(
      '#productDetails_detailBullets_sections1 tr, #productDetails_techSpec_section_1 tr, #prodDetails tr, .a-keyvalue tr, #technicalSpecifications_section_1 tr',
    )) {
      const label = clean(row.querySelector('th')?.textContent);
      const value = clean(row.querySelector('td')?.textContent);

      if (label) {
        rows.push([label, value]);
      }
    }

    return rows;
  }

  /**
   * A row whose label matches, by the label's letters alone.
   *
   * The label is stripped of the bidirectional marks and colons Amazon wraps it
   * in and collapsed to single spaces, so `ASIN ‏ : ‎` tests as `ASIN`.
   */
  function detail(rows, pattern) {
    for (const [label, value] of rows) {
      const stripped = label.replace(/[^\p{L}\d]+/gu, ' ').trim();

      if (pattern.test(stripped) && value) {
        return value;
      }
    }

    return null;
  }

  /**
   * The barcode, when the page prints one.
   *
   * Often it does not — Amazon omits it for most listings, and the Sony
   * headphones this was tested against on 2026-09-14 had a brand, a model
   * number and an ASIN but no EAN. So this returns null routinely and that is
   * not a failure; the import stores what it has.
   */
  function barcode(rows) {
    const value = detail(rows, BARCODE_LABEL);

    if (!value) {
      return null;
    }

    // A row can list several ("EAN : 0027242920453, 4548736141926"). The first
    // is enough, and the digits are pulled out of whatever punctuation is
    // around them.
    const digits = /\d[\d\s-]{10,}\d/.exec(value)?.[0]?.replace(/\D+/g, '') ?? '';

    return digits.length === 12 || digits.length === 13 ? digits : null;
  }

  /**
   * The most specific category on the breadcrumb.
   *
   * The trail runs coarse to fine — Electronics › Headphones › On-ear — and the
   * last entry is the useful one, the same choice the bol connector makes about
   * its GPC taxonomy and for the same reason: specificity is what makes a
   * category worth storing.
   */
  function category() {
    const links = document.querySelectorAll('#wayfinding-breadcrumbs_feature_div a');

    return links.length > 0 ? clean(links[links.length - 1].textContent) : null;
  }

  /**
   * The page's own product, in full.
   *
   * Only a product page has a description, a barcode or a breadcrumb, which is
   * why a shelf import is thinner than a product import of the same ASIN — and
   * why the server merges rather than overwrites.
   */
  function pageProduct() {
    const asin = asinFrom(location.href) ?? detail(detailRows(), /\bASIN\b/i);

    if (!asin || !ASIN.test(asin)) {
      return null;
    }

    const rows = detailRows();

    const bullets = [...document.querySelectorAll('#feature-bullets li span.a-list-item')]
      .map((node) => clean(node.textContent))
      .filter(Boolean);

    return {
      asin,
      title: clean(document.querySelector('#productTitle')?.textContent) || null,
      // The feature bullets, which are the description a person actually
      // reads. `#productDescription` is the fallback because many listings
      // leave it empty while the bullets are always there.
      description:
        bullets.join(' ') || clean(document.querySelector('#productDescription')?.textContent) || null,
      imageUrl: absolute(
        document.querySelector('#landingImage')?.getAttribute('src') ??
          document.querySelector('#imgTagWrapperId img')?.getAttribute('src'),
      ),
      category: category(),
      // The details table, not `#bylineInfo`: that renders as "Visit the Sony
      // Store", which is a link's label rather than a brand. The manufacturer
      // is the fallback because plenty of listings carry one and no brand row.
      brand: detail(rows, BRAND_LABEL) ?? detail(rows, MAKER_LABEL),
      ean: barcode(rows),
      url: location.origin + location.pathname,
      isPageProduct: true,
    };
  }

  function usableTitle(text) {
    const value = clean(text).replace(SPONSORED, '');

    if (value.length < 3 || value.length > 500) {
      return null;
    }

    return NOT_A_TITLE.test(value) ? null : value;
  }

  /**
   * The best name available for the product behind this link.
   *
   * A wish list is the case this ordering is for: its rows carry the full
   * product name in the anchor's `title` attribute and truncate the visible
   * text with an ellipsis, so reading the text would send a chopped-off name.
   * An image's `alt` is last and only as a fallback — on a search result it is
   * the product's name, which is why it is here at all.
   */
  function titleFrom(anchor, container) {
    const candidates = [
      anchor.getAttribute('title'),
      anchor.getAttribute('aria-label'),
      // `innerText`, not `textContent`: a badge or a price is a child element
      // with no whitespace around it, so textContent glues it onto the name.
      anchor.innerText ?? anchor.textContent,
      container?.querySelector('h2, h3, [id^="itemName"]')?.textContent,
      anchor.querySelector('img')?.getAttribute('alt'),
      container?.querySelector('img[alt]')?.getAttribute('alt'),
    ];

    for (const candidate of candidates) {
      const title = usableTitle(candidate);

      if (title) {
        return title;
      }
    }

    return null;
  }

  function imageFrom(anchor, container) {
    const image =
      anchor.querySelector('img') ??
      container?.querySelector('img.s-image, img[data-image-latency], img');

    return absolute(image?.getAttribute('src') ?? image?.getAttribute('data-src'));
  }

  /**
   * Every product on a page that is a list of products.
   *
   * ## Two passes, because Amazon has two kinds of list page
   *
   * **Search and category results** are built from `[data-asin]` containers.
   * The attribute appears on several nested elements per result and only one of
   * them holds the content, so a container counts only when a name can be found
   * inside it. Verified against amazon.nl on 2026-09-14, where the outer three
   * wrappers per product are empty.
   *
   * **Everything else** — a wish list, a registry, an Ideas list, a bestseller
   * chart, a deals page, a storefront — carries no `data-asin` at all. Those
   * are read from the links themselves: any href with a `/dp/<asin>` in it is a
   * product, which is the same reasoning the bol scraper uses for its ids. A
   * link is what a list page is made of, so it survives the redesigns that
   * rename every container.
   *
   * The link pass runs second and never overwrites, so where both apply the
   * richer result-card reading wins.
   */
  function shelfProducts() {
    const byAsin = new Map();

    const add = (asin, build) => {
      if (!ASIN.test(asin) || byAsin.has(asin)) {
        return;
      }

      const product = build();

      if (product?.title) {
        byAsin.set(asin, product);
      }
    };

    for (const container of [
      ...document.querySelectorAll('[data-component-type="s-search-result"][data-asin]'),
      ...document.querySelectorAll('[data-asin]'),
    ]) {
      const asin = (container.getAttribute('data-asin') ?? '').toUpperCase();

      add(asin, () => {
        const heading = container.querySelector('h2');
        const image = container.querySelector('img.s-image, img[data-image-latency]');
        const title = usableTitle(heading?.textContent) ?? usableTitle(image?.getAttribute('alt'));

        return {
          asin,
          title,
          // A shelf carries none of these. Sent as null so the server's merge
          // leaves whatever a product page said earlier intact.
          description: null,
          imageUrl: absolute(image?.getAttribute('src')),
          category: null,
          brand: null,
          ean: null,
          url: absolute(container.querySelector('a[href]')?.getAttribute('href'))?.split('?')[0] ?? null,
          isPageProduct: false,
        };
      });
    }

    for (const anchor of document.querySelectorAll('a[href]')) {
      const asin = asinFrom(anchor.getAttribute('href'));

      if (asin === null) {
        continue;
      }

      add(asin, () => {
        const container = anchor.closest('li, tr, [data-itemid], [data-asin], [class*="item"], [class*="card"]');

        return {
          asin,
          title: titleFrom(anchor, container),
          description: null,
          imageUrl: imageFrom(anchor, container),
          category: null,
          brand: null,
          ean: null,
          url: absolute(anchor.getAttribute('href'))?.split('?')[0] ?? null,
          isPageProduct: false,
        };
      });
    }

    return [...byAsin.values()];
  }

  function giftcovesScanAmazon() {
    const own = pageProduct();
    const products = shelfProducts();

    // The page's own product first and in full: on a product page Amazon
    // surrounds it with dozens of recommendations, and the one somebody
    // actually opened is the one they meant.
    if (own) {
      const index = products.findIndex((product) => product.asin === own.asin);

      if (index !== -1) {
        products.splice(index, 1);
      }

      products.unshift(own);
    }

    return {
      site: 'amazon',
      locale: localeFromHost(location.hostname),
      pageUrl: location.href,
      pageTitle: document.title,
      isProductPage: own !== null,
      unidentified: 0,
      products: products.map((product) => ({ ...product, productId: product.asin })),
    };
  }

  globalThis.giftcovesScanAmazon = giftcovesScanAmazon;
})();
