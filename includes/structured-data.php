<?php
/**
 * SalesDesk — Structured Data (JSON-LD) builders
 *
 * Every function here returns a ready-to-echo <script type="application/
 * ld+json"> string — never raw arrays — so call sites stay a one-liner:
 *   echo renderBreadcrumbSchema($breadcrumbs, SITE_URL);
 *
 * All builders escape via json_encode's own string escaping (JSON_
 * UNESCAPED_SLASHES so URLs don't come out as http:\/\/...) and never
 * concatenate raw user input into the JSON by hand.
 */

declare(strict_types=1);

function sdJsonLd(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "<script type=\"application/ld+json\">{$json}</script>\n";
}

/**
 * Sitewide Organization schema. Render this ONCE — homepage is enough;
 * Google associates it with the domain, it doesn't need repeating on
 * every page. See INTEGRATION section at the bottom for where.
 */
function renderOrganizationSchema(string $baseUrl, array $socialProfiles = []): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => 'SalesDesk',
        'url'      => $baseUrl,
        'logo'     => $baseUrl . '/assets/img/logo.png',
        'description' => "South Africa's independent car marketplace connecting buyers, brokers, and dealerships.",
    ];
    if (!empty($socialProfiles)) {
        $data['sameAs'] = array_values($socialProfiles);
    }
    return sdJsonLd($data);
}

/**
 * BreadcrumbList — takes the exact $breadcrumbs array shape already
 * used across the codebase: [['Label', $urlOrNull], ...]. The final
 * crumb (current page) conventionally has a null URL — that's already
 * how how-it-works/*.php and desks/index.php build theirs, so no
 * changes needed at the call site beyond passing the same array in.
 *
 * $baseUrl should be SITE_URL (or its fallback). Home is prepended
 * automatically as position 1 so callers never need to include it.
 */
function renderBreadcrumbSchema(array $breadcrumbs, string $baseUrl): string
{
    $items   = [];
    $items[] = ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $baseUrl . '/'];

    $position = 2;
    foreach ($breadcrumbs as [$label, $url]) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => $label,
        ];
        // Only the final/current crumb is allowed to omit "item" per
        // spec — but a null URL for a middle crumb would be a bug
        // elsewhere in the page, so we still emit item when present.
        if ($url) {
            $entry['item'] = str_starts_with($url, 'http') ? $url : $baseUrl . $url;
        }
        $items[] = $entry;
        $position++;
    }

    return sdJsonLd([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ]);
}

/**
 * FAQPage — takes the exact $faqs array shape already defined in
 * how-it-works/brokers.php, dealers.php, sales-exec.php:
 *   [['Question text?', 'Answer HTML/text'], ...]
 *
 * Answers are stripped of HTML tags for the schema (Google wants
 * plain text in the "text" field, even though your visible <details>
 * markup can keep the <strong> tags etc. for display).
 */
function renderFaqSchema(array $faqs): string
{
    $items = [];
    foreach ($faqs as [$question, $answer]) {
        $items[] = [
            '@type'          => 'Question',
            'name'           => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => trim(strip_tags($answer)),
            ],
        ];
    }

    return sdJsonLd([
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $items,
    ]);
}

/**
 * Vehicle + Offer schema for a single car detail page
 * (c/car-detail/index.php — desk-attributed route).
 *
 * $car expects the same keys your existing queries already select:
 * make, model, year, price, mileage, condition_type, body_type,
 * colour, transmission, fuel_type, drivetrain, image_urls (JSON
 * string), car_slug, desk_slug.
 * $dealer expects: dealer_name, dealer_province / dealer_city.
 */
