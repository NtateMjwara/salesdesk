<?php
/**
 * SalesDesk — Motus/Mastercars showroom platform parser.
 *
 * A "known-CMS adapter" (see WebsiteImporter's docblock, item 2's "Phase
 * 2+" note) for the showroom template used across Motus Group's dealer-
 * brand websites — confirmed against motusvw.co.za; likely shared by
 * other motus*.co.za brand sites and Motus's "Mastercars" used-vehicle
 * network, since they share the same image CDN (stock-images.cdn.motus.cars)
 * and page template. NOT a general solution for arbitrary dealer sites —
 * do not widen this beyond the Motus network without re-verifying the
 * label vocabulary against a real page first.
 *
 * WHY THIS EXISTS:
 *   This platform's vehicle detail pages carry no schema.org Vehicle
 *   markup and no useful microdata — SchemaOrgVehicleParser finds nothing
 *   on them at all. But the rendered page has a very consistent,
 *   heading-driven label/value structure: a heading element containing a
 *   known label (e.g. "Model:", "Mileage:") is immediately followed by an
 *   element containing just that field's value, and a later "Additional
 *   Specifications" section groups further label/value pairs under
 *   sub-headings (Comfort, Measurements, Performance, Styling).
 *
 * ROBUSTNESS CAVEAT — READ BEFORE TRUSTING THIS IN PRODUCTION:
 *   This was built from the RENDERED TEXT STRUCTURE of one real page,
 *   fetched twice through two different content-extraction pipelines with
 *   identical results — not from inspection of the page's actual raw
 *   HTML/CSS. The tooling available at the time could not retrieve raw
 *   markup to verify real tag names, classes, or DOM nesting. The label-
 *   matching strategy below is deliberately selector-agnostic (it matches
 *   by the TEXT CONTENT of any heading-like element — h1–h6, dt, strong,
 *   b — rather than a specific tag or class) specifically to reduce that
 *   risk, and nextValueText()/sectionText() are written to tolerate
 *   several plausible sibling-structure shapes rather than assuming one.
 *   Even so: if this comes back with zero records on a real crawl of a
 *   Motus-network site, the fix is to capture the ACTUAL HTML of one
 *   vehicle page (View Source, or Save Page As) and hand it back — the
 *   heading traversal can then be corrected against real markup instead
 *   of guessed further. Don't assume silence means the site has no
 *   inventory; check for that failure mode first.
 *
 * OUTPUT SHAPE: matches SchemaOrgVehicleParser's raw node shape (the same
 * schema.org-ish vocabulary — brand, model, vehicleModelDate, offers.price,
 * mileageFromOdometer, color, vehicleTransmission, fuelType, bodyType,
 * driveWheelConfiguration, sku, image, description — plus a few extra
 * keys for fields schema.org's Vehicle type doesn't cover well, which
 * WebsiteImporter::parseVehicle() now also reads: engineCapacityRaw,
 * cylinders, powerKwRaw, gearsRaw, co2EmissionsRaw, doors, seats). This
 * means WebsiteImporter's extract() → parseVehicle() pipeline needs no
 * further changes to consume this parser's output — it's purely an
 * alternative EXTRACTION strategy, not a new mapping layer.
 */
class MotusShowroomParser
{
    /** Top-level label => canonical schema.org-ish node key. */
    private const TOP_LEVEL_LABELS = [
        'model'        => 'model',
        'model year'   => 'vehicleModelDate',
        'mileage'      => 'mileageFromOdometer',
        'colour'       => 'color',
        'color'        => 'color',
        'body type'    => 'bodyType',
        'transmission' => 'vehicleTransmission',
        'fuel type'    => 'fuelType',
        'stock number' => 'sku',
    ];

    /** "Additional Specifications" subsection label => canonical node key. */
    private const SPEC_LABELS = [
        'seats'                => 'seats',
        'doors'                => 'doors',
        'engine capacity'      => 'engineCapacityRaw', // e.g. "1398 cc"
        'power output'         => 'powerKwRaw',         // e.g. "55 kW"
        'co2'                  => 'co2EmissionsRaw',    // e.g. "132"
        'cylinders'            => 'cylinders',
        'driven wheels'        => 'driveWheelConfiguration', // Front/Rear/All
        'gear ratios quantity' => 'gearsRaw',
    ];

