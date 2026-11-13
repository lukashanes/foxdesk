<?php
/**
 * Security Helper Functions
 *
 * This file contains all security-related functions including CSRF protection,
 * rate limiting, password generation, and security logging.
 */

/**
 * CSRF protection helpers
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid($token = null)
{
    $session_token = $_SESSION['csrf_token'] ?? '';
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }
    if ($session_token === '' || $token === '') {
        return false;
    }
    return hash_equals($session_token, $token);
}

function require_csrf_token($json = false)
{
    // API token auth doesn't need CSRF — the token itself is the proof
    if (!empty($GLOBALS['is_api_token_auth'])) {
        return;
    }

    if (csrf_is_valid()) {
        return;
    }

    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    flash(t('Security check failed. Please try again.'), 'error');
    $fallback = url('dashboard');
    $redirect = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($redirect !== '') {
        if (preg_match('#^https?://#i', $redirect)) {
            $parts = parse_url($redirect);
            $expected_host = function_exists('foxdesk_request_host') ? foxdesk_request_host() : ($_SERVER['HTTP_HOST'] ?? '');
            if (
                !$parts
                || empty($parts['host'])
                || strcasecmp((string) $parts['host'], (string) $expected_host) !== 0
            ) {
                $redirect = $fallback;
            }
        } elseif (
            !str_starts_with($redirect, '/')
            && !str_starts_with($redirect, 'index.php')
            && !str_starts_with($redirect, '?')
        ) {
            $redirect = $fallback;
        }
    } else {
        $redirect = $fallback;
    }

    header('Location: ' . $redirect);
    exit;
}

/**
 * Send comprehensive security headers including CSP and HSTS.
 */
function send_security_headers()
{
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent XSS attacks
    header('X-XSS-Protection: 1; mode=block');

    // Prevent clickjacking (SAMEORIGIN allows embedding on same domain)
    header('X-Frame-Options: SAMEORIGIN');

    // Control referrer information
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy - Only enable in production
    // For development on localhost, CSP can interfere with Tailwind CDN
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8081', '127.0.0.1', '127.0.0.1:8081']);

    if (!$is_localhost) {
        header("Content-Security-Policy: default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
            "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
            "font-src 'self' https://cdnjs.cloudflare.com data:; " .
            "img-src 'self' data: https:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'self'");
    }

    // HTTP Strict Transport Security - Force HTTPS (only if using HTTPS)
    $is_https = function_exists('foxdesk_request_is_https')
        ? foxdesk_request_is_https()
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if ($is_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Validate password strength
 *
 * @param string $password The password to validate
 * @return array ['valid' => bool, 'errors' => array]
 */
function validate_password($password)
{
    $errors = [];

    // Minimum length: 12 characters
    if (strlen($password) < 12) {
        $errors[] = t('Password must be at least 12 characters long.');
    }

    // Must contain uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = t('Password must contain at least one uppercase letter.');
    }

    // Must contain lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = t('Password must contain at least one lowercase letter.');
    }

    // Must contain number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = t('Password must contain at least one number.');
    }

    // Must contain special character
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        $errors[] = t('Password must contain at least one special character.');
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Generate random password meeting strength requirements
 */
function generate_password($length = 12)
{
    if ($length < 12) {
        $length = 12;
    }

    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $special = '!@#$%^&*()_+-=[]{}';

    // Ensure at least one of each required character type
    $password = '';
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];

    // Fill the rest with random characters from all sets
    $all_chars = $lowercase . $uppercase . $numbers . $special;
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all_chars[random_int(0, strlen($all_chars) - 1)];
    }

    // Shuffle the password to avoid predictable patterns
    return str_shuffle($password);
}

/**
 * Validate avatar URL to prevent XSS via data: URLs
 *
 * @param string $url The avatar URL to validate
 * @return bool True if safe, false otherwise
 */
