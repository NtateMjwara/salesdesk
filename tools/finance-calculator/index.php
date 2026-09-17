<?php
/**
 * SalesDesk — Finance Calculator
 * Route: /tools/finance-calculator/
 *
 * A full vehicle finance calculator covering:
 *   - Monthly repayment (annuity formula)
 *   - Total cost of credit
 *   - Amortisation schedule (full term, downloadable)
 *   - Balloon payment support
 *   - Affordability reverse-calculator (target monthly → max price)
 *   - Rate sensitivity table (prime ± 4%)
 *   - Insurance & running cost estimator
 *
 * All arithmetic runs client-side (JS). No auth required.
 * Wired into layout-public.php.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// Live car count for nav badge
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

// ── Page meta ──────────────────────────────────────────────────
$pageTitle      = 'Vehicle Finance Calculator — Estimate Your Monthly Repayments | SalesDesk';
$ogTitle        = 'Vehicle Finance Calculator | SalesDesk';
$ogDescription  = 'Calculate your monthly car repayments, total cost of credit, and full amortisation schedule. Free SA vehicle finance calculator with balloon payment support.';
$canonicalUrl   = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/tools/finance-calculator/';
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [
    ['Tools & Services', null],
    ['Finance Calculator', null],
];

ob_start();
?>

<!-- ══════════════════════════════════════════════════════
     FINANCE CALCULATOR PAGE
     ══════════════════════════════════════════════════════ -->

<div class="fc-page">

  <!-- ── Page header ──────────────────────────────────── -->
  <div class="fc-page-header">
    <div class="fc-page-header__eyebrow">
      <i class="fa-solid fa-calculator"></i> Free Tool
    </div>
    <h1 class="fc-page-header__title">Vehicle Finance Calculator</h1>
    <p class="fc-page-header__sub">
      Estimate monthly repayments, total cost of credit, and your full amortisation
      schedule — based on South African NCA-regulated finance terms.
    </p>
    <div class="fc-rate-ribbon">
      <span class="fc-rate-ribbon__item">
        <span class="fc-rate-ribbon__label">Prime rate</span>
        <span class="fc-rate-ribbon__val" id="ribbonPrime">11.25%</span>
      </span>
      <span class="fc-rate-ribbon__sep"></span>
      <span class="fc-rate-ribbon__item">
        <span class="fc-rate-ribbon__label">Linked rate</span>
        <span class="fc-rate-ribbon__val" id="ribbonLinked">13.25%</span>
      </span>
      <span class="fc-rate-ribbon__sep"></span>
      <span class="fc-rate-ribbon__item">
        <span class="fc-rate-ribbon__label">NCA cap</span>
        <span class="fc-rate-ribbon__val">21%</span>
      </span>
    </div>
  </div>

  <!-- ── Mode tabs ─────────────────────────────────────── -->
  <div class="fc-tabs">
    <button class="fc-tab fc-tab--active" data-mode="repayment" type="button">
      <i class="fa-solid fa-money-bill-wave"></i>
      <span>Monthly Repayment</span>
    </button>
    <button class="fc-tab" data-mode="affordability" type="button">
      <i class="fa-solid fa-wallet"></i>
      <span>Affordability Check</span>
    </button>
    <button class="fc-tab" data-mode="comparison" type="button">
      <i class="fa-solid fa-table-columns"></i>
      <span>Rate Comparison</span>
    </button>
    <button class="fc-tab" data-mode="running" type="button">
      <i class="fa-solid fa-gas-pump"></i>
      <span>Running Costs</span>
    </button>
  </div>

  <!-- ══════════════════════════════════════════════════
       MODE: REPAYMENT CALCULATOR
       ══════════════════════════════════════════════════ -->
  <div class="fc-panel fc-panel--active" id="panel-repayment">
    <div class="fc-grid">

      <!-- Left: Inputs -->
      <div class="fc-inputs-col">
        <div class="fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-sliders"></i> Loan details
          </div>

          <div class="fc-field">
            <label class="fc-label" for="vehiclePrice">
              Vehicle price
              <span class="fc-label__hint">incl. VAT</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="vehiclePrice"
                     value="450000" min="10000" max="10000000" step="1000">
            </div>
            <input type="range" class="fc-range" id="vehiclePriceSlider"
                   min="50000" max="5000000" step="10000" value="450000">
            <div class="fc-range-labels">
              <span>R 50k</span><span>R 5M</span>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="depositAmt">
              Deposit
              <span class="fc-label__hint" id="depositPctLabel">20% — R 90 000</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="depositAmt"
                     value="90000" min="0" step="1000">
            </div>
            <input type="range" class="fc-range" id="depositSlider"
                   min="0" max="100" step="1" value="20">
            <div class="fc-range-labels">
              <span>0%</span><span>50%</span>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="interestRate">
              Interest rate (p.a.)
              <span class="fc-label__hint" id="interestRateLabel">Prime + 2%</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <input class="fc-input fc-input--no-prefix" type="number"
                     id="interestRate" value="13.25" min="1" max="30" step="0.25">
              <span class="fc-suffix">%</span>
            </div>
            <input type="range" class="fc-range" id="interestRateSlider"
                   min="7" max="25" step="0.25" value="13.25">
            <div class="fc-range-labels">
              <span>7%</span><span>25%</span>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="loanTerm">
              Loan term
              <span class="fc-label__hint" id="termLabel">60 months</span>
            </label>
            <input type="range" class="fc-range" id="loanTerm"
                   min="12" max="96" step="12" value="60">
            <div class="fc-term-chips" id="termChips">
              <button class="fc-term-chip" data-months="12" type="button">1 yr</button>
              <button class="fc-term-chip" data-months="24" type="button">2 yr</button>
              <button class="fc-term-chip" data-months="36" type="button">3 yr</button>
              <button class="fc-term-chip" data-months="48" type="button">4 yr</button>
              <button class="fc-term-chip fc-term-chip--active" data-months="60" type="button">5 yr</button>
              <button class="fc-term-chip" data-months="72" type="button">6 yr</button>
              <button class="fc-term-chip" data-months="84" type="button">7 yr</button>
              <button class="fc-term-chip" data-months="96" type="button">8 yr</button>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="balloonPct">
              Balloon / Residual payment
              <span class="fc-label__hint" id="balloonLabel">0% — R 0</span>
            </label>
            <input type="range" class="fc-range" id="balloonPct"
                   min="0" max="35" step="5" value="0">
            <div class="fc-range-labels">
              <span>0% (none)</span><span>35%</span>
            </div>
            <div class="fc-balloon-note" id="balloonNote" style="display:none;">
              <i class="fa-solid fa-triangle-exclamation"></i>
              A balloon reduces your monthly payment but leaves a lump sum due at the end.
            </div>
          </div>

          <!-- Initiation & monthly fee -->
          <div class="fc-field-row">
            <div class="fc-field fc-field--half">
              <label class="fc-label" for="initiationFee">
                Initiation fee
                <span class="fc-label__hint">NCA max R 6 037.50</span>
              </label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input" type="number" id="initiationFee"
                       value="6037.50" min="0" max="6037.50" step="0.5">
              </div>
            </div>
            <div class="fc-field fc-field--half">
              <label class="fc-label" for="monthlyFee">
                Monthly service fee
                <span class="fc-label__hint">NCA max R 69</span>
              </label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input" type="number" id="monthlyFee"
                       value="69" min="0" max="69" step="1">
              </div>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label">
              Include VAT on fees
            </label>
            <label class="fc-toggle">
              <input type="checkbox" id="includeVAT" checked>
              <span class="fc-toggle__track"></span>
              <span class="fc-toggle__label">VAT (15%) on initiation &amp; service fees</span>
            </label>
          </div>

        </div><!-- /fc-card -->

        <!-- Browse button -->
        <a href="/c/" class="fc-browse-cta">
          <i class="fa-solid fa-magnifying-glass"></i>
          Browse <?= $totalCars > 0 ? number_format($totalCars) . ' vehicles' : 'vehicles' ?> on SalesDesk
          <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div><!-- /fc-inputs-col -->

      <!-- Right: Results -->
      <div class="fc-results-col">

        <!-- Hero result -->
        <div class="fc-result-hero">
          <div class="fc-result-hero__label">Estimated monthly repayment</div>
          <div class="fc-result-hero__amount" id="heroMonthly">R 0</div>
          <div class="fc-result-hero__sub" id="heroSub">incl. service fee &amp; VAT</div>
        </div>

        <!-- Key metrics grid -->
        <div class="fc-metrics">
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--blue">
              <i class="fa-solid fa-coins"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Loan amount</div>
              <div class="fc-metric__val" id="metLoanAmount">R 0</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--amber">
              <i class="fa-solid fa-percent"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Total interest</div>
              <div class="fc-metric__val" id="metTotalInterest">R 0</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--green">
              <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Total cost of credit</div>
              <div class="fc-metric__val" id="metTotalCredit">R 0</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--purple">
              <i class="fa-solid fa-hourglass-end"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Balloon at end</div>
              <div class="fc-metric__val" id="metBalloon">R 0</div>
            </div>
          </div>
        </div>

        <!-- Cost breakdown bar -->
        <div class="fc-card fc-card--breakdown">
          <div class="fc-card__title">
            <i class="fa-solid fa-chart-pie"></i> Cost breakdown
          </div>
          <div class="fc-breakdown-bar">
            <div class="fc-breakdown-bar__principal" id="barPrincipal" style="width:60%"></div>
            <div class="fc-breakdown-bar__interest"  id="barInterest"  style="width:25%"></div>
            <div class="fc-breakdown-bar__fees"      id="barFees"      style="width:15%"></div>
          </div>
          <div class="fc-breakdown-legend">
            <div class="fc-legend-item">
              <span class="fc-legend-dot fc-legend-dot--principal"></span>
              <span class="fc-legend-label">Principal</span>
              <span class="fc-legend-val" id="legendPrincipal">—</span>
            </div>
            <div class="fc-legend-item">
              <span class="fc-legend-dot fc-legend-dot--interest"></span>
              <span class="fc-legend-label">Interest</span>
              <span class="fc-legend-val" id="legendInterest">—</span>
            </div>
            <div class="fc-legend-item">
              <span class="fc-legend-dot fc-legend-dot--fees"></span>
              <span class="fc-legend-label">Fees (incl. VAT)</span>
              <span class="fc-legend-val" id="legendFees">—</span>
            </div>
          </div>
        </div>

        <!-- Amortisation preview -->
        <div class="fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-table-list"></i> Amortisation schedule
            <button class="fc-amort-toggle" id="amortToggle" type="button">
              Show full schedule <i class="fa-solid fa-chevron-down"></i>
            </button>
          </div>
          <div class="fc-amort-wrap" id="amortWrap">
            <table class="fc-amort-table" id="amortTable">
              <thead>
                <tr>
                  <th>Month</th>
                  <th>Payment</th>
                  <th>Principal</th>
                  <th>Interest</th>
                  <th>Balance</th>
                </tr>
              </thead>
              <tbody id="amortBody">
                <!-- Filled by JS -->
              </tbody>
            </table>
          </div>
          <button class="fc-download-btn" id="downloadCSV" type="button">
            <i class="fa-solid fa-download"></i> Download schedule (CSV)
          </button>
        </div>

      </div><!-- /fc-results-col -->
    </div><!-- /fc-grid -->
  </div><!-- /panel-repayment -->


  <!-- ══════════════════════════════════════════════════
       MODE: AFFORDABILITY CHECK
       ══════════════════════════════════════════════════ -->
  <div class="fc-panel" id="panel-affordability">
    <div class="fc-grid">
      <div class="fc-inputs-col">
        <div class="fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-wallet"></i> Your budget
          </div>

          <div class="fc-field">
            <label class="fc-label" for="grossIncome">
              Gross monthly income
              <span class="fc-label__hint">before tax</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="grossIncome"
                     value="45000" min="5000" step="500">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="existingDebt">
              Existing monthly debt obligations
              <span class="fc-label__hint">bond, loans, credit cards</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="existingDebt"
                     value="5000" min="0" step="500">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="targetMonthly">
              Target monthly car payment
              <span class="fc-label__hint">leave blank to auto-calculate</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="targetMonthly"
                     placeholder="e.g. 8 500" min="500" step="100">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="aff_deposit">Deposit available</label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="aff_deposit"
                     value="50000" min="0" step="5000">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="aff_rate">Interest rate (p.a.)</label>
            <div class="fc-input-prefix-wrap">
              <input class="fc-input fc-input--no-prefix" type="number"
                     id="aff_rate" value="13.25" min="7" max="25" step="0.25">
              <span class="fc-suffix">%</span>
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="aff_term">Loan term (months)</label>
            <div class="fc-term-chips" id="affTermChips">
              <button class="fc-term-chip" data-months="24" type="button">24</button>
              <button class="fc-term-chip" data-months="36" type="button">36</button>
              <button class="fc-term-chip" data-months="48" type="button">48</button>
              <button class="fc-term-chip fc-term-chip--active" data-months="60" type="button">60</button>
              <button class="fc-term-chip" data-months="72" type="button">72</button>
            </div>
            <input type="hidden" id="aff_term" value="60">
          </div>

          <button class="fc-calc-btn" id="calcAffordability" type="button">
            <i class="fa-solid fa-calculator"></i> Calculate my affordability
          </button>
        </div>
      </div>

      <div class="fc-results-col">
        <div class="fc-result-hero fc-result-hero--green" id="affResultHero">
          <div class="fc-result-hero__label">Maximum vehicle price</div>
          <div class="fc-result-hero__amount" id="affMaxPrice">R 0</div>
          <div class="fc-result-hero__sub" id="affMaxSub">at R 0 /month</div>
        </div>

        <div class="fc-metrics" id="affMetrics">
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--blue">
              <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">NCA 30% rule budget</div>
              <div class="fc-metric__val" id="affNCABudget">R 0</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--green">
              <i class="fa-solid fa-piggy-bank"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Recommended budget</div>
              <div class="fc-metric__val" id="affRecomBudget">R 0</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--amber">
              <i class="fa-solid fa-percent"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Debt-to-income ratio</div>
              <div class="fc-metric__val" id="affDTI">0%</div>
            </div>
          </div>
          <div class="fc-metric">
            <div class="fc-metric__icon fc-metric__icon--purple">
              <i class="fa-solid fa-car"></i>
            </div>
            <div class="fc-metric__body">
              <div class="fc-metric__label">Suggested car budget</div>
              <div class="fc-metric__val" id="affSuggestedCar">R 0</div>
            </div>
          </div>
        </div>

        <div class="fc-aff-gauge-card fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-gauge-high"></i> Affordability health
          </div>
          <div class="fc-gauge-wrap">
            <svg class="fc-gauge-svg" viewBox="0 0 200 110" xmlns="http://www.w3.org/2000/svg">
              <path d="M10 100 A90 90 0 0 1 190 100" fill="none" stroke="#e2e8f0" stroke-width="16" stroke-linecap="round"/>
              <path class="fc-gauge-arc" id="gaugeArc"
                    d="M10 100 A90 90 0 0 1 190 100" fill="none" stroke="#22c55e"
                    stroke-width="16" stroke-linecap="round"
                    stroke-dasharray="283" stroke-dashoffset="283"/>
              <text x="100" y="95" text-anchor="middle" class="fc-gauge-pct" id="gaugePct">0%</text>
              <text x="100" y="108" text-anchor="middle" class="fc-gauge-lbl" id="gaugeLbl">DTI ratio</text>
            </svg>
          </div>
          <div class="fc-gauge-bands">
            <span class="fc-band fc-band--green">
              <i class="fa-solid fa-circle"></i> &lt;25% Healthy
            </span>
            <span class="fc-band fc-band--amber">
              <i class="fa-solid fa-circle"></i> 25–35% Caution
            </span>
            <span class="fc-band fc-band--red">
              <i class="fa-solid fa-circle"></i> &gt;35% High risk
            </span>
          </div>
        </div>

        <a href="/c/?price_max=<?= /** will be filled by JS */ 0 ?>" id="affBrowseLink"
           class="fc-browse-cta" style="display:none;">
          <i class="fa-solid fa-car"></i>
          Browse cars within your budget
          <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>
  </div><!-- /panel-affordability -->


  <!-- ══════════════════════════════════════════════════
       MODE: RATE COMPARISON TABLE
       ══════════════════════════════════════════════════ -->
  <div class="fc-panel" id="panel-comparison">
    <div class="fc-card">
      <div class="fc-card__title">
        <i class="fa-solid fa-table"></i> Rate sensitivity — how the rate affects your payment
      </div>
      <p class="fc-card__desc">
        Edit the loan amount and term below, then see how your monthly repayment changes
        across a range of interest rates.
      </p>
      <div class="fc-cmp-inputs">
        <div class="fc-field">
          <label class="fc-label" for="cmp_amount">Loan amount</label>
          <div class="fc-input-prefix-wrap">
            <span class="fc-prefix">R</span>
            <input class="fc-input" type="number" id="cmp_amount" value="360000" step="5000">
          </div>
        </div>
        <div class="fc-field">
          <label class="fc-label">Term</label>
          <div class="fc-term-chips" id="cmpTermChips">
            <button class="fc-term-chip" data-months="24" type="button">24 mo</button>
            <button class="fc-term-chip" data-months="36" type="button">36 mo</button>
            <button class="fc-term-chip" data-months="48" type="button">48 mo</button>
            <button class="fc-term-chip fc-term-chip--active" data-months="60" type="button">60 mo</button>
            <button class="fc-term-chip" data-months="72" type="button">72 mo</button>
          </div>
          <input type="hidden" id="cmp_term" value="60">
        </div>
      </div>

      <div class="fc-cmp-table-wrap">
        <table class="fc-cmp-table" id="cmpTable">
          <thead>
            <tr>
              <th>Rate (p.a.)</th>
              <th>Monthly</th>
              <th>Total interest</th>
              <th>Total repaid</th>
              <th>vs. prime + 2%</th>
            </tr>
          </thead>
          <tbody id="cmpBody">
            <!-- Filled by JS -->
          </tbody>
        </table>
      </div>
      <p class="fc-footnote">
        Comparison excludes initiation fee &amp; monthly service fee for clarity.
        Prime rate assumed at 11.25% p.a.
      </p>
    </div>
  </div><!-- /panel-comparison -->


  <!-- ══════════════════════════════════════════════════
       MODE: RUNNING COSTS
       ══════════════════════════════════════════════════ -->
  <div class="fc-panel" id="panel-running">
    <div class="fc-grid">
      <div class="fc-inputs-col">
        <div class="fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-gas-pump"></i> Vehicle &amp; usage details
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_price">Vehicle price</label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="run_price" value="450000" step="5000">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_km">
              Monthly km driven
              <span class="fc-label__hint" id="runKmLabel">1 500 km/month</span>
            </label>
            <input type="range" class="fc-range" id="run_km"
                   min="500" max="5000" step="100" value="1500">
            <div class="fc-range-labels"><span>500 km</span><span>5 000 km</span></div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_fuel_type">Fuel type</label>
            <select class="fc-select" id="run_fuel_type">
              <option value="petrol_95">Petrol 95 (unleaded)</option>
              <option value="petrol_93">Petrol 93 (unleaded)</option>
              <option value="diesel">Diesel</option>
              <option value="electric">Electric (kWh)</option>
            </select>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_consumption">
              Fuel consumption
              <span class="fc-label__hint" id="runConsLabel">7.5 L/100km</span>
            </label>
            <input type="range" class="fc-range" id="run_consumption"
                   min="3" max="20" step="0.5" value="7.5">
            <div class="fc-range-labels"><span>3 L/100km</span><span>20 L/100km</span></div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_service_interval">Service interval (km)</label>
            <select class="fc-select" id="run_service_interval">
              <option value="10000">10 000 km</option>
              <option value="15000" selected>15 000 km</option>
              <option value="20000">20 000 km</option>
              <option value="30000">30 000 km</option>
            </select>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_service_cost">
              Average service cost
              <span class="fc-label__hint">per service visit</span>
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="run_service_cost"
                     value="4500" min="500" step="100">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_insurance">
              Monthly insurance estimate
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="run_insurance"
                     value="1200" min="0" step="50">
            </div>
          </div>

          <div class="fc-field">
            <label class="fc-label" for="run_tyres">
              Tyre replacement (per set)
            </label>
            <div class="fc-input-prefix-wrap">
              <span class="fc-prefix">R</span>
              <input class="fc-input" type="number" id="run_tyres"
                     value="8000" min="1000" step="500">
            </div>
          </div>

        </div>
      </div>

      <div class="fc-results-col">
        <div class="fc-result-hero fc-result-hero--orange">
          <div class="fc-result-hero__label">Estimated total monthly cost of ownership</div>
          <div class="fc-result-hero__amount" id="runTotalMonthly">R 0</div>
          <div class="fc-result-hero__sub">finance + fuel + insurance + maintenance</div>
        </div>

        <div class="fc-run-breakdown" id="runBreakdown">
          <div class="fc-run-item">
            <div class="fc-run-item__label">
              <span class="fc-run-dot fc-run-dot--blue"></span>Finance repayment
            </div>
            <div class="fc-run-item__bar-wrap">
              <div class="fc-run-item__bar fc-run-item__bar--blue" id="runBarFinance" style="width:0%"></div>
            </div>
            <div class="fc-run-item__val" id="runValFinance">R 0</div>
          </div>
          <div class="fc-run-item">
            <div class="fc-run-item__label">
              <span class="fc-run-dot fc-run-dot--amber"></span>Fuel cost
            </div>
            <div class="fc-run-item__bar-wrap">
              <div class="fc-run-item__bar fc-run-item__bar--amber" id="runBarFuel" style="width:0%"></div>
            </div>
            <div class="fc-run-item__val" id="runValFuel">R 0</div>
          </div>
          <div class="fc-run-item">
            <div class="fc-run-item__label">
              <span class="fc-run-dot fc-run-dot--green"></span>Insurance
            </div>
            <div class="fc-run-item__bar-wrap">
              <div class="fc-run-item__bar fc-run-item__bar--green" id="runBarIns" style="width:0%"></div>
            </div>
            <div class="fc-run-item__val" id="runValIns">R 0</div>
          </div>
          <div class="fc-run-item">
            <div class="fc-run-item__label">
              <span class="fc-run-dot fc-run-dot--purple"></span>Service &amp; maintenance
            </div>
            <div class="fc-run-item__bar-wrap">
              <div class="fc-run-item__bar fc-run-item__bar--purple" id="runBarMaint" style="width:0%"></div>
            </div>
            <div class="fc-run-item__val" id="runValMaint">R 0</div>
          </div>
          <div class="fc-run-item">
            <div class="fc-run-item__label">
              <span class="fc-run-dot fc-run-dot--red"></span>Tyres (amortised)
            </div>
            <div class="fc-run-item__bar-wrap">
              <div class="fc-run-item__bar fc-run-item__bar--red" id="runBarTyres" style="width:0%"></div>
            </div>
            <div class="fc-run-item__val" id="runValTyres">R 0</div>
          </div>
        </div>

        <div class="fc-card fc-run-annual">
          <div class="fc-card__title">
            <i class="fa-solid fa-calendar-year"></i> Annual totals
          </div>
          <div class="fc-run-annual-grid">
            <div>
              <div class="fc-run-annual__label">Total annual cost</div>
              <div class="fc-run-annual__val" id="runAnnualTotal">R 0</div>
            </div>
            <div>
              <div class="fc-run-annual__label">Annual fuel spend</div>
              <div class="fc-run-annual__val" id="runAnnualFuel">R 0</div>
            </div>
            <div>
              <div class="fc-run-annual__label">Cost per km driven</div>
              <div class="fc-run-annual__val" id="runCostPerKm">R 0.00</div>
            </div>
            <div>
              <div class="fc-run-annual__label">Annual km</div>
              <div class="fc-run-annual__val" id="runAnnualKm">0 km</div>
            </div>
          </div>
        </div>

        <div class="fc-fuel-prices fc-card">
          <div class="fc-card__title">
            <i class="fa-solid fa-gas-pump"></i> Fuel price reference
            <span class="fc-card__title-note">edit to match current pump prices</span>
          </div>
          <div class="fc-fuel-grid">
            <div class="fc-fuel-item">
              <label class="fc-fuel-label" for="fp_95">Petrol 95 (R/L)</label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input fc-input--sm" type="number" id="fp_95" value="22.80" step="0.01">
              </div>
            </div>
            <div class="fc-fuel-item">
              <label class="fc-fuel-label" for="fp_93">Petrol 93 (R/L)</label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input fc-input--sm" type="number" id="fp_93" value="22.54" step="0.01">
              </div>
            </div>
            <div class="fc-fuel-item">
              <label class="fc-fuel-label" for="fp_diesel">Diesel (R/L)</label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input fc-input--sm" type="number" id="fp_diesel" value="21.36" step="0.01">
              </div>
            </div>
            <div class="fc-fuel-item">
              <label class="fc-fuel-label" for="fp_elec">Electricity (R/kWh)</label>
              <div class="fc-input-prefix-wrap">
                <span class="fc-prefix">R</span>
                <input class="fc-input fc-input--sm" type="number" id="fp_elec" value="3.50" step="0.01">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /panel-running -->

  <!-- ── Disclaimer ───────────────────────────────────── -->
  <div class="fc-disclaimer">
    <i class="fa-solid fa-circle-info"></i>
    <p>
      All calculations are estimates only and do not constitute a formal finance offer or advice.
      Actual repayments depend on your credit profile, lender terms, and NCA regulations.
      Interest rates shown are indicative; prime rate assumed at 11.25% p.a. Consult your bank
      or a registered credit provider for a personalised quote.
    </p>
  </div>

