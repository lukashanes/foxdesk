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
/** Read at most the limit plus one byte, including streams without a length. */
function security_read_limited_stream($stream, int $limit): string
{
    $body = stream_get_contents($stream, $limit + 1);
    if ($body === false) {
        throw new RuntimeException('Request body could not be read.');
    }
    if (strlen($body) > $limit) {
        throw new LengthException('Request body exceeds the size limit.');
    }
    return $body;
}

function security_read_request_body(int $limit = 1048576): string
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new LengthException('Request body exceeds the size limit.');
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new RuntimeException('Request body could not be opened.');
    }
    try {
        return security_read_limited_stream($stream, $limit);
    } finally {
        fclose($stream);
    }
}

/** Public pages do not accept files; reject large bodies before any side effect. */
function security_enforce_public_request_limit(): void
{
    try {
        security_read_request_body(65536);
    } catch (LengthException $e) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Payload Too Large';
        exit;
    }
}

function foxdesk_parse_ini_size($value): int
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    if (is_numeric($value)) {
        return (int) $value;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    switch ($unit) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int) round($number);
}

function foxdesk_request_exceeded_post_max_size(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }

    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length <= 0) {
        return false;
    }

    $post_max_size = foxdesk_parse_ini_size(ini_get('post_max_size'));
    if ($post_max_size <= 0 || $content_length <= $post_max_size) {
        return false;
    }

    return empty($_POST) && empty($_FILES);
}

function foxdesk_post_max_size_error_message(): ?string
{
    if (!foxdesk_request_exceeded_post_max_size()) {
        return null;
    }

    $limit = foxdesk_parse_ini_size(ini_get('post_max_size'));
    $limit_label = function_exists('format_file_size') ? format_file_size($limit) : ((string) $limit . ' B');

    return t('Upload exceeds the server request limit of {size}. Reduce the total size of attachments or ask your administrator to increase PHP post_max_size.', [
        'size' => $limit_label,
    ]);
}

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

/**
 * Compare a parsed Referer URL with the request host, including the port.
 * The Host header carries any non-default port (for example 127.0.0.1:8080),
 * so the Referer port must be compared too; default ports are ignored.
 */
function foxdesk_referer_host_matches(array $parts, string $expected_host): bool
{
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $default_port = $scheme === 'https' ? ':443' : ':80';
    $normalize = static function (string $host) use ($default_port): string {
        $host = strtolower(trim($host));
        return str_ends_with($host, $default_port) ? substr($host, 0, -strlen($default_port)) : $host;
    };

    $referer_host = (string) ($parts['host'] ?? '');
    if ($referer_host === '') {
        return false;
    }
    if (isset($parts['port'])) {
        $referer_host .= ':' . (int) $parts['port'];
    }

    return $normalize($referer_host) === $normalize($expected_host);
}

function require_csrf_token($json = false)
{
    // API token auth doesn't need CSRF — the token itself is the proof
    if (!empty($GLOBALS['is_api_token_auth'])) {
        return;
    }

    $post_size_error = foxdesk_post_max_size_error_message();
    if ($post_size_error !== null) {
        if ($json) {
            http_response_code(413);
            echo json_encode(['error' => $post_size_error]);
            exit;
        }

        flash($post_size_error, 'error');
        $fallback = url(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
        $redirect = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($redirect !== '') {
            if (preg_match('#^https?://#i', $redirect)) {
                $parts = parse_url($redirect);
                $expected_host = function_exists('foxdesk_request_host') ? foxdesk_request_host() : ($_SERVER['HTTP_HOST'] ?? '');
                if (
                    !$parts
                    || empty($parts['host'])
                    || !foxdesk_referer_host_matches($parts, (string) $expected_host)
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

    if (csrf_is_valid()) {
        return;
    }

    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    flash(t('Security check failed. Please try again.'), 'error');
    $fallback = url(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
    $redirect = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($redirect !== '') {
        if (preg_match('#^https?://#i', $redirect)) {
            $parts = parse_url($redirect);
            $expected_host = function_exists('foxdesk_request_host') ? foxdesk_request_host() : ($_SERVER['HTTP_HOST'] ?? '');
            if (
                !$parts
                || empty($parts['host'])
                || !foxdesk_referer_host_matches($parts, (string) $expected_host)
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
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

    // Content Security Policy - Only enable in production
    // Runtime libraries are self-hosted in assets/vendor; no CDN host is allowed.
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8081', '127.0.0.1', '127.0.0.1:8081']);

    if (!$is_localhost) {
        header("Content-Security-Policy: default-src 'self'; " .
            "base-uri 'self'; object-src 'none'; form-action 'self'; " .
            "script-src 'self' 'unsafe-inline'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "font-src 'self' data:; " .
            "img-src 'self' data: blob: https:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'self'");
        header("Content-Security-Policy-Report-Only: default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'self'");
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

/** Consume the reset link and revoke prior credentials in one transaction. */
function password_reset_complete(array $user, string $password_hash, string $token): bool
{
    $user_id = (int) ($user['id'] ?? 0);
    if ($user_id <= 0 || $token === '') {
        return false;
    }
    schema_require('password reset revocation', [], ['users' => ['auth_version', 'remember_token']]);
    $db = get_db();
    if ($db->inTransaction()) {
        return false;
    }
    try {
        $db->beginTransaction();
        $locked = db_fetch_one('SELECT id FROM users WHERE id = ? AND reset_token = ? AND reset_token_expires > ? AND is_active = 1 AND deleted_at IS NULL FOR UPDATE', [$user_id, hash_reset_token($token), foxdesk_db_datetime()]);
        if (!$locked) {
            $db->rollBack();
            return false;
        }
        db_query('UPDATE users SET password = ?, auth_version = auth_version + 1, remember_token = NULL, reset_token = NULL, reset_token_expires = NULL WHERE id = ?', [$password_hash, $user_id]);
        auth_revoke_user_credentials($user_id);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Called only within the transaction that advances the authentication epoch. */
function auth_revoke_user_credentials(int $user_id): void
{
    if (table_exists('api_tokens')) {
        db_query('UPDATE api_tokens SET is_active = 0, revoked_at = ? WHERE user_id = ?', [foxdesk_db_datetime(), $user_id]);
    }
}

/**
 * Determine client IP address for security logging.
 */
function get_client_ip()
{
    $remote = trim($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return '';
    }

    // Only trust forwarded headers when request comes through a local reverse proxy
    $is_trusted_proxy = function_exists('foxdesk_request_uses_trusted_proxy') ? foxdesk_request_uses_trusted_proxy() : (
        str_starts_with($remote, '127.') ||
        str_starts_with($remote, '10.') ||
        preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remote) === 1 ||
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
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
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

function rate_limit_key(string $scope, string $subject = ''): string
{
    $scope = preg_replace('/[^a-z0-9_.:-]/i', '_', $scope) ?: 'default';
    $subject = strtolower(trim($subject));
    if ($subject === '') {
        return $scope;
    }

    return $scope . ':' . substr(hash('sha256', $subject), 0, 32);
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
function security_request_log_context(): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    return ['method' => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER'];
}

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
