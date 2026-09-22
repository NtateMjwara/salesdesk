<?php
/**
 * SalesDesk — Hero Search Card  (v4)
 * views/partials/hero-search-widget.php
 *
 * Included from index.php inside the hero.
 *
 * v4 (public UX/UI overhaul):
 *   – Inline <style> (≈500 lines) and <script> (≈420 lines) REMOVED.
 *     Styles: assets/css/hero-search.css. Behaviour: assets/js/home.js.
 *   – Now a real <form method="get" action="/cars-for-sale/">: works with
 *     JavaScript disabled, and native <select>s give phones their own
 *     pickers instead of custom popovers.
 *   – Make list comes from live inventory ($makeCounts) with counts,
 *     replacing the hardcoded MAKES array that could offer makes with
 *     zero cars.
 *   – Keyword field uses the shared typeahead (/api/cars/suggest.php).
 *   – Live "Show N cars" count kept (home.js → /api/cars/search.php).
 *
 * Expects from index.php scope:
 *   int   $totalCars
 *   array $makeCounts   [make => count]
 */

$hsMakes     = $makeCounts ?? [];
$hsProvinces = ['Gauteng', 'Western Cape', 'KwaZulu-Natal', 'Eastern Cape', 'Limpopo',
                'Mpumalanga', 'North West', 'Free State', 'Northern Cape'];
$hsPrices    = [50000, 100000, 150000, 200000, 250000, 300000, 400000, 500000,
                600000, 750000, 1000000, 1500000, 2000000];
$hsBodies    = ['SUV', 'Bakkie', 'Hatchback', 'Sedan', 'Crossover', 'MPV'];
$hsFuels     = ['Petrol', 'Diesel', 'Hybrid', 'Electric'];
$hsYearTop   = (int) date('Y') + 1;

$hsPriceLabel = static function (int $p): string {
    return $p >= 1000000
        ? 'R' . rtrim(rtrim(number_format($p / 1000000, 1), '0'), '.') . 'm'
        : 'R' . number_format($p / 1000) . 'k';
};
?>
<form class="hs" id="heroSearch" action="/cars-for-sale/" method="get" role="search"
      aria-labelledby="hsTitle" data-total="<?= (int) $totalCars ?>">

  <div class="hs__head">
    <h2 class="hs__title" id="hsTitle">Find your next car</h2>
    <div class="pub-segment hs__cond" role="radiogroup" aria-label="Condition">
      <?php foreach (['' => 'All', 'used' => 'Used', 'new' => 'New', 'demo' => 'Demo'] as $val => $label): ?>
      <label class="pub-segment__opt">
        <input type="radio" name="condition" value="<?= $val ?>" <?= $val === '' ? 'checked' : '' ?>>
        <span><?= $label ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="hs__field">
    <label class="hs__label" for="hq">Keyword</label>
    <div class="hs__control hs__control--q">
      <i class="fa-solid fa-magnifying-glass hs__icon" aria-hidden="true"></i>
      <input class="hs__input" id="hq" name="q" type="search" autocomplete="off" enterkeyhint="search"
             placeholder="Try “Hilux” or “Polo Vivo”" data-typeahead-box="hqBox">
      <div id="hqBox" class="typeahead-box" role="listbox" aria-label="Suggestions"></div>
    </div>
  </div>

  <div class="hs__grid">
    <div class="hs__field">
      <label class="hs__label" for="hMake">Make</label>
      <select class="hs__input hs__select" id="hMake" name="make">
        <option value="">Any make</option>
        <?php foreach ($hsMakes as $mk => $cnt): ?>
        <option value="<?= htmlspecialchars((string) $mk) ?>"><?= htmlspecialchars((string) $mk) ?> (<?= (int) $cnt ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hs__field">
      <label class="hs__label" for="hProvince">Province</label>
      <select class="hs__input hs__select" id="hProvince" name="province">
        <option value="">All of SA</option>
        <?php foreach ($hsProvinces as $pv): ?>
        <option value="<?= htmlspecialchars($pv) ?>"><?= htmlspecialchars($pv) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hs__field">
      <label class="hs__label" for="hMin">Min price</label>
      <select class="hs__input hs__select" id="hMin" name="price_min">
        <option value="">No min</option>
        <?php foreach ($hsPrices as $p): ?>
        <option value="<?= $p ?>"><?= $hsPriceLabel($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hs__field">
      <label class="hs__label" for="hMax">Max price</label>
      <select class="hs__input hs__select" id="hMax" name="price_max">
        <option value="">No max</option>
        <?php foreach ($hsPrices as $p): ?>
        <option value="<?= $p ?>"><?= $hsPriceLabel($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <details class="hs__more">
    <summary class="hs__more-toggle">
      <i class="fa-solid fa-sliders" aria-hidden="true"></i>
      More filters
      <span class="hs__more-count" data-hs-more-count hidden></span>
      <i class="fa-solid fa-chevron-down hs__more-chev" aria-hidden="true"></i>
    </summary>

    <div class="hs__more-body">
      <fieldset class="hs__set">
        <legend class="hs__label">Body type</legend>
        <div class="pub-chip-row">
          <?php foreach ($hsBodies as $b): ?>
          <label class="pub-chip hs__chip">
            <input class="sr-only" type="checkbox" name="body_type[]" value="<?= $b ?>">
            <?= $b ?>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="hs__set">
        <legend class="hs__label">Fuel</legend>
        <div class="pub-chip-row">
          <?php foreach ($hsFuels as $f): ?>
          <label class="pub-chip hs__chip">
            <input class="sr-only" type="checkbox" name="fuel_type[]" value="<?= $f ?>">
            <?= $f ?>
          </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="hs__grid">
        <div class="hs__field">
          <label class="hs__label" for="hYearMin">Year from</label>
          <select class="hs__input hs__select" id="hYearMin" name="year_min">
            <option value="">Any</option>
            <?php for ($y = $hsYearTop; $y >= 2000; $y--): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="hs__field">
          <label class="hs__label" for="hYearMax">Year to</label>
          <select class="hs__input hs__select" id="hYearMax" name="year_max">
            <option value="">Any</option>
            <?php for ($y = $hsYearTop; $y >= 2000; $y--): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>
  </details>

  <button class="pub-btn pub-btn-accent pub-btn-lg pub-btn-full hs__submit" type="submit">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    <span data-hs-label><?= $totalCars > 0 ? 'Show ' . number_format($totalCars) . ' cars' : 'Search cars' ?></span>
  </button>

  <div class="hs__foot">
    <button class="hs__reset" type="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Clear</button>
    <span class="hs__note"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Verified dealers only</span>
  </div>
</form>
