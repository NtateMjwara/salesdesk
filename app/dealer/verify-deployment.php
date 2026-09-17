<?php
/**
 * Run this from your browser, placed ANYWHERE under public_html.
 * Checks BOTH files changed in the latest round of fixes:
 *   - MotusApiImporter.php  (already-enriched skip logic, updateCar's
 *     writeEnrichment safety flag)
 *   - MotusShowroomParser.php (the NBSP price-parsing fix, variant capture)
 *
 * This exists because deployment/OPcache staleness has been the actual
 * cause of "the fix didn't work" at least twice already in this same
 * debugging session — checking THIS explicitly, again, for the latest
 * round specifically (not just the previous round's markers) is cheaper
 * than another round of guessing.
 */

header('Content-Type: text/plain; charset=utf-8');

function findPublicHtmlRoot(string $start): ?string
{
    $dir = $start;
    for ($i = 0; $i < 6; $i++) {
        if (basename($dir) === 'public_html') {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function findFile(string $filename, ?string $root): ?string
{
    $candidates = [];
    if ($root !== null) {
        $candidates[] = $root . '/includes/importers/' . $filename;
        $candidates[] = $root . '/includes/' . $filename;
    }
    $candidates[] = __DIR__ . '/' . $filename;
    $candidates[] = __DIR__ . '/../includes/importers/' . $filename;
    $candidates[] = __DIR__ . '/../../includes/importers/' . $filename;
    $candidates[] = __DIR__ . '/importers/' . $filename;

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && file_exists($real)) {
            return $real;
        }
    }
    return null;
}

function checkFile(string $label, ?string $file, array $markers): void
{
    echo str_repeat('=', 70) . "\n{$label}\n" . str_repeat('=', 70) . "\n";

    if ($file === null) {
        echo "COULD NOT FIND THIS FILE in any expected location.\n\n";
        return;
    }

    echo "Path: {$file}\n";
    echo "Size: " . filesize($file) . " bytes\n";
    echo "Last modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n\n";

    $source = file_get_contents($file);
    $allFound = true;
    foreach ($markers as $needle => $desc) {
        $found = str_contains($source, $needle);
        $allFound = $allFound && $found;
        echo ($found ? '[OK]      ' : '[MISSING] ') . "{$desc} (\"{$needle}\")\n";
    }

    echo "\n";
    if ($allFound) {
        echo "ALL MARKERS FOUND — this IS the latest version.\n";
    } else {
        echo "SOME MARKERS MISSING — this is an OLDER version than what\n";
        echo "we've been testing against. Re-upload it and run this check again.\n";
    }

    if (function_exists('opcache_get_status')) {
        $status = @opcache_get_status(false);
        if ($status !== false) {
            $cached = @opcache_is_script_cached($file);
            echo "\nOPcache has this file cached: " . ($cached ? 'YES' : 'no') . "\n";
            if ($cached) {
                $reset = @opcache_reset();
                echo "opcache_reset() called: " . ($reset ? 'success' : 'failed/not permitted') . "\n";
            }
        }
    }
    echo "\n";
}

$root = findPublicHtmlRoot(__DIR__);
echo "Detected public_html root: " . ($root ?? '(not found)') . "\n\n";

$importerFile = findFile('MotusApiImporter.php', $root);
$parserFile   = findFile('MotusShowroomParser.php', $root);

checkFile('MotusApiImporter.php', $importerFile, [
    'alreadyEnriched'         => 'the already-enriched lookup (skips re-fetching done vehicles)',
    'writeEnrichment'         => "updateCar()'s safety flag protecting good data from being nulled out",
    'skippedEnrichmentThisRun' => 'the per-vehicle skip-tracking variable',
    'motus-debug.log'         => 'the debugLog() file-based logging',
]);

checkFile('MotusShowroomParser.php', $parserFile, [
    '\xc2\xa0'         => 'the non-breaking-space normalization fix for price parsing',
    'prevWasHero'      => 'the variant/trim heading capture logic',
    "'variant'] = \$rawText" => 'the actual variant assignment line',
]);

echo str_repeat('=', 70) . "\n";
echo "If BOTH files show ALL MARKERS FOUND, the deployed code IS what\n";
echo "we've tested. If it still fails after that, the next step is a\n";
echo "fresh motus-debug.log from an actual run with these confirmed files.\n";
