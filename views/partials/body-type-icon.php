<?php
/**
 * SalesDesk — Body-type silhouette icons  (v1)
 * views/partials/body-type-icon.php
 *
 * Line-art side profiles used by the homepage body-type tiles and the
 * browse sidebar. Inline SVG (markup, not CSS/JS) so the icons inherit
 * currentColor and need no extra request.
 *
 *   echo sdBodyTypeIcon('SUV');          // unknown types fall back to Sedan
 */

declare(strict_types=1);

if (!function_exists('sdBodyTypeIcon')) {
    function sdBodyTypeIcon(string $bodyType, string $class = 'sd-body-icon'): string
    {
        $shapes = [
            'sedan'      => ['M4 20 6 14.5Q7.5 12 13 11.6L22 11 29.5 5.2Q31 4 34 4H44Q47 4 49.5 6.6L54 11.3 58 12.2Q61 13 61 16V20Z', 16, 50],
            'hatchback'  => ['M6 20 7 14.5Q8 12 13 11.4L20 10.6 27 5Q28.6 4 31 4H46Q48.8 4 50.4 7L52.8 12 55.4 13Q58 14 58 17V20Z', 16, 48],
            'suv'        => ['M4 20V12.5Q4 10 7 9.8L14 9.2 19.6 3.2Q20.8 2 23 2H50Q53 2 54.8 4.6L58.2 9.6Q61 10.6 61 14V20Z', 16, 50],
            'crossover'  => ['M4 20 4.6 14Q5.4 11 9 10.6L16 10 22.5 4.2Q24 3 26.5 3H47Q50 3 52 5.6L56 10.6Q60 11.6 60 15V20Z', 16, 49],
            'bakkie'     => ['M3 20V12.2H28.5V11L33.6 3.3Q34.5 2 37 2H48Q51 2 52.8 4.6L56.6 9.8Q61 10.8 61 14V20Z', 14, 50],
            'mpv'        => ['M4 20V13Q4 10 7.6 9.4L12 8.6 17.4 3.2Q18.6 2 21 2H53.6Q57.4 2 58.6 5.6L61 12V20Z', 16, 50],
            'coupe'      => ['M4 20 5 15.2Q6 13 11.6 12.2L22 11.2 31.4 5Q33 4 36 4H43Q47 4 51 8.4L59.6 11.8Q62 12.8 62 16V20Z', 16, 51],
            'convertible'=> ['M4 20 5 15.2Q6 13 11.6 12.2L30 11.2 36 8 40 11.2 59.6 11.8Q62 12.8 62 16V20Z', 16, 51],
            'wagon'      => ['M4 20 5.2 14.6Q6.4 12 11.4 11.6L19 11 25.6 5Q27 4 30 4H56Q59 4 60 7L61 12V20Z', 16, 50],
            'van'        => ['M3 20V6Q3 2 7 2H44Q47 2 49.4 4.6L57.6 12.2Q61 13 61 16V20Z', 14, 50],
        ];

        $key = strtolower($bodyType);
        $key = match (true) {
            str_contains($key, 'suv') || str_contains($key, '4x4') => 'suv',
            str_contains($key, 'bakkie') || str_contains($key, 'truck') || str_contains($key, 'pickup') => 'bakkie',
            str_contains($key, 'hatch')       => 'hatchback',
            str_contains($key, 'cross')       => 'crossover',
            str_contains($key, 'mpv') || str_contains($key, 'minibus') => 'mpv',
            str_contains($key, 'coupe')       => 'coupe',
            str_contains($key, 'convertible') || str_contains($key, 'cabrio') => 'convertible',
            str_contains($key, 'wagon') || str_contains($key, 'estate') => 'wagon',
            str_contains($key, 'van')         => 'van',
            default                           => isset($shapes[$key]) ? $key : 'sedan',
        };

        [$path, $w1, $w2] = $shapes[$key];

        return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 66 26" aria-hidden="true" focusable="false">'
             . '<path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
             . '<circle cx="' . $w1 . '" cy="20" r="4" fill="var(--white, #fff)" stroke="currentColor" stroke-width="1.7"/>'
             . '<circle cx="' . $w2 . '" cy="20" r="4" fill="var(--white, #fff)" stroke="currentColor" stroke-width="1.7"/>'
             . '</svg>';
    }
}