function renderVehicleSchema(array $car, array $dealer, string $canonicalUrl): string
{
    $images = json_decode($car['image_urls'] ?? '[]', true) ?: [];

    $conditionMap = [
        'new'  => 'https://schema.org/NewCondition',
        'demo' => 'https://schema.org/RefurbishedCondition',
        'used' => 'https://schema.org/UsedCondition',
    ];

    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Vehicle',
        'name'             => trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? '')),
        'brand'            => ['@type' => 'Brand', 'name' => $car['make'] ?? ''],
        'model'            => $car['model'] ?? '',
        'vehicleModelDate' => (string) ($car['year'] ?? ''),
        'url'              => $canonicalUrl,
    ];

    if (!empty($images)) {
        $data['image'] = array_values($images);
    }
    if (!empty($car['mileage'])) {
        $data['mileageFromOdometer'] = [
            '@type' => 'QuantitativeValue',
            'value' => (int) $car['mileage'],
            'unitCode' => 'KMT', // UN/CEFACT code for kilometers
        ];
    }
    if (!empty($car['fuel_type']))    $data['fuelType']         = $car['fuel_type'];
    if (!empty($car['transmission'])) $data['vehicleTransmission'] = $car['transmission'];
    if (!empty($car['drivetrain']))   $data['driveWheelConfiguration'] = $car['drivetrain'];
    if (!empty($car['colour']))       $data['color']            = $car['colour'];
    if (!empty($car['body_type']))    $data['bodyType']          = $car['body_type'];
    if (!empty($conditionMap[$car['condition_type'] ?? '']))
        $data['itemCondition'] = $conditionMap[$car['condition_type']];

    if (!empty($car['price'])) {
        $data['offers'] = [
            '@type'         => 'Offer',
            'price'         => (string) (float) $car['price'],
            'priceCurrency' => 'ZAR',
            'availability'  => 'https://schema.org/InStock',
            'url'           => $canonicalUrl,
            'seller'        => [
                '@type' => 'AutoDealer',
                'name'  => $dealer['dealer_name'] ?? 'SalesDesk Dealer',
            ],
        ];
        if (!empty($dealer['dealer_province']) || !empty($dealer['dealer_city'])) {
            $data['offers']['availableAtOrFrom'] = [
                '@type'   => 'Place',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $dealer['dealer_city'] ?? '',
                    'addressRegion'   => $dealer['dealer_province'] ?? '',
                    'addressCountry'  => 'ZA',
                ],
            ];
        }
    }

    return sdJsonLd($data);
}

/**
 * Article schema for a single blog post (news/article.php).
 * $post expects: title, excerpt, content, featured_image_url,
 * published_at, author_first, author_last, category_name (all already
 * selected by news/index.php's and index.php's blog_posts queries).
 */
function renderArticleSchema(array $post, string $canonicalUrl, string $baseUrl): string
{
    $authorName = trim(($post['author_first'] ?? '') . ' ' . ($post['author_last'] ?? '')) ?: 'SalesDesk';
    $image = $post['featured_image_url']
        ?: 'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?q=80&w=1200&auto=format&fit=crop';

    return sdJsonLd([
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post['title'] ?? '',
        'description'   => $post['excerpt'] ?? '',
        'image'         => [$image],
        'datePublished' => date('c', strtotime((string) ($post['published_at'] ?? 'now'))),
        'dateModified'  => date('c', strtotime((string) ($post['updated_at'] ?? $post['published_at'] ?? 'now'))),
        'author'        => ['@type' => 'Person', 'name' => $authorName],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => 'SalesDesk',
            'logo'  => ['@type' => 'ImageObject', 'url' => $baseUrl . '/assets/img/logo.png'],
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
    ]);
}

/**
 * LocalBusiness schema for a broker storefront (/{slug}/). Optional/
 * bonus — only worth adding once broker pages actively target local
 * search ("car broker in Sandton" etc).
 * $desk expects: display_name, city, province, avatar_url or logo_url.
 */
function renderLocalBusinessSchema(array $desk, string $canonicalUrl): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'AutoDealer',
        'name'     => $desk['display_name'] ?? '',
        'url'      => $canonicalUrl,
    ];
    if (!empty($desk['logo_url']) || !empty($desk['avatar_url'])) {
        $data['image'] = $desk['logo_url'] ?? $desk['avatar_url'];
    }
    if (!empty($desk['city']) || !empty($desk['province'])) {
        $data['address'] = [
            '@type'           => 'PostalAddress',
            'addressLocality' => $desk['city'] ?? '',
            'addressRegion'   => $desk['province'] ?? '',
            'addressCountry'  => 'ZA',
        ];
    }
    return sdJsonLd($data);
}