    /**
     * The known sub-group headings under "Additional Specifications".
     * Content under each of these renders as single-line "Label: Value"
     * text (e.g. "CO2: 132") rather than as separate heading+value
     * elements the way the top-level fields do — so these are handled by
     * a distinct code path (see parseLabelValueLines()) rather than
     * nextValueText(). Matching by this explicit list, rather than "any
     * heading seen after Additional Specifications", avoids accidentally
     * scanning unrelated later sections (Dealership Information, address
     * blocks, etc.) for stray label-shaped text.
     */
    private const SPEC_SUBSECTIONS = ['comfort', 'measurements', 'performance', 'styling'];

    /** Longer free-text sections, keyed by their heading text. */
    private const SECTION_HEADINGS = [
        'additional information' => 'featuresText',
        "dealer's comments"      => 'description',
        'dealers comments'       => 'description',
    ];

    private const HEADING_XPATH = '//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //dt | //strong | //b';

    /**
     * @param string $html Raw HTML of one vehicle detail page.
     * @return array<int, array<string, mixed>> Zero or one raw node — this
     *         platform's detail pages carry exactly one vehicle each.
     *         WebsiteImporter's crawl already visits each detail page
     *         individually (via sitemap or BFS discovery), so this parser
     *         deliberately does not attempt to handle listing pages with
     *         several vehicles on one page.
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $prevErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // @ suppresses libxml warnings on real-world malformed HTML — same
        // defensive pattern WebsiteImporter already uses for sitemap XML.
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_use_internal_errors($prevErrors);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $headingNodes = $xpath->query(self::HEADING_XPATH);
        if ($headingNodes === false || $headingNodes->length === 0) {
            return [];
        }

        $node           = [];
        $inSpecsSection = false;

        foreach ($headingNodes as $el) {
            $label = $this->normalizeLabel($el->textContent ?? '');
            if ($label === '') {
                continue;
            }

            if ($label === 'additional specifications') {
                $inSpecsSection = true;
                continue;
            }

            if (isset(self::SECTION_HEADINGS[$label])) {
                $text = $this->sectionText($el);
                if ($text !== '') {
                    $node[self::SECTION_HEADINGS[$label]] = $text;
                }
                continue;
            }

            // Spec sub-group headings (Comfort/Measurements/Performance/
            // Styling): their content is single-line "Label: Value" text
            // (e.g. "CO2: 132"), not a separate heading+value pair the way
            // the top-level fields render — so this is handled by a
            // distinct path (parseLabelValueLines over the whole block),
            // not nextValueText(). Gated on the explicit subsection-name
            // list rather than "any heading once inSpecsSection is true"
            // so later, unrelated sections (Dealership Information, an
            // address block, etc.) never get scanned for stray label-
            // shaped text.
            if ($inSpecsSection && in_array($label, self::SPEC_SUBSECTIONS, true)) {
                $blockText = $this->specSubsectionText($el);
                foreach ($this->parseLabelValueLines($blockText) as $lineLabel => $lineValue) {
                    if (isset(self::SPEC_LABELS[$lineLabel]) && !isset($node[self::SPEC_LABELS[$lineLabel]])) {
                        $node[self::SPEC_LABELS[$lineLabel]] = $lineValue;
                    }
                }
                continue;
            }

            $value = $this->nextValueText($el);
            if ($value === '') {
                continue;
            }

            if (isset(self::TOP_LEVEL_LABELS[$label])) {
                $node[self::TOP_LEVEL_LABELS[$label]] = $value;
            }
        }

        // Require at minimum a model and SOME price signal before calling
        // this a vehicle at all — a page that matched a couple of stray
        // labels but isn't really a vehicle listing (e.g. a finance or
        // contact page reusing the same template chrome) shouldn't produce
        // a bogus record that then fails loudly downstream in
        // parseVehicle(). Price itself isn't captured by label/value pairs
        // on this platform (it renders as "Price: R 228,950" outside the
        // heading structure) — extractPriceText() below handles that
        // separately since it doesn't fit the heading→value pattern.
        if (!isset($node['model'])) {
            return [];
        }

        $priceText = $this->extractPriceText($doc, $xpath);
        if ($priceText === null) {
            return [];
        }
        $node['offers'] = ['price' => $priceText];

        $node['image'] = $this->extractGalleryImages($xpath);
        $node['@type'] = 'Vehicle';

        // This platform has no "Make:" label anywhere in the labeled
        // spec section — the make only appears in the page's H1 title
        // (e.g. "2025 VOLKSWAGEN POLO VIVO"). WebsiteImporter::parseVehicle()
        // already has a make/model/year fallback parser for exactly this
        // shape (it's the same code path SchemaOrgVehicleParser's output
        // uses when a site's markup omits `brand`), keyed off a 'name'
        // field — so rather than duplicate that parsing here, surface the
        // matching H1's text as 'name' and let it do the work. Targeted
        // at an H1 that starts with the model year we already captured
        // (falling back to "starts with any 19xx/20xx year" if that
        // doesn't match) rather than blindly taking the page's first H1,
        // which risks grabbing nav chrome like "Sell Your Car" — exactly
        // the decoy heading this was tested against.
        $heroName = $this->extractHeroName($xpath, $node);
        if ($heroName !== null) {
            $node['name'] = $heroName;
        }

        return [$this->finalizeNode($node)];
    }

    /**
     * Finds the H1 that actually is the vehicle's title, as distinct from
     * page chrome that may also use H1 (nav links, section headers, etc.
     * — this platform's own page had exactly that: "Sell Your Car" as an
     * H1 in the nav, ahead of "2025 VOLKSWAGEN POLO VIVO" as the real
     * title). Prefers an H1 that starts with the model year we already
     * extracted from the labeled "Model Year:" field, since this
     * platform's vehicle titles consistently start with the year; falls
     * back to any H1 starting with a plausible 19xx/20xx year if that
     * specific match isn't found.
     */
    private function extractHeroName(DOMXPath $xpath, array $node): ?string
    {
        $h1s = $xpath->query('//h1');
        if ($h1s === false || $h1s->length === 0) {
            return null;
        }

        $expectedYear = isset($node['vehicleModelDate']) ? (string) $node['vehicleModelDate'] : null;
        if ($expectedYear !== null) {
            foreach ($h1s as $h1) {
                $text = trim($h1->textContent ?? '');
                if ($text !== '' && str_starts_with($text, $expectedYear)) {
                    return $text;
                }
            }
        }

        foreach ($h1s as $h1) {
            $text = trim($h1->textContent ?? '');
            if (preg_match('/^(19|20)\d{2}\b/', $text)) {
                return $text;
            }
        }

        return null;
    }

