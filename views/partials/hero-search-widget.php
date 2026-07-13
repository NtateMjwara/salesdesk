<?php
/**
 * SalesDesk — Hero Search Widget v3
 * Included inline from index.php.
 *
 * Drop-in replacement for the <div class="sd-search-card"> block and
 * <script src="/assets/js/home.js"> call.
 *
 * HOW TO USE IN index.php:
 *   1. Delete the old <div class="sd-search-card">…</div> block in the hero section.
 *   2. Delete the existing "home.js" search listener block at the bottom.
 *   3. Add at the bottom of the hero section (before closing </section>):
 *        <?php include __DIR__ . '/views/partials/hero-search-widget.php'; ?>
 *   4. Place this file at: views/partials/hero-search-widget.php
 *
 * CSS + JS are inlined to defeat browser caching of stale asset files.
 * A comment with <!-- HeroSearch v3.0 --> marks this version.
 *
 * $totalCars must be available from index.php scope (it's set before the view renders).
 */
?>
<!-- HeroSearch v3.0 -->

<!-- ═══════════════════════════════════════════════════════════
     HERO SEARCH WIDGET STYLES (inlined — no caching issues)
     ═══════════════════════════════════════════════════════════ -->
<style>
/* ── Widget shell ──────────────────────────────────────────── */
#heroSearchWidget {
  width: 100%;
}

.hws-card {
  background: #fff;
  border-radius: clamp(16px, 2vw, 24px);
  padding: clamp(16px, 2.2vw, 22px);
  box-shadow: 0 28px 60px rgba(8,20,60,.32), 0 4px 16px rgba(0,0,0,.14);
  position: relative;
}

/* ── Top row: text input ───────────────────────────────────── */
.hws-top {
  position: relative;
  margin-bottom: 12px;
}

