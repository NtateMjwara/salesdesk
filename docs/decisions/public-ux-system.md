# SalesDesk public UX/UI system (v3 overhaul — Sep 2026)

Scope done: layout-public shell (nav, drawer, search sheet, footer), homepage, /cars-for-sale/ browse,
car detail, /desks/ directory, broker storefront (/{desk-slug}/), sitewide 404 (broker/404.php), /pricing (Nov 2026).
Next in page order: how-it-works pages, vehicle/index.php, broker/brokers.php. (Careers and newsletter pages were
removed in the Oct 2026 lean-up.)

## Rules kept
- No inline <style>/<script>/style=""/on*="" in pages; CSS/JS in external files, shared where possible.
- Extensionless internal links; .htaccess 301s .php GETs (api/ excluded).

## CSS layers (load order)
1. assets/css/global.css — tokens (accent orange #f26b1d, --accent-text/--on-accent for AA contrast, ink #0a1628, --container 1360px, --page-gutter, type scale, motion), .sd-container
2. assets/css/public-shell.css — nav / mega panels / search sheet / drawer / breadcrumb / footer (only layout-public.php)
3. assets/css/public.css — shared components: .pub-btn(-primary|-accent|-ghost|-dark|-soft|-on-ink|-whatsapp), .pub-chip, .pub-segment, .pub-badge, .pub-section scaffold, .pub-rail, .vc vehicle card, .vc-grid, .typeahead-box, .pub-empty, form kit, .fin finance widget, share sheet, toasts, lightbox, §16 pagination, §17 sort control + active-filter chips
4. page CSS: home.css + hero-search.css, browse.css (sidebar/filter sheet/results grid; keeps the legacy class contract), car-detail.css, desks.css (directory .dk-*, storefront .ds-*, error page .err-*)

## PHP partials
- views/partials/vehicle-card.php → sdVehicleCard($car, ['wishlisted','ref','variant'=>'compact','eager','heading'])
- views/partials/body-type-icon.php → sdBodyTypeIcon($bodyType, $class)
- layout-public.php optional vars: $hideNavSearch, $includeBrowseCss, $includeHowItWorksCss, $bodyClass, $extraJs

## JS (data-attribute driven, public.js)
[data-wishlist], [data-share-open|copy|close], [data-lightbox-images]+[data-lightbox-open], [data-carousel],
#enquiryForm, [data-clamp], [data-finance="repay|afford"], [data-tabs], [data-rail-prev|next], [data-sticky-cta],
[data-scrollspy], .pub-reveal, window.sdToast(), and the shared GET-form module:
window.sdSubmitClean(form) + [data-autosubmit] / [data-clean-submit] / .sort-form.
public-nav.js initialises every input[data-typeahead-box].
browse.js: filter sheet (≤1024px) + grid/list toggle; while the sheet is open it sets
[data-autosubmit-suspended] on [data-browse-filters] and shows a live count from /api/cars/search.php
(a form marked [data-desk-scoped] shows "Show results" instead — the API can't filter by desk).

## Decisions taken
- Broker commission shown on car detail only to signed-in trade roles (broker, sales_exec, dealer, admin).
- Orange buttons use ink text (white on orange fails WCAG AA); small orange text uses --accent-text.
- Placeholder social links (#) removed from footer until real profiles exist.
- Desk directory: no filter drawer (only name + province); both live in the hero/toolbar.
- Desk storefront cards omit desk_name so the seller line shows the dealership (the desk is implied).
- desks/index.php card photo previews use ROW_NUMBER() in one grouped query, wrapped in try/catch.
