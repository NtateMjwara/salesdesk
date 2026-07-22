<?php
/**
 * SalesDesk — Image Re-hosting Service.
 *
 * Downloads listing images from a source URL (cars.co.za export, a
 * dealer's DMS, etc.) and stores them under our own /uploads/cars/{uuid}/
 * path, so cars.image_urls always points at SalesDesk-controlled URLs
 * rather than hotlinking a third party.
 *
 * Why this exists rather than storing source URLs directly:
 *   - If the source listing is later removed, hotlinked images go dead.
 *   - Some platforms block hotlinking from third-party domains outright.
 *   - Our own Cache-Control/CDN headers (.htaccess) only apply to assets
 *     actually served from our domain.
 *   - Re-running an importer daily shouldn't re-download unchanged images
 *     every time — see the "unchanged since last run" short-circuit below.
 *
 * Used by CsvImporter and WebsiteImporter; written source-agnostic so a
 * future cars.co.za API importer or dealer-feed importer can reuse it
 * as-is.
 *
 * CHANGES IN THIS PASS (504 Gateway Time-out fix — see WebsiteImporter's
 * docblock for the full context):
 *   DEADLINE-1  rehost() now accepts an optional wall-clock deadline. Image
 *               downloads are the single biggest time sink in a website
 *               import — a dealer site with 30 vehicles × 5 photos each is
 *               150 sequential HTTP downloads in the worst case, easily
 *               enough on its own to blow past a reverse proxy's read
 *               timeout even with everything else bounded. When the
 *               deadline is reached mid-batch, the loop stops cleanly
 *               instead of continuing to download — this is the actual
 *               fix; CsvImporter, which has no such deadline concern
 *               (deadlineTs stays null there), is completely unaffected.
 *   DEADLINE-2  source_image_urls now reflects only the URLs actually
 *               ATTEMPTED (successes + real failures), not the full
 *               requested list, when a run stops early due to the
 *               deadline. This matters for the "unchanged since last run"
 *               short-circuit below: if we recorded the full list after
 *               only attempting the first few, a future run would
 *               wrongly believe every image had already been tried and
 *               would never retry the ones we ran out of time for.
 */
class ImageRehostService
{
    /** Accepted image MIME types => file extension to save with. */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES        = 8 * 1024 * 1024; // 8MB per image
    private const TIMEOUT_SECONDS  = 15;
    /** Fallback only — callers should pass the live platform_config value; see rehost(). */
    private const DEFAULT_MAX_IMAGES_PER_CAR = 10;

    private string $uploadRoot;

    /**
     * @param string|null $uploadRoot Absolute filesystem path to the
     *                                uploads/cars directory. Defaults to
     *                                <project root>/uploads/cars.
     */
    public function __construct(?string $uploadRoot = null)
    {
        $this->uploadRoot = $uploadRoot ?? (dirname(__DIR__) . '/uploads/cars');
    }

    /**
     * Re-host a car's images if the source URL list has changed since the
     * last import. Returns null when there is nothing to do — either no
     * source URLs were supplied, the list is unchanged from last time,
     * every download in the batch failed, or the deadline was already
     * passed before any download was attempted (logged, not thrown — one
     * bad image, or running out of time, must never fail the whole car
     * row).
     *
     * @param string             $carUuid           Used to namespace the storage directory.
     * @param array<int,string>  $sourceUrls        Image URLs from this import run.
     * @param array<int,string>  $previousSourceUrls Image URLs stored from the last run (cars.source_image_urls), or [].
     * @param int                $maxImages         Live value of platform_config.max_images_per_car.
     * @param float|null         $deadlineTs        Optional microtime(true)-style wall-clock deadline
     *                                               (DEADLINE-1). null means unlimited — every existing
     *                                               caller that doesn't pass this gets identical behavior
     *                                               to before this parameter existed.
     * @param string|null        $referer           Optional Referer header to send with each download.
     *                                               Needed for source CDNs with referrer-based hotlink
     *                                               protection (confirmed on stock-images.cdn.motus.cars,
     *                                               which returns HTTP 403 with no Referer at all) — this
     *                                               is a SERVER-SIDE header on our one-time download only,
     *                                               not something end visitors' browsers ever send, so it
     *                                               doesn't change anything about how the re-hosted image
     *                                               is served afterward. null (default) sends no Referer,
     *                                               identical to every existing caller's behavior before
     *                                               this parameter existed.
     *
     * @return array{image_urls: array<int,string>, source_image_urls: array<int,string>}|null
     */
    public function rehost(
        string $carUuid,
        array $sourceUrls,
        array $previousSourceUrls = [],
        int $maxImages = self::DEFAULT_MAX_IMAGES_PER_CAR,
        ?float $deadlineTs = null,
        ?string $referer = null
    ): ?array
    {
        $sourceUrls = array_values(array_unique(array_filter(array_map('trim', $sourceUrls))));
        if (empty($sourceUrls)) {
            return null;
        }

        $sourceUrls = array_slice($sourceUrls, 0, max(1, $maxImages));

        // Nothing changed since the last successful import — skip entirely.
        // (Compares the full attempted list, not just successes — see note
        // in the "partial success" branch below for why.)
        if ($sourceUrls === array_values($previousSourceUrls)) {
            return null;
        }

        $dir = "{$this->uploadRoot}/{$carUuid}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("[ImageRehostService] Could not create upload directory: {$dir}");
            return null;
        }

