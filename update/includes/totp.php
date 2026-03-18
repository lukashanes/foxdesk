<?php
/**
 * TOTP Two-Factor Authentication
 *
 * Implements RFC 6238 (TOTP) and RFC 4648 (Base32) without external dependencies.
 * Compatible with Google Authenticator, Authy, 1Password, and other TOTP apps.
 */

// ─── Base32 Encoding/Decoding (RFC 4648) ────────────────────────────────────

/**
 * Encode binary data to Base32.
 */
function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($binary, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $result .= $alphabet[bindec($chunk)];
    }
    return $result;
}

/**
 * Decode Base32 string to binary data.
 */
function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $binary = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($binary, 8) as $byte) {
        if (strlen($byte) < 8) break;
        $result .= chr(bindec($byte));
    }
    return $result;
}

// ─── TOTP Core (RFC 6238) ───────────────────────────────────────────────────

/**
 * Generate a random TOTP secret (Base32 encoded).
 *
 * @param int $bytes Number of random bytes (default 20 = 160-bit, standard for SHA-1)
 * @return string Base32-encoded secret (32 characters for 20 bytes)
 */
function totp_generate_secret(int $bytes = 20): string
{
    return base32_encode(random_bytes($bytes));
}

/**
 * Generate the current TOTP code for a given secret.
 *
 * @param string $secret Base32-encoded secret
 * @param int|null $time Unix timestamp (null = current time)
 * @param int $period Time step in seconds (default 30)
 * @param int $digits Number of digits in the code (default 6)
 * @return string Zero-padded code string
 */
function totp_get_code(string $secret, ?int $time = null, int $period = 30, int $digits = 6): string
{
    $time = $time ?? time();
    $counter = intdiv($time, $period);
    $key = base32_decode($secret);

    // Pack counter as 64-bit big-endian unsigned integer
    $packed = pack('N*', 0, $counter);

    // HMAC-SHA1
    $hash = hash_hmac('sha1', $packed, $key, true);

    // Dynamic truncation (RFC 4226 §5.4)
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** $digits);

    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code against the current time with a tolerance window.
 *
 * @param string $secret Base32-encoded secret
 * @param string $code The 6-digit code to verify
 * @param int $window Number of time steps to check before/after current (default 1 = ±30s)
 * @return bool True if the code matches any window
 */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        return false;
    }

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $check_time = $now + ($i * 30);
        if (hash_equals(totp_get_code($secret, $check_time), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Generate the otpauth:// URI for QR code scanning.
 *
 * @param string $secret Base32-encoded secret
 * @param string $email User's email (used as account label)
 * @param string $issuer App name shown in authenticator
 * @return string otpauth:// URI
 */
function totp_get_uri(string $secret, string $email, string $issuer = 'FoxDesk'): string
{
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'digits' => 6,
        'period' => 30,
    ]);
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email) . '?' . $params;
}

// ─── Backup Codes ───────────────────────────────────────────────────────────

/**
 * Generate an array of one-time backup codes.
 *
 * @param int $count Number of codes to generate
 * @return array Array of plaintext codes in format "xxxx-xxxx"
 */
function generate_backup_codes(int $count = 8): array
{
    $codes = [];
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789'; // no i,l,o,0,1 to avoid confusion
    for ($i = 0; $i < $count; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }
    return $codes;
}

/**
 * Verify and consume a backup code for a user.
 *
 * @param int $user_id
 * @param string $code The backup code to verify (with or without dash)
 * @return bool True if the code was valid and consumed
 */
function verify_backup_code(int $user_id, string $code): bool
{
    $code = strtolower(str_replace(['-', ' '], '', trim($code)));
    if (strlen($code) !== 8) {
        return false;
    }
    // Re-format to match stored hash format (with dash)
    $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4);
    $code_hash = hash('sha256', $formatted);

    $user = db_fetch_one("SELECT totp_backup_codes FROM users WHERE id = ?", [$user_id]);
    if (!$user || empty($user['totp_backup_codes'])) {
        return false;
    }

    $stored_hashes = json_decode($user['totp_backup_codes'], true);
    if (!is_array($stored_hashes)) {
        return false;
    }

    $index = array_search($code_hash, $stored_hashes, true);
    if ($index === false) {
        return false;
    }

    // Remove used code
    array_splice($stored_hashes, $index, 1);
    db_update('users', [
        'totp_backup_codes' => json_encode(array_values($stored_hashes))
    ], 'id = ?', [$user_id]);

    if (function_exists('log_security_event')) {
        log_security_event('2fa_backup_used', $user_id, 'remaining=' . count($stored_hashes));
    }

    return true;
}

/**
 * Count remaining backup codes for a user.
 */
function count_backup_codes(array $user): int
{
    if (empty($user['totp_backup_codes'])) {
        return 0;
    }
    $codes = json_decode($user['totp_backup_codes'], true);
    return is_array($codes) ? count($codes) : 0;
}

// ─── 2FA Status Helpers ─────────────────────────────────────────────────────

/**
 * Check if a user has 2FA enabled.
 */
function is_2fa_enabled(array $user): bool
{
    return !empty($user['totp_enabled']);
}

/**
 * Check if 2FA is required for a given role by admin settings.
 *
 * @param string $role 'user', 'agent', or 'admin'
 * @return bool
 */
function is_2fa_required_for_role(string $role): bool
{
    $settings = get_settings();
    $key = '2fa_required_' . $role;
    return ($settings[$key] ?? '0') === '1';
}

/**
 * Ensure TOTP columns exist on the users table.
 * Safe to call multiple times — checks before altering.
 */
function ensure_totp_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM users");
        $existing = array_column($cols, 'Field');

        if (!in_array('totp_secret', $existing)) {
            db_execute("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL");
        }
        if (!in_array('totp_enabled', $existing)) {
            db_execute("ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0");
        }
        if (!in_array('totp_backup_codes', $existing)) {
            db_execute("ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL");
        }
    } catch (Throwable $e) {
        // Silently fail — table might already have columns
        if (function_exists('debug_log')) {
            debug_log('ensure_totp_columns failed', ['error' => $e->getMessage()], 'error', 'auth');
        }
    }
}

/**
 * Format a Base32 secret for display with spaces every 4 characters.
 *
 * @param string $secret Base32-encoded secret
 * @return string Formatted string like "JBSW Y3DP EHPK 3PXP"
 */
function format_totp_secret(string $secret): string
{
    return implode(' ', str_split($secret, 4));
}
