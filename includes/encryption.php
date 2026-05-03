<?php
/**
 * SalesDesk — Encryption helpers for sensitive financial data.
 * T1 owns this file.
 *
 * Used for:
 *   - bank_accounts.account_number (AES-256-CBC, per-row IV)
 *
 * Key management:
 *   The encryption key is read from the BANK_ENCRYPTION_KEY environment
 *   variable. It must never be stored in config.php, the database,
 *   or version control. Use a secrets manager or a .env file that
 *   sits outside the web root and is excluded from git.
 *
 * Usage:
 *   require_once __DIR__ . '/encryption.php';
 *
 *   // Encrypt before INSERT / UPDATE
 *   $encrypted = encryptBankAccountNumber($plainAccountNumber);
 *
 *   // Decrypt after SELECT (for display or payout processing)
 *   $plain = decryptBankAccountNumber($row['account_number_encrypted']);
 *
 *   // Check if encryption is available
 *   if (!isBankEncryptionAvailable()) { ... }
 *
 * Encryption scheme:
 *   AES-256-CBC
 *   Random 16-byte IV generated per encryption call
 *   Stored format: base64_encode(iv_bytes . ciphertext_bytes)
 *
 * Note on display:
 *   Never render full account numbers in HTML.
 *   Use maskAccountNumber() which shows only the last 4 digits.
 */

require_once __DIR__ . '/config.php';

// ── Internal: derive key bytes ────────────────────────────────

/**
 * Derive the 32-byte AES key from the environment variable.
 * Uses SHA-256 so any string length produces a valid 32-byte key.
 *
 * @return string  32 raw bytes
 * @throws RuntimeException if env var is not set or too short
 */
function _getBankEncryptionKeyBytes(): string
{
    static $keyBytes = null;
    if ($keyBytes !== null) return $keyBytes;

    $raw = getenv('BANK_ENCRYPTION_KEY');
    if (!$raw || strlen($raw) < 32) {
        throw new RuntimeException(
            'BANK_ENCRYPTION_KEY environment variable is not set or is shorter than 32 characters. ' .
            'Set it before enabling bank encryption.'
        );
    }
    $keyBytes = hash('sha256', $raw, true); // 32 raw bytes
    return $keyBytes;
}


// ── Public API ────────────────────────────────────────────────

/**
 * Returns true if bank encryption is configured and available.
 * Use this to gate the "Mark paid" button in the admin panel.
 */
function isBankEncryptionAvailable(): bool
{
    if (!defined('USE_BANK_ENCRYPTION') || !USE_BANK_ENCRYPTION) {
        return false;
    }
    $key = getenv('BANK_ENCRYPTION_KEY');
    return $key && strlen($key) >= 32;
}

/**
 * Encrypt a bank account number for storage.
 *
 * @param string $plaintext  The raw account number
 * @return string  base64-encoded (IV + ciphertext)
 * @throws RuntimeException on encryption failure or missing key
 */
function encryptBankAccountNumber(string $plaintext): string
{
    $keyBytes   = _getBankEncryptionKeyBytes();
    $iv         = random_bytes(16);
    $ciphertext = openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $keyBytes,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed: ' . openssl_error_string());
    }

    return base64_encode($iv . $ciphertext);
}

/**
 * Decrypt a stored bank account number.
 *
 * @param string $encrypted  The base64-encoded (IV + ciphertext) value
 * @return string  The original plaintext account number
 * @throws RuntimeException on decryption failure or corrupt data
 */
function decryptBankAccountNumber(string $encrypted): string
{
    $keyBytes = _getBankEncryptionKeyBytes();
    $raw      = base64_decode($encrypted, true);

    if ($raw === false || strlen($raw) < 17) {
        throw new RuntimeException('Malformed encrypted account number — cannot decode.');
    }

    $iv         = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $plaintext  = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $keyBytes,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed: ' . openssl_error_string());
    }

    return $plaintext;
}

/**
 * Mask an account number for safe display — shows only the last 4 digits.
 * Call this whenever rendering account numbers in HTML.
 *
 * @param string $accountNumber  Plaintext or masked value
 * @return string  e.g. "···· 6789"
 */
function maskAccountNumber(string $accountNumber): string
{
    $len  = strlen($accountNumber);
    $last = substr($accountNumber, -4);
    return '···· ' . $last;
}

/**
 * Get the decrypted account number from a bank_accounts row.
 * Handles both encrypted rows (encryption_version=1) and legacy
 * plaintext rows (encryption_version=0) for backwards compatibility
 * during the cutover period.
 *
 * @param array $bankAccountRow  Full row from bank_accounts table
 * @return string  Plaintext account number
 * @throws RuntimeException if neither value is available
 */
function getBankAccountNumberPlain(array $bankAccountRow): string
{
    $version = (int) ($bankAccountRow['encryption_version'] ?? 0);

    if ($version >= 1 && !empty($bankAccountRow['account_number_encrypted'])) {
        return decryptBankAccountNumber($bankAccountRow['account_number_encrypted']);
    }

    // Legacy fallback — plaintext column still exists.
    if (!empty($bankAccountRow['account_number'])) {
        return $bankAccountRow['account_number'];
    }

    throw new RuntimeException(
        "Bank account id={$bankAccountRow['id']} has neither encrypted nor plaintext account number."
    );
}
