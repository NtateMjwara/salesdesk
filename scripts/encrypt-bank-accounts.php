<?php
/**
 * SalesDesk — Bank Account Encryption Script
 * T1 owns this file. CLI only — never accessible via web.
 *
 * Run ONCE to encrypt all existing plaintext account_number values
 * into account_number_encrypted before dropping the plaintext column.
 *
 * Usage:
 *   php scripts/encrypt-bank-accounts.php [--dry-run] [--verify]
 *
 * Options:
 *   --dry-run   Show what would be encrypted without writing to DB
 *   --verify    After encryption, attempt to decrypt and verify all rows
 *
 * Prerequisites:
 *   1. BANK_ENCRYPTION_KEY env var must be set (min 32 chars):
 *      export BANK_ENCRYPTION_KEY="your-very-long-random-secret-key-here"
 *   2. Migration 0006 step 1 must have been run first (adds account_number_encrypted column)
 *   3. Run from the project root: php scripts/encrypt-bank-accounts.php
 *
 * Encryption scheme:
 *   AES-256-CBC with a random 16-byte IV per row.
 *   Stored value: base64_encode(iv . ciphertext)
 *   Decryption:   $iv = substr(base64_decode($stored), 0, 16)
 *                 $ct = substr(base64_decode($stored), 16)
 *                 openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv)
 *
 * SECURITY NOTE:
 *   The encryption key must be stored in the environment — never in
 *   config.php, the database, or version control. Use a secrets manager
 *   or .env file that is outside the web root and not committed.
 *
 * After running successfully:
 *   1. Review output — zero errors required before proceeding
 *   2. Run 0006 STEP 2 to drop the plaintext column
 *   3. Set USE_BANK_ENCRYPTION = true in config.php
 */

declare(strict_types=1);

// ── CLI guard ─────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

$isDryRun = in_array('--dry-run', $argv ?? [], true);
$isVerify = in_array('--verify',  $argv ?? [], true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

// ── Encryption key ────────────────────────────────────────────
$encKey = getenv('BANK_ENCRYPTION_KEY');
if (!$encKey || strlen($encKey) < 32) {
    fwrite(STDERR, "[FATAL] BANK_ENCRYPTION_KEY env var is not set or is shorter than 32 characters.\n");
    fwrite(STDERR, "        export BANK_ENCRYPTION_KEY=\"your-very-long-random-secret-key-here\"\n");
    exit(1);
}

// Derive a 32-byte key for AES-256.
$keyBytes = hash('sha256', $encKey, true); // 32 raw bytes

$cipher = 'AES-256-CBC';

// ── Helpers ───────────────────────────────────────────────────

function encryptAccountNumber(string $plaintext, string $keyBytes, string $cipher): string
{
    $iv         = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('openssl_encrypt failed: ' . openssl_error_string());
    }
    return base64_encode($iv . $ciphertext);
}

function decryptAccountNumber(string $encrypted, string $keyBytes, string $cipher): string
{
    $raw        = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 17) {
        throw new RuntimeException('Malformed encrypted value — cannot base64 decode.');
    }
    $iv         = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $plaintext  = openssl_decrypt($ciphertext, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        throw new RuntimeException('openssl_decrypt failed: ' . openssl_error_string());
    }
    return $plaintext;
}

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

// ── Main ──────────────────────────────────────────────────────
$log("Bank account encryption script starting.");
if ($isDryRun) $log("DRY RUN — no changes will be written.");

try {
    $pdo = Database::getInstance();

    // Fetch rows that still have plaintext (encryption_version = 0).
    $stmt = $pdo->prepare("
        SELECT id, account_number, account_holder
        FROM bank_accounts
        WHERE encryption_version = 0
          AND account_number IS NOT NULL
          AND account_number != ''
        ORDER BY id ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $total   = count($rows);
    $success = 0;
    $errors  = 0;

    $log("Found {$total} row(s) to encrypt.");

    if ($total === 0) {
        $log("Nothing to do. All rows are already encrypted or have no account number.");
    }

    foreach ($rows as $row) {
        $id          = (int) $row['id'];
        $plaintext   = $row['account_number'];
        $holder      = $row['account_holder'];

        // Mask plaintext in logs — show only last 4 digits.
        $masked = str_repeat('*', max(0, strlen($plaintext) - 4)) . substr($plaintext, -4);

        if ($isDryRun) {
            $log("  [DRY RUN] Would encrypt id={$id} holder=\"{$holder}\" account={$masked}");
            $success++;
            continue;
        }

        try {
            $encrypted = encryptAccountNumber($plaintext, $keyBytes, $cipher);

            $pdo->prepare("
                UPDATE bank_accounts
                SET account_number_encrypted = ?,
                    encryption_version       = 1
                WHERE id = ?
                  AND encryption_version = 0
            ")->execute([$encrypted, $id]);

            $log("  Encrypted id={$id} holder=\"{$holder}\" account={$masked}");
            $success++;
        } catch (Throwable $e) {
            $log("  [ERROR] id={$id}: " . $e->getMessage());
            $errors++;
        }
    }

    $log("Encryption complete. Success: {$success}. Errors: {$errors}.");

    if ($errors > 0) {
        fwrite(STDERR, "[WARNING] {$errors} row(s) failed to encrypt. Review errors above before proceeding.\n");
    }

    // ── Verification pass ─────────────────────────────────────
    if ($isVerify && !$isDryRun) {
        $log("Running verification pass...");
        $verifyStmt = $pdo->prepare("
            SELECT id, account_number, account_number_encrypted, account_holder
            FROM bank_accounts
            WHERE encryption_version = 1
        ");
        $verifyStmt->execute();
        $verifyRows  = $verifyStmt->fetchAll();
        $verifyOk    = 0;
        $verifyFail  = 0;

        foreach ($verifyRows as $vr) {
            try {
                $decrypted = decryptAccountNumber($vr['account_number_encrypted'], $keyBytes, $cipher);

                // If plaintext column still exists, compare.
                if ($vr['account_number'] !== null && $decrypted !== $vr['account_number']) {
                    $log("  [MISMATCH] id={$vr['id']} holder=\"{$vr['account_holder']}\" — decrypted value does not match plaintext!");
                    $verifyFail++;
                } else {
                    $verifyOk++;
                }
            } catch (Throwable $e) {
                $log("  [VERIFY ERROR] id={$vr['id']}: " . $e->getMessage());
                $verifyFail++;
            }
        }

        $log("Verification: {$verifyOk} passed, {$verifyFail} failed.");

        if ($verifyFail > 0) {
            fwrite(STDERR, "[FATAL] Verification failures detected — DO NOT drop the plaintext column.\n");
            exit(1);
        } else {
            $log("All rows verified. Safe to run migration 0006 STEP 2 (DROP COLUMN).");
            $log("Then set USE_BANK_ENCRYPTION = true in config.php.");
        }
    }

    if (!$isDryRun && $errors === 0) {
        $log("Next steps:");
        $log("  1. Run with --verify to confirm all rows decrypt correctly.");
        $log("  2. Run migration 0006 STEP 2 to drop the plaintext account_number column.");
        $log("  3. Set USE_BANK_ENCRYPTION = true in config.php.");
    }

} catch (Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
