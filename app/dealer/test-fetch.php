<?php
/**
 * Browser-run version. Flushes output after EACH of the four tests, so
 * if one of them hangs (a real possibility — see the Range-header test
 * below) or the host kills the script on a hard execution-time limit,
 * you still SEE every block that completed before that happened, live in
 * the browser, rather than getting nothing until the whole script ends.
 *
 * Usage: upload this file and open it directly in your browser, e.g.
 *   https://yourdomain/path/to/test-fetch.php
 *
 * Watch the page as it loads. If it stalls for a long time right after
 * one particular block's header appears, note WHICH block that is —
 * that tells us which curl option is causing a hang, which is just as
 * useful a finding as an outright error would be.
 */

@ini_set('max_execution_time', 150);
@set_time_limit(150);

// Plain-text, no HTML wrapping, so it's easy to read/copy directly.
header('Content-Type: text/plain; charset=utf-8');

// Disable any output buffering the host's PHP config might have turned
// on by default — without this, echo below can sit in a buffer and never
// reach the browser until the WHOLE script finishes, defeating the
// entire point of testing progressively.
while (ob_get_level() > 0) {
    ob_end_flush();
}

function out(string $text): void
{
    echo $text;
    // Force it to the browser NOW, not whenever PHP/the webserver
    // feels like buffering it.
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function doFetch(string $label, string $url, array $curlOpts): void
{
    out(str_repeat('=', 70) . "\n{$label}\n" . str_repeat('=', 70) . "\n");
    out("(starting request " . date('H:i:s') . " — if the page stalls right here, THIS is the config that hangs)\n");

    $ch = curl_init($url);
    curl_setopt_array($ch, $curlOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADER         => true,
    ]);

    $start    = microtime(true);
    $response = curl_exec($ch);
    $elapsed  = round((microtime(true) - $start) * 1000);

    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    $errno     = curl_errno($ch);
    $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    out("finished at " . date('H:i:s') . "\n");
    out("HTTP Code   : {$httpCode}\n");
    out("curl errno  : {$errno}\n");
    out("curl error  : " . ($err !== '' ? $err : '(none)') . "\n");
    out("Time taken  : {$elapsed} ms\n");

    if ($response === false) {
        out("Body        : (curl_exec returned false — transport failure, see curl error above)\n\n");
        return;
    }

    $headers = substr($response, 0, $headerLen);
    $body    = substr($response, $headerLen);

    out("Body length : " . strlen($body) . " bytes\n");
    out("\n--- Response headers ---\n" . trim($headers) . "\n");

    $bodyLower = strtolower($body);
    $challengeMarkers = [
        'checking your browser', 'cf-browser-verification', 'cf_chl_',
        'attention required', 'just a moment', 'cloudflare-static/challenge',
        'access denied',
    ];
    $found = array_values(array_filter($challengeMarkers, fn($m) => str_contains($bodyLower, $m)));
    out("\n--- Bot-challenge markers found in body ---\n");
    out(($found ? implode(', ', $found) : '(none found)') . "\n");

    $hasVlpDataDeclaration = preg_match('/const\s+VLPData\s*=\s*\[/', $body) === 1;
    $hasBareVlpDataWord    = str_contains($body, 'VLPData');
    out("\nHas 'const VLPData = [' declaration : " . ($hasVlpDataDeclaration ? 'YES' : 'no') . "\n");
    out("Has bare word 'VLPData' anywhere     : " . ($hasBareVlpDataWord ? 'YES' : 'no') . "\n");

    out("\nFirst 300 chars of BODY:\n" . substr($body, 0, 300) . "\n\n");
}

$url = 'https://www.motusvw.co.za/midrand/showroom/625-vwmdf608385/2/';

out("Test started " . date('H:i:s') . " — testing URL: {$url}\n\n");

// 1. Exactly what your last successful manual test sent.
doFetch('[A] Plain — old bot UA, nothing else set', $url, [
    CURLOPT_USERAGENT => 'SalesDeskImporter/1.0 (+https://salesdesk.co.za)',
]);

// 2. Same as [A], but add ONLY the Range header our real fetchPage() sends.
//    This is the one most likely to hang/behave oddly if Cloudflare or the
//    origin mishandles a byte-Range request on an HTML page.
doFetch('[B] Old bot UA + Range header (isolates Range specifically)', $url, [
    CURLOPT_USERAGENT => 'SalesDeskImporter/1.0 (+https://salesdesk.co.za)',
    CURLOPT_RANGE     => '0-' . (5 * 1024 * 1024),
]);

// 3. Browser UA + Referer, no Range — isolates UA/Referer alone.
doFetch('[C] Browser UA + Referer, no Range', $url, [
    CURLOPT_USERAGENT  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    CURLOPT_REFERER    => 'https://www.motusvw.co.za/midrand/showroom/',
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-ZA,en;q=0.9',
    ],
]);

// 4. EXACTLY what MotusApiImporter::fetchPage() sends today, verbatim.
doFetch('[D] EXACT production fetchPage() config (browser UA + Referer + Accept headers + Range)', $url, [
    CURLOPT_USERAGENT  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    CURLOPT_REFERER    => 'https://www.motusvw.co.za/midrand/showroom/',
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-ZA,en;q=0.9',
    ],
    CURLOPT_RANGE => '0-' . (5 * 1024 * 1024),
]);

out(str_repeat('=', 70) . "\n");
out("ALL FOUR TESTS COMPLETED at " . date('H:i:s') . " — if you can see this line,\n");
out("nothing hung or got killed. Copy this ENTIRE page's output and send it back.\n");