</div><!-- /fc-page -->


<!-- ══════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════ -->
<style>
/* ── Page shell ─────────────────────────────────────────────── */
.fc-page {
  max-width: 1280px;
  margin: 0 auto;
  padding: 32px clamp(16px, 4vw, 48px) 80px;
}

/* ── Page header ────────────────────────────────────────────── */
.fc-page-header {
  margin-bottom: 32px;
  max-width: 680px;
}
.fc-page-header__eyebrow {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--p);
  margin-bottom: 8px;
}
.fc-page-header__title {
  font-family: var(--font-d);
  font-size: clamp(22px, 3vw, 34px);
  font-weight: 800;
  color: var(--text);
  letter-spacing: -.02em;
  margin-bottom: 10px;
}
.fc-page-header__sub {
  font-size: 15px;
  color: var(--muted);
  line-height: 1.7;
  margin-bottom: 18px;
}
.fc-rate-ribbon {
  display: inline-flex;
  align-items: center;
  gap: 0;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-full);
  padding: 6px 16px;
  box-shadow: var(--shadow-sm);
  flex-wrap: wrap;
  gap: 4px;
}
.fc-rate-ribbon__item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
.fc-rate-ribbon__label { color: var(--faint); }
.fc-rate-ribbon__val { font-weight: 700; color: var(--text); font-family: var(--mono); }
.fc-rate-ribbon__sep {
  width: 1px; height: 14px;
  background: var(--border);
  margin: 0 10px;
}