.hws-q {
  width: 100%;
  height: 54px;
  border: 2px solid #e4e8f0;
  border-radius: 14px;
  padding: 0 52px 0 48px;
  font-size: 15px;
  font-family: var(--sans, 'DM Sans', sans-serif);
  color: #1e293b;
  background: #f8faff;
  outline: none;
  transition: border-color .2s, background .2s, box-shadow .2s;
}
.hws-q:focus {
  border-color: var(--p, #0f4c9e);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(15,76,158,.10);
}
.hws-q::placeholder { color: #94a3b8; font-size: 14px; }

.hws-q-icon {
  position: absolute;
  left: 15px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--p, #0f4c9e);
  font-size: 16px;
  pointer-events: none;
}

.hws-spinner {
  position: absolute;
  right: 15px;
  top: 50%;
  transform: translateY(-50%);
  display: none;
  width: 16px;
  height: 16px;
  border: 2.5px solid #e2e8f0;
  border-top-color: var(--p, #0f4c9e);
  border-radius: 50%;
  animation: hws-spin .7s linear infinite;
}
@keyframes hws-spin { to { transform: translateY(-50%) rotate(360deg); } }

/* ── Autocomplete dropdown ─────────────────────────────────── */
#hAcBox {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(15,76,158,.15), 0 2px 8px rgba(0,0,0,.08);
  z-index: 9999;
  overflow: hidden;
  display: none;
}

.hac-section-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: #94a3b8;
  padding: 10px 14px 4px;
}

.hac-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  cursor: pointer;
  transition: background .12s;
  border-radius: 0;
  font-size: 13px;
  color: #1e293b;
}
.hac-item:hover,
.hac-item.focused {
  background: #eff4ff;
}
.hac-icon { font-size: 14px; flex-shrink: 0; }
.hac-text { flex: 1; }
.hac-text mark {
  background: none;
  color: var(--p, #0f4c9e);
  font-weight: 700;
}
.hac-type {
  font-size: 10px;
  font-weight: 600;
  color: #94a3b8;
  background: #f3f4f8;
  padding: 2px 8px;
  border-radius: 99px;
  flex-shrink: 0;
}
.hac-remove {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 16px;
  cursor: pointer;
  padding: 0 4px;
  line-height: 1;
  flex-shrink: 0;
}
.hac-remove:hover { color: #ef4444; }
.hac-clear-row { padding: 8px 14px 10px; }
.hac-clear-btn {
  font-size: 12px;
  color: var(--p, #0f4c9e);
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-weight: 600;
  padding: 0;
  text-decoration: underline;
}

/* ── Filter row: make + province ───────────────────────────── */
.hws-filter-row {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.hws-filter-btn {
  flex: 1;
  height: 42px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  background: #f8faff;
  font-family: var(--sans, 'DM Sans', sans-serif);
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  transition: all .18s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding: 0 12px;
  position: relative;
}
.hws-filter-btn:hover {
  border-color: var(--p, #0f4c9e);
  color: var(--p, #0f4c9e);
  background: #eff4ff;
}
.hws-filter-btn.active {
  border-color: var(--p, #0f4c9e);
  background: #eff4ff;
  color: var(--p, #0f4c9e);
  font-weight: 600;
}
.hws-filter-btn i { font-size: 11px; opacity: .7; }

/* ── Dropdown panels (Make / Province) ─────────────────────── */
.hws-panel-wrap {
  position: relative;
}

.hws-panel {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  min-width: 220px;
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(15,76,158,.15);
  z-index: 9998;
  display: none;
  overflow: hidden;
}
.hws-panel.open { display: block; }

.hpanel-search {
  width: 100%;
  padding: 10px 14px;
  border: none;
  border-bottom: 1px solid #e2e8f0;
  font-size: 13px;
  font-family: inherit;
  color: #1e293b;
  outline: none;
  background: #f8faff;
}
.hpanel-search::placeholder { color: #94a3b8; }

.hpanel-list {
  max-height: 220px;
  overflow-y: auto;
  padding: 6px;
  scrollbar-width: thin;
}
.hpanel-item {
  padding: 9px 10px;
  border-radius: 8px;
  font-size: 13px;
  color: #1e293b;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: background .12s;
}
.hpanel-item:hover { background: #eff4ff; }
.hpanel-item.active {
  background: var(--p, #0f4c9e);
  color: #fff;
}
.hpanel-item mark {
  background: none;
  color: var(--p, #0f4c9e);
  font-weight: 700;
}
.hpanel-item.active mark { color: #fff; }
.hpanel-prov-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--p, #0f4c9e);
  flex-shrink: 0;
  opacity: .45;
}
.hpanel-item.active .hpanel-prov-dot { background: #fff; opacity: .8; }

/* ── Condition chips ───────────────────────────────────────── */
.hws-cond-row {
  display: flex;
  gap: 6px;
  margin-bottom: 10px;
}

/* ── Advanced toggle ───────────────────────────────────────── */
.hws-adv-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--p, #0f4c9e);
  padding: 4px 0 10px;
  gap: 6px;
}
.hws-adv-toggle i { font-size: 9px; transition: transform .2s; }
.hws-adv-toggle.open i { transform: rotate(180deg); }
.hws-adv-toggle-line {
  flex: 1;
  height: 1px;
  background: #e2e8f0;
}

/* ── Advanced panel ────────────────────────────────────────── */
.hws-adv-panel {
  display: none;
  gap: 12px;
  flex-direction: column;
  padding-bottom: 10px;
}
.hws-adv-panel.open { display: flex; }

.hws-adv-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 6px;
}

.hws-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.hws-chip {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 500;
  font-family: inherit;
  color: #64748b;
  background: #f8faff;
  cursor: pointer;
  transition: all .15s;
  white-space: nowrap;
}
.hws-chip:hover {
  border-color: var(--p, #0f4c9e);
  color: var(--p, #0f4c9e);
}
.hws-chip.active {
  background: var(--p, #0f4c9e);
  border-color: var(--p, #0f4c9e);
  color: #fff;
}

.hws-range-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.hws-range-input {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  font-family: inherit;
  color: #1e293b;
  background: #f8faff;
  outline: none;
  transition: border-color .18s;
  -moz-appearance: textfield;
}
.hws-range-input::-webkit-outer-spin-button,
.hws-range-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.hws-range-input:focus {
  border-color: var(--p, #0f4c9e);
  background: #fff;
}
.hws-range-input::placeholder { color: #94a3b8; font-size: 12px; }

/* ── Active filter tags ────────────────────────────────────── */
#hActiveTags {
  display: none;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 10px;
}

.h-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #eff4ff;
  border: 1px solid #bfdbfe;
  border-radius: 99px;
  padding: 4px 10px 4px 12px;
  font-size: 12px;
  font-weight: 500;
  font-family: inherit;
  color: var(--p, #0f4c9e);
  cursor: pointer;
  transition: background .15s;
}
.h-tag:hover { background: #dbeafe; }
.h-tag-x { font-size: 14px; opacity: .65; line-height: 1; }

/* ── Divider ───────────────────────────────────────────────── */
.hws-divider {
  height: 1px;
  background: #f0f2f8;
  margin: 10px 0;
}

/* ── Submit button ─────────────────────────────────────────── */
.hws-submit {
  width: 100%;
  height: 52px;
  border: none;
  border-radius: 12px;
  background: var(--p, #0f4c9e);
  color: #fff;
  font-family: var(--font-d, 'DM Sans', sans-serif);
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  transition: background .2s, transform .1s, box-shadow .2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  letter-spacing: .01em;
  box-shadow: 0 4px 18px rgba(15,76,158,.3);
  position: relative;
  overflow: hidden;
}
.hws-submit::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0);
  transition: background .15s;
}
.hws-submit:hover {
  background: #0c3273;
  box-shadow: 0 6px 24px rgba(15,76,158,.4);
  transform: translateY(-1px);
}
.hws-submit:active { transform: translateY(0); }
.hws-submit.loading { opacity: .8; pointer-events: none; }
.hws-submit i { font-size: 14px; }
.h-submit-label { transition: opacity .15s; }

/* ── Reset row ─────────────────────────────────────────────── */
.hws-reset-row {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin: 8px 0 10px;
}
.hws-reset-btn {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 500;
  color: #94a3b8;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  padding: 0;
  transition: color .15s;
}
.hws-reset-btn:hover { color: #ef4444; }

/* ── Hints row ─────────────────────────────────────────────── */
.hws-hints {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 14px;
  font-size: 12px;
  color: rgba(255,255,255,.72);
}
.hws-hints span {
  display: flex;
  align-items: center;
  gap: 5px;
}

/* ── Mobile ────────────────────────────────────────────────── */
@media (max-width: 480px) {
  .hws-card { padding: 14px; border-radius: 18px; }
  .hws-q { height: 50px; font-size: 14px; }
  .hws-filter-btn { height: 40px; font-size: 12px; padding: 0 10px; }
  .hws-submit { height: 50px; font-size: 15px; }
  .hws-range-grid { grid-template-columns: 1fr; }
  .hpanel-list { max-height: 180px; }
}

@media (max-width: 360px) {
  .hws-filter-row { flex-wrap: wrap; }
  .hws-filter-btn { flex: none; width: calc(50% - 4px); }
}
</style>

<!-- ═══════════════════════════════════════════════════════════
     HERO SEARCH WIDGET HTML
     ═══════════════════════════════════════════════════════════ -->
<div id="heroSearchWidget">

  <div class="hws-card">

    <!-- Text search with spinner -->
    <div class="hws-top" style="position:relative">
      <span class="hws-q-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
      <input
        id="hq"
        type="text"
        class="hws-q"
        placeholder='Try "Toyota Hilux" or "Diesel SUV under R500k"'
        autocomplete="off"
        aria-label="Search vehicles"
        aria-autocomplete="list"
        aria-controls="hAcBox"
      >
      <div class="hws-spinner" id="hSpinner"></div>
      <!-- Autocomplete -->
      <div id="hAcBox" role="listbox" aria-label="Suggestions"></div>
    </div>

    <!-- Active filter tags (hidden until filters applied) -->
    <div id="hActiveTags" role="group" aria-label="Active filters"></div>

    <!-- Make + Province filter row -->
    <div class="hws-filter-row">

      <div class="hws-panel-wrap" style="flex:1">
        <button class="hws-filter-btn" id="hMakeBtn" type="button" aria-haspopup="listbox">
          <i class="fa-solid fa-car"></i>
          <span>Make / Model</span>
        </button>
        <div class="hws-panel" id="hMakePanel" role="listbox" aria-label="Select make">
          <input class="hpanel-search" id="hMakeSearch" type="text" placeholder="Search makes…" autocomplete="off">
          <div class="hpanel-list" id="hMakeList"></div>
        </div>
      </div>

      <div class="hws-panel-wrap" style="flex:1">
        <button class="hws-filter-btn" id="hProvBtn" type="button" aria-haspopup="listbox">
          <i class="fa-solid fa-location-dot"></i>
          <span>Province</span>
        </button>
        <div class="hws-panel" id="hProvPanel" role="listbox" aria-label="Select province">
          <div class="hpanel-list" id="hProvList"></div>
        </div>
      </div>

    </div>

    <!-- Condition chips -->
    <div class="hws-cond-row" role="group" aria-label="Condition">
      <button class="hws-chip h-cond-chip" data-val="new"  type="button">New</button>
      <button class="hws-chip h-cond-chip" data-val="demo" type="button">Demo</button>
      <button class="hws-chip h-cond-chip" data-val="used" type="button">Used</button>
    </div>

    <!-- Advanced filters toggle -->
    <button class="hws-adv-toggle" id="hAdvToggle" type="button" aria-expanded="false">
      <span class="hws-adv-toggle-line"></span>
      <span><i class="fa-solid fa-sliders"></i> <span>More filters</span></span>
      <i class="fa-solid fa-chevron-down" style="margin-left:2px"></i>
      <span class="hws-adv-toggle-line"></span>
    </button>

    <!-- Advanced panel -->
    <div class="hws-adv-panel" id="hAdvPanel" aria-hidden="true">

      <!-- Body type -->
      <div>
        <div class="hws-adv-label">Body type</div>
        <div class="hws-chip-row">
          <button class="hws-chip h-body-chip" data-val="Bakkie"     type="button">🛻 Bakkie</button>
          <button class="hws-chip h-body-chip" data-val="Hatchback"  type="button">🚗 Hatch</button>
          <button class="hws-chip h-body-chip" data-val="Sedan"      type="button">🚙 Sedan</button>
          <button class="hws-chip h-body-chip" data-val="SUV"        type="button">🚐 SUV / 4x4</button>
          <button class="hws-chip h-body-chip" data-val="Crossover"  type="button">🚗 Crossover</button>
          <button class="hws-chip h-body-chip" data-val="MPV"        type="button">🚌 MPV</button>
        </div>
      </div>

      <!-- Fuel type -->
      <div>
        <div class="hws-adv-label">Fuel type</div>
        <div class="hws-chip-row">
          <button class="hws-chip h-fuel-chip" data-val="Petrol"        type="button">⛽ Petrol</button>
          <button class="hws-chip h-fuel-chip" data-val="Diesel"        type="button">🛢 Diesel</button>
          <button class="hws-chip h-fuel-chip" data-val="Hybrid"        type="button">🌿 Hybrid</button>
          <button class="hws-chip h-fuel-chip" data-val="Electric"      type="button">⚡ Electric</button>
        </div>
      </div>

      <!-- Price range -->
      <div>
        <div class="hws-adv-label">Price (R)</div>
        <div class="hws-range-grid">
          <input id="hMinPrice" type="number" class="hws-range-input" placeholder="Min price" min="0" step="10000">
          <input id="hMaxPrice" type="number" class="hws-range-input" placeholder="Max price" min="0" step="10000">
        </div>
      </div>

      <!-- Year range -->
      <div>
        <div class="hws-adv-label">Year</div>
        <div class="hws-range-grid">
          <input id="hMinYear" type="number" class="hws-range-input" placeholder="From year" min="1990" max="<?= date('Y') ?>">
          <input id="hMaxYear" type="number" class="hws-range-input" placeholder="To year"   min="1990" max="<?= date('Y') ?>">
        </div>
      </div>

    </div>

    <div class="hws-reset-row">
      <button class="hws-reset-btn" id="hReset" type="button">
        <i class="fa-solid fa-rotate-left"></i> Clear all
      </button>
    </div>

    <!-- Submit -->
    <button class="hws-submit" id="hSubmit" type="button" aria-label="Search cars">
      <i class="fa-solid fa-magnifying-glass"></i>
      <span class="h-submit-label">Browse <?= $totalCars > 0 ? number_format($totalCars) . ' cars' : 'all cars' ?></span>
    </button>

  </div><!-- /.hws-card -->

  <!-- Hints below card -->
  <div class="hws-hints anim-up d2">
    <span>
      <i class="fa-solid fa-circle" style="color:#4ade80;font-size:7px"></i>
      <?= $totalCars > 0 ? number_format($totalCars) . ' active listings' : 'Live listings' ?>
    </span>
    <span>
      <i class="fa-solid fa-shield-halved" style="color:#60a5fa;font-size:11px"></i>
      Verified dealers
    </span>
  </div>

</div><!-- /#heroSearchWidget -->

<!-- ═══════════════════════════════════════════════════════════
     HERO SEARCH WIDGET SCRIPT (inlined — no caching issues)
     ═══════════════════════════════════════════════════════════ -->
<script>
(function(){
'use strict';

var DEBOUNCE_MS = 280;
var MAX_RECENT  = 6;
var STORAGE_KEY = 'sd_hero_searches_v2';
var API_BASE    = '/api/cars/search.php';

var MAKES = [
  'Audi','BMW','Chevrolet','Chrysler','Citroën','Datsun','Fiat','Ford',
  'GWM','Haval','Honda','Hyundai','Isuzu','JAC','Jeep','Kia',
  'Land Rover','Mahindra','Mazda','Mercedes-Benz','MG','Mitsubishi',
  'Nissan','Opel','Peugeot','Porsche','Renault','SEAT','Škoda',
  'Subaru','Suzuki','Toyota','Volkswagen','Volvo'
];
/*
 * BUG FIX: BODY_TYPES had 'SUV / 4x4' and FUEL_TYPES had 'Plug-in Hybrid'
 * — neither matches what app/dealer/car-upload.php's wizard actually
 * writes to cars.body_type / cars.fuel_type (confirmed by reading that
 * file directly: plain 'SUV', and 'Plug-in Hybrid (PHEV)' with the
 * parenthetical suffix). Selecting either from autocomplete, or the SUV
 * chip below, submitted a value that could never match a real row.
 */
var BODY_TYPES  = ['Bakkie','Crossover','Hatchback','MPV','SUV','Sedan','Station Wagon','Convertible'];
var FUEL_TYPES  = ['Petrol','Diesel','Hybrid','Plug-in Hybrid (PHEV)','Electric'];
var PROVINCES   = [
  'Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape',
  'Limpopo','Mpumalanga','North West','Free State','Northern Cape'
];

/* ── Helpers ── */
function debounce(fn, ms) {
  var t;
  return function() { var args = arguments; clearTimeout(t); t = setTimeout(function(){ fn.apply(null, args); }, ms); };
}
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function hl(text, q) {
  if (!q) return esc(text);
  var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
  return esc(text).replace(re, '<mark>$1</mark>');
}

/* ── Recent searches ── */
var Recent = {
  load: function() { try { return JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]'); } catch(e){ return []; } },
  save: function(l) { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(l.slice(0,MAX_RECENT))); } catch(e){} },
  push: function(label, qs) {
    var l = this.load().filter(function(r){ return r.qs !== qs; });
    l.unshift({ label: label, qs: qs, ts: Date.now() });
    this.save(l);
  },
  clear: function() { try { localStorage.removeItem(STORAGE_KEY); } catch(e){} }
};

/* ── Init ── */
var root = document.getElementById('heroSearchWidget');
if (!root) return;

var state = {
  q:'', make:'', province:'', body:'', fuel:'',
  minPrice:'', maxPrice:'', minYear:'', maxYear:'',
  condition:'any', resultCount:null
};

var qInput      = document.getElementById('hq');
var makeBtn     = document.getElementById('hMakeBtn');
var makePanel   = document.getElementById('hMakePanel');
var makeSearch  = document.getElementById('hMakeSearch');
var makeList    = document.getElementById('hMakeList');
var provBtn     = document.getElementById('hProvBtn');
var provPanel   = document.getElementById('hProvPanel');
var provList    = document.getElementById('hProvList');
var acBox       = document.getElementById('hAcBox');
var submitBtn   = document.getElementById('hSubmit');
var resetBtn    = document.getElementById('hReset');
var advToggle   = document.getElementById('hAdvToggle');
var advPanel    = document.getElementById('hAdvPanel');
var activeTagsEl= document.getElementById('hActiveTags');
var spinnerEl   = document.getElementById('hSpinner');
var countLabel  = submitBtn.querySelector('.h-submit-label');
var bodyChips   = Array.from(root.querySelectorAll('.h-body-chip'));
var fuelChips   = Array.from(root.querySelectorAll('.h-fuel-chip'));
var condChips   = Array.from(root.querySelectorAll('.h-cond-chip'));
var minPriceEl  = document.getElementById('hMinPrice');
var maxPriceEl  = document.getElementById('hMaxPrice');
var minYearEl   = document.getElementById('hMinYear');
var maxYearEl   = document.getElementById('hMaxYear');

var acFocus = -1;
var acItems = [];

/* ── Autocomplete ── */
function buildSuggestions(q) {
  if (!q) return [];
  var lq = q.toLowerCase();
  var out = [];
  MAKES.forEach(function(m){ if (m.toLowerCase().startsWith(lq)) out.push({type:'make',label:m,val:m}); });
  MAKES.forEach(function(m){ if (!m.toLowerCase().startsWith(lq) && m.toLowerCase().includes(lq)) out.push({type:'make',label:m,val:m}); });
  BODY_TYPES.forEach(function(b){ if (b.toLowerCase().includes(lq)) out.push({type:'body',label:b,val:b}); });
  FUEL_TYPES.forEach(function(f){ if (f.toLowerCase().includes(lq)) out.push({type:'fuel',label:f,val:f}); });
  PROVINCES.forEach(function(p){ if (p.toLowerCase().includes(lq)) out.push({type:'province',label:p,val:p}); });
  return out.slice(0,8);
}
var AC_ICONS  = {make:'🚗',body:'📐',fuel:'⛽',province:'📍'};
var AC_LABELS = {make:'Make',body:'Body type',fuel:'Fuel',province:'Province'};

function renderAc(open) {
  if (!open) { acBox.style.display='none'; return; }
  var recent = state.q ? [] : Recent.load();
  var html = '';
  if (acItems.length) {
    html += '<div class="hac-section-label">Suggestions</div>';
    acItems.forEach(function(item,i){
      html += '<div class="hac-item'+(i===acFocus?' focused':'')+'" data-idx="'+i+'" role="option">'
        +'<span class="hac-icon">'+AC_ICONS[item.type]+'</span>'
        +'<span class="hac-text">'+hl(item.label,state.q)+'</span>'
        +'<span class="hac-type">'+AC_LABELS[item.type]+'</span>'
        +'</div>';
    });
  }
  if (recent.length) {
    html += '<div class="hac-section-label">Recent searches</div>';
    recent.forEach(function(r,i){
      html += '<div class="hac-item hac-recent" data-recent-idx="'+i+'" role="option">'
        +'<span class="hac-icon">🕐</span>'
        +'<span class="hac-text">'+esc(r.label)+'</span>'
        +'<button class="hac-remove" data-remove="'+i+'" title="Remove">×</button>'
        +'</div>';
    });
    html += '<div class="hac-clear-row"><button class="hac-clear-btn" id="hClearRecent">Clear recent</button></div>';
  }
  if (!html) { acBox.style.display='none'; return; }
  acBox.innerHTML = html;
  acBox.style.display = 'block';

  acBox.querySelectorAll('.hac-item:not(.hac-recent)').forEach(function(el){
    el.addEventListener('mousedown', function(e){
      e.preventDefault();
      var item = acItems[parseInt(el.dataset.idx)];
      applyAcItem(item);
    });
  });
  acBox.querySelectorAll('.hac-recent').forEach(function(el){
    el.addEventListener('mousedown', function(e){
      if (e.target.classList.contains('hac-remove')) {
        e.preventDefault();
        var list = Recent.load(); list.splice(parseInt(e.target.dataset.remove),1); Recent.save(list); renderAc(true); return;
      }
      e.preventDefault();
      var rec = Recent.load()[parseInt(el.dataset.recentIdx)];
      if (rec) window.location.href = '/c/?' + rec.qs;
    });
  });
  var cb = document.getElementById('hClearRecent');
  if (cb) cb.addEventListener('mousedown', function(e){ e.preventDefault(); Recent.clear(); renderAc(true); });
}

function applyAcItem(item) {
  if (item.type==='make')     { state.make=item.val;     updateMakeBtn(); }
  if (item.type==='body')     { state.body=item.val;     syncChips('body'); }
  if (item.type==='fuel')     { state.fuel=item.val;     syncChips('fuel'); }
  if (item.type==='province') { state.province=item.val; updateProvBtn(); }
  qInput.value=''; state.q=''; acItems=[]; acFocus=-1;
  renderAc(false);
  updateActiveTags();
  fetchCount();
}

/* ── Make panel ── */
function renderMakeList(filter) {
  var fl = (filter||'').toLowerCase();
  var items = fl ? MAKES.filter(function(m){ return m.toLowerCase().includes(fl); }) : MAKES;
  makeList.innerHTML = items.map(function(m){
    return '<div class="hpanel-item'+(state.make===m?' active':'')+'" data-val="'+esc(m)+'">'+hl(m,filter||'')+'</div>';
  }).join('');
  makeList.querySelectorAll('.hpanel-item').forEach(function(el){
    el.addEventListener('click', function(){
      state.make = state.make===el.dataset.val ? '' : el.dataset.val;
      updateMakeBtn(); closePanels(); updateActiveTags(); fetchCount();
    });
  });
}
function updateMakeBtn() {
  var span = makeBtn.querySelector('span');
  span.textContent = state.make || 'Make / Model';
  makeBtn.classList.toggle('active', !!state.make);
}
makeBtn.addEventListener('click', function(e){
  e.stopPropagation();
  var open = makePanel.classList.contains('open');
  closePanels();
  if (!open) { makePanel.classList.add('open'); makeSearch.value=''; renderMakeList(''); makeSearch.focus(); }
});
makeSearch.addEventListener('input', function(){ renderMakeList(makeSearch.value); });

/* ── Province panel ── */
function renderProvList() {
  provList.innerHTML = PROVINCES.map(function(p){
    return '<div class="hpanel-item'+(state.province===p?' active':'')+'" data-val="'+esc(p)+'">'
      +'<span class="hpanel-prov-dot"></span>'+esc(p)+'</div>';
  }).join('');
  provList.querySelectorAll('.hpanel-item').forEach(function(el){
    el.addEventListener('click', function(){
      state.province = state.province===el.dataset.val ? '' : el.dataset.val;
      updateProvBtn(); closePanels(); updateActiveTags(); fetchCount();
    });
  });
}
function updateProvBtn() {
  var span = provBtn.querySelector('span');
  span.textContent = state.province || 'Province';
  provBtn.classList.toggle('active', !!state.province);
}
provBtn.addEventListener('click', function(e){
  e.stopPropagation();
  var open = provPanel.classList.contains('open');
  closePanels();
  if (!open) { provPanel.classList.add('open'); renderProvList(); }
});

function closePanels() { makePanel.classList.remove('open'); provPanel.classList.remove('open'); }
document.addEventListener('click', closePanels);
makePanel.addEventListener('click', function(e){ e.stopPropagation(); });
provPanel.addEventListener('click', function(e){ e.stopPropagation(); });

/* ── Chips ── */
function syncChips(type) {
  var map   = {body:bodyChips, fuel:fuelChips, cond:condChips};
  var skey  = {body:'body', fuel:'fuel', cond:'condition'};
  map[type].forEach(function(chip){ chip.classList.toggle('active', chip.dataset.val===state[skey[type]]); });
}
bodyChips.forEach(function(c){ c.addEventListener('click',function(){ state.body=state.body===c.dataset.val?'':c.dataset.val; syncChips('body'); updateActiveTags(); fetchCount(); }); });
fuelChips.forEach(function(c){ c.addEventListener('click',function(){ state.fuel=state.fuel===c.dataset.val?'':c.dataset.val; syncChips('fuel'); updateActiveTags(); fetchCount(); }); });
condChips.forEach(function(c){ c.addEventListener('click',function(){ state.condition=state.condition===c.dataset.val?'any':c.dataset.val; syncChips('cond'); updateActiveTags(); fetchCount(); }); });

/* ── Range inputs ── */
function onRangeInput() {
  state.minPrice=minPriceEl.value; state.maxPrice=maxPriceEl.value;
  state.minYear=minYearEl.value;   state.maxYear=maxYearEl.value;
  updateActiveTags(); fetchCount();
}
var debouncedRange = debounce(onRangeInput, 380);
[minPriceEl,maxPriceEl,minYearEl,maxYearEl].forEach(function(el){ el.addEventListener('input', debouncedRange); });

/* ── Advanced toggle ── */
advToggle.addEventListener('click', function(){
  var open = advPanel.classList.toggle('open');
  advToggle.classList.toggle('open', open);
  advToggle.setAttribute('aria-expanded', open ? 'true':'false');
  advPanel.setAttribute('aria-hidden', open ? 'false':'true');
  var span = advToggle.querySelectorAll('span')[1].querySelector('span');
  span.textContent = open ? 'Fewer filters' : 'More filters';
});

/* ── Active tags ── */
function updateActiveTags() {
  var tags = [];
  if (state.make)     tags.push({key:'make',     label:state.make});
  if (state.province) tags.push({key:'province',  label:state.province});
  if (state.body)     tags.push({key:'body',      label:state.body});
  if (state.fuel)     tags.push({key:'fuel',      label:state.fuel});
  if (state.condition!=='any') tags.push({key:'condition', label:state.condition.charAt(0).toUpperCase()+state.condition.slice(1)});
  if (state.minPrice) tags.push({key:'minPrice',  label:'Min R'+Number(state.minPrice).toLocaleString('en-ZA')});
  if (state.maxPrice) tags.push({key:'maxPrice',  label:'Max R'+Number(state.maxPrice).toLocaleString('en-ZA')});
  if (state.minYear)  tags.push({key:'minYear',   label:'From '+state.minYear});
  if (state.maxYear)  tags.push({key:'maxYear',   label:'To '+state.maxYear});

  activeTagsEl.innerHTML = tags.map(function(t){
    return '<button class="h-tag" data-key="'+t.key+'" type="button">'+esc(t.label)+' <span class="h-tag-x">×</span></button>';
  }).join('');

  activeTagsEl.querySelectorAll('.h-tag').forEach(function(btn){
    btn.addEventListener('click', function(){
      var k = btn.dataset.key;
      if (k==='make')      { state.make='';       updateMakeBtn(); }
      if (k==='province')  { state.province='';   updateProvBtn(); }
      if (k==='body')      { state.body='';       syncChips('body'); }
      if (k==='fuel')      { state.fuel='';       syncChips('fuel'); }
      if (k==='condition') { state.condition='any'; syncChips('cond'); }
      if (k==='minPrice')  { state.minPrice=''; minPriceEl.value=''; }
      if (k==='maxPrice')  { state.maxPrice=''; maxPriceEl.value=''; }
      if (k==='minYear')   { state.minYear='';  minYearEl.value=''; }
      if (k==='maxYear')   { state.maxYear='';  maxYearEl.value=''; }
      updateActiveTags(); fetchCount();
    });
  });
  activeTagsEl.style.display = tags.length ? 'flex' : 'none';
}

/* ── Live count ── */
var abortCtrl = null;
var fetchCount = debounce(function() {
  if (abortCtrl) { try { abortCtrl.abort(); } catch(e){} }
  if (!window.AbortController) { abortCtrl = null; }
  else { abortCtrl = new AbortController(); }

  var qs = new URLSearchParams();
  if (state.q)        qs.set('q',        state.q);
  if (state.make)     qs.set('make',     state.make);
  if (state.province) qs.set('province', state.province);
  if (state.body)     qs.append('body_type[]', state.body);
  if (state.fuel)     qs.append('fuel_type[]', state.fuel);
  if (state.minPrice) qs.set('price_min', state.minPrice);
  if (state.maxPrice) qs.set('price_max', state.maxPrice);
  if (state.minYear)  qs.set('year_min',  state.minYear);
  if (state.maxYear)  qs.set('year_max',  state.maxYear);
  if (state.condition&&state.condition!=='any') qs.set('condition', state.condition);
  qs.set('per_page','1');
  // BUG FIX: without this, the API counted every active car from every
  // active dealer, including ones not yet added to any broker's desk.
  // /c/ (the page this search actually lands on) only ever shows cars
  // that ARE on a desk, so the live count here was always >= what the
  // user would actually see after clicking through. api/cars/search.php
  // keeps this opt-in (default off) since the broker marketplace uses
  // the same endpoint and deliberately needs the opposite — seeing cars
  // NOT yet on a desk, so brokers can discover and add them.
  qs.set('on_desk_only', '1');

  spinnerEl.style.display = 'inline-block';
  submitBtn.classList.add('loading');

  var opts = { method:'GET' };
  if (abortCtrl) opts.signal = abortCtrl.signal;

  fetch(API_BASE + '?' + qs.toString(), opts)
    .then(function(r){ return r.json(); })
    .then(function(d){
      state.resultCount = d.total || 0;
      updateSubmitLabel();
    })
    .catch(function(e){
      if (e && e.name === 'AbortError') return;
      state.resultCount = null;
      updateSubmitLabel();
    })
    .finally(function(){
      spinnerEl.style.display = 'none';
      submitBtn.classList.remove('loading');
    });
}, DEBOUNCE_MS);

function updateSubmitLabel() {
  var n = state.resultCount;
  if (n === null) { countLabel.textContent = 'Search cars'; return; }
  var hasFilters = state.q || state.make || state.province || state.body ||
                   state.fuel || state.minPrice || state.maxPrice ||
                   state.minYear || state.maxYear || state.condition !== 'any';
  var nf = n.toLocaleString('en-ZA');
  countLabel.textContent = hasFilters
    ? 'View ' + nf + ' ' + (n === 1 ? 'car' : 'cars')
    : 'Browse ' + nf + ' ' + (n === 1 ? 'car' : 'cars');
}

/* ── Text input events ── */
var debouncedQ = debounce(function(){
  state.q = qInput.value.trim();
  acItems  = buildSuggestions(state.q);
  acFocus  = -1;
  renderAc(true);
  fetchCount();
}, 150);

qInput.addEventListener('input', debouncedQ);
qInput.addEventListener('focus', function(){ acItems=buildSuggestions(state.q); renderAc(true); });
qInput.addEventListener('blur',  function(){ setTimeout(function(){ renderAc(false); }, 180); });
qInput.addEventListener('keydown', function(e){
  if (!acItems.length || acBox.style.display==='none') {
    if (e.key==='Enter') doSearch();
    return;
  }
  if (e.key==='ArrowDown')  { e.preventDefault(); acFocus=Math.min(acFocus+1,acItems.length-1); renderAc(true); }
  else if (e.key==='ArrowUp') { e.preventDefault(); acFocus=Math.max(acFocus-1,-1); renderAc(true); }
  else if (e.key==='Enter')   { e.preventDefault(); if (acFocus>=0) applyAcItem(acItems[acFocus]); else doSearch(); }
  else if (e.key==='Escape')  { renderAc(false); }
});

/* ── Submit ── */
function doSearch() {
  state.q = qInput.value.trim();
  var params = new URLSearchParams();
  if (state.q)        params.set('q',         state.q);
  if (state.make)     params.set('make',       state.make);
  if (state.province) params.set('province',   state.province);
  if (state.body)     params.append('body_type[]', state.body);
  if (state.fuel)     params.append('fuel_type[]', state.fuel);
  if (state.minPrice) params.set('price_min',  state.minPrice);
  if (state.maxPrice) params.set('price_max',  state.maxPrice);
  if (state.minYear)  params.set('year_min',   state.minYear);
  if (state.maxYear)  params.set('year_max',   state.maxYear);
  if (state.condition&&state.condition!=='any') params.set('condition', state.condition);
  var qs = params.toString();
  var label = [state.q, state.make, state.province, state.body].filter(Boolean).join(' · ') || 'All cars';
  Recent.push(label, qs);
  window.location.href = '/c/' + (qs ? '?' + qs : '');
}
submitBtn.addEventListener('click', doSearch);

/* ── Reset ── */
resetBtn.addEventListener('click', function(){
  state.q=''; state.make=''; state.province=''; state.body='';
  state.fuel=''; state.minPrice=''; state.maxPrice='';
  state.minYear=''; state.maxYear=''; state.condition='any';
  qInput.value='';
  minPriceEl.value=''; maxPriceEl.value='';
  minYearEl.value='';  maxYearEl.value='';
  updateMakeBtn(); updateProvBtn();
  syncChips('body'); syncChips('fuel'); syncChips('cond');
  updateActiveTags(); fetchCount();
  renderAc(false);
});

/* ── Boot ── */
fetchCount();
updateActiveTags();

})();
</script>
