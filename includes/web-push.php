<?php
/**
 * FoxDesk - Minimal Web Push Implementation
 *
 * Uses VAPID authentication with no-payload pushes.
 * The service worker fetches notification data from the app on push events.
 * Requires: PHP 8.1+, openssl extension with EC support, curl extension.
 */

// ── Table & Column Management ───────────────────────────────────────────────

/**
 * Ensure push_subscriptions table exists.
 */
function ensure_push_subscriptions_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $exists = (bool) db_fetch_one("SHOW TABLES LIKE 'push_subscriptions'");
        if (!$exists) {
            db_query("CREATE TABLE push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(255) NOT NULL DEFAULT '',
                auth_key VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_push_user (user_id),
                INDEX idx_push_endpoint (endpoint(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) { /* ignore */ }
}

// ── VAPID Key Management ────────────────────────────────────────────────────

/**
 * Generate VAPID key pair (P-256 ECDSA).
 * Stores keys in settings table.
 *
 * @return array ['public' => base64url, 'private' => PEM]
 */
function generate_vapid_keys(): array
{
    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    if (!$key) {
        throw new RuntimeException('Failed to generate VAPID key pair');
    }

    openssl_pkey_export($key, $priv_pem);
    $details = openssl_pkey_get_details($key);

    // Extract raw public key (uncompressed point: 0x04 || X || Y)
    $x = $details['ec']['x'];
    $y = $details['ec']['y'];
    $raw_pub = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT)
                      . str_pad($y, 32, "\x00", STR_PAD_LEFT);

    $pub_b64 = base64url_encode($raw_pub);

    save_setting('vapid_public_key', $pub_b64);
    save_setting('vapid_private_key', $priv_pem);

    return ['public' => $pub_b64, 'private' => $priv_pem];
}

/**
 * Get VAPID keys, generating if needed.
 *
 * @return array ['public' => base64url, 'private' => PEM]
 */
function get_vapid_keys(): array
{
    $pub = get_setting('vapid_public_key', '');
    $priv = get_setting('vapid_private_key', '');

    if (empty($pub) || empty($priv)) {
        return generate_vapid_keys();
    }

    return ['public' => $pub, 'private' => $priv];
}

// ── Push Subscription CRUD ──────────────────────────────────────────────────

/**
 * Save a push subscription for a user.
 */
function save_push_subscription(int $user_id, string $endpoint, string $p256dh = '', string $auth = ''): bool
{
    ensure_push_subscriptions_table();

    // Remove existing sub for same endpoint (re-subscribe)
    db_query("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?", [$user_id, $endpoint]);

    return (bool) db_insert('push_subscriptions', [
        'user_id'    => $user_id,
        'endpoint'   => $endpoint,
        'p256dh'     => $p256dh,
        'auth_key'   => $auth,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Remove a push subscription.
 */
function remove_push_subscription(int $user_id, string $endpoint): bool
{
    ensure_push_subscriptions_table();
    return db_query("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?", [$user_id, $endpoint]);
}

/**
 * Get all push subscriptions for a user.
 */
function get_push_subscriptions(int $user_id): array
{
    ensure_push_subscriptions_table();
    return db_fetch_all("SELECT * FROM push_subscriptions WHERE user_id = ?", [$user_id]);
}

/**
 * Check if a user has any push subscriptions.
 */
function user_has_push_subscription(int $user_id): bool
{
    ensure_push_subscriptions_table();
    $row = db_fetch_one("SELECT COUNT(*) as cnt FROM push_subscriptions WHERE user_id = ?", [$user_id]);
    return ($row['cnt'] ?? 0) > 0;
}

// ── Send Push ───────────────────────────────────────────────────────────────

/**
 * Send a no-payload push notification to a single subscription endpoint.
 * The service worker will fetch actual notification data from the app.
 *
 * @param string $endpoint  Push service URL
 * @param int    $ttl       Time-to-live in seconds
 * @return bool True on success (HTTP 201), false on failure
 */
function send_web_push(string $endpoint, int $ttl = 86400): bool
{
    $vapid = get_vapid_keys();

    // Build VAPID JWT
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $jwt = create_vapid_jwt($audience, $vapid['private']);

    if (!$jwt) {
        error_log('[web-push] Failed to create VAPID JWT');
        return false;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
            'TTL: ' . $ttl,
            'Content-Length: 0',
            'Urgency: normal',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 201 = Created (success), 410 = Gone (subscription expired)
    if ($status === 410) {
        // Clean up expired subscription
        db_query("DELETE FROM push_subscriptions WHERE endpoint = ?", [$endpoint]);
    }

    return $status >= 200 && $status < 300;
}

/**
 * Create a VAPID JWT token (ES256).
 *
 * @param string $audience Push service origin (e.g., https://fcm.googleapis.com)
 * @param string $priv_pem VAPID private key in PEM format
 * @return string|false JWT string or false on failure
 */
function create_vapid_jwt(string $audience, string $priv_pem)
{
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));

    $admin_email = get_setting('smtp_from_email', '');
    if (empty($admin_email)) {
        $admin = db_fetch_one("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
        $admin_email = $admin['email'] ?? 'admin@localhost';
    }

    $payload = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200, // 12 hours
        'sub' => 'mailto:' . $admin_email,
    ]));

    $signing_input = $header . '.' . $payload;

    $key = openssl_pkey_get_private($priv_pem);
    if (!$key) return false;

    $success = openssl_sign($signing_input, $der_sig, $key, OPENSSL_ALGO_SHA256);
    if (!$success || empty($der_sig)) return false;

    // Convert DER-encoded ECDSA signature to raw R||S format (64 bytes)
    $raw_sig = der_signature_to_raw($der_sig);
    if (!$raw_sig) return false;

    return $signing_input . '.' . base64url_encode($raw_sig);
}

/**
 * Convert DER-encoded ECDSA signature to raw R||S (64 bytes).
 */
function der_signature_to_raw(string $der): ?string
{
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return null;

    // Length byte (skip)
    $total_len = ord($der[$offset++]);
    if ($total_len > 127) {
        $offset += ($total_len & 0x7f);
    }

    // Read R
    if (ord($der[$offset++]) !== 0x02) return null;
    $r_len = ord($der[$offset++]);
    $r = substr($der, $offset, $r_len);
    $offset += $r_len;

    // Read S
    if (ord($der[$offset++]) !== 0x02) return null;
    $s_len = ord($der[$offset++]);
    $s = substr($der, $offset, $s_len);

    // Pad/trim to exactly 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return substr($r, -32) . substr($s, -32);
}

/**
 * Dispatch push notifications to all subscribed devices for given user IDs.
 *
 * @param int[] $user_ids Array of user IDs to notify
 */
function dispatch_push_notifications(array $user_ids): void
{
    if (empty($user_ids)) return;

    ensure_push_subscriptions_table();

    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $subs = db_fetch_all(
        "SELECT endpoint FROM push_subscriptions WHERE user_id IN ({$placeholders})",
        array_values($user_ids)
    );

    foreach ($subs as $sub) {
        try {
            send_web_push($sub['endpoint']);
        } catch (Throwable $e) {
            error_log('[web-push] Send failed: ' . $e->getMessage());
        }
    }
}

// ── Utilities ───────────────────────────────────────────────────────────────

if (!function_exists('base64url_encode')) {
    /**
     * Base64url encode (URL-safe base64 without padding).
     */
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    /**
     * Base64url decode.
     */
    function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
