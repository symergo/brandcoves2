<?xml version="1.0" encoding="UTF-8"?>
<!--
  Shows a GiftCoves sitemap as a table in a browser (owner, 2026-11-08: "make the
  site map human readable"). Crawlers read the XML and ignore this file. Served
  by SitemapController::stylesheet() from this host, because browsers refuse an
  XSLT from another origin. XSLT 1.0: the only version browsers run.
-->
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xhtml="http://www.w3.org/1999/xhtml"
    exclude-result-prefixes="s xhtml">

    <xsl:output method="html" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>

    <xsl:template match="/">
        <html lang="en">
            <head>
                <meta charset="utf-8"/>
                <meta name="viewport" content="width=device-width, initial-scale=1"/>
                <meta name="robots" content="noindex"/>
                <title>GiftCoves sitemap</title>
                <style>
                    :root { --bg: #fff; --ink: #1d1d1b; --soft: #6b6b66; --line: #e4e2dc; --row: #f7f6f2; --link: #0b5c8a; }
                    @media (prefers-color-scheme: dark) {
                        :root { --bg: #161615; --ink: #ecebe6; --soft: #a3a29b; --line: #34332f; --row: #1f1f1d; --link: #7cc0ea; }
                    }
                    * { box-sizing: border-box; }
                    body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
                    main { max-width: 72rem; margin: 0 auto; padding: 2rem 16px 4rem; }
                    h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
                    p.lead { color: var(--soft); margin: 0 0 1.5rem; }
                    a { color: var(--link); text-decoration: none; overflow-wrap: anywhere; }
                    a:hover { text-decoration: underline; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
                    th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--soft); font-weight: 600; }
                    tbody tr:nth-child(odd) { background: var(--row); }
                    td.num, th.num { text-align: right; white-space: nowrap; }
                    td.small { font-size: .8rem; color: var(--soft); white-space: nowrap; }
                    .langs a { display: inline-block; margin-right: .5rem; font-size: .8rem; }
                    @media (max-width: 40rem) { .hide-sm { display: none; } }
                </style>
            </head>
            <body>
                <main>
                    <xsl:apply-templates/>
                </main>
            </body>
        </html>
    </xsl:template>

    <!-- The index: one file per market. -->
    <xsl:template match="s:sitemapindex">
        <h1>GiftCoves sitemap</h1>
        <p class="lead">
            <xsl:value-of select="count(s:sitemap)"/> files, one per market. Search engines read this
            page as XML; this table is for people.
        </p>
        <table>
            <thead><tr><th>File</th></tr></thead>
            <tbody>
                <xsl:for-each select="s:sitemap">
                    <tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td></tr>
                </xsl:for-each>
            </tbody>
        </table>
    </xsl:template>

    <!-- A market's file: every submitted page, with its language versions. -->
    <xsl:template match="s:urlset">
        <h1>GiftCoves sitemap</h1>
        <p class="lead">
            <xsl:value-of select="count(s:url)"/> pages.
            <a href="/sitemap.xml">All markets</a>
        </p>
        <table>
            <thead>
                <tr>
                    <th>Page</th>
                    <th class="hide-sm">Also in</th>
                    <th class="hide-sm">Checked</th>
                    <th class="num hide-sm">Priority</th>
                    <th class="hide-sm">Last changed</th>
                </tr>
            </thead>
            <tbody>
                <xsl:for-each select="s:url">
                    <xsl:variable name="loc" select="s:loc"/>
                    <tr>
                        <td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                        <td class="langs hide-sm">
                            <xsl:for-each select="xhtml:link[@href != $loc]">
                                <a href="{@href}" hreflang="{@hreflang}"><xsl:value-of select="@hreflang"/></a>
                            </xsl:for-each>
                        </td>
                        <td class="small hide-sm"><xsl:value-of select="s:changefreq"/></td>
                        <td class="num small hide-sm"><xsl:value-of select="s:priority"/></td>
                        <td class="small hide-sm"><xsl:value-of select="substring(s:lastmod, 1, 10)"/></td>
                    </tr>
                </xsl:for-each>
            </tbody>
        </table>
    </xsl:template>
</xsl:stylesheet>
