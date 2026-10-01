<?php
/**
 * SalesDesk — Encryption helpers for sensitive financial & identity data.
 * T1 owns this file.
 *
 * Used for:
 *   - bank_accounts.account_number        (AES-256-CBC, per-row IV)
 *   - outreach_registrations.id_number    (AES-256-CBC, per-row IV)
 *
 * Key management:
 *   Each secret is read from its own environment variable. Neither is
 *   ever stored in config.php, the database, or version control. Use
 *   a secrets manager or a .env file that sits outside the web root
 *   and is excluded from git.
 *
 *   BANK_ENCRYPTION_KEY          — bank account numbers (existing)
 *   OUTREACH_ID_ENCRYPTION_KEY   — outreach ID numbers, reversible
 *   OUTREACH_ID_HASH_PEPPER      — outreach ID numbers, one-way dedupe hash
 *
 *   Keep OUTREACH_ID_ENCRYPTION_KEY and OUTREACH_ID_HASH_PEPPER distinct
 *   from BANK_ENCRYPTION_KEY and from each other — a leak of one secret
 *   should not automatically compromise the others.
 *
 * DEBUGGING "registrations temporarily unavailable":
 *   That message comes from registerOutreachInterest() in
 *   includes/outreach.php when isOutreachIdEncryptionAvailable()
 *   below returns false. Call outreachIdEncryptionDiagnostics() (see
 *   bottom of this file) to see exactly which check is failing —
 *   it never returns the secret values themselves, only booleans and
 *   lengths, so it's safe to log or print on an admin-only page.
 *
 *   The single most common cause: environment variables set via a
 *   shell `export` (or a `.env` file only loaded for CLI scripts) are
 *   NOT automatically visible to Apache/PHP-FPM. `getenv()` only sees
 *   what the *web server process* was started with. Fixes:
 *     - Apache + mod_php:  add `SetEnv OUTREACH_ID_ENCRYPTION_KEY ...`
 *       to the vhost or .htaccess, then restart Apache (SetEnv alone
 *       doesn't require a config reload but the values must be present
 *       when Apache itself starts if set outside a vhost context).
 *     - PHP-FPM:            add `env[OUTREACH_ID_ENCRYPTION_KEY] = ...`
 *       to the pool config (e.g. www.conf), then FULLY RESTART
 *       (not reload) php-fpm — pool `env[]` values are only read at
 *       master-process startup.
 *     - Docker:             confirm the var is in the *running*
 *       container's environment (`docker exec <container> printenv`),
 *       not just in a .env file the compose file forgot to reference.
 *     - phpdotenv / similar: by default many versions populate $_ENV
 *       and $_SERVER but do NOT call putenv() unless configured to —
 *       getenv() will then see nothing even though $_ENV has it. This
 *       file intentionally checks $_ENV/$_SERVER as a fallback (see
 *       _readEnvVar() below) to cover that case.
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
 * Shared by both the bank-account and outreach-ID key derivations
 * below (each caches its own result under its own static var).
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

function _getOutreachIdEncryptionKeyBytes(): string
{
    static $keyBytes = null;
    if ($keyBytes !== null) return $keyBytes;
    $keyBytes = _deriveEncryptionKeyBytes('OUTREACH_ID_ENCRYPTION_KEY');
    return $keyBytes;
}

/**
 * Generic AES-256-CBC encrypt, shared by both call sites.
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
 * Generic AES-256-CBC decrypt, shared by both call sites.
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


// ============================================================
// OUTREACH ID NUMBERS — new, backs outreach_registrations
// ============================================================

/**
 * Returns true if outreach ID encryption is configured and available.
 * Use this to gate registration submission in includes/outreach.php —
 * fail closed (refuse to accept new registrations) rather than ever
 * falling back to storing an ID number in plaintext.
 */
function isOutreachIdEncryptionAvailable(): bool
{
    return outreachIdEncryptionDiagnostics()['available'];
}

/**
 * Detailed, secret-safe diagnostics for isOutreachIdEncryptionAvailable().
 * Returns booleans and lengths only — NEVER the actual key/pepper
 * values — so this is safe to error_log() or print on an admin-only
 * page when tracking down "registrations temporarily unavailable".
 *
 * @return array{
 *   use_flag_defined: bool,
 *   use_flag_true: bool,
 *   key_present: bool,
 *   key_length: int,
 *   key_length_ok: bool,
 *   pepper_present: bool,
 *   pepper_length: int,
 *   pepper_length_ok: bool,
 *   available: bool
 * }
 */