    private function normalizeLabel(string $text): string
    {
        $t = strtolower(trim($text));
        $t = rtrim($t, ':');
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * Reads the value immediately following a label element: walks
     * forward through sibling nodes (skipping whitespace-only text nodes)
     * and returns the first non-empty text found. This tolerates a few
     * plausible real-world shapes ("Label:" then a sibling <p>/<span>/<div>
     * with the value; or the value as a bare trailing text node) without
     * needing to know which one the real markup actually uses.
     */
    private function nextValueText(DOMNode $labelEl): string
    {
        $sibling = $labelEl->nextSibling;
        while ($sibling !== null) {
            $text = trim($sibling->textContent ?? '');
            if ($text !== '') {
                // Guard against accidentally swallowing the NEXT label —
                // if this sibling is itself one of our known heading tags
                // AND its text matches a known label, stop instead of
                // treating it as this label's value.
                if ($sibling->nodeType === XML_ELEMENT_NODE
                    && in_array(strtolower($sibling->nodeName), ['h1','h2','h3','h4','h5','h6','dt','strong','b'], true)
                    && $this->isKnownLabel($this->normalizeLabel($text))
                ) {
                    return '';
                }
                return $text;
            }
            $sibling = $sibling->nextSibling;
        }
        return '';
    }

    private function isKnownLabel(string $normalized): bool
    {
        return isset(self::TOP_LEVEL_LABELS[$normalized])
            || isset(self::SPEC_LABELS[$normalized])
            || isset(self::SECTION_HEADINGS[$normalized])
            || $normalized === 'additional specifications';
    }

    /**
     * Parses a block of text made of "Label: Value" lines (one pair per
     * line — the shape spec-subsection content renders as, e.g.
     * "CO2: 132\n\nDriven Wheels: Front\n\n...") into normalized-label =>
     * value pairs. Lines that don't contain a colon, or whose label isn't
     * recognized, are ignored rather than causing an error — a spec
     * subsection may legitimately contain fields this parser doesn't map
     * to any canonical column yet.
     *
     * @return array<string, string>
     */
    private function parseLabelValueLines(string $blockText): array
    {
        $pairs = [];
        foreach (preg_split('/\r\n|\r|\n/', $blockText) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$rawLabel, $rawValue] = array_map('trim', explode(':', $line, 2));
            $label = $this->normalizeLabel($rawLabel);
            if ($label !== '' && $rawValue !== '') {
                $pairs[$label] = $rawValue;
            }
        }
        return $pairs;
    }

