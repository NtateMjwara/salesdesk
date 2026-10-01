<?php
/**
 * Admin partial — pick the 1–3 brands a desk organisation sells.  (0013)
 *
 * Expects:
 *   array  $selectedBrands  current picks (may be empty)
 *   string $brandIdPrefix   unique id prefix for the <select>s
 *
 * Posts brands[] (up to SD_ORG_MAX_BRANDS values); validated by
 * adminParseOrgBrands() in includes/admin_scope.php.
 */
$selectedBrands = array_values($selectedBrands ?? []);
$brandIdPrefix  = $brandIdPrefix ?? 'brand';
$allMakes       = sdCarMakes();
?>
<fieldset class="adm-brands">
  <legend class="flabel">Brands this organisation sells <span class="flabel-opt">1 required, up to <?= SD_ORG_MAX_BRANDS ?></span></legend>
  <div class="adm-brands-row">
    <?php for ($i = 0; $i < SD_ORG_MAX_BRANDS; $i++):
      $cur = $selectedBrands[$i] ?? ''; ?>
    <select class="finput" name="brands[]" id="<?= htmlspecialchars($brandIdPrefix . '_' . $i) ?>"
            aria-label="Brand <?= $i + 1 ?>" <?= $i === 0 ? 'required' : '' ?>>
      <option value=""><?= $i === 0 ? 'Choose a brand…' : '— none —' ?></option>
      <?php foreach ($allMakes as $mk): ?>
      <option value="<?= htmlspecialchars($mk) ?>" <?= $cur === $mk ? 'selected' : '' ?>><?= htmlspecialchars($mk) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endfor; ?>
  </div>
  <p class="adm-sub">Approved agents can only add cars of these brands to their desks.</p>
</fieldset>
