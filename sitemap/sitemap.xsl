<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>

<xsl:template match="/">
<html>
<head>
  <meta charset="UTF-8"/>
  <title>SalesDesk — XML Sitemap</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; background:#f6f8fc; color:#1a2340; margin:0; padding:32px; }
    .wrap { max-width:1080px; margin:0 auto; }
    h1 { font-size:20px; margin:0 0 4px; }
    .sub { color:#6b7590; font-size:13px; margin-bottom:24px; }
    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2e6f0; border-radius:10px; overflow:hidden; }
    th { text-align:left; background:#0f4c9e; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:.05em; padding:10px 14px; }
    td { padding:9px 14px; font-size:13px; border-top:1px solid #eef0f7; }
    tr:hover td { background:#f8faff; }
    a { color:#0f4c9e; text-decoration:none; word-break:break-all; }
    a:hover { text-decoration:underline; }
    .badge { display:inline-block; font-size:10px; font-weight:700; background:#eef2ff; color:#0f4c9e; border-radius:999px; padding:2px 8px; }
    .count { font-weight:700; color:#0f4c9e; }
  </style>
</head>
<body>
<div class="wrap">

  <xsl:choose>
    <!-- Sitemap index: lists child sitemaps -->
    <xsl:when test="sm:sitemapindex">
      <h1>SalesDesk Sitemap Index</h1>
      <p class="sub"><span class="count"><xsl:value-of select="count(sm:sitemapindex/sm:sitemap)"/></span> child sitemap(s). This file is machine-readable XML for search engines — you're seeing a human-friendly view.</p>
      <table>
        <tr><th>Sitemap</th><th>Last Modified</th></tr>
        <xsl:for-each select="sm:sitemapindex/sm:sitemap">
          <tr>
            <td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td>
            <td><xsl:value-of select="sm:lastmod"/></td>
          </tr>
        </xsl:for-each>
      </table>
    </xsl:when>

    <!-- URL set: lists actual pages -->
    <xsl:otherwise>
      <h1>SalesDesk Sitemap</h1>
      <p class="sub"><span class="count"><xsl:value-of select="count(sm:urlset/sm:url)"/></span> URL(s) in this file.</p>
      <table>
        <tr><th>URL</th><th>Last Modified</th><th>Change Freq</th><th>Priority</th></tr>
        <xsl:for-each select="sm:urlset/sm:url">
          <tr>
            <td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td>
            <td><xsl:value-of select="sm:lastmod"/></td>
            <td><span class="badge"><xsl:value-of select="sm:changefreq"/></span></td>
            <td><xsl:value-of select="sm:priority"/></td>
          </tr>
        </xsl:for-each>
      </table>
    </xsl:otherwise>
  </xsl:choose>

</div>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
