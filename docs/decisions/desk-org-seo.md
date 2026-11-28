# Desk organisations & SEO — decisions (Sep 2026)

> **Note (Nov 2026):** "desk organisations" are now **business networks** (see `plans-and-billing.md`). The attribution,
> URL, title, directory and noindex rules below still apply. The org hubs at `/desks/{org-slug}/` now show business
> networks, and a hub 404s until it has a verified broker with an active desk.

## Decided
- Attribution is unchanged. The lead goes to the desk slug in the URL, or to a valid ?ref=. Last click wins, and leads.organization_id is snapshotted when the lead is submitted. The org is presentation only.
- Desks own their organic traffic. Desk car pages are self-canonical, and desks compete for rankings themselves.
- Desk and car URLs stay flat (/{desk}/ and /cars-for-sale/{desk}/{car}/). The org never appears in a URL.
- Title pattern: "Desk | Org | SalesDesk", or "Desk | Independent Car Broker | SalesDesk". Suffixes are dropped from the right when the title runs past about 60 characters (70 on car pages, which read "Car — Price | Desk | Org | SalesDesk").
- /desks/ is the organisation directory (org cards plus an independent tile) above the full broker list. Hubs are at /desks/{org-slug}/ and /desks/independent/. Hubs list desks, not cars.
- Hub and directory ordering is neutral and merit-based: live cars, then views, then desk id.
- A platform car page (/cars-for-sale/car/{slug}/) is noindex whenever any live desk carries that car.
- Desks still named "My SalesDesk" are noindex and left out of sitemap-desks.xml.
- Org slugs "independent", "org" and "index" are reserved.
- OEM naming is flexible: partner with the brand, or give the org a similar name.

## Deferred
- Keeping a buyer tied to one desk across a visit. For now, if the buyer lands on Desk A and then enquires through Desk B, Desk B gets the lead.
- Replacing "first-listed desk" linking on /cars-for-sale/ and in sitemap-cars.
- Keeping old org slugs working after a rename. Renaming an org currently changes its slug (admin_scope.php), which breaks the old hub URL. This needs a slug-history table plus 301 redirects. (Since 2a, a single-dealership business's slug is fixed once real. Check whether Enterprise renames still change it.)

## Implemented in
includes/desk-seo.php, includes/desk-directory.php, views/partials/desk-card.php, desks/index.php, desks/org.php, broker/index.php, cars-for-sale/car-detail/index.php, includes/structured-data.php, sitemap/orgs.php, .htaccess
