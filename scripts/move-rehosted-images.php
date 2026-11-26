<?php
/**
 * SalesDesk — One-off: move re-hosted import images to the correct folder.
 * CLI only — never accessible via web.
 *
 * Before the Oct 2026 path fix, ImageRehostService saved imported images
 * to <project root>/includes/uploads/cars/{uuid}/ while storing URLs of
 * /uploads/cars/{uuid}/N.jpg in cars.image_urls. Those URLs point at
 * <project root>/uploads/cars/, so every imported image 404'd.
 *
 * This moves each {uuid} folder from the old location to the new one.
 * No database changes are needed — the stored URLs were already right;
 * only the files were in the wrong place.
 *
 * Usage (from the project root):
 *   php scripts/move-rehosted-images.php --dry-run   # show what would move
 *   php scripts/move-rehosted-images.php             # do it
 *
 * Safe to run more than once: folders already moved are skipped, and an
 * existing destination file is never overwritten.
 *
 * No CLI on your host? Do the same thing in the file manager: move every
 * folder inside includes/uploads/cars/ into uploads/cars/.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root   = dirname(__DIR__);
$oldDir = $root . '/includes/uploads/cars';
$newDir = $root . '/uploads/cars';
$dryRun = in_array('--dry-run', $argv, true);

if (!is_dir($oldDir)) {
    echo "Nothing to do — {$oldDir} does not exist.\n";
    exit(0);
}
if (!$dryRun && !is_dir($newDir) && !mkdir($newDir, 0755, true) && !is_dir($newDir)) {
    fwrite(STDERR, "Could not create {$newDir}\n");
    exit(1);
}

$movedFolders = 0;
$movedFiles   = 0;
$skipped      = 0;

foreach (new DirectoryIterator($oldDir) as $carFolder) {
    if ($carFolder->isDot() || !$carFolder->isDir()) {
        continue;
    }
    $uuid = $carFolder->getFilename();
    $src  = $carFolder->getPathname();
    $dst  = "{$newDir}/{$uuid}";

    if (!is_dir($dst)) {
        echo ($dryRun ? '[dry-run] ' : '') . "move folder {$uuid}\n";
        if (!$dryRun && !rename($src, $dst)) {
            fwrite(STDERR, "  FAILED to move {$src}\n");
            continue;
        }
        $movedFolders++;
        continue;
    }

    // Destination folder already exists (e.g. a re-import after the fix
    // already wrote there) — move only files that aren't there yet.
    foreach (new DirectoryIterator($src) as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }
        $target = "{$dst}/" . $file->getFilename();
        if (file_exists($target)) {
            $skipped++;
            continue;
        }
        echo ($dryRun ? '[dry-run] ' : '') . "move file {$uuid}/" . $file->getFilename() . "\n";
        if ($dryRun || rename($file->getPathname(), $target)) {
            $movedFiles++;
        }
    }
}

echo "\nDone" . ($dryRun ? ' (dry run — nothing changed)' : '') .
     ": {$movedFolders} folder(s), {$movedFiles} extra file(s) moved, {$skipped} already in place.\n";

if (!$dryRun) {
    echo "You can delete {$oldDir} once you've confirmed images load on the site.\n";
}
