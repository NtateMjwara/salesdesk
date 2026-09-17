<?php
/**
 * SalesDesk — Car Comparison Tool
 * Route: /tools/compare/
 *        /tools/compare/?ids=12,47,93          (pre-load up to 3 cars by ID)
 *        /tools/compare/?slugs=slug-a,slug-b   (pre-load by car slug)
 *
 * Features:
 *   - Up to 3 cars compared side-by-side
 *   - Live search picker (queries cars table, returns JSON)
 *   - Full spec table with per-row winner highlight
 *   - Finance estimate per car (NCA annuity, configurable term/rate)
 *   - Running cost estimate per car
 *   - Overall "best value" verdict
 *   - Share URL (ids encoded in query string)
 *   - Print-optimised layout
 *
 * PHP role: pre-load cars requested via ?ids= or ?slugs= query params,
 * then pass to JS as JSON. All UI interaction is client-side.
 * No auth required.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Live car count (nav badge) ─────────────────────────────────
try {
    $totalCars = (int) $pdo->query("
        SELECT COUNT(DISTINCT c.id)
        FROM cars c
        JOIN broker_inventory bi ON bi.car_id = c.id
        WHERE c.status = 'active'
    ")->fetchColumn();
} catch (Throwable) {
    $totalCars = 0;
}

// ── Pre-load cars from query string ───────────────────────────
// Accepts ?ids=1,2,3 or ?slugs=slug-a,slug-b,slug-c
// Sanitised, limited to 3, only active cars with at least one desk.
$preloadCars = [];

function loadCarsByField(PDO $pdo, string $field, array $values): array
{
    if (empty($values)) return [];
    $values = array_slice($values, 0, 3);

    // Build safe placeholders
    $ph = implode(',', array_fill(0, count($values), '?'));

    $stmt = $pdo->prepare("
        SELECT
            c.id, c.slug, c.make, c.model, c.year, c.price,
            c.mileage, c.condition_type, c.body_type, c.colour,
            c.transmission, c.fuel_type, c.drivetrain,
            c.engine_size, c.doors, c.seats,
            c.image_urls, c.status,
            d.company_name      AS dealer_name,
            d.verification_status AS dealer_verified,
            a.city              AS dealer_city,
            a.province          AS dealer_province,
            first_desk.desk_slug,
            first_desk.desk_name
        FROM cars c
        JOIN dealers d        ON d.id = c.dealer_id
        LEFT JOIN addresses a ON a.id = d.address_id
        LEFT JOIN (
            SELECT bi.car_id,
                   sd.slug         AS desk_slug,
                   sd.display_name AS desk_name
            FROM broker_inventory bi
            JOIN salesdesks sd ON sd.id = bi.salesdesk_id
            WHERE bi.added_at = (
                SELECT MIN(bi2.added_at) FROM broker_inventory bi2
                WHERE bi2.car_id = bi.car_id
            )
            GROUP BY bi.car_id
        ) first_desk ON first_desk.car_id = c.id
        WHERE c.{$field} IN ({$ph})
          AND c.status = 'active'
          AND first_desk.desk_slug IS NOT NULL
        ORDER BY FIELD(c.{$field}, " . $ph . ")
        LIMIT 3
    ");

    // Execute with values twice (once for IN, once for FIELD ordering)
    $stmt->execute(array_merge($values, $values));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!empty($_GET['ids'])) {
        $rawIds = array_map('intval', explode(',', $_GET['ids']));
        $rawIds = array_filter($rawIds, fn($v) => $v > 0);
        $preloadCars = loadCarsByField($pdo, 'id', array_values($rawIds));
    } elseif (!empty($_GET['slugs'])) {
        $rawSlugs = array_map(
            fn($s) => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($s))),
            explode(',', $_GET['slugs'])
        );
        $rawSlugs = array_filter($rawSlugs);
        $preloadCars = loadCarsByField($pdo, 'slug', array_values($rawSlugs));
    }
} catch (Throwable $e) {
    error_log('[SalesDesk compare] preload error: ' . $e->getMessage());
    $preloadCars = [];
}

// Normalise image_urls to first image only for PHP-side OG tag
$ogImage = '';
if (!empty($preloadCars[0]['image_urls'])) {
    $imgs = json_decode($preloadCars[0]['image_urls'], true);
    $ogImage = $imgs[0] ?? '';
}

// ── Page meta ──────────────────────────────────────────────────
$pageTitle     = 'Car Comparison Tool — Compare Up to 3 Vehicles | SalesDesk';
$ogTitle       = 'Compare Cars Side-by-Side | SalesDesk';
$ogDescription = 'Compare up to 3 vehicles side-by-side: specs, pricing, finance estimates, and running costs. South Africa\'s free car comparison tool.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/tools/compare/';
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [
    ['Tools & Services', null],
    ['Car Comparison', null],
];

// Pass preloaded data to JS safely
$preloadJson = json_encode($preloadCars, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

ob_start();
?>

<!-- ══════════════════════════════════════════════════════
     CAR COMPARISON PAGE
     ══════════════════════════════════════════════════════ -->

<div class="cmp-page">

  <!-- ── Page header ──────────────────────────────────── -->
  <div class="cmp-page-header">
    <div class="cmp-page-header__eyebrow">
      <i class="fa-solid fa-scale-balanced"></i> Free Tool
    </div>
    <h1 class="cmp-page-header__title">Car Comparison Tool</h1>
    <p class="cmp-page-header__sub">
      Compare up to 3 vehicles side-by-side — specs, pricing, estimated finance
      repayments, and total running costs. Add cars using the search below.
    </p>
  </div>

  <!-- ── Finance settings bar ─────────────────────────── -->
  <div class="cmp-settings-bar">
    <div class="cmp-settings-bar__label">
      <i class="fa-solid fa-sliders"></i> Finance settings applied to all cars
    </div>
    <div class="cmp-settings-bar__fields">
      <div class="cmp-settings-field">
        <label class="cmp-settings-label" for="cfg_deposit_pct">Deposit</label>
        <div class="cmp-settings-input-wrap">
          <input type="number" id="cfg_deposit_pct" value="20" min="0" max="50" step="5" class="cmp-settings-input">
          <span class="cmp-settings-suffix">%</span>
        </div>
      </div>
      <div class="cmp-settings-field">
        <label class="cmp-settings-label" for="cfg_rate">Interest rate</label>
        <div class="cmp-settings-input-wrap">
          <input type="number" id="cfg_rate" value="13.25" min="7" max="25" step="0.25" class="cmp-settings-input">
          <span class="cmp-settings-suffix">% p.a.</span>
        </div>
      </div>
      <div class="cmp-settings-field">
        <label class="cmp-settings-label">Term</label>
        <div class="cmp-term-chips">
          <button class="cmp-term-chip" data-months="36" type="button">36 mo</button>
          <button class="cmp-term-chip" data-months="48" type="button">48 mo</button>
          <button class="cmp-term-chip cmp-term-chip--active" data-months="60" type="button">60 mo</button>
          <button class="cmp-term-chip" data-months="72" type="button">72 mo</button>
        </div>
        <input type="hidden" id="cfg_term" value="60">
      </div>
      <div class="cmp-settings-field">
        <label class="cmp-settings-label" for="cfg_km">Monthly km</label>
        <div class="cmp-settings-input-wrap">
          <input type="number" id="cfg_km" value="1500" min="500" max="5000" step="100" class="cmp-settings-input">
          <span class="cmp-settings-suffix">km</span>
        </div>
      </div>
    </div>
    <div class="cmp-settings-bar__actions">
      <button class="cmp-share-btn" id="cmpShareBtn" type="button">
        <i class="fa-solid fa-share-nodes"></i> Share comparison
      </button>
      <button class="cmp-print-btn" onclick="window.print()" type="button">
        <i class="fa-solid fa-print"></i> Print
      </button>
    </div>
  </div>

  <!-- ── Car slots ─────────────────────────────────────── -->
  <div class="cmp-slots" id="cmpSlots">

    <!-- Slot template (repeated 3×) -->
    <?php for ($slot = 0; $slot < 3; $slot++): ?>
    <div class="cmp-slot" id="slot<?= $slot ?>" data-slot="<?= $slot ?>">

      <!-- Empty state -->
      <div class="cmp-slot__empty" id="slotEmpty<?= $slot ?>">
        <div class="cmp-slot__empty-icon">
          <i class="fa-solid fa-car-side"></i>
        </div>
        <div class="cmp-slot__empty-title">
          <?= $slot === 0 ? 'Add your first car' : ($slot === 1 ? 'Add a second car' : 'Add a third car') ?>
        </div>
        <div class="cmp-slot__empty-sub">Search by make, model, or year</div>
        <div class="cmp-search-wrap">
          <div class="cmp-search-box">
            <i class="fa-solid fa-magnifying-glass cmp-search-icon"></i>
            <input type="text"
                   class="cmp-search-input"
                   placeholder="e.g. Toyota Hilux 2023…"
                   data-slot="<?= $slot ?>"
                   autocomplete="off">
            <div class="cmp-search-spinner" style="display:none;">
              <i class="fa-solid fa-circle-notch fa-spin"></i>
            </div>
          </div>
          <div class="cmp-search-results" id="searchResults<?= $slot ?>" style="display:none;"></div>
        </div>
      </div>

      <!-- Filled state (hidden until car selected) -->
      <div class="cmp-slot__filled" id="slotFilled<?= $slot ?>" style="display:none;">
        <div class="cmp-car-header" id="carHeader<?= $slot ?>">
          <!-- Filled by JS -->
        </div>
      </div>

    </div>
    <?php endfor; ?>

  </div><!-- /cmp-slots -->

  <!-- ── Comparison table (hidden until ≥2 cars) ──────── -->
  <div class="cmp-table-wrap" id="cmpTableWrap" style="display:none;">

    <!-- Verdict banner -->
    <div class="cmp-verdict" id="cmpVerdict" style="display:none;">
      <div class="cmp-verdict__icon"><i class="fa-solid fa-trophy"></i></div>
      <div class="cmp-verdict__body">
        <div class="cmp-verdict__label">Best overall value</div>
        <div class="cmp-verdict__name" id="verdictName">—</div>
      </div>
      <div class="cmp-verdict__score" id="verdictScore">—</div>
    </div>

    <div class="cmp-table-container">

      <!-- Sticky header row (image + name + price) -->
      <div class="cmp-sticky-header" id="cmpStickyHeader">
        <div class="cmp-sticky-header__label">Comparing</div>
        <div class="cmp-sticky-slots" id="cmpStickySlots">
          <!-- Filled by JS -->
        </div>
      </div>

      <!-- Spec sections -->
      <table class="cmp-table" id="cmpTable">
        <tbody id="cmpTableBody">
          <!-- Filled by JS -->
        </tbody>
      </table>

    </div>
  </div><!-- /cmp-table-wrap -->

  <!-- ── Share toast ──────────────────────────────────── -->
  <div class="cmp-toast" id="cmpToast" style="display:none;">
    <i class="fa-solid fa-check"></i> Link copied to clipboard!
  </div>

  <!-- ── Empty prompt ─────────────────────────────────── -->
  <div class="cmp-start-prompt" id="cmpStartPrompt">
    <div class="cmp-start-prompt__icon"><i class="fa-solid fa-scale-balanced"></i></div>
    <div class="cmp-start-prompt__title">Start comparing</div>
    <div class="cmp-start-prompt__sub">
      Search for cars in the slots above to begin your comparison.<br>
      Add at least 2 cars to see the full side-by-side breakdown.
    </div>
    <a href="/c/" class="cmp-browse-link">
      <i class="fa-solid fa-magnifying-glass"></i>
      Browse <?= $totalCars > 0 ? number_format($totalCars) . ' vehicles' : 'vehicles' ?> to compare
      <i class="fa-solid fa-arrow-right"></i>
    </a>
  </div>

  <!-- ── Disclaimer ───────────────────────────────────── -->
  <div class="cmp-disclaimer">
    <i class="fa-solid fa-circle-info"></i>
    <p>
      Finance estimates are indicative only, based on the configured deposit, rate, and term. 
      Running costs use approximate fuel prices and consumption figures — actual costs will vary.
      This tool does not constitute financial advice. Prime rate assumed at 11.25% p.a.
    </p>
  </div>

</div><!-- /cmp-page -->


<!-- ══════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════ -->
<style>
/* ── Page shell ─────────────────────────────────────────────── */
.cmp-page {
  max-width: 1400px;
  margin: 0 auto;
  padding: 32px clamp(16px, 3vw, 40px) 80px;
}

