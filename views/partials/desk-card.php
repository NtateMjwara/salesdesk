<?php
/**
 * SalesDesk — Directory cards  (desk, organisation, independent)
 *
 * Used by /desks/ and the org hubs (/desks/{org-slug}/,
 * /desks/independent/). All three share the .dk-card look from
 * assets/css/desks.css §3 — the whole card is one link (stretched
 * ::after on .dk-card__link), so nothing else inside is interactive.
 *
 *   sdDeskCard($desk, $previews, ['heading' => 'h2', 'show_org' => true])
 *   sdOrgCard($org, $previews, ['heading' => 'h3'])
 *   sdIndependentCard($stats, $previews, ['heading' => 'h3'])
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/desk-directory.php';

if (!function_exists('sdCardEsc')) {
    function sdCardEsc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

/** Three-photo strip; empty slots show a car glyph. */
function sdCardPreviewStrip(array $previews): string
{
    $html = '<div class="dk-card__preview" aria-hidden="true">';
    foreach (array_slice($previews, 0, 3) as $pv) {
        $html .= '<span class="dk-card__thumb"><img src="' . sdCardEsc($pv['src'])
               . '" alt="" width="180" height="135" loading="lazy"></span>';
    }
    for ($i = min(3, count($previews)); $i < 3; $i++) {
        $html .= '<span class="dk-card__thumb dk-card__thumb--empty"><i class="fa-solid fa-car-side"></i></span>';
    }
    return $html . '</div>';
}

function sdCardHeading(string $tag): string
{
    return in_array($tag, ['h2', 'h3', 'h4'], true) ? $tag : 'h2';
}

/** One broker desk. $desk is a row from sdDeskListing(). */
function sdDeskCard(array $desk, array $previews = [], array $opts = []): string
{
    $e        = 'sdCardEsc';
    $h        = sdCardHeading($opts['heading'] ?? 'h2');
    $showOrg  = $opts['show_org'] ?? true;

    $brokerName = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? '')) ?: $desk['display_name'];
    $initials   = strtoupper(substr($desk['first_name'] ?? '', 0, 1) . substr($desk['last_name'] ?? '', 0, 1)) ?: 'SD';
    $location   = implode(', ', array_filter([$desk['city'] ?? null, $desk['province'] ?? null]));
    $deskUrl    = '/' . rawurlencode($desk['slug']) . '/';
    $carsCount  = (int) ($desk['cars_count'] ?? 0);
    $hasOrg     = $showOrg && !empty($desk['org_name']);
    $orgOk      = ($desk['org_verification'] ?? null) === 'verified';

    ob_start(); ?>
    <article class="dk-card pub-reveal">
      <div class="dk-card__head">
        <span class="dk-avatar">
          <?php if (!empty($desk['avatar_url'])): ?>
          <img src="<?= $e($desk['avatar_url']) ?>" alt="" width="56" height="56" loading="lazy">
          <?php elseif (!empty($desk['logo_url'])): ?>
          <img src="<?= $e($desk['logo_url']) ?>" alt="" width="56" height="56" loading="lazy">
          <?php else: ?>
          <?= $e($initials) ?>
          <?php endif; ?>
        </span>

        <span class="dk-card__id">
          <<?= $h ?> class="dk-card__name"><a class="dk-card__link" href="<?= $e($deskUrl) ?>"><?= $e($desk['display_name']) ?></a></<?= $h ?>>
          <span class="dk-card__broker"><?= $e($brokerName) ?></span>
          <?php if ($location): ?>
          <span class="dk-card__loc"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($location) ?></span>
          <?php endif; ?>
        </span>
      </div>

      <?php if ($hasOrg || !empty($desk['tagline'])): ?>
      <div class="dk-card__meta">
        <?php if ($hasOrg): ?>
        <span class="pub-badge <?= $orgOk ? 'pub-badge-verified' : 'pub-badge-desk' ?>"><i class="fa-solid <?= $orgOk ? 'fa-circle-check' : 'fa-building' ?>" aria-hidden="true"></i> <?= $e($desk['org_name']) ?></span>
        <?php endif; ?>
        <?php if (!empty($desk['tagline'])): ?>
        <p class="dk-card__tagline"><?= $e(mb_strimwidth($desk['tagline'], 0, 90, '…')) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?= sdCardPreviewStrip($previews) ?>

      <div class="dk-card__stats">
        <span><strong><?= $carsCount ?></strong> car<?= $carsCount === 1 ? '' : 's' ?></span>
        <span><strong><?= (int) ($desk['deals_closed'] ?? 0) ?></strong> closed</span>
        <span><strong><?= $e(sdCompactNumber((int) ($desk['total_views'] ?? 0))) ?></strong> views</span>
        <span class="dk-card__cta">Visit desk <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

