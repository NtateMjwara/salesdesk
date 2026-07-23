<?php
/**
 * SalesDesk — schema.org Vehicle markup parser.
 *
 * Pure extraction: given one page's raw HTML, finds and returns any
 * schema.org Vehicle records embedded as JSON-LD
 * (<script type="application/ld+json">) blocks, or as itemscope/itemprop
 * microdata as a fallback.
 *
 * DELIBERATELY NARROW SCOPE FOR PHASE 1 (see WebsiteImporter.php):
 *   - JSON-LD is the primary path — it's what Google's structured-data
 *     guidelines recommend, so it's what most dealer sites/SEO agencies
 *     actually implement when they mark up inventory at all.
 *   - Microdata is a best-effort fallback for older dealer CMS templates
 *     that still emit itemprop-based markup instead.
 *   - No headless-browser / JS-rendered-DOM support. A site that renders
 *     schema.org markup client-side via JS won't be seen by this parser —
 *     that needs a rendering engine, which is an intentionally separate,
 *     heavier later-phase concern, not something to bolt on here.
 *
 * This class does no I/O — it never fetches a URL. WebsiteImporter owns
 * fetching; this only ever receives an HTML string already in hand. That
 * split keeps this class trivially unit-testable on its own.
 */
class SchemaOrgVehicleParser
{
    /**
     * @param string $html Raw HTML of one page.
     * @return array<int, array<string, mixed>> Zero or more raw schema.org
     *         nodes found on this page, one per detected vehicle. Keys are
     *         schema.org property names as found — WebsiteImporter maps
     *         these into canonical `cars` columns.
     */
    public function extract(string $html): array
    {
        $records = $this->extractJsonLd($html);
        if (!empty($records)) {
            return $records;
        }
        // Fall back to microdata only when no JSON-LD vehicle was found at
        // all — a site emitting both would otherwise duplicate every car.
        return $this->extractMicrodata($html);
    }

    // ============================================================
    // JSON-LD
    // ============================================================

    private function extractJsonLd(string $html): array
    {
        $found = [];

        if (!preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        )) {
            return $found;
        }

        foreach ($matches[1] as $blockRaw) {
            $block = trim(html_entity_decode($blockRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($block === '') {
                continue;
            }

            $data = json_decode($block, true);
            if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
                // Some templates emit malformed JSON-LD (trailing commas,
                // concatenated objects). Not worth a hand-rolled repair
                // pass for Phase 1 — skip this block; other blocks on this
                // page or other pages are independent.
                continue;
            }

            foreach ($this->flattenGraph($data) as $node) {
                if ($this->looksLikeVehicleNode($node)) {
                    $found[] = $node;
                }
            }
        }

        return $found;
    }

    /**
     * JSON-LD sometimes wraps everything in a top-level @graph array, and
     * a single <script> block can itself be a JSON array of several
     * top-level objects. Normalize both shapes into a flat node list.
     */
    private function flattenGraph(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            $out = [];
            foreach ($data as $item) {
                $out = array_merge($out, $this->flattenGraph($item));
            }
            return $out;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $out = [];
            foreach ($data['@graph'] as $item) {
                $out = array_merge($out, $this->flattenGraph($item));
            }
            return $out;
        }
        return [$data];
    }

    private function looksLikeVehicleNode(array $node): bool
    {
        $type  = $node['@type'] ?? '';
        $types = is_array($type) ? $type : [$type];
        $types = array_map('strtolower', array_map('strval', $types));

        if (array_intersect($types, ['vehicle', 'car', 'motorvehicle', 'automobile'])) {
            return true;
        }

        // Some dealer sites mark listings up as generic Product. Only
        // treat those as vehicles if they carry a vehicle-specific field —
        // otherwise this would match unrelated products on the same site
        // (accessories, parts, service bookings, etc).
        if (in_array('product', $types, true)) {
            return isset($node['vehicleIdentificationNumber'])
                || isset($node['mileageFromOdometer'])
                || isset($node['vehicleModelDate'])
                || isset($node['modelDate'])
                || isset($node['vehicleTransmission']);
        }

        return false;
    }

    // ============================================================
    // MICRODATA (fallback)
    // ============================================================

    /**
     * Best-effort itemprop extraction using bounded-window regex rather
     * than a full DOM walk — enough to catch the common flat-markup case
     * (itemscope on a listing container, itemprop on its direct-ish
     * descendants) that older dealer templates use. Deeply nested
     * itemscope structures (vehicle inside offer inside listing) aren't
     * handled here; a site that needs that falls through to "no records
     * found" and is a candidate for a CMS-specific adapter later rather
     * than a reason to generalize this regex further.
     */
    private function extractMicrodata(string $html): array
    {
        $found = [];

        if (!preg_match_all(
            '#<[^>]+itemscope[^>]*itemtype=["\'][^"\']*schema\.org/(?:Vehicle|Car)["\'][^>]*>#i',
            $html,
            $blocks,
            PREG_OFFSET_CAPTURE
        )) {
            return $found;
        }

        $starts = array_map(fn($b) => $b[1], $blocks[0]);
        $starts[] = strlen($html);

        foreach ($blocks[0] as $i => $blockMatch) {
            $start  = $blockMatch[1];
            $end    = min($starts[$i + 1] ?? strlen($html), $start + 20000);
            $window = substr($html, $start, $end - $start);

            $node = [];
            if (preg_match_all(
                '#itemprop=["\']([a-zA-Z]+)["\'][^>]*?(?:content=["\']([^"\']*)["\']|>([^<]*)<)#s',
                $window,
                $props,
                PREG_SET_ORDER
            )) {
                foreach ($props as $p) {
                    $name  = $p[1];
                    $value = trim($p[2] !== '' ? $p[2] : ($p[3] ?? ''));
                    if ($value !== '' && !isset($node[$name])) {
                        $node[$name] = $value;
                    }
                }
            }

            if (!empty($node)) {
                $node['@type'] = 'Vehicle';
                $found[] = $node;
            }
        }

        return $found;
    }
}
