# Legal and about pages

`/about`, `/privacy`, `/terms` in every market. Markdown on disk, rendered through
`LegalController`, with the company details interpolated from config at render time.

## Where the text lives

`resources/legal/{language}/{page}.md`, front matter for `title` and `updated`, body in Markdown.
Written by hand, not generated. These are the pages a regulator, a retailer's compliance team or an
affiliate network reads, and a model that invents an obligation we do not meet is worse than no page
at all.

Translated: `en`, `nl`. The other markets fall back to English and the page carries a visible
notice saying so. A silently untranslated legal page reads as if it were the local version.

`LegalController::PAGES` is an allowlist. The page slug reaches the filesystem, so anything not on
that list is a 404 before a path is built.

## Company details are placeholders, not text

`{{name}}`, `{{number}}`, `{{address}}`, `{{email}}`, `{{privacy_email}}` resolve from
`config('brandcoves.company')`. An imprint under Article 5 of the e-Commerce Directive has to be
correct in six documents at once; keeping the strings in one place is the only way that stays true
after the first address change.

A missing key renders as **[key — to be completed]** rather than an empty gap. A blank line where a
company number belongs looks deliberate. A visible placeholder does not.

## How the service is described

**Brandcoves is a product and brand discovery service that links to the shops selling the products.
It is not a price comparison service, and the pages must not describe it as one.**

The distinction is not cosmetic. "Comparison service" is close to a term of art, it invites the
reader to treat the site as the place where a buying decision is finished, and it makes price the
headline promise when the actual product is discovery: Coves, gift matching, brands, serendipity.
Prices appear on the site as information next to a link, and the pages say that much and no more.

What survived the reframing, deliberately:

- **The ranking disclosure in Terms §3.** Annex I of Directive (EU) 2019/2161 requires an online
  search facility to state the main parameters determining ranking and their relative weight. That
  obligation follows from offering search, not from calling ourselves a comparison site.
  `LegalPagesTest` asserts the section exists.
- **The accuracy disclaimer in Terms §4.** Prices are shown, so they have to be qualified: the
  retailer's page is authoritative, and nothing here is an offer capable of acceptance.
- **The discount-badge explanation.** Our badge measures against our own 30-day median, not a
  retailer's crossed-out "was" price. Article 6a of Directive 98/6/EC binds the seller announcing
  the reduction, which is not us, but stating the basis of our own badge is what keeps it honest.

## Retention

`bc:prune-personal-data` runs nightly at 03:20 and enforces the windows the privacy policy states.
A policy that promises a retention period nothing deletes against is a false statement, not an
aspiration. Windows live in `PrunePersonalDataCommand::RETENTION`; change them there and in the
policy together.

## Open

`hello@brandcoves.com` and `privacy@brandcoves.com` are published in the pages and **do not exist
yet**. Until those mailboxes are created, the GDPR contact point named in the privacy policy is
unreachable, which is itself a compliance gap.

French and Spanish translations are outstanding; both markets currently serve English with the
fallback notice.