    /**
     * For "Additional Information" / "Dealer's Comments" style sections,
     * the content isn't one short value but a longer block — walk forward
     * through subsequent siblings until the next heading-like element.
     * Confirmed against real markup: these headings and their content ARE
     * direct siblings on this platform, unlike the spec-subsection case
     * below, which needs different handling.
     */
    private function sectionText(DOMNode $headingEl): string
    {
        $parts    = [];
        $sibling  = $headingEl->nextSibling;
        $stopTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dt'];

        while ($sibling !== null) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && in_array(strtolower($sibling->nodeName), $stopTags, true)) {
                break;
            }
            $text = trim($sibling->textContent ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
            $sibling = $sibling->nextSibling;
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * Grabs a spec-subsection's content block (e.g. everything under
     * "Comfort" — "Seats: 5"). CORRECTED against real production markup:
     * an earlier version of this method reused sectionText()'s "walk my
     * own next siblings" approach, which returned nothing at all for
     * every spec subsection. The real structure nests the heading one
     * level deeper than its content:
     *
     *   <div class="group ...">              accordion item
     *     <div class="...">                  header row
     *       <h4>Comfort</h4>                 <- $headingEl
     *     </div>
     *     <div class="invisible ...">        content panel (SIBLING OF
     *       <p>Seats: 5</p>                     THE HEADER ROW, not of h4)
     *     </div>
     *   </div>
     *
     * So $headingEl's own nextSibling is just the chevron <img> within
     * its own header-row div — never the content. The fix: try the
     * direct-sibling approach first (covers simpler templates where
     * heading and content genuinely are siblings), and if that yields
     * nothing, fall back to taking the SINGLE element sibling of
     * $headingEl's PARENT (the header row) — not an open-ended walk,
     * since an open-ended walk would also sweep in every subsequent
     * accordion item's entire subtree (each is a generic <div>, so a
     * heading-tag-based stop condition can't detect where the next
     * subsection begins).
     */
    private function specSubsectionText(DOMNode $headingEl): string
    {
        $text = $this->sectionText($headingEl);
        if ($text !== '') {
            return $text;
        }

        $headerRow = $headingEl->parentNode;
        if ($headerRow === null) {
            return '';
        }

        $panel = $headerRow->nextSibling;
        while ($panel !== null && trim($panel->textContent ?? '') === '') {
            $panel = $panel->nextSibling; // skip whitespace-only text nodes
        }

        return $panel !== null ? trim($panel->textContent ?? '') : '';
    }

    /**
     * Price on this platform renders as "Price: R 228,950" as plain text
     * near the top of the page, not as a heading/value pair — walk all
     * text nodes looking for that pattern rather than trying to force it
     * into the heading traversal above.
     */
    private function extractPriceText(DOMDocument $doc, DOMXPath $xpath): ?string
    {
        // Excludes text nodes inside <script>/<style> — this platform's real
        // pages have a finance-calculator script containing lines like
        // `var price ="198950";`, which happened to sit AFTER the real
        // "Price: R 198,950" text in document order on the page this was
        // verified against, but relying on that ordering would be fragile.
        // Explicitly excluding script/style content removes the risk
        // rather than hoping document order keeps saving it.
        $textNodes = $xpath->query('//*[not(self::script) and not(self::style)]/text()[contains(translate(., "PRICE", "price"), "price")]');
        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $text = trim($textNode->textContent ?? '');
                // CORRECTED against real production markup: this site
                // renders the amount as "Price: R\u{00A0}329,995" — a
                // non-breaking space (U+00A0, UTF-8 bytes 0xC2 0xA0)
                // between "R" and the digits, not a regular space. \s in
                // this non-Unicode-mode regex does not match that byte
                // sequence, so the match silently failed on every real
                // page even though the text looked identical to a plain
                // space when printed/viewed. Normalizing NBSP to a
                // regular space before matching fixes this without
                // needing Unicode mode on the regex itself.
                $text = str_replace("\xc2\xa0", ' ', $text);
                if (preg_match('/price\s*:?\s*R?\s*([\d,]+(?:\.\d+)?)/i', $text, $m)) {
                    return str_replace(',', '', $m[1]);
                }
            }
        }
        // Fallback: scan the whole document text for "R <number>" near the
        // very top of the page — belt-and-braces in case the "Price:"
        // label text isn't isolated into its own text node.
        $bodyText = $doc->textContent ?? '';
        $bodyText = str_replace("\xc2\xa0", ' ', $bodyText);
        if (preg_match('/\bR\s*([\d,]{4,})\b/', $bodyText, $m)) {
            return str_replace(',', '', $m[1]);
        }
        return null;
    }

    /**
     * Public entry point for reusing JUST the gallery-image extraction
     * from another importer — specifically MotusApiImporter, which gets
     * a vehicle's core fields from VLPData (fast, bulk) but only a single
     * cover photo from that same source; the full photo gallery still
     * only exists on the vehicle's own detail page, which is exactly
     * what this method is for. Static since it needs no instance state.
     *
     * @return string[]
     */
    public static function extractImagesFromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $prevErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prevErrors);

        if (!$loaded) {
            return [];
        }

        return (new self())->extractGalleryImages(new DOMXPath($doc));
    }

    /**
     * Gallery images: prefers images served from a distinct "cdn"/"images"/
     * "photos"/"media" subdomain (the stock-images.cdn.motus.cars pattern,
     * generalized rather than hardcoded to that literal domain so this
     * survives a CDN rename), falling back to all plausible photo <img>
     * tags if no such pattern is found. SVG icons (nav arrows, chevrons,
     * social icons — all observed as .svg in the one page inspected) are
     * excluded.
     *
     * @return string[]
     */
    private function extractGalleryImages(DOMXPath $xpath): array
    {
        $imgs = $xpath->query('//img[@src]');
        if ($imgs === false) {
            return [];
        }

        $urls = [];
        foreach ($imgs as $img) {
            $src = trim($img->getAttribute('src'));
            if ($src === '' || !preg_match('#^https?://#i', $src)) {
                continue;
            }
            if (preg_match('#\.svg(\?|$)#i', $src)) {
                continue;
            }
            $urls[] = $src;
        }

        $cdnLike = array_values(array_filter($urls, static function (string $u): bool {
            $host = parse_url($u, PHP_URL_HOST) ?: '';
            return (bool) preg_match('/\b(cdn|images|photos|media)\b/i', $host);
        }));

        return array_values(array_unique(!empty($cdnLike) ? $cdnLike : $urls));
    }

    /**
     * Normalize captured raw strings into the shapes/units
     * WebsiteImporter::parseVehicle() already expects — numeric-only
     * strings for the *Raw fields (parsed there via parseSmallUint()),
     * and a schema.org QuantitativeValue-ish array for mileage to match
     * what SchemaOrgVehicleParser already produces.
     */
    private function finalizeNode(array $node): array
    {
        if (isset($node['mileageFromOdometer'])) {
            // "20500 km" -> ['value' => '20500'], matching
            // SchemaOrgVehicleParser's shape so WebsiteImporter's
            // extractMileage() doesn't need a second code path.
            if (preg_match('/([\d,\.]+)/', $node['mileageFromOdometer'], $m)) {
                $node['mileageFromOdometer'] = ['value' => str_replace(',', '', $m[1])];
            } else {
                unset($node['mileageFromOdometer']);
            }
        }

        foreach (['engineCapacityRaw', 'powerKwRaw', 'co2EmissionsRaw', 'gearsRaw', 'cylinders', 'doors', 'seats'] as $numericKey) {
            if (isset($node[$numericKey]) && preg_match('/([\d.]+)/', (string) $node[$numericKey], $m)) {
                $node[$numericKey] = $m[1];
            }
        }

        if (isset($node['driveWheelConfiguration'])) {
            $node['driveWheelConfiguration'] = match (strtolower(trim($node['driveWheelConfiguration']))) {
                'front'                => 'FWD',
                'rear'                 => 'RWD',
                'all', '4x4', 'four'   => 'AWD',
                default                => $node['driveWheelConfiguration'],
            };
        }

        // Combine "Additional Information" (features list) into the
        // description if no free-text "Dealer's Comments" already claimed
        // that key, so the feature list isn't silently dropped even
        // though there's no dedicated column for it yet on WebsiteImporter
        // (CsvImporter's car_feature_links population is a separate,
        // not-yet-built piece — see the note left for a follow-up pass).
        if (isset($node['featuresText'])) {
            $node['description'] = isset($node['description'])
                ? $node['description'] . "\n\nFeatures: " . $node['featuresText']
                : 'Features: ' . $node['featuresText'];
            unset($node['featuresText']);
        }

        return $node;
    }
}