function is_safe_avatar_url($url)
{
    if (empty($url)) {
        return true; // Empty is safe, will use default avatar
    }

    // Allow data: URLs only for SVG avatars (generated by system)
    if (str_starts_with($url, 'data:image/svg+xml')) {
        // Block known SVG XSS vectors: script tags, event handlers, dangerous elements
        $dangerous_patterns = [
            '<script',       // Inline scripts
            'javascript:',   // JS protocol
            'onload',        // Event handlers
            'onerror',
            'onclick',
            'onmouseover',
            'onfocus',
            'onanimationend',
            '<foreignObject', // Can embed arbitrary HTML
            '<use',           // Can reference external resources
            '<iframe',
            '<embed',
            '<object',
            'xlink:href',    // External references
        ];
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return false;
            }
        }
        return true;
    }

    // Allow HTTPS URLs only (external avatars like Gravatar)
    if (str_starts_with($url, 'https://')) {
        return true;
    }

    // Allow relative paths (local uploads like "uploads/file.jpg", "/uploads/file.jpg", "./uploads/file.jpg")
    if (str_starts_with($url, '/') || str_starts_with($url, './') || preg_match('#^[a-zA-Z0-9_\-]+/#', $url)) {
        // Ensure no protocol or javascript: injection
        if (stripos($url, 'javascript:') !== false || stripos($url, 'data:') !== false) {
            return false;
        }
        return true;
    }

    // Block everything else (http://, javascript:, data:text/html, etc.)
    return false;
}

/**
 * Generate reset token
 */
function generate_reset_token()
{
    return bin2hex(random_bytes(32));
}

/**
 * Hash reset tokens for storage.
 */
function hash_reset_token($token)
{
    return hash('sha256', $token);
}

/**
 * Determine client IP address for security logging.
 */
function get_client_ip()
{
    $remote = trim($_SERVER['REMOTE_ADDR'] ?? '');

    // Only trust forwarded headers when request comes through a local reverse proxy
    $is_trusted_proxy = $remote !== '' && (
        str_starts_with($remote, '127.') ||
        str_starts_with($remote, '10.') ||
        str_starts_with($remote, '172.') ||
        str_starts_with($remote, '192.168.') ||
        $remote === '::1'
    );

    if ($is_trusted_proxy) {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim($_SERVER[$key]);
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if ($ip !== '') return $ip;
            }
        }
    }

    return $remote;
}

/**
 * Rate limit storage checks.
 */
function rate_limit_table_exists()
{
    return table_exists('rate_limits');
}

function security_log_table_exists()
{
    return table_exists('security_log');
}

function rate_limit_is_blocked($key, $limit, $window_seconds)
{
    if (!rate_limit_table_exists()) {
        return false;
    }

    $ip = get_client_ip();
    if ($ip === '') {
        return false;
    }

    $row = db_fetch_one("SELECT attempts, window_start FROM rate_limits WHERE rate_key = ? AND ip_address = ?", [$key, $ip]);
    if (!$row) {
        return false;
    }

    $window_start = strtotime($row['window_start']);
    if ($window_start === false || (time() - $window_start) > $window_seconds) {
        return false;
    }

    return ((int) $row['attempts'] >= (int) $limit);
}

function rate_limit_record($key, $window_seconds)
{
    if (!rate_limit_table_exists()) {
        return;
    }

    $ip = get_client_ip();
    if ($ip === '') {
        return;
    }

    $row = db_fetch_one("SELECT id, attempts, window_start FROM rate_limits WHERE rate_key = ? AND ip_address = ?", [$key, $ip]);
    $now = date('Y-m-d H:i:s');

    if (!$row) {
        db_insert('rate_limits', [
            'rate_key' => $key,
            'ip_address' => $ip,
            'attempts' => 1,
            'window_start' => $now,
            'updated_at' => $now
        ]);
        return;
    }

    $window_start = strtotime($row['window_start']);
    if ($window_start === false || (time() - $window_start) > $window_seconds) {
        db_update('rate_limits', [
            'attempts' => 1,
            'window_start' => $now,
            'updated_at' => $now
        ], 'id = ?', [$row['id']]);
        return;
    }

    db_update('rate_limits', [
        'attempts' => (int) $row['attempts'] + 1,
        'updated_at' => $now
    ], 'id = ?', [$row['id']]);
}

function rate_limit_clear($key)
{
    if (!rate_limit_table_exists()) {
        return;
    }
    $ip = get_client_ip();
    if ($ip === '') {
        return;
    }
    db_execute("DELETE FROM rate_limits WHERE rate_key = ? AND ip_address = ?", [$key, $ip]);
}

/**
 * Log security events to the database (if available).
 */
function log_security_event($event_type, $user_id = null, $context = '')
{
    if (!security_log_table_exists()) {
        return;
    }
    $ip = get_client_ip();
    db_insert('security_log', [
        'event_type' => $event_type,
        'user_id' => $user_id,
        'ip_address' => $ip,
        'context' => $context,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}
