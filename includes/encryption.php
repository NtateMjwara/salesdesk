<?php
/**
 * SalesDesk — Encryption helpers for sensitive financial & identity data.
 * T1 owns this file.
 *
 * Used for:
 *   - bank_accounts.account_number        (AES-256-CBC, per-row IV)
 *
 * Key management:
 *   The secret is read from an environment variable. It is never
 *   stored in config.php, the database, or version control. Use a
 *   secrets manager or a .env file that sits outside the web root and
 *   is excluded from git.
 *
 *   BANK_ENCRYPTION_KEY          — bank account numbers
 *
 *   (LEAN-1, Oct 2026: the outreach ID-number helpers were removed
 *   with the outreach programme. outreach_registrations data is kept
 *   in the database, still encrypted under OUTREACH_ID_ENCRYPTION_KEY —
 *   keep that secret archived if the data may ever need to be read.)
 *
 * DEBUGGING missing keys:
 *   Environment variables set via a shell `export` (or a `.env` file
 *   only loaded for CLI scripts) are NOT automatically visible to
 *   Apache/PHP-FPM. `getenv()` only sees what the *web server process*
 *   was started with. Fixes:
 *     - Apache + mod_php:  add `SetEnv BANK_ENCRYPTION_KEY ...` to the
 *       vhost or .htaccess, then restart Apache.
 *     - PHP-FPM:            add `env[BANK_ENCRYPTION_KEY] = ...` to the
 *       pool config (e.g. www.conf), then FULLY RESTART (not reload)
 *       php-fpm — pool `env[]` values are only read at startup.
 *     - Docker:             confirm the var is in the *running*
 *       container's environment (`docker exec <container> printenv`).
 *     - phpdotenv / similar: many versions populate $_ENV and $_SERVER
 *       but do NOT call putenv() — this file checks $_ENV/$_SERVER as a
 *       fallback (see _readEnvVar() below) to cover that case.
 */

require_once __DIR__ . '/config.php';

// ── Internal: read an env var from every place PHP might expose it ──

/**
 * Read an environment variable, checking getenv() first (works for
 * real OS-level env vars and most SAPI env[] configs) and falling
 * back to $_ENV / $_SERVER (covers .env loaders that populate those
 * superglobals without calling putenv()).
 */
function _readEnvVar(string $name): string|false
{
    $val = getenv($name);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (!empty($_ENV[$name])) {
        return $_ENV[$name];
    }
    if (!empty($_SERVER[$name])) {
        return $_SERVER[$name];
    }
    return false;
}

// ── Internal: derive key bytes ────────────────────────────────

/**
 * Derive a 32-byte AES key from a named environment variable.
 * Uses SHA-256 so any string length produces a valid 32-byte key.
 * Kept generic so another encrypted column can reuse it later.
 *
 * @return string  32 raw bytes
 * @throws RuntimeException if the env var is not set or too short
 */
function _deriveEncryptionKeyBytes(string $envVarName): string
{
    $raw = _readEnvVar($envVarName);
    if (!$raw || strlen($raw) < 32) {
        throw new RuntimeException(
            "{$envVarName} environment variable is not set or is shorter than 32 characters. " .
            'Set it before enabling this encryption.'
        );
    }
    return hash('sha256', $raw, true); // 32 raw bytes
}

function _getBankEncryptionKeyBytes(): string
{
    static $keyBytes = null;
    if ($keyBytes !== null) return $keyBytes;
    $keyBytes = _deriveEncryptionKeyBytes('BANK_ENCRYPTION_KEY');
    return $keyBytes;
}

/**
 * Generic AES-256-CBC encrypt.
 */
function _aesEncrypt(string $plaintext, string $keyBytes): string
{
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
 * Generic AES-256-CBC decrypt.
 */
function _aesDecrypt(string $encrypted, string $keyBytes): string
{
    $raw = base64_decode($encrypted, true);

    if ($raw === false || strlen($raw) < 17) {
        throw new RuntimeException('Malformed encrypted value — cannot decode.');
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


// ============================================================
// BANK ACCOUNT NUMBERS — unchanged from the original file
// ============================================================

/**
 * Returns true if bank encryption is configured and available.
 * Use this to gate the "Mark paid" button in the admin panel.
 */
function isBankEncryptionAvailable(): bool
{
    if (!defined('USE_BANK_ENCRYPTION') || !USE_BANK_ENCRYPTION) {
        return false;
    }
    $key = _readEnvVar('BANK_ENCRYPTION_KEY');
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
    return _aesEncrypt($plaintext, _getBankEncryptionKeyBytes());
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
    return _aesDecrypt($encrypted, _getBankEncryptionKeyBytes());
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
