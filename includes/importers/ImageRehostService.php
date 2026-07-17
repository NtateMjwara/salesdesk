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
 * Used by CsvImporter today; written source-agnostic so a future
 * cars.co.za API importer or dealer-feed importer can reuse it as-is.
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
     * source URLs were supplied, the list is unchanged from last time, or
     * every download in the batch failed (logged, not thrown — one bad
     * image must never fail the whole car row).
     *
     * SANITY-CHECK FIX: max image count was a hardcoded constant duplicating
     * platform_config.max_images_per_car — if an admin changes that config
     * value, this class previously wouldn't reflect it. Now takes the limit
     * as a parameter; CsvImporter reads the live config value and passes it
     * through (see rehostImages() there), falling back to
     * DEFAULT_MAX_IMAGES_PER_CAR only if the caller doesn't specify one.
     *
     * @param string        $carUuid             Used to namespace the storage directory.
     * @param array<int,string> $sourceUrls       Image URLs from this import run's CSV row.
     * @param array<int,string> $previousSourceUrls Image URLs stored from the last run (cars.source_image_urls), or [].
     * @param int           $maxImages           Live value of platform_config.max_images_per_car.
     *
     * @return array{image_urls: array<int,string>, source_image_urls: array<int,string>}|null
     */
    public function rehost(
        string $carUuid,
        array $sourceUrls,
        array $previousSourceUrls = [],
        int $maxImages = self::DEFAULT_MAX_IMAGES_PER_CAR
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

        $localUrls   = [];
        $index       = 0;
        $failures    = 0;

        foreach ($sourceUrls as $url) {
            $index++;
            try {
                $localUrls[] = $this->downloadOne($url, $dir, $carUuid, $index);
            } catch (Throwable $e) {
                $failures++;
                error_log("[ImageRehostService] Skipped image ({$carUuid}, #{$index}) {$url}: " . $e->getMessage());
            }
        }

        if (empty($localUrls)) {
            // Every image in the batch failed — treat as a no-op rather than
            // wiping out whatever images the car already has, and rather
            // than recording source_image_urls (which would make the
            // unchanged-check above wrongly skip retrying next run).
            error_log("[ImageRehostService] All {$index} image(s) failed for car {$carUuid} — leaving existing images untouched.");
            return null;
        }

        if ($failures > 0) {
            error_log("[ImageRehostService] {$failures}/{$index} image(s) failed for car {$carUuid} — proceeding with the {$index} - {$failures} that succeeded.");
        }

        return [
            'image_urls'        => $localUrls,
            // Store the full attempted list (not just successes) so a
            // future run only re-attempts when the CSV's URLs actually
            // change, rather than endlessly retrying URLs that are
            // consistently broken at the source.
            'source_image_urls' => $sourceUrls,
        ];
    }

    /**
     * Download one image, validate it, and save it to disk.
     *
     * @throws InvalidArgumentException on a bad URL or unsupported/invalid image content.
     * @throws RuntimeException on network failure, non-2xx response, oversized body, or disk write failure.
     */
    private function downloadOne(string $url, string $dir, string $carUuid, int $index): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException("Invalid image URL: {$url}");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
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
        ]);
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