/* ── Page header ────────────────────────────────────────────── */
.cmp-page-header { margin-bottom: 28px; max-width: 640px; }
.cmp-page-header__eyebrow {
  font-size: 11px; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--p); margin-bottom: 8px;
}
.cmp-page-header__title {
  font-family: var(--font-d);
  font-size: clamp(22px, 3vw, 32px);
  font-weight: 800; color: var(--text);
  letter-spacing: -.02em; margin-bottom: 10px;
}
.cmp-page-header__sub {
  font-size: 14px; color: var(--muted); line-height: 1.7;
}

/* ── Settings bar ───────────────────────────────────────────── */
.cmp-settings-bar {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 14px 20px;
  margin-bottom: 24px;
  box-shadow: var(--shadow-sm);
}
.cmp-settings-bar__label {
  font-size: 12px; font-weight: 700; color: var(--muted);
  display: flex; align-items: center; gap: 7px; white-space: nowrap;
  flex-shrink: 0;
}
.cmp-settings-bar__label i { color: var(--p); }
.cmp-settings-bar__fields {
  display: flex; gap: 12px; align-items: center; flex-wrap: wrap; flex: 1;
}
.cmp-settings-field {
  display: flex; align-items: center; gap: 8px;
}
.cmp-settings-label {
  font-size: 11px; font-weight: 700; color: var(--faint);
  text-transform: uppercase; letter-spacing: .04em; white-space: nowrap;
}
.cmp-settings-input-wrap {
  display: flex; align-items: center;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  background: #f8faff;
  overflow: hidden;
  transition: border-color .18s;
}
.cmp-settings-input-wrap:focus-within { border-color: var(--p); background: #fff; }
.cmp-settings-input {
  width: 70px; height: 34px;
  border: none; background: transparent;
  padding: 0 8px;
  font-size: 13px; font-family: var(--mono);
  color: var(--text); outline: none;
  -moz-appearance: textfield;
}
.cmp-settings-input::-webkit-outer-spin-button,
.cmp-settings-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.cmp-settings-suffix {
  padding: 0 8px; font-size: 11px; font-weight: 600;
  color: var(--faint); background: #f0f3f9;
  border-left: 1px solid var(--border);
  height: 34px; display: flex; align-items: center; white-space: nowrap;
}
.cmp-term-chips { display: flex; gap: 4px; }
.cmp-term-chip {
  padding: 4px 10px; border: 1.5px solid var(--border);
  border-radius: var(--r-full); font-size: 11px; font-weight: 600;
  color: var(--muted); background: #fff; cursor: pointer;
  font-family: var(--sans); transition: all .15s; white-space: nowrap;
}
.cmp-term-chip:hover { border-color: var(--p); color: var(--p); }
.cmp-term-chip--active { background: var(--p); color: #fff; border-color: var(--p); }
.cmp-settings-bar__actions { display: flex; gap: 8px; margin-left: auto; flex-shrink: 0; }
.cmp-share-btn, .cmp-print-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 14px;
  border: 1.5px solid var(--border); border-radius: var(--r-md);
  font-size: 12px; font-weight: 600; color: var(--muted);
  background: #fff; cursor: pointer; font-family: var(--sans);
  transition: all .18s; white-space: nowrap;
}
.cmp-share-btn:hover, .cmp-print-btn:hover {
  border-color: var(--p); color: var(--p); background: var(--p-light);
}

/* ── Car slots ──────────────────────────────────────────────── */
.cmp-slots {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 28px;
}
.cmp-slot {
  border-radius: var(--r-xl);
  border: 1.5px dashed var(--border);
  background: #fafbff;
  min-height: 200px;
  transition: border-color .18s;
}
.cmp-slot--filled {
  border-style: solid;
  border-color: var(--border);
  background: #fff;
  box-shadow: var(--shadow-md);
}

/* Empty state */
.cmp-slot__empty {
  padding: 24px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 8px;
}
.cmp-slot__empty-icon {
  width: 48px; height: 48px;
  border-radius: var(--r-lg);
  background: var(--p-light);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: var(--p); margin-bottom: 4px;
}
.cmp-slot__empty-title { font-size: 14px; font-weight: 700; color: var(--text); }
.cmp-slot__empty-sub { font-size: 12px; color: var(--faint); margin-bottom: 4px; }

/* Search */
.cmp-search-wrap { width: 100%; position: relative; }
.cmp-search-box {
  display: flex; align-items: center;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  background: #fff;
  overflow: visible;
  transition: border-color .18s;
  position: relative;
}
.cmp-search-box:focus-within { border-color: var(--p); box-shadow: 0 0 0 3px rgba(15,76,158,.07); }
.cmp-search-icon {
  padding: 0 10px; font-size: 12px; color: var(--faint); flex-shrink: 0;
}
.cmp-search-input {
  flex: 1; height: 38px; border: none; background: transparent;
  font-size: 13px; font-family: var(--sans); color: var(--text); outline: none;
  min-width: 0;
}
.cmp-search-input::placeholder { color: var(--faint); }
.cmp-search-spinner { padding: 0 10px; color: var(--faint); }
.cmp-search-results {
  position: absolute;
  top: calc(100% + 6px);
  left: 0; right: 0;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg);
  z-index: var(--z-dropdown);
  max-height: 320px;
  overflow-y: auto;
}
.cmp-search-result {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px;
  cursor: pointer;
  transition: background .13s;
  border-bottom: 1px solid var(--border);
}
.cmp-search-result:last-child { border-bottom: none; }
.cmp-search-result:hover { background: var(--p-light); }
.cmp-search-result__thumb {
  width: 52px; height: 36px;
  border-radius: var(--r-sm);
  overflow: hidden; flex-shrink: 0;
  background: var(--bg);
}
.cmp-search-result__thumb img { width: 100%; height: 100%; object-fit: cover; }
.cmp-search-result__thumb-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: var(--border);
}
.cmp-search-result__body { flex: 1; min-width: 0; }
.cmp-search-result__name { font-size: 13px; font-weight: 700; color: var(--text); }
.cmp-search-result__meta { font-size: 11px; color: var(--faint); margin-top: 1px; }
.cmp-search-result__price {
  font-family: var(--mono); font-size: 13px; font-weight: 700; color: var(--p);
  flex-shrink: 0;
}
.cmp-search-no-results { padding: 16px; text-align: center; font-size: 13px; color: var(--faint); }
.cmp-search-loading { padding: 16px; text-align: center; font-size: 13px; color: var(--faint); }