/* ── Tabs ───────────────────────────────────────────────────── */
.fc-tabs {
  display: flex;
  gap: 4px;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 6px;
  margin-bottom: 28px;
  overflow-x: auto;
  box-shadow: var(--shadow-sm);
}
.fc-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 10px 18px;
  border-radius: var(--r-lg);
  border: none;
  background: none;
  font-size: 13px;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  white-space: nowrap;
  font-family: var(--sans);
  transition: all .18s;
}
.fc-tab i { font-size: 12px; }
.fc-tab:hover { color: var(--p); background: var(--p-light); }
.fc-tab--active {
  background: var(--p);
  color: #fff;
  box-shadow: 0 2px 10px rgba(15,76,158,.25);
}
.fc-tab--active:hover { background: var(--p-dark); color: #fff; }

/* ── Panels ─────────────────────────────────────────────────── */
.fc-panel { display: none; }
.fc-panel--active { display: block; }

/* ── Main grid ──────────────────────────────────────────────── */
.fc-grid {
  display: grid;
  grid-template-columns: 380px 1fr;
  gap: 24px;
  align-items: start;
}
.fc-inputs-col { display: flex; flex-direction: column; gap: 16px; }
.fc-results-col { display: flex; flex-direction: column; gap: 16px; }

/* ── Card ───────────────────────────────────────────────────── */
.fc-card {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 22px;
  box-shadow: var(--shadow-sm);
}
.fc-card__title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 7px;
}
.fc-card__title i { color: var(--p); }
.fc-card__title-note { font-size: 11px; font-weight: 400; color: var(--faint); margin-left: auto; }
.fc-card__desc { font-size: 13px; color: var(--muted); margin-bottom: 18px; line-height: 1.6; }