/** One desk organisation. $org is a row from sdOrgDirectory(). */
function sdOrgCard(array $org, array $previews = [], array $opts = []): string
{
    $e        = 'sdCardEsc';
    $h        = sdCardHeading($opts['heading'] ?? 'h3');
    $desks    = (int) ($org['desk_count'] ?? 0);
    $cars     = (int) ($org['car_count'] ?? 0);
    $provs    = (int) ($org['province_count'] ?? 0);
    $verified = ($org['verification_status'] ?? '') === 'verified';
    $initials = strtoupper(implode('', array_map(
        static fn($w) => mb_substr($w, 0, 1),
        array_slice(preg_split('/\s+/', trim((string) $org['name'])) ?: [], 0, 2)
    ))) ?: 'SD';
    $location = implode(', ', array_filter([$org['city'] ?? null, $org['province'] ?? null]));

    ob_start(); ?>
    <article class="dk-card dk-card--org pub-reveal">
      <div class="dk-card__head">
        <span class="dk-avatar dk-avatar--org">
          <?php if (!empty($org['logo_url'])): ?>
          <img src="<?= $e($org['logo_url']) ?>" alt="" width="56" height="56" loading="lazy">
          <?php else: ?>
          <?= $e($initials) ?>
          <?php endif; ?>
        </span>

        <span class="dk-card__id">
          <<?= $h ?> class="dk-card__name"><a class="dk-card__link" href="<?= $e(sdOrgHubPath($org)) ?>"><?= $e($org['name']) ?></a></<?= $h ?>>
          <span class="dk-card__broker">Desk organisation<?= $verified ? ' · Verified' : '' ?></span>
          <?php if ($location): ?>
          <span class="dk-card__loc"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($location) ?></span>
          <?php endif; ?>
        </span>
      </div>

      <?php if (!empty($org['brand_list'])): ?>
      <div class="dk-card__meta dk-card__brands">
        <?php foreach ($org['brand_list'] as $brand): ?>
        <span class="pub-badge pub-badge-desk"><?= $e($brand) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?= sdCardPreviewStrip($previews) ?>

      <div class="dk-card__stats">
        <span><strong><?= $desks ?></strong> desk<?= $desks === 1 ? '' : 's' ?></span>
        <span><strong><?= $e(sdCompactNumber($cars)) ?></strong> car<?= $cars === 1 ? '' : 's' ?></span>
        <?php if ($provs > 1): ?>
        <span><strong><?= $provs ?></strong> provinces</span>
        <?php endif; ?>
        <span class="dk-card__cta">View desks <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

/** The "Independent brokers" tile. $stats from sdIndependentStats(). */
function sdIndependentCard(array $stats, array $opts = []): string
{
    $e     = 'sdCardEsc';
    $h     = sdCardHeading($opts['heading'] ?? 'h3');
    $desks = (int) ($stats['desk_count'] ?? 0);
    $cars  = (int) ($stats['car_count'] ?? 0);

    ob_start(); ?>
    <article class="dk-card dk-card--org dk-card--independent pub-reveal">
      <div class="dk-card__head">
        <span class="dk-avatar dk-avatar--org"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
        <span class="dk-card__id">
          <<?= $h ?> class="dk-card__name"><a class="dk-card__link" href="<?= $e(sdOrgHubPath(null)) ?>"><?= $e(SD_INDEPENDENT_LABEL) ?></a></<?= $h ?>>
          <span class="dk-card__broker">Brokers who run their own desk, any brand</span>
        </span>
      </div>

      <div class="dk-card__meta">
        <p class="dk-card__tagline">Not tied to an organisation — every make on SalesDesk, one broker to deal with.</p>
      </div>

      <div class="dk-card__stats">
        <span><strong><?= $desks ?></strong> desk<?= $desks === 1 ? '' : 's' ?></span>
        <span><strong><?= $e(sdCompactNumber($cars)) ?></strong> car<?= $cars === 1 ? '' : 's' ?></span>
        <span class="dk-card__cta">View desks <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

/**
 * Pagination for directory pages. $urlFor(int $page): string.
 */
function sdDirectoryPagination(int $page, int $totalPages, callable $urlFor): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $e = 'sdCardEsc';
    ob_start(); ?>
  <nav class="pagination" aria-label="Pages">
    <?php if ($page > 1): ?>
    <a class="pagination__page pagination__page--nav" rel="prev" href="<?= $e($urlFor($page - 1)) ?>">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="pagination__label">Previous</span>
    </a>
    <?php endif; ?>
    <?php
    $prev = null;
    for ($i = 1; $i <= $totalPages; $i++):
        if (!($i === 1 || $i === $totalPages || abs($i - $page) <= 1)) continue;
        if ($prev !== null && $i - $prev > 1): ?>
    <span class="pagination__ellipsis" aria-hidden="true">…</span>
        <?php endif; ?>
    <a class="pagination__page <?= $i === $page ? 'active' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>
       href="<?= $e($urlFor($i)) ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
    <?php $prev = $i; endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a class="pagination__page pagination__page--nav" rel="next" href="<?= $e($urlFor($page + 1)) ?>">
      <span class="pagination__label">Next</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </nav>
    <?php
    return ob_get_clean();
}