        $localUrls    = [];
        $index        = 0;
        $failures     = 0;
        $stoppedEarly = false;

        foreach ($sourceUrls as $url) {
            // DEADLINE-1: checked BEFORE each attempt (not after), so
            // $index at the point of an early stop reflects exactly how
            // many URLs were actually attempted — see DEADLINE-2 below.
            if ($deadlineTs !== null && microtime(true) >= $deadlineTs) {
                $stoppedEarly = true;
                break;
            }

            $index++;
            try {
                $localUrls[] = $this->downloadOne($url, $dir, $carUuid, $index, $referer);
            } catch (Throwable $e) {
                $failures++;
                error_log("[ImageRehostService] Skipped image ({$carUuid}, #{$index}) {$url}: " . $e->getMessage());
            }
        }

        if (empty($localUrls)) {
            // Every attempted image failed, OR the deadline was already
            // passed before we attempted any — either way, treat as a
            // no-op rather than wiping out whatever images the car
            // already has, and rather than recording source_image_urls
            // (which would make the unchanged-check above wrongly skip
            // retrying next run).
            $reason = $stoppedEarly
                ? 'ran out of time before attempting any image'
                : "all {$index} image(s) failed";
            error_log("[ImageRehostService] {$reason} for car {$carUuid} — leaving existing images untouched.");
            return null;
        }

        if ($failures > 0) {
            error_log("[ImageRehostService] {$failures}/{$index} image(s) failed for car {$carUuid} — proceeding with the " . count($localUrls) . " that succeeded.");
        }
        if ($stoppedEarly) {
            error_log(
                "[ImageRehostService] Stopped early for car {$carUuid} after {$index}/" . count($sourceUrls) .
                " image(s) — wall-clock deadline reached. Remaining images will be retried on the next import."
            );
        }

        return [
            'image_urls'        => $localUrls,
            // DEADLINE-2: only the URLs actually attempted (index reflects
            // this whether we stopped early or ran the full list), not the
            // full requested list, so a future run retries whatever we
            // didn't get to rather than wrongly believing it was tried and
            // failed. When nothing stopped the loop early, $index equals
            // count($sourceUrls) and this is identical to the pre-fix
            // behavior of always recording the full list.
            'source_image_urls' => array_slice($sourceUrls, 0, $index),
        ];
    }

    /**
     * Download one image, validate it, and save it to disk.
     *
     * @throws InvalidArgumentException on a bad URL or unsupported/invalid image content.
     * @throws RuntimeException on network failure, non-2xx response, oversized body, or disk write failure.
     */
    private function downloadOne(string $url, string $dir, string $carUuid, int $index, ?string $referer = null): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException("Invalid image URL: {$url}");
        }

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => 'SalesDeskImporter/1.0 (+https://salesdesk.co.za)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Belt-and-braces cap so a misbehaving server can't stream forever —
            // curl doesn't enforce a body-size limit natively, so we also check
            // strlen() after the fact below.
        ];
        if ($referer !== null && $referer !== '') {
            $curlOpts[CURLOPT_REFERER] = $referer;
        }
        curl_setopt_array($ch, $curlOpts);
        $body        = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr     = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            throw new RuntimeException("Download failed for {$url}: {$curlErr}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("HTTP {$httpCode} fetching {$url}");
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException("Image exceeds max size (" . self::MAX_BYTES . " bytes): {$url}");
        }
        if (strlen($body) === 0) {
            throw new RuntimeException("Empty response body: {$url}");
        }

        // Sniff actual file bytes rather than trusting the declared
        // Content-Type header — cheap and avoids saving an HTML error
        // page as though it were a .jpg.
        $mime = $this->sniffMime($body) ?? $contentType;
        $ext  = self::ALLOWED_MIME[$mime] ?? null;
        if ($ext === null) {
            throw new InvalidArgumentException("Unsupported image type ({$mime}) for {$url}");
        }

        $filename = "{$index}.{$ext}";
        $path     = "{$dir}/{$filename}";
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException("Could not write image to disk: {$path}");
        }

        return "/uploads/cars/{$carUuid}/{$filename}";
    }

    private function sniffMime(string $bytes): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_buffer($finfo, $bytes) ?: null;
        finfo_close($finfo);
        return $mime;
    }
}