/* Filled car header inside slot */
.cmp-slot__filled { display: flex; flex-direction: column; }
.cmp-car-header {
  position: relative;
}
.cmp-car-header__img {
  width: 100%; height: 160px;
  overflow: hidden; background: var(--bg);
  border-radius: calc(var(--r-xl) - 1px) calc(var(--r-xl) - 1px) 0 0;
}
.cmp-car-header__img img { width: 100%; height: 100%; object-fit: cover; }
.cmp-car-header__img-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 40px; color: var(--border);
}
.cmp-car-header__remove {
  position: absolute; top: 8px; right: 8px;
  width: 28px; height: 28px;
  border-radius: 50%; background: rgba(0,0,0,.5);
  border: none; color: #fff; font-size: 11px;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.cmp-car-header__remove:hover { background: var(--red); }
.cmp-car-header__body { padding: 14px; }
.cmp-car-header__name {
  font-family: var(--font-d); font-size: 15px; font-weight: 700;
  color: var(--text); letter-spacing: -.01em; margin-bottom: 2px;
  line-height: 1.25;
}
.cmp-car-header__price {
  font-family: var(--font-d); font-size: 20px; font-weight: 800;
  color: var(--p); letter-spacing: -.02em; margin-bottom: 6px;
}
.cmp-car-header__meta {
  font-size: 11px; color: var(--faint); line-height: 1.6;
}
.cmp-car-header__badges { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.cmp-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 600; padding: 2px 8px;
  border-radius: var(--r-full); border: 1px solid transparent;
}
.cmp-badge--verified { background: var(--gr-bg); color: var(--green); border-color: var(--gr-b); }
.cmp-badge--desk     { background: var(--p-light); color: var(--p); border-color: var(--p-b); font-family: var(--font-d); }
.cmp-badge--cond     { background: var(--bg); color: var(--muted); border-color: var(--border); }
.cmp-car-header__actions {
  display: flex; gap: 6px; margin-top: 10px;
}
.cmp-car-action-btn {
  flex: 1; padding: 7px 6px;
  border: 1.5px solid var(--border); border-radius: var(--r-md);
  font-size: 11px; font-weight: 600; color: var(--muted);
  background: #fff; cursor: pointer; font-family: var(--sans);
  transition: all .15s; text-align: center; text-decoration: none;
  display: flex; align-items: center; justify-content: center; gap: 5px;
}
.cmp-car-action-btn:hover { border-color: var(--p); color: var(--p); text-decoration: none; }
.cmp-car-action-btn--primary {
  background: var(--p); color: #fff; border-color: var(--p);
}
.cmp-car-action-btn--primary:hover { background: var(--p-dark); color: #fff; }

/* ── Comparison table ───────────────────────────────────────── */
.cmp-table-wrap { margin-bottom: 32px; }
.cmp-verdict {
  display: flex; align-items: center; gap: 16px;
  background: linear-gradient(140deg, #08143c 0%, var(--p) 100%);
  border-radius: var(--r-xl);
  padding: 18px 24px;
  margin-bottom: 16px;
  color: #fff;
  box-shadow: 0 8px 24px rgba(15,76,158,.2);
}
.cmp-verdict__icon {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
  color: #fbbf24;
}
.cmp-verdict__label { font-size: 11px; color: rgba(255,255,255,.6); margin-bottom: 2px; }
.cmp-verdict__name { font-family: var(--font-d); font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
.cmp-verdict__score {
  margin-left: auto; font-family: var(--font-d);
  font-size: 28px; font-weight: 800; color: #4ade80;
  flex-shrink: 0;
}

/* Sticky header */
.cmp-table-container {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: var(--shadow-md);
}
.cmp-sticky-header {
  display: flex;
  align-items: center;
  gap: 0;
  background: #f8faff;
  border-bottom: 2px solid var(--border);
  padding: 0;
  position: sticky;
  top: 60px; /* nav height */
  z-index: 10;
}
.cmp-sticky-header__label {
  width: 200px; flex-shrink: 0;
  padding: 14px 18px;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--faint);
  border-right: 1px solid var(--border);
}
.cmp-sticky-slots { display: flex; flex: 1; }
.cmp-sticky-slot {
  flex: 1;
  padding: 12px 16px;
  border-right: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.cmp-sticky-slot:last-child { border-right: none; }
.cmp-sticky-slot__thumb {
  width: 40px; height: 28px; border-radius: var(--r-sm);
  overflow: hidden; flex-shrink: 0; background: var(--bg);
}
.cmp-sticky-slot__thumb img { width: 100%; height: 100%; object-fit: cover; }
.cmp-sticky-slot__name {
  font-size: 12px; font-weight: 700; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cmp-sticky-slot__price {
  font-family: var(--mono); font-size: 12px; color: var(--p); font-weight: 700;
  white-space: nowrap;
}

/* Main table */
.cmp-table {
  width: 100%; border-collapse: collapse;
}
.cmp-table .cmp-section-row td {
  padding: 10px 18px;
  background: #f0f5ff;
  font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  color: var(--p);
  border-top: 1px solid var(--border);
  border-bottom: 1px solid #dce8ff;
}
.cmp-table .cmp-data-row td {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  font-size: 13px; vertical-align: middle;
}
.cmp-table .cmp-data-row:last-child td { border-bottom: none; }
.cmp-table .cmp-data-row:nth-child(even) td { background: #fafcff; }

/* First column = label */
.cmp-row-label {
  width: 200px; min-width: 160px;
  font-size: 12px; font-weight: 600; color: var(--faint);
  padding-left: 18px !important;
  border-right: 1px solid var(--border);
}
/* Value cells */
.cmp-cell {
  text-align: center;
  font-family: var(--mono);
  border-right: 1px solid var(--border);
  position: relative;
}
.cmp-cell:last-child { border-right: none; }

/* Winner highlight */
.cmp-cell--winner {
  background: #f0fdf4 !important;
}
.cmp-cell--winner .cmp-cell-val { color: var(--green); font-weight: 700; }
.cmp-winner-crown {
  position: absolute; top: 4px; right: 6px;
  font-size: 10px; color: #fbbf24;
}

/* Loser/neutral */
.cmp-cell--loser .cmp-cell-val { color: var(--faint); }

/* Cell content */
.cmp-cell-val { display: block; font-size: 13px; color: var(--text); }
.cmp-cell-sub { display: block; font-size: 10px; color: var(--faint); margin-top: 1px; }

/* Bar cells (finance, running cost) */
.cmp-bar-wrap {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
}
.cmp-bar-track {
  width: 80%; height: 6px; background: var(--bg);
  border-radius: var(--r-full); overflow: hidden;
}
.cmp-bar-fill {
  height: 100%; border-radius: var(--r-full);
  background: var(--p);
  transition: width .4s ease;
}
.cmp-bar-fill--best   { background: var(--green); }
.cmp-bar-fill--worst  { background: var(--red); }
.cmp-bar-fill--mid    { background: var(--amber); }

/* ── Start prompt ───────────────────────────────────────────── */
.cmp-start-prompt {
  text-align: center;
  padding: 64px 24px;
  border: 1.5px dashed var(--border);
  border-radius: var(--r-xl);
  background: #fafbff;
  margin-bottom: 32px;
}
.cmp-start-prompt__icon { font-size: 40px; color: var(--border); margin-bottom: 16px; }
.cmp-start-prompt__title {
  font-family: var(--font-d); font-size: 20px; font-weight: 700;
  color: var(--text); margin-bottom: 8px;
}
.cmp-start-prompt__sub { font-size: 14px; color: var(--muted); line-height: 1.65; margin-bottom: 24px; }
.cmp-browse-link {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 12px 24px; background: var(--p); color: #fff;
  border-radius: var(--r-lg); font-size: 14px; font-weight: 700;
  text-decoration: none; transition: background .18s;
  box-shadow: 0 4px 14px rgba(15,76,158,.2);
}
.cmp-browse-link:hover { background: var(--p-dark); text-decoration: none; color: #fff; }

/* ── Toast ──────────────────────────────────────────────────── */
.cmp-toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
  background: var(--text); color: #fff; padding: 10px 20px;
  border-radius: var(--r-full); font-size: 13px; font-weight: 600;
  display: flex; align-items: center; gap: 8px;
  box-shadow: var(--shadow-lg); z-index: var(--z-toast);
  animation: cmpToastIn .25s ease;
}
@keyframes cmpToastIn {
  from { opacity: 0; transform: translateX(-50%) translateY(12px); }
  to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* ── Disclaimer ─────────────────────────────────────────────── */
.cmp-disclaimer {
  display: flex; gap: 10px; align-items: flex-start;
  background: #f8faff; border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 14px 18px;
  font-size: 12px; color: var(--muted); line-height: 1.65;
}
.cmp-disclaimer i { color: var(--p); margin-top: 2px; flex-shrink: 0; }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 900px) {
  .cmp-slots { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
  .cmp-sticky-header__label { width: 120px; min-width: 80px; font-size: 10px; }
  .cmp-row-label { width: 120px; min-width: 80px; font-size: 11px; }
}
@media (max-width: 680px) {
  .cmp-settings-bar { flex-direction: column; align-items: flex-start; }
  .cmp-settings-bar__fields { flex-wrap: wrap; }
  .cmp-settings-bar__actions { width: 100%; }
  .cmp-share-btn, .cmp-print-btn { flex: 1; justify-content: center; }
  .cmp-slots { grid-template-columns: 1fr; }
  .cmp-table-container { overflow-x: auto; }
  .cmp-table { min-width: 500px; }
  .cmp-sticky-header { position: static; }
}

/* ── Print ──────────────────────────────────────────────────── */
@media print {
  .pub-nav, .pub-mobile-nav, .sd-footer,
  .cmp-settings-bar__actions, .cmp-search-wrap,
  .cmp-car-header__actions, .cmp-browse-link,
  .cmp-start-prompt, .cmp-disclaimer { display: none !important; }
  .cmp-page { padding: 0; }
  .cmp-table-container { box-shadow: none; border: 1px solid #ccc; }
  .cmp-sticky-header { position: static; }
}
</style>


<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* ── Constants ──────────────────────────────────────────────── */
  var MAX_SLOTS = 3;
  var PRIME     = 11.25;
  var FUEL_PRICES = { petrol_95: 22.80, petrol_93: 22.54, diesel: 21.36, electric: 3.50 };

  /* ── State ──────────────────────────────────────────────────── */
  var cars = [null, null, null];   // car objects per slot index
  var searchTimers = [null, null, null];
  var cfg = { depositPct: 20, rate: 13.25, term: 60, km: 1500 };

  /* ── Utilities ──────────────────────────────────────────────── */
  function fmt(n) {
    if (n == null || isNaN(n)) return '—';
    return 'R\u00a0' + Math.round(n).toLocaleString('en-ZA');
  }
  function fmtRaw(n, dp) {
    if (n == null || isNaN(n)) return '—';
    dp = dp == null ? 0 : dp;
    return n.toLocaleString('en-ZA', { minimumFractionDigits: dp, maximumFractionDigits: dp });
  }
  function monthlyPayment(P, annualRate, n) {
    if (!P || P <= 0) return 0;
    var r = annualRate / 100 / 12;
    if (r === 0) return P / n;
    return P * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
  }
  function thumb(car) {
    if (!car) return null;
    try { var imgs = JSON.parse(car.image_urls || '[]'); return imgs[0] || null; }
    catch(e) { return null; }
  }
  function carName(car) {
    return car.year + ' ' + car.make + ' ' + car.model;
  }
  function carUrl(car) {
    if (!car.desk_slug || !car.slug) return '/c/';
    return '/c/' + car.desk_slug + '/' + car.slug + '/';
  }

  /* ── Finance calc per car ───────────────────────────────────── */
  function calcFinance(car) {
    var price   = parseFloat(car.price) || 0;
    var dep     = price * cfg.depositPct / 100;
    var loan    = price - dep;
    var pm      = monthlyPayment(loan, cfg.rate, cfg.term);
    var total   = pm * cfg.term;
    var interest = total - loan;
    var initFee  = 6037.50 * 1.15;   // NCA max + VAT
    var svcFee   = 69 * 1.15 * cfg.term;
    return { dep, loan, pm, total, interest, initFee, svcFee, toc: total + initFee + svcFee };
  }

  /* ── Running cost per car ───────────────────────────────────── */
  function guessConsumption(car) {
    var ft = (car.fuel_type || '').toLowerCase();
    if (ft === 'electric') return 18;   // kWh/100km
    if (ft === 'diesel') return 7.0;
    var bt = (car.body_type || '').toLowerCase();
    if (bt.includes('suv') || bt.includes('4x4') || bt.includes('bakkie')) return 10.5;
    if (bt.includes('sedan') || bt.includes('hatch')) return 7.5;
    return 8.5;
  }
  function calcRunning(car) {
    var fin = calcFinance(car);
    var ft  = (car.fuel_type || '').toLowerCase();
    var fuelKey = ft === 'electric' ? 'electric' : ft === 'diesel' ? 'diesel' : 'petrol_95';
    var fuelPrice = FUEL_PRICES[fuelKey] || 22.80;
    var cons      = guessConsumption(car);
    var fuelMo    = (cons / 100) * cfg.km * fuelPrice;
    var ins       = Math.max(800, Math.round(parseFloat(car.price) * 0.0025)); // ~0.25% /mo
    var svcMo     = 4500 / (15000 / cfg.km);
    var tyreMo    = 8000 / 40000 * cfg.km;
    var total     = fin.pm + fuelMo + ins + svcMo + tyreMo;
    return { finance: fin.pm, fuel: fuelMo, insurance: ins, service: svcMo, tyres: tyreMo, total };
  }

  /* ── Score a car (lower is better for cost-based, higher for features) */
  function scoreCard(car) {
    var fin = calcFinance(car);
    var run = calcRunning(car);
    var score = 0;
    // Cheaper monthly = better (inverted)
    var filledCars = cars.filter(Boolean);
    var prices = filledCars.map(function(c) { return calcFinance(c).pm; });
    var minPm = Math.min.apply(null, prices);
    var maxPm = Math.max.apply(null, prices);
    var range = maxPm - minPm || 1;
    score += (1 - (fin.pm - minPm) / range) * 40;   // 40 pts: monthly payment
    // Lower mileage = better (newer with less wear)
    var miles = filledCars.map(function(c) { return parseFloat(c.mileage) || 0; });
    var minMi = Math.min.apply(null, miles);
    var maxMi = Math.max.apply(null, miles);
    var rangeM = maxMi - minMi || 1;
    score += (1 - ((parseFloat(car.mileage) || 0) - minMi) / rangeM) * 30;  // 30 pts: mileage
    // Newer year = better
    var years = filledCars.map(function(c) { return parseInt(c.year) || 0; });
    var minY = Math.min.apply(null, years);
    var maxY = Math.max.apply(null, years);
    var rangeY = maxY - minY || 1;
    score += ((parseInt(car.year) || 0) - minY) / rangeY * 20;  // 20 pts: year
    // Verified dealer bonus
    if (car.dealer_verified === 'verified') score += 10;
    return Math.round(score);
  }

  /* ── Render slot header ─────────────────────────────────────── */
  function renderSlotHeader(slot, car) {
    var imgSrc = thumb(car);
    var verBadge = car.dealer_verified === 'verified'
      ? '<span class="cmp-badge cmp-badge--verified"><i class="fa-solid fa-circle-check"></i> Verified</span>'
      : '';
    var condLabel = { new: 'New', demo: 'Demo', used: 'Used' }[car.condition_type] || 'Used';

    document.getElementById('carHeader' + slot).innerHTML =
      '<div class="cmp-car-header__img">' +
        (imgSrc
          ? '<img src="' + escHtml(imgSrc) + '" alt="' + escHtml(carName(car)) + '" loading="lazy">'
          : '<div class="cmp-car-header__img-placeholder"><i class="fa-solid fa-car-side"></i></div>') +
      '</div>' +
      '<button class="cmp-car-header__remove" onclick="removeCar(' + slot + ')" title="Remove" type="button">' +
        '<i class="fa-solid fa-xmark"></i>' +
      '</button>' +
      '<div class="cmp-car-header__body">' +
        '<div class="cmp-car-header__name">' + escHtml(carName(car)) + '</div>' +
        '<div class="cmp-car-header__price">' + fmt(parseFloat(car.price)) + '</div>' +
        '<div class="cmp-car-header__meta">' +
          escHtml(car.dealer_name || '') +
          (car.dealer_city ? ' &middot; ' + escHtml(car.dealer_city) : '') +
        '</div>' +
        '<div class="cmp-car-header__badges">' +
          verBadge +
          '<span class="cmp-badge cmp-badge--cond">' + condLabel + '</span>' +
          (car.desk_name ? '<span class="cmp-badge cmp-badge--desk"><i class="fa-solid fa-id-card"></i> ' + escHtml(car.desk_name) + '</span>' : '') +
        '</div>' +
        '<div class="cmp-car-header__actions">' +
          '<a href="' + escHtml(carUrl(car)) + '" class="cmp-car-action-btn cmp-car-action-btn--primary" target="_blank">' +
            '<i class="fa-solid fa-arrow-up-right-from-square"></i> View listing' +
          '</a>' +
          '<button class="cmp-car-action-btn" onclick="replaceCarSlot(' + slot + ')" type="button">' +
            '<i class="fa-solid fa-rotate"></i> Replace' +
          '</button>' +
        '</div>' +
      '</div>';
  }

  /* ── Show/hide slot states ──────────────────────────────────── */
  function setSlotFilled(slot, car) {
    cars[slot] = car;
    document.getElementById('slotEmpty' + slot).style.display = 'none';
    document.getElementById('slotFilled' + slot).style.display = 'flex';
    document.getElementById('slot' + slot).classList.add('cmp-slot--filled');
    renderSlotHeader(slot, car);
    rebuildTable();
    updateURL();
  }

  window.removeCar = function(slot) {
    cars[slot] = null;
    document.getElementById('slotEmpty' + slot).style.display = 'flex';
    document.getElementById('slotFilled' + slot).style.display = 'none';
    document.getElementById('slot' + slot).classList.remove('cmp-slot--filled');
    // Clear search input
    var inp = document.querySelector('.cmp-search-input[data-slot="' + slot + '"]');
    if (inp) inp.value = '';
    rebuildTable();
    updateURL();
  };

  window.replaceCarSlot = function(slot) {
    cars[slot] = null;
    document.getElementById('slotFilled' + slot).style.display = 'none';
    document.getElementById('slotEmpty' + slot).style.display = 'flex';
    document.getElementById('slot' + slot).classList.remove('cmp-slot--filled');
    var inp = document.querySelector('.cmp-search-input[data-slot="' + slot + '"]');
    if (inp) { inp.value = ''; inp.focus(); }
    rebuildTable();
    updateURL();
  };

  /* ── Spec table ─────────────────────────────────────────────── */
  var SPEC_SECTIONS = [
    {
      label: 'Pricing & Finance',
      rows: [
        { key: 'price',       label: 'Asking price',          get: function(c) { return { val: fmt(parseFloat(c.price)), raw: parseFloat(c.price) }; }, lowerBetter: true },
        { key: 'deposit',     label: 'Deposit (' + cfg.depositPct + '%)', get: function(c) { var f=calcFinance(c); return { val: fmt(f.dep), raw: f.dep }; }, lowerBetter: true, financeRow: true },
        { key: 'monthly',     label: 'Est. monthly payment',  get: function(c) { var f=calcFinance(c); return { val: fmt(f.pm), raw: f.pm, sub: 'over ' + cfg.term + ' months' }; }, lowerBetter: true, financeRow: true, isBar: true },
        { key: 'toi',         label: 'Total interest paid',   get: function(c) { var f=calcFinance(c); return { val: fmt(f.interest), raw: f.interest }; }, lowerBetter: true, financeRow: true },
        { key: 'toc',         label: 'Total cost of credit',  get: function(c) { var f=calcFinance(c); return { val: fmt(f.toc), raw: f.toc }; }, lowerBetter: true, financeRow: true },
      ]
    },
    {
      label: 'Vehicle Details',
      rows: [
        { key: 'year',         label: 'Year',          get: function(c) { return { val: c.year, raw: parseInt(c.year) }; }, lowerBetter: false },
        { key: 'mileage',      label: 'Mileage',       get: function(c) { var m=parseInt(c.mileage)||0; return { val: m ? fmtRaw(m) + ' km' : 'New / 0 km', raw: m }; }, lowerBetter: true },
        { key: 'condition',    label: 'Condition',     get: function(c) { return { val: ({new:'New',demo:'Demo',used:'Pre-owned'})[c.condition_type]||'—', raw: null }; }, noHighlight: true },
        { key: 'body_type',    label: 'Body type',     get: function(c) { return { val: c.body_type||'—', raw: null }; }, noHighlight: true },
        { key: 'colour',       label: 'Colour',        get: function(c) { return { val: c.colour||'—', raw: null }; }, noHighlight: true },
      ]
    },
    {
      label: 'Powertrain',
      rows: [
        { key: 'transmission', label: 'Transmission',  get: function(c) { return { val: c.transmission||'—', raw: null }; }, noHighlight: true },
        { key: 'fuel_type',    label: 'Fuel type',     get: function(c) { return { val: c.fuel_type||'—', raw: null }; }, noHighlight: true },
        { key: 'drivetrain',   label: 'Drivetrain',    get: function(c) { return { val: c.drivetrain||'—', raw: null }; }, noHighlight: true },
        { key: 'engine_size',  label: 'Engine',        get: function(c) { return { val: c.engine_size ? c.engine_size + 'L' : '—', raw: parseFloat(c.engine_size)||null }; }, noHighlight: true },
        { key: 'doors',        label: 'Doors',         get: function(c) { return { val: c.doors||'—', raw: null }; }, noHighlight: true },
        { key: 'seats',        label: 'Seats',         get: function(c) { return { val: c.seats||'—', raw: null }; }, noHighlight: true },
      ]
    },
    {
      label: 'Running Costs (Estimated Monthly)',
      rows: [
        { key: 'run_total',    label: 'Total monthly cost',   get: function(c) { return { val: fmt(calcRunning(c).total), raw: calcRunning(c).total }; }, lowerBetter: true, isBar: true },
        { key: 'run_fuel',     label: 'Fuel',                 get: function(c) { var r=calcRunning(c); return { val: fmt(r.fuel), raw: r.fuel, sub: guessConsumption(c) + (c.fuel_type==='Electric'?' kWh':' L') + '/100km est.' }; }, lowerBetter: true },
        { key: 'run_ins',      label: 'Insurance (est.)',     get: function(c) { return { val: fmt(calcRunning(c).insurance), raw: calcRunning(c).insurance }; }, lowerBetter: true },
        { key: 'run_service',  label: 'Service & maintenance',get: function(c) { return { val: fmt(calcRunning(c).service), raw: calcRunning(c).service }; }, lowerBetter: true },
        { key: 'run_tyres',    label: 'Tyres (amortised)',    get: function(c) { return { val: fmt(calcRunning(c).tyres), raw: calcRunning(c).tyres }; }, lowerBetter: true },
      ]
    },
    {
      label: 'Dealer',
      rows: [
        { key: 'dealer',       label: 'Dealer',        get: function(c) { return { val: c.dealer_name||'—', raw: null }; }, noHighlight: true },
        { key: 'location',     label: 'Location',      get: function(c) { return { val: [c.dealer_city, c.dealer_province].filter(Boolean).join(', ')||'—', raw: null }; }, noHighlight: true },
        { key: 'verified',     label: 'Verified dealer', get: function(c) { var v=c.dealer_verified==='verified'; return { val: v ? '✓ Verified' : 'Not verified', raw: v ? 1 : 0 }; }, lowerBetter: false },
        { key: 'desk',         label: 'SalesDesk',     get: function(c) { return { val: c.desk_name||'—', raw: null }; }, noHighlight: true },
      ]
    },
  ];

  function rebuildTable() {
    var filled = cars.filter(Boolean);
    var hasCars = filled.length > 0;
    var hasTwo  = filled.length >= 2;

    document.getElementById('cmpStartPrompt').style.display = hasCars ? 'none' : 'block';
    document.getElementById('cmpTableWrap').style.display   = hasTwo  ? 'block' : 'none';

    if (!hasTwo) {
      updateStickyHeader();
      return;
    }

    updateStickyHeader();
    buildSpecTable();
    buildVerdict();
  }

  function updateStickyHeader() {
    var stickySlots = document.getElementById('cmpStickySlots');
    stickySlots.innerHTML = '';
    for (var i = 0; i < MAX_SLOTS; i++) {
      var car = cars[i];
      var div = document.createElement('div');
      div.className = 'cmp-sticky-slot';
      if (car) {
        var imgSrc = thumb(car);
        div.innerHTML =
          '<div class="cmp-sticky-slot__thumb">' +
            (imgSrc ? '<img src="' + escHtml(imgSrc) + '" loading="lazy">' : '') +
          '</div>' +
          '<div>' +
            '<div class="cmp-sticky-slot__name">' + escHtml(car.make + ' ' + car.model) + '</div>' +
            '<div class="cmp-sticky-slot__price">' + fmt(parseFloat(car.price)) + '</div>' +
          '</div>';
      } else {
        div.innerHTML = '<span style="font-size:12px;color:var(--faint);">—</span>';
      }
      stickySlots.appendChild(div);
    }
  }

  function buildSpecTable() {
    var tbody = document.getElementById('cmpTableBody');
    tbody.innerHTML = '';

    SPEC_SECTIONS.forEach(function (section) {
      // Section header row
      var sectionTr = document.createElement('tr');
      sectionTr.className = 'cmp-section-row';
      var sectionTd = document.createElement('td');
      sectionTd.colSpan = 1 + MAX_SLOTS;
      sectionTd.textContent = section.label;
      sectionTr.appendChild(sectionTd);
      tbody.appendChild(sectionTr);

      section.rows.forEach(function (rowDef) {
        var tr = document.createElement('tr');
        tr.className = 'cmp-data-row';

        // Label cell
        var labelTd = document.createElement('td');
        labelTd.className = 'cmp-row-label';
        labelTd.textContent = rowDef.label;
        tr.appendChild(labelTd);

        // Get values for all slots
        var values = cars.map(function(car) {
          return car ? rowDef.get(car) : null;
        });

        // Find winner/loser
        var raws = values.map(function(v) { return v && v.raw != null ? v.raw : null; });
        var validRaws = raws.filter(function(r) { return r != null; });
        var winnerRaw = null, loserRaw = null;
        if (!rowDef.noHighlight && validRaws.length >= 2) {
          if (rowDef.lowerBetter) {
            winnerRaw = Math.min.apply(null, validRaws);
            loserRaw  = Math.max.apply(null, validRaws);
          } else {
            winnerRaw = Math.max.apply(null, validRaws);
            loserRaw  = Math.min.apply(null, validRaws);
          }
        }

        // Bar max
        var barMax = rowDef.isBar ? Math.max.apply(null, validRaws.filter(function(r) { return r != null; })) || 1 : null;

        // Value cells
        for (var i = 0; i < MAX_SLOTS; i++) {
          var td = document.createElement('td');
          td.className = 'cmp-cell';
          var v = values[i];
          if (!v) {
            td.innerHTML = '<span class="cmp-cell-val" style="color:var(--border);">—</span>';
          } else {
            var isWinner = (winnerRaw != null) && (v.raw === winnerRaw) && (validRaws.filter(function(r) { return r === winnerRaw; }).length < validRaws.length);
            var isLoser  = (loserRaw != null) && (v.raw === loserRaw) && validRaws.length >= 2 && loserRaw !== winnerRaw;
            if (isWinner) td.classList.add('cmp-cell--winner');
            if (isLoser && !isWinner) td.classList.add('cmp-cell--loser');

            var inner = '';
            if (rowDef.isBar && barMax && v.raw != null) {
              var pct = Math.round(v.raw / barMax * 100);
              var fillClass = isWinner ? 'cmp-bar-fill--best' : isLoser ? 'cmp-bar-fill--worst' : 'cmp-bar-fill--mid';
              inner += '<div class="cmp-bar-wrap">' +
                '<span class="cmp-cell-val">' + v.val + '</span>' +
                '<div class="cmp-bar-track"><div class="cmp-bar-fill ' + fillClass + '" style="width:' + pct + '%"></div></div>' +
                '</div>';
            } else {
              inner += '<span class="cmp-cell-val">' + escHtml(v.val) + '</span>';
              if (v.sub) inner += '<span class="cmp-cell-sub">' + escHtml(v.sub) + '</span>';
            }
            if (isWinner) inner += '<span class="cmp-winner-crown" title="Best value"><i class="fa-solid fa-crown"></i></span>';
            td.innerHTML = inner;
          }
          tr.appendChild(td);
        }

        tbody.appendChild(tr);
      });
    });
  }

  function buildVerdict() {
    var filled = cars.map(function(c, i) { return c ? { car: c, slot: i } : null; }).filter(Boolean);
    if (filled.length < 2) { document.getElementById('cmpVerdict').style.display = 'none'; return; }

    var scored = filled.map(function(f) { return { car: f.car, score: scoreCard(f.car) }; });
    scored.sort(function(a,b) { return b.score - a.score; });
    var winner = scored[0];
    document.getElementById('cmpVerdict').style.display = 'flex';
    document.getElementById('verdictName').textContent = carName(winner.car);
    document.getElementById('verdictScore').textContent = winner.score + '/100';
  }

  /* ── URL update (shareable) ─────────────────────────────────── */
  function updateURL() {
    var ids = cars.filter(Boolean).map(function(c) { return c.id; });
    var url = '/tools/compare/' + (ids.length ? '?ids=' + ids.join(',') : '');
    try { history.replaceState(null, '', url); } catch(e) {}
  }

  /* ── Live search ────────────────────────────────────────────── */
  function doSearch(slot, q) {
    if (q.length < 2) {
      document.getElementById('searchResults' + slot).style.display = 'none';
      return;
    }
    clearTimeout(searchTimers[slot]);
    searchTimers[slot] = setTimeout(function () {
      var spinner = document.querySelector('.cmp-slot[data-slot="' + slot + '"] .cmp-search-spinner');
      if (spinner) spinner.style.display = 'flex';

      var usedIds = cars.filter(Boolean).map(function(c) { return c.id; });
      var qs = '?q=' + encodeURIComponent(q) + '&limit=8&format=json' +
        (usedIds.length ? '&exclude_ids=' + usedIds.join(',') : '');

      fetch('/api/cars/search.php' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (spinner) spinner.style.display = 'none';
          renderSearchResults(slot, data.cars || []);
        })
        .catch(function() {
          if (spinner) spinner.style.display = 'none';
          // Graceful: show a "no results" message if the API isn't wired yet
          renderSearchResults(slot, []);
        });
    }, 280);
  }

  function renderSearchResults(slot, results) {
    var box = document.getElementById('searchResults' + slot);
    if (!results.length) {
      box.innerHTML = '<div class="cmp-search-no-results"><i class="fa-solid fa-magnifying-glass"></i> No matching cars found</div>';
      box.style.display = 'block';
      return;
    }
    var html = results.map(function(car) {
      var imgs = [];
      try { imgs = JSON.parse(car.image_urls || '[]'); } catch(e) {}
      var imgSrc = imgs[0] || null;
      var thumbHtml = imgSrc
        ? '<img src="' + escHtml(imgSrc) + '" loading="lazy">'
        : '<div class="cmp-search-result__thumb-placeholder"><i class="fa-solid fa-car-side"></i></div>';
      return '<div class="cmp-search-result" data-slot="' + slot + '" data-id="' + car.id + '">' +
        '<div class="cmp-search-result__thumb">' + thumbHtml + '</div>' +
        '<div class="cmp-search-result__body">' +
          '<div class="cmp-search-result__name">' + escHtml(car.year + ' ' + car.make + ' ' + car.model) + '</div>' +
          '<div class="cmp-search-result__meta">' + escHtml(car.condition_type || '') +
            (car.mileage ? ' &middot; ' + parseInt(car.mileage).toLocaleString('en-ZA') + ' km' : '') +
            (car.dealer_city ? ' &middot; ' + escHtml(car.dealer_city) : '') +
          '</div>' +
        '</div>' +
        '<div class="cmp-search-result__price">' + fmt(parseFloat(car.price)) + '</div>' +
        '</div>';
    }).join('');
    box.innerHTML = html;
    box.style.display = 'block';

    box.querySelectorAll('.cmp-search-result').forEach(function(row) {
      row.addEventListener('click', function() {
        var s = parseInt(row.dataset.slot);
        var id = parseInt(row.dataset.id);
        var car = results.find(function(c) { return c.id === id; });
        if (car) {
          setSlotFilled(s, car);
          box.style.display = 'none';
          var inp = document.querySelector('.cmp-search-input[data-slot="' + s + '"]');
          if (inp) inp.value = '';
        }
      });
    });
  }

  /* ── Bind search inputs ─────────────────────────────────────── */
  document.querySelectorAll('.cmp-search-input').forEach(function(inp) {
    var slot = parseInt(inp.dataset.slot);
    inp.addEventListener('input', function() { doSearch(slot, this.value.trim()); });
    inp.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.getElementById('searchResults' + slot).style.display = 'none'; });
    // Close results on outside click
    document.addEventListener('click', function(e) {
      if (!inp.closest('.cmp-search-wrap').contains(e.target)) {
        document.getElementById('searchResults' + slot).style.display = 'none';
      }
    });
  });

  /* ── Config controls ────────────────────────────────────────── */
  function readCfg() {
    cfg.depositPct = parseFloat(document.getElementById('cfg_deposit_pct').value) || 20;
    cfg.rate       = parseFloat(document.getElementById('cfg_rate').value)        || 13.25;
    cfg.term       = parseInt(document.getElementById('cfg_term').value, 10)      || 60;
    cfg.km         = parseFloat(document.getElementById('cfg_km').value)           || 1500;
  }
  function onCfgChange() { readCfg(); rebuildTable(); }

  ['cfg_deposit_pct','cfg_rate','cfg_km'].forEach(function(id) {
    document.getElementById(id).addEventListener('input', onCfgChange);
  });
  document.querySelectorAll('.cmp-term-chips .cmp-term-chip').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.cmp-term-chips .cmp-term-chip').forEach(function(b) {
        b.classList.remove('cmp-term-chip--active');
      });
      btn.classList.add('cmp-term-chip--active');
      document.getElementById('cfg_term').value = btn.dataset.months;
      onCfgChange();
    });
  });

  /* ── Share ──────────────────────────────────────────────────── */
  document.getElementById('cmpShareBtn').addEventListener('click', function() {
    var url = window.location.href;
    navigator.clipboard.writeText(url).then(function() {
      var toast = document.getElementById('cmpToast');
      toast.style.display = 'flex';
      setTimeout(function() { toast.style.display = 'none'; }, 2500);
    }).catch(function() {
      prompt('Copy this link:', url);
    });
  });

  /* ── HTML escape ────────────────────────────────────────────── */
  function escHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  /* ── Pre-load cars from PHP ─────────────────────────────────── */
  (function preload() {
    var data = <?= $preloadJson ?>;
    if (!Array.isArray(data) || !data.length) return;
    data.forEach(function(car, i) {
      if (i < MAX_SLOTS && car) setSlotFilled(i, car);
    });
  })();

  /* ── Initial render ─────────────────────────────────────────── */
  rebuildTable();

})();
</script>

<?php
$pageContent = ob_get_clean();
$layoutVariant = 'wide';
require_once '../../views/layout-public.php';