function outreachIdEncryptionDiagnostics(): array
{
    $useFlagDefined = defined('USE_OUTREACH_ID_ENCRYPTION');
    $useFlagTrue    = $useFlagDefined && (bool) USE_OUTREACH_ID_ENCRYPTION;

    $key    = _readEnvVar('OUTREACH_ID_ENCRYPTION_KEY');
    $pepper = _readEnvVar('OUTREACH_ID_HASH_PEPPER');

    $keyLen    = $key !== false ? strlen($key) : 0;
    $pepperLen = $pepper !== false ? strlen($pepper) : 0;

    $keyOk    = $key !== false && $keyLen >= 32;
    $pepperOk = $pepper !== false && $pepperLen >= 16;

    return [
        'use_flag_defined' => $useFlagDefined,
        'use_flag_true'    => $useFlagTrue,
        'key_present'      => $key !== false,
        'key_length'       => $keyLen,
        'key_length_ok'    => $keyOk,
        'pepper_present'   => $pepper !== false,
        'pepper_length'    => $pepperLen,
        'pepper_length_ok' => $pepperOk,
        'available'        => $useFlagTrue && $keyOk && $pepperOk,
    ];
}

/**
 * Encrypt an SA ID number for storage.
 *
 * @param string $plaintext  The raw 13-digit ID number
 * @return string  base64-encoded (IV + ciphertext)
 * @throws RuntimeException on encryption failure or missing key
 */
function encryptIdNumber(string $plaintext): string
{
    return _aesEncrypt($plaintext, _getOutreachIdEncryptionKeyBytes());
}

/**
 * Decrypt a stored SA ID number.
 *
 * @param string $encrypted  The base64-encoded (IV + ciphertext) value
 * @return string  The original plaintext ID number
 * @throws RuntimeException on decryption failure or corrupt data
 */
function decryptIdNumber(string $encrypted): string
{
    return _aesDecrypt($encrypted, _getOutreachIdEncryptionKeyBytes());
}

/**
 * One-way HMAC hash of an ID number, used ONLY to detect duplicate
 * registrations (outreach_registrations.id_number_hash has a UNIQUE
 * index on this value). Not reversible — do not use this as a
 * substitute for encryptIdNumber() when the plaintext is genuinely
 * needed later.
 *
 * Uses a pepper distinct from OUTREACH_ID_ENCRYPTION_KEY so that a
 * leak of the encryption key doesn't also expose a lookup oracle,
 * and vice versa.
 *
 * @throws RuntimeException if the pepper env var is not set
 */
function hashIdNumber(string $plaintext): string
{
    $pepper = _readEnvVar('OUTREACH_ID_HASH_PEPPER');
    if (!$pepper || strlen($pepper) < 16) {
        throw new RuntimeException(
            'OUTREACH_ID_HASH_PEPPER environment variable is not set or is shorter than 16 characters.'
        );
    }
    return hash_hmac('sha256', $plaintext, $pepper);
}

/**
 * Mask an ID number for safe display — shows only the last 4 digits,
 * matching the convention already used for bank account numbers.
 *
 * @param string $idNumber  Plaintext ID number
 * @return string  e.g. "·········6789"
 */
function maskIdNumber(string $idNumber): string
{
    $last = substr($idNumber, -4);
    return str_repeat('·', max(0, strlen($idNumber) - 4)) . $last;
}

/**
 * Get the decrypted ID number from an outreach_registrations row.
 * Every call site that uses this (currently only the admin "Reveal"
 * action) should audit-log the access — see revealOutreachIdNumber()
 * in includes/outreach.php.
 *
 * @param array $registrationRow  Full row from outreach_registrations
 * @return string  Plaintext ID number
 * @throws RuntimeException if the encrypted value is missing/corrupt
 */
function getOutreachIdNumberPlain(array $registrationRow): string
{
    if (empty($registrationRow['id_number_encrypted'])) {
        throw new RuntimeException(
            "Outreach registration id={$registrationRow['id']} has no encrypted ID number."
        );
    }

    return decryptIdNumber($registrationRow['id_number_encrypted']);
}