/* ── Fields ─────────────────────────────────────────────────── */
.fc-field { margin-bottom: 18px; }
.fc-field:last-child { margin-bottom: 0; }
.fc-field--half { flex: 1; }
.fc-field-row { display: flex; gap: 12px; margin-bottom: 18px; }
.fc-label {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--text);
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-bottom: 7px;
}
.fc-label__hint { font-weight: 400; font-size: 11px; color: var(--faint); text-transform: none; letter-spacing: 0; }

.fc-input-prefix-wrap {
  display: flex;
  align-items: center;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  background: #f8faff;
  overflow: hidden;
  transition: border-color .18s;
  margin-bottom: 8px;
}
.fc-input-prefix-wrap:focus-within { border-color: var(--p); background: #fff; box-shadow: 0 0 0 3px rgba(15,76,158,.07); }
.fc-prefix, .fc-suffix {
  padding: 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--faint);
  background: #f0f3f9;
  border-right: 1px solid var(--border);
  height: 42px;
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.fc-suffix { border-right: none; border-left: 1px solid var(--border); }
.fc-input {
  flex: 1;
  height: 42px;
  border: none;
  background: transparent;
  padding: 0 12px;
  font-size: 14px;
  font-family: var(--mono);
  color: var(--text);
  outline: none;
  min-width: 0;
  -moz-appearance: textfield;
}
.fc-input::-webkit-outer-spin-button,
.fc-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.fc-input--no-prefix { padding-left: 14px; }
.fc-input--sm { height: 36px; font-size: 13px; }
.fc-select {
  width: 100%;
  height: 42px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  background: #f8faff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' fill='none' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E") right 12px center no-repeat;
  padding: 0 32px 0 12px;
  font-size: 13px;
  font-family: var(--sans);
  color: var(--text);
  outline: none;
  appearance: none;
  cursor: pointer;
  transition: border-color .18s;
}
.fc-select:focus { border-color: var(--p); background-color: #fff; }

/* ── Range slider ───────────────────────────────────────────── */
.fc-range {
  width: 100%;
  accent-color: var(--p);
  height: 4px;
  cursor: pointer;
  margin-bottom: 4px;
}
.fc-range-labels {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: var(--faint);
}

/* ── Term chips ─────────────────────────────────────────────── */
.fc-term-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
  margin-bottom: 4px;
}
.fc-term-chip {
  padding: 5px 13px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-full);
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
  background: #fff;
  cursor: pointer;
  font-family: var(--sans);
  transition: all .15s;
}
.fc-term-chip:hover { border-color: var(--p); color: var(--p); }
.fc-term-chip--active {
  background: var(--p);
  color: #fff;
  border-color: var(--p);
}

/* ── Toggle ─────────────────────────────────────────────────── */
.fc-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  font-size: 13px;
  color: var(--muted);
}
.fc-toggle input { display: none; }
.fc-toggle__track {
  width: 36px;
  height: 20px;
  background: var(--border);
  border-radius: var(--r-full);
  flex-shrink: 0;
  position: relative;
  transition: background .18s;
}
.fc-toggle__track::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 14px;
  height: 14px;
  background: #fff;
  border-radius: 50%;
  transition: transform .18s;
}
.fc-toggle input:checked ~ .fc-toggle__track { background: var(--p); }
.fc-toggle input:checked ~ .fc-toggle__track::after { transform: translateX(16px); }

/* ── Balloon note ───────────────────────────────────────────── */
.fc-balloon-note {
  font-size: 11px;
  color: var(--amber);
  background: var(--amb-bg);
  border: 1px solid var(--amb-b);
  border-radius: var(--r-md);
  padding: 8px 12px;
  margin-top: 6px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.5;
}

/* ── Result hero ────────────────────────────────────────────── */
.fc-result-hero {
  background: linear-gradient(140deg, #08143c 0%, var(--p) 100%);
  border-radius: var(--r-xl);
  padding: 28px 28px 24px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 8px 28px rgba(15,76,158,.2);
}
.fc-result-hero::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 160px; height: 160px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
}
.fc-result-hero--green { background: linear-gradient(140deg, #052e16 0%, #15803d 100%); }
.fc-result-hero--orange { background: linear-gradient(140deg, #431407 0%, #c2410c 100%); }
.fc-result-hero__label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: rgba(255,255,255,.6);
  margin-bottom: 6px;
  position: relative;
  z-index: 1;
}
.fc-result-hero__amount {
  font-family: var(--font-d);
  font-size: clamp(28px, 4vw, 40px);
  font-weight: 800;
  color: #fff;
  letter-spacing: -.02em;
  line-height: 1;
  position: relative;
  z-index: 1;
}
.fc-result-hero__sub {
  font-size: 12px;
  color: rgba(255,255,255,.5);
  margin-top: 6px;
  position: relative;
  z-index: 1;
}

/* ── Metrics grid ───────────────────────────────────────────── */
.fc-metrics {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.fc-metric {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: var(--shadow-sm);
}
.fc-metric__icon {
  width: 36px; height: 36px;
  border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.fc-metric__icon--blue   { background: var(--p-light); color: var(--p); }
.fc-metric__icon--amber  { background: var(--amb-bg); color: var(--amber); }
.fc-metric__icon--green  { background: var(--gr-bg); color: var(--green); }
.fc-metric__icon--purple { background: var(--pur-bg); color: var(--purple); }
.fc-metric__label { font-size: 11px; color: var(--faint); margin-bottom: 2px; }
.fc-metric__val { font-family: var(--mono); font-size: 15px; font-weight: 700; color: var(--text); }

/* ── Breakdown bar ──────────────────────────────────────────── */
.fc-card--breakdown {}
.fc-breakdown-bar {
  display: flex;
  height: 10px;
  border-radius: var(--r-full);
  overflow: hidden;
  background: var(--bg);
  margin-bottom: 14px;
}
.fc-breakdown-bar__principal { background: var(--p); transition: width .4s ease; }
.fc-breakdown-bar__interest  { background: var(--amber); transition: width .4s ease; }
.fc-breakdown-bar__fees      { background: var(--purple); transition: width .4s ease; }
.fc-breakdown-legend { display: flex; flex-direction: column; gap: 6px; }
.fc-legend-item {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; color: var(--muted);
}
.fc-legend-dot {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.fc-legend-dot--principal { background: var(--p); }
.fc-legend-dot--interest  { background: var(--amber); }
.fc-legend-dot--fees      { background: var(--purple); }
.fc-legend-label { flex: 1; }
.fc-legend-val { font-family: var(--mono); font-weight: 600; color: var(--text); }

/* ── Amortisation ───────────────────────────────────────────── */
.fc-amort-toggle {
  margin-left: auto;
  font-size: 11px;
  font-weight: 600;
  color: var(--p);
  background: none;
  border: none;
  cursor: pointer;
  font-family: var(--sans);
  padding: 0;
  display: flex;
  align-items: center;
  gap: 4px;
}
.fc-amort-wrap {
  max-height: 240px;
  overflow-y: auto;
  transition: max-height .35s ease;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.fc-amort-wrap.fc-amort--expanded { max-height: 600px; }
.fc-amort-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.fc-amort-table thead th {
  position: sticky; top: 0;
  background: #f8faff;
  padding: 8px 10px;
  text-align: right;
  font-weight: 700;
  color: var(--faint);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .05em;
  border-bottom: 1px solid var(--border);
}
.fc-amort-table thead th:first-child { text-align: left; }
.fc-amort-table tbody td {
  padding: 7px 10px;
  text-align: right;
  border-bottom: 1px solid var(--border);
  font-family: var(--mono);
  color: var(--text);
}
.fc-amort-table tbody td:first-child { text-align: left; color: var(--faint); }
.fc-amort-table tbody tr:last-child td { border-bottom: none; }
.fc-amort-table tbody tr:nth-child(even) { background: #fafcff; }
.fc-amort-table tbody tr.fc-amort-row--year {
  background: var(--p-light);
  font-weight: 600;
}
.fc-download-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-top: 14px;
  padding: 9px 18px;
  background: none;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  font-family: var(--sans);
  transition: all .18s;
}
.fc-download-btn:hover { border-color: var(--p); color: var(--p); background: var(--p-light); }

/* ── CTA ────────────────────────────────────────────────────── */
.fc-calc-btn {
  width: 100%;
  padding: 12px;
  background: var(--p);
  color: #fff;
  border: none;
  border-radius: var(--r-md);
  font-size: 14px;
  font-weight: 700;
  font-family: var(--sans);
  cursor: pointer;
  transition: background .18s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 6px;
}
.fc-calc-btn:hover { background: var(--p-dark); }
.fc-browse-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 13px 20px;
  background: var(--p);
  color: #fff;
  border-radius: var(--r-lg);
  font-size: 13px;
  font-weight: 700;
  font-family: var(--sans);
  text-decoration: none;
  transition: background .18s;
  box-shadow: 0 4px 14px rgba(15,76,158,.2);
}
.fc-browse-cta:hover { background: var(--p-dark); text-decoration: none; color: #fff; }

/* ── Affordability gauge ────────────────────────────────────── */
.fc-aff-gauge-card {}
.fc-gauge-wrap {
  display: flex;
  justify-content: center;
  margin: -4px 0 10px;
}
.fc-gauge-svg { width: 200px; }
.fc-gauge-pct {
  font-family: var(--font-d);
  font-size: 22px;
  font-weight: 800;
  fill: var(--text);
}
.fc-gauge-lbl { font-size: 9px; fill: var(--faint); text-transform: uppercase; letter-spacing: .05em; }
.fc-gauge-arc { transition: stroke-dashoffset .5s ease, stroke .5s ease; }
.fc-gauge-bands {
  display: flex;
  justify-content: center;
  gap: 14px;
  flex-wrap: wrap;
}
.fc-band { font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.fc-band i { font-size: 9px; }
.fc-band--green { color: var(--green); }
.fc-band--amber { color: var(--amber); }
.fc-band--red   { color: var(--red); }

/* ── Rate comparison table ──────────────────────────────────── */
.fc-cmp-inputs {
  display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;
}
.fc-cmp-inputs .fc-field { flex: 1; min-width: 200px; }
.fc-cmp-table-wrap { overflow-x: auto; }
.fc-cmp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.fc-cmp-table thead th {
  padding: 10px 14px;
  text-align: right;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--faint);
  background: #f8faff;
  border-bottom: 1px solid var(--border);
}
.fc-cmp-table thead th:first-child { text-align: left; }
.fc-cmp-table tbody td {
  padding: 11px 14px;
  text-align: right;
  border-bottom: 1px solid var(--border);
  font-family: var(--mono);
}
.fc-cmp-table tbody td:first-child { text-align: left; font-weight: 700; }
.fc-cmp-table tbody tr.fc-cmp-row--highlight {
  background: var(--p-light);
}
.fc-cmp-table tbody tr.fc-cmp-row--highlight td { color: var(--p); }
.fc-cmp-table tbody tr:last-child td { border-bottom: none; }
.fc-cmp-diff--pos { color: var(--red); }
.fc-cmp-diff--neg { color: var(--green); }
.fc-cmp-diff--zero { color: var(--faint); }
.fc-footnote { font-size: 11px; color: var(--faint); margin-top: 12px; }

/* ── Running cost bars ──────────────────────────────────────── */
.fc-run-breakdown { display: flex; flex-direction: column; gap: 10px; }
.fc-run-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
}
.fc-run-item__label {
  display: flex;
  align-items: center;
  gap: 7px;
  width: 200px;
  flex-shrink: 0;
  color: var(--muted);
  font-size: 12px;
}
.fc-run-dot {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.fc-run-dot--blue   { background: var(--p); }
.fc-run-dot--amber  { background: var(--amber); }
.fc-run-dot--green  { background: var(--green); }
.fc-run-dot--purple { background: var(--purple); }
.fc-run-dot--red    { background: var(--red); }
.fc-run-item__bar-wrap {
  flex: 1;
  height: 8px;
  background: var(--bg);
  border-radius: var(--r-full);
  overflow: hidden;
}
.fc-run-item__bar {
  height: 100%;
  border-radius: var(--r-full);
  transition: width .4s ease;
}
.fc-run-item__bar--blue   { background: var(--p); }
.fc-run-item__bar--amber  { background: var(--amber); }
.fc-run-item__bar--green  { background: var(--green); }
.fc-run-item__bar--purple { background: var(--purple); }
.fc-run-item__bar--red    { background: var(--red); }
.fc-run-item__val {
  font-family: var(--mono);
  font-weight: 700;
  color: var(--text);
  font-size: 13px;
  width: 80px;
  text-align: right;
  flex-shrink: 0;
}
.fc-run-annual { }
.fc-run-annual-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.fc-run-annual__label { font-size: 11px; color: var(--faint); margin-bottom: 3px; }
.fc-run-annual__val { font-family: var(--font-d); font-size: 18px; font-weight: 700; color: var(--text); }

/* ── Fuel prices ────────────────────────────────────────────── */
.fc-fuel-prices {}
.fc-fuel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fc-fuel-item {}
.fc-fuel-label { font-size: 11px; font-weight: 600; color: var(--faint); display: block; margin-bottom: 5px; }

/* ── Disclaimer ─────────────────────────────────────────────── */
.fc-disclaimer {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  background: #f8faff;
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 16px 18px;
  font-size: 12px;
  color: var(--muted);
  line-height: 1.65;
  margin-top: 32px;
}
.fc-disclaimer i { color: var(--p); margin-top: 2px; flex-shrink: 0; }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 900px) {
  .fc-grid { grid-template-columns: 1fr; }
  .fc-metrics { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .fc-tabs { border-radius: var(--r-lg); }
  .fc-tab span { display: none; }
  .fc-tab { padding: 10px 16px; }
  .fc-metrics { grid-template-columns: 1fr; }
  .fc-run-item__label { width: 140px; }
  .fc-fuel-grid { grid-template-columns: 1fr; }
  .fc-run-annual-grid { grid-template-columns: 1fr; }
  .fc-cmp-inputs { flex-direction: column; }
}
</style>


<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* ── Utilities ────────────────────────────────────────────── */
  var PRIME = 11.25;
  var DEFAULT_LINKED = 13.25;

  function fmt(n) {
    return 'R\u00a0' + Math.round(n).toLocaleString('en-ZA');
  }
  function fmtExact(n, dp) {
    dp = dp || 0;
    return 'R\u00a0' + n.toLocaleString('en-ZA', { minimumFractionDigits: dp, maximumFractionDigits: dp });
  }

  /* Annuity formula: monthly payment on loan P, monthly rate r, n months */
  function monthlyPayment(P, annualRate, n) {
    if (P <= 0) return 0;
    var r = annualRate / 100 / 12;
    if (r === 0) return P / n;
    return P * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
  }

  /* Reverse annuity: maximum loan given monthly budget M */
  function maxLoan(M, annualRate, n) {
    var r = annualRate / 100 / 12;
    if (r === 0) return M * n;
    return M * (1 - Math.pow(1 + r, -n)) / r;
  }


  /* ══════════════════════════════════════════════════════
     TAB SWITCHER
     ══════════════════════════════════════════════════════ */
  document.querySelectorAll('.fc-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-mode');
      document.querySelectorAll('.fc-tab').forEach(function (b) {
        b.classList.remove('fc-tab--active');
      });
      btn.classList.add('fc-tab--active');
      document.querySelectorAll('.fc-panel').forEach(function (p) {
        p.classList.remove('fc-panel--active');
      });
      document.getElementById('panel-' + mode).classList.add('fc-panel--active');

      // Trigger recalc on switch
      if (mode === 'comparison') buildCmpTable();
      if (mode === 'running') calcRunning();
      if (mode === 'affordability') calcAffordability();
    });
  });


  /* ══════════════════════════════════════════════════════
     MODE 1: REPAYMENT CALCULATOR
     ══════════════════════════════════════════════════════ */

  var repInputs = {
    price:    document.getElementById('vehiclePrice'),
    priceSlider: document.getElementById('vehiclePriceSlider'),
    deposit:  document.getElementById('depositAmt'),
    depSlider: document.getElementById('depositSlider'),
    rate:     document.getElementById('interestRate'),
    rateSlider: document.getElementById('interestRateSlider'),
    term:     document.getElementById('loanTerm'),
    balloon:  document.getElementById('balloonPct'),
    initFee:  document.getElementById('initiationFee'),
    monthlyFee: document.getElementById('monthlyFee'),
    vat:      document.getElementById('includeVAT'),
  };

  var amortData = [];

  function getRepValues() {
    var price   = parseFloat(repInputs.price.value)    || 0;
    var dep     = parseFloat(repInputs.deposit.value)   || 0;
    var rate    = parseFloat(repInputs.rate.value)      || DEFAULT_LINKED;
    var n       = parseInt(repInputs.term.value, 10)    || 60;
    var ballPct = parseFloat(repInputs.balloon.value)   || 0;
    var initFee = parseFloat(repInputs.initFee.value)   || 0;
    var svcFee  = parseFloat(repInputs.monthlyFee.value) || 0;
    var useVAT  = repInputs.vat.checked;
    var vatMult = useVAT ? 1.15 : 1;

    dep = Math.min(dep, price);
    var balloon   = price * ballPct / 100;
    var principal = price - dep;       // before balloon
    var loanPrin  = principal - balloon; // amount amortised over term

    var initFeeFinal = initFee * vatMult;
    var svcFeeFinal  = svcFee * vatMult;

    // Finance charge: on loan principal + initiation fee (capitalised)
    var loanWithFee = loanPrin + initFeeFinal;
    var monthlyBase = monthlyPayment(loanWithFee, rate, n);
    var monthlyTotal = monthlyBase + svcFeeFinal;

    var totalPaid     = monthlyBase * n + balloon;
    var totalFees     = initFeeFinal + svcFeeFinal * n;
    var totalInterest = totalPaid - loanWithFee + totalFees - initFeeFinal;
    // Simpler: total repaid = months * monthlyBase + balloon
    var totalRepaid   = monthlyTotal * n + balloon;
    var totalCredit   = totalRepaid;

    return {
      price, dep, rate, n, balloon, ballPct,
      principal, loanPrin, loanWithFee,
      initFee: initFeeFinal, svcFee: svcFeeFinal,
      monthlyBase, monthlyTotal,
      totalPaid, totalFees, totalInterest, totalRepaid, totalCredit,
    };
  }

  function buildAmortTable(v) {
    var rows = [];
    var balance = v.loanWithFee;
    var r = v.rate / 100 / 12;
    for (var i = 1; i <= v.n; i++) {
      var interest  = balance * r;
      var principal = v.monthlyBase - interest;
      balance -= principal;
      if (balance < 0) balance = 0;
      rows.push({ month: i, payment: v.monthlyBase, principal: principal, interest: interest, balance: balance });
    }
    // Balloon row
    if (v.balloon > 0) {
      rows.push({ month: 'Balloon', payment: v.balloon, principal: v.balloon, interest: 0, balance: 0 });
    }
    return rows;
  }

  function renderAmortTable(rows, showAll) {
    var tbody = document.getElementById('amortBody');
    tbody.innerHTML = '';
    var limit = showAll ? rows.length : Math.min(6, rows.length);
    for (var i = 0; i < limit; i++) {
      var row = rows[i];
      var tr = document.createElement('tr');
      var isYearEnd = (typeof row.month === 'number') && (row.month % 12 === 0);
      if (isYearEnd) tr.className = 'fc-amort-row--year';
      tr.innerHTML =
        '<td>' + row.month + '</td>' +
        '<td>' + fmt(row.payment) + '</td>' +
        '<td>' + fmt(row.principal) + '</td>' +
        '<td>' + fmt(row.interest) + '</td>' +
        '<td>' + fmt(row.balance) + '</td>';
      tbody.appendChild(tr);
    }
  }

  function calcRepayment() {
    var v = getRepValues();
    amortData = buildAmortTable(v);

    // Hero
    document.getElementById('heroMonthly').textContent = fmt(v.monthlyTotal);
    document.getElementById('heroSub').textContent = v.balloon > 0
      ? 'excl. balloon of ' + fmt(v.balloon) + ' at end'
      : 'incl. service fee & VAT';

    // Metrics
    document.getElementById('metLoanAmount').textContent  = fmt(v.loanPrin);
    document.getElementById('metTotalInterest').textContent = fmt(v.totalInterest);
    document.getElementById('metTotalCredit').textContent  = fmt(v.totalCredit);
    document.getElementById('metBalloon').textContent      = fmt(v.balloon);

    // Breakdown bar
    var totForBar = v.totalRepaid || 1;
    var pPct = v.loanPrin / totForBar * 100;
    var iPct = v.totalInterest / totForBar * 100;
    var fPct = v.totalFees / totForBar * 100;
    document.getElementById('barPrincipal').style.width = pPct + '%';
    document.getElementById('barInterest').style.width  = iPct + '%';
    document.getElementById('barFees').style.width      = fPct + '%';
    document.getElementById('legendPrincipal').textContent = fmt(v.loanPrin);
    document.getElementById('legendInterest').textContent  = fmt(v.totalInterest);
    document.getElementById('legendFees').textContent      = fmt(v.totalFees);

    // Rate ribbon hint
    document.getElementById('ribbonLinked').textContent = v.rate.toFixed(2) + '%';
    document.getElementById('ribbonPrime').textContent  = PRIME.toFixed(2) + '%';

    // Deposit label
    var depPct = v.price > 0 ? Math.round(v.dep / v.price * 100) : 0;
    document.getElementById('depositPctLabel').textContent =
      depPct + '% — ' + fmt(v.dep);

    // Balloon label & note
    document.getElementById('balloonLabel').textContent = v.ballPct + '% — ' + fmt(v.balloon);
    document.getElementById('balloonNote').style.display = v.balloon > 0 ? 'flex' : 'none';

    // Rate label
    var diff = v.rate - PRIME;
    document.getElementById('interestRateLabel').textContent =
      diff >= 0 ? 'Prime + ' + diff.toFixed(2) + '%' : 'Prime − ' + Math.abs(diff).toFixed(2) + '%';

    // Term label
    document.getElementById('termLabel').textContent = v.n + ' months (' + (v.n / 12).toFixed(1) + ' yrs)';

    // Amort table
    var isExpanded = document.getElementById('amortWrap').classList.contains('fc-amort--expanded');
    renderAmortTable(amortData, isExpanded);
  }

  // ── Slider ↔ input sync ────────────────────────────────────
  function syncRepSliders() {
    var price = parseFloat(repInputs.price.value) || 0;
    var dep   = parseFloat(repInputs.deposit.value) || 0;
    var depSlMax = price * 0.5;

    // price slider ↔ price input
    repInputs.priceSlider.addEventListener('input', function () {
      repInputs.price.value = this.value;
      // Adjust deposit if it exceeds 50% of new price
      var maxDep = parseFloat(this.value) * 0.5;
      if (parseFloat(repInputs.deposit.value) > maxDep) {
        repInputs.deposit.value = Math.round(maxDep);
      }
      calcRepayment();
    });
    repInputs.price.addEventListener('input', function () {
      repInputs.priceSlider.value = Math.min(this.value, 5000000);
      calcRepayment();
    });

    // deposit slider: 0–50% of vehicle price
    repInputs.depSlider.addEventListener('input', function () {
      var p = parseFloat(repInputs.price.value) || 0;
      repInputs.deposit.value = Math.round(p * this.value / 100);
      calcRepayment();
    });
    repInputs.deposit.addEventListener('input', function () {
      var p = parseFloat(repInputs.price.value) || 1;
      repInputs.depSlider.value = Math.min(Math.round(parseFloat(this.value) / p * 100), 100);
      calcRepayment();
    });

    // rate slider
    repInputs.rateSlider.addEventListener('input', function () {
      repInputs.rate.value = this.value;
      calcRepayment();
    });
    repInputs.rate.addEventListener('input', function () {
      repInputs.rateSlider.value = this.value;
      calcRepayment();
    });

    // term slider
    repInputs.term.addEventListener('input', function () {
      // sync term chips
      document.querySelectorAll('#termChips .fc-term-chip').forEach(function (c) {
        c.classList.toggle('fc-term-chip--active', parseInt(c.dataset.months) === parseInt(repInputs.term.value));
      });
      calcRepayment();
    });

    // balloon
    repInputs.balloon.addEventListener('input', calcRepayment);

    // fee & vat
    repInputs.initFee.addEventListener('input', calcRepayment);
    repInputs.monthlyFee.addEventListener('input', calcRepayment);
    repInputs.vat.addEventListener('change', calcRepayment);
  }

  // Term chips (repayment)
  document.querySelectorAll('#termChips .fc-term-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#termChips .fc-term-chip').forEach(function (b) {
        b.classList.remove('fc-term-chip--active');
      });
      btn.classList.add('fc-term-chip--active');
      repInputs.term.value = btn.dataset.months;
      calcRepayment();
    });
  });

  // Amortisation expand/collapse
  var amortExpanded = false;
  document.getElementById('amortToggle').addEventListener('click', function () {
    amortExpanded = !amortExpanded;
    var wrap = document.getElementById('amortWrap');
    wrap.classList.toggle('fc-amort--expanded', amortExpanded);
    this.innerHTML = amortExpanded
      ? 'Hide <i class="fa-solid fa-chevron-up"></i>'
      : 'Show full schedule <i class="fa-solid fa-chevron-down"></i>';
    renderAmortTable(amortData, amortExpanded);
  });

  // Download CSV
  document.getElementById('downloadCSV').addEventListener('click', function () {
    if (!amortData.length) return;
    var csv = 'Month,Payment (R),Principal (R),Interest (R),Balance (R)\n';
    amortData.forEach(function (r) {
      csv += [r.month, Math.round(r.payment), Math.round(r.principal),
              Math.round(r.interest), Math.round(r.balance)].join(',') + '\n';
    });
    var blob = new Blob([csv], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = 'amortisation-schedule.csv'; a.click();
    URL.revokeObjectURL(url);
  });


  /* ══════════════════════════════════════════════════════
     MODE 2: AFFORDABILITY
     ══════════════════════════════════════════════════════ */
  var affTerm = 60;

  document.querySelectorAll('#affTermChips .fc-term-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#affTermChips .fc-term-chip').forEach(function (b) {
        b.classList.remove('fc-term-chip--active');
      });
      btn.classList.add('fc-term-chip--active');
      affTerm = parseInt(btn.dataset.months);
      document.getElementById('aff_term').value = affTerm;
      calcAffordability();
    });
  });

  function calcAffordability() {
    var gross  = parseFloat(document.getElementById('grossIncome').value) || 0;
    var debt   = parseFloat(document.getElementById('existingDebt').value) || 0;
    var target = parseFloat(document.getElementById('targetMonthly').value) || 0;
    var dep    = parseFloat(document.getElementById('aff_deposit').value) || 0;
    var rate   = parseFloat(document.getElementById('aff_rate').value) || DEFAULT_LINKED;
    var n      = affTerm;

    // NCA guideline: total debt obligations ≤ 30% of gross
    var ncaBudget   = gross * 0.30;
    var ncaCar      = Math.max(0, ncaBudget - debt);
    var recomBudget = gross * 0.15;   // more conservative: 15%
    var carPayment  = target > 0 ? target : Math.min(ncaCar, recomBudget);

    // Max loan from monthly budget
    var maxLoanAmt  = maxLoan(carPayment, rate, n);
    var maxPrice    = maxLoanAmt + dep;

    // DTI
    var totalDebt  = debt + carPayment;
    var dti        = gross > 0 ? (totalDebt / gross * 100) : 0;
    var dtiColor   = dti < 25 ? '#22c55e' : dti < 35 ? '#d97706' : '#dc2626';

    // Update outputs
    document.getElementById('affMaxPrice').textContent = fmt(maxPrice);
    document.getElementById('affMaxSub').textContent   = 'at ' + fmt(carPayment) + '/month';
    document.getElementById('affNCABudget').textContent   = fmt(ncaCar);
    document.getElementById('affRecomBudget').textContent = fmt(recomBudget);
    document.getElementById('affDTI').textContent         = dti.toFixed(1) + '%';
    document.getElementById('affSuggestedCar').textContent = fmt(Math.min(maxPrice, dep + maxLoan(recomBudget, rate, n)));

    // Gauge (semicircle arc = 283 units for 180 deg)
    var dashOffset = 283 - (Math.min(dti, 50) / 50 * 283);
    var arc = document.getElementById('gaugeArc');
    arc.style.strokeDashoffset = dashOffset;
    arc.style.stroke = dtiColor;
    document.getElementById('gaugePct').textContent = dti.toFixed(0) + '%';
    document.getElementById('gaugePct').style.fill = dtiColor;

    // Browse link
    var browseLink = document.getElementById('affBrowseLink');
    browseLink.href = '/c/?price_max=' + Math.round(maxPrice);
    browseLink.style.display = 'flex';
  }

  // Trigger on input
  ['grossIncome','existingDebt','targetMonthly','aff_deposit','aff_rate'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', calcAffordability);
  });
  document.getElementById('calcAffordability').addEventListener('click', calcAffordability);


  /* ══════════════════════════════════════════════════════
     MODE 3: RATE COMPARISON
     ══════════════════════════════════════════════════════ */
  var cmpTerm = 60;

  document.querySelectorAll('#cmpTermChips .fc-term-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('#cmpTermChips .fc-term-chip').forEach(function (b) {
        b.classList.remove('fc-term-chip--active');
      });
      btn.classList.add('fc-term-chip--active');
      cmpTerm = parseInt(btn.dataset.months);
      document.getElementById('cmp_term').value = cmpTerm;
      buildCmpTable();
    });
  });

  document.getElementById('cmp_amount').addEventListener('input', buildCmpTable);

  function buildCmpTable() {
    var amount  = parseFloat(document.getElementById('cmp_amount').value) || 360000;
    var n       = cmpTerm;
    var baseRate = DEFAULT_LINKED;

    var rates = [];
    for (var r = 7; r <= 22; r += 0.5) {
      rates.push(parseFloat(r.toFixed(2)));
    }

    var baseMonthly = monthlyPayment(amount, baseRate, n);
    var baseTotalInterest = baseMonthly * n - amount;

    var tbody = document.getElementById('cmpBody');
    tbody.innerHTML = '';

    rates.forEach(function (rate) {
      var pm      = monthlyPayment(amount, rate, n);
      var total   = pm * n;
      var interst = total - amount;
      var diff    = pm - baseMonthly;
      var isBase  = Math.abs(rate - baseRate) < 0.01;

      var tr = document.createElement('tr');
      if (isBase) tr.className = 'fc-cmp-row--highlight';

      var diffStr;
      if (isBase) {
        diffStr = '<span class="fc-cmp-diff--zero">baseline</span>';
      } else if (diff > 0) {
        diffStr = '<span class="fc-cmp-diff--pos">+' + fmt(diff) + '/mo</span>';
      } else {
        diffStr = '<span class="fc-cmp-diff--neg">' + fmt(diff) + '/mo</span>';
      }

      var primeDiff = rate - PRIME;
      var rateLabel = primeDiff >= 0
        ? rate.toFixed(2) + '% (P+' + primeDiff.toFixed(2) + ')'
        : rate.toFixed(2) + '% (P−' + Math.abs(primeDiff).toFixed(2) + ')';

      tr.innerHTML =
        '<td>' + rateLabel + '</td>' +
        '<td>' + fmt(pm) + '</td>' +
        '<td>' + fmt(interst) + '</td>' +
        '<td>' + fmt(total) + '</td>' +
        '<td>' + diffStr + '</td>';
      tbody.appendChild(tr);
    });
  }


  /* ══════════════════════════════════════════════════════
     MODE 4: RUNNING COSTS
     ══════════════════════════════════════════════════════ */
  function fuelPriceForType(type) {
    var map = { petrol_95: 'fp_95', petrol_93: 'fp_93', diesel: 'fp_diesel', electric: 'fp_elec' };
    return parseFloat(document.getElementById(map[type]).value) || 0;
  }

  function calcRunning() {
    var price    = parseFloat(document.getElementById('run_price').value) || 0;
    var km       = parseFloat(document.getElementById('run_km').value) || 1500;
    var fuelType = document.getElementById('run_fuel_type').value;
    var cons     = parseFloat(document.getElementById('run_consumption').value) || 7.5;
    var svcIntvl = parseInt(document.getElementById('run_service_interval').value, 10) || 15000;
    var svcCost  = parseFloat(document.getElementById('run_service_cost').value) || 4500;
    var insurance = parseFloat(document.getElementById('run_insurance').value) || 1200;
    var tyresCost = parseFloat(document.getElementById('run_tyres').value) || 8000;

    // Finance: use current repayment calc values (price, 20% dep, default rate/term)
    var dep      = price * 0.20;
    var loanAmt  = price - dep;
    var finance  = monthlyPayment(loanAmt, DEFAULT_LINKED, 60);

    // Fuel: L/100km × km/month × price/L (or kWh for EV)
    var fuelPrice = fuelPriceForType(fuelType);
    var fuelMonthlyCost = (cons / 100) * km * fuelPrice;

    // Service: cost / (interval km / monthly km)
    var svcMonths = svcIntvl / km;
    var maintMonthly = svcCost / svcMonths;

    // Tyres: assume 1 set per 40k km
    var tyreMonthly = (tyresCost / 40000) * km;

    var total = finance + fuelMonthlyCost + insurance + maintMonthly + tyreMonthly;

    document.getElementById('runTotalMonthly').textContent = fmt(total);

    // Bar widths
    function bar(id, val) {
      var pct = total > 0 ? (val / total * 100) : 0;
      document.getElementById('runBar' + id).style.width = pct + '%';
      document.getElementById('runVal' + id).textContent = fmt(val);
    }
    bar('Finance', finance);
    bar('Fuel',    fuelMonthlyCost);
    bar('Ins',     insurance);
    bar('Maint',   maintMonthly);
    bar('Tyres',   tyreMonthly);

    // Annual
    document.getElementById('runAnnualTotal').textContent = fmt(total * 12);
    document.getElementById('runAnnualFuel').textContent  = fmt(fuelMonthlyCost * 12);
    var annualKm = km * 12;
    document.getElementById('runCostPerKm').textContent   =
      'R\u00a0' + (total / km).toFixed(2);
    document.getElementById('runAnnualKm').textContent    =
      annualKm.toLocaleString('en-ZA') + ' km';

    // Slider labels
    document.getElementById('runKmLabel').textContent  = km.toLocaleString('en-ZA') + ' km/month';
    document.getElementById('runConsLabel').textContent = cons + (fuelType === 'electric' ? ' kWh/100km' : ' L/100km');
  }

  var runIds = ['run_price','run_km','run_fuel_type','run_consumption',
                'run_service_interval','run_service_cost','run_insurance',
                'run_tyres','fp_95','fp_93','fp_diesel','fp_elec'];
  runIds.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', calcRunning);
    if (el) el.addEventListener('change', calcRunning);
  });


  /* ── Initial renders ──────────────────────────────────────── */
  syncRepSliders();
  calcRepayment();
  calcAffordability();
  buildCmpTable();
  calcRunning();

})();
</script>

<?php
$pageContent = ob_get_clean();
$layoutVariant = 'wide';
require_once '../../views/layout-public.php';