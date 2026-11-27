<?php
/**
 * FoxDesk - Minimal Web Push Implementation
 *
 * Uses VAPID authentication with no-payload pushes.
 * The service worker fetches notification data from the app on push events.
 * Requires: PHP 8.1+, openssl extension with EC support, curl extension.
 */

// ── Schema capability ───────────────────────────────────────────────────────

/** Verify web-push schema installed by the database migration. */
function ensure_push_subscriptions_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    schema_require('web push', ['push_subscriptions'], [
        'push_subscriptions' => ['user_id', 'endpoint', 'p256dh', 'auth_key', 'created_at'],
    ]);
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

/** Resolve a public HTTPS push destination once, then pin it at connection time. */
function web_push_resolve_target(string $endpoint, ?callable $resolver = null): ?array
{
    if (strlen($endpoint) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
        return null;
    }
    $url = parse_url($endpoint);
    if (!$url || strtolower($url['scheme'] ?? '') !== 'https'
        || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
        || (isset($url['port']) && $url['port'] !== 443)) {
        return null;
    }
    $host = strtolower((string) ($url['host'] ?? ''));
    // Push services use DNS names. Reject literal, numeric and ambiguous IP forms.
    if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)$/D', $host)) {
        return null;
    }
    try {
        $addresses = $resolver ? $resolver($host) : null;
        if ($addresses === null && $resolver === null) {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                if (isset($record['ip'])) $addresses[] = $record['ip'];
                if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
            }
        }
        if (!$addresses || !is_array($addresses)) {
            return null;
        }
        foreach ($addresses as $ip) {
            if (!web_push_ip_is_public((string) $ip)) {
                return null;
            }
        }
        // Most shared hosts have IPv4 egress only. Keep IPv6-only destinations
        // supported, but do not let DNS answer order select an unusable AAAA.
        $ipv4 = array_filter($addresses, static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        return ['host' => $host, 'ip' => (string) ($ipv4 ? reset($ipv4) : reset($addresses))];
    } catch (Throwable $e) {
        return null;
    }
}

function web_push_ip_is_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    $packed = inet_pton($ip);
    $denied = ['192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
    foreach ($denied as $cidr) {
        [$network, $bits] = explode('/', $cidr);
        $network = inet_pton($network);
        if (strlen($network) !== strlen($packed)) continue;
        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;
        if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
            && ($remainder === 0 || (ord($packed[$bytes]) & (0xff << (8 - $remainder))) === (ord($network[$bytes]) & (0xff << (8 - $remainder))))) {
            return false;
        }
    }
    if (strlen($packed) === 4) {
        $bytes = array_values(unpack('C4', $packed));
        return $bytes[0] > 0 && $bytes[0] < 224
            && !($bytes[0] === 100 && $bytes[1] >= 64 && $bytes[1] <= 127);
    }
    // Only global unicast IPv6; reject mapped IPv4 and transition mechanisms.
    return (ord($packed[0]) & 0xe0) === 0x20;
}

/**
 * Save a push subscription for a user.
 */
function save_push_subscription(int $user_id, string $endpoint, string $p256dh = '', string $auth = ''): bool
{
    if ($user_id <= 0 || web_push_resolve_target($endpoint) === null) {
        return false;
    }
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
    $target = web_push_resolve_target($endpoint);
    if ($target === null) {
        return false;
    }
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
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY          => '',
        CURLOPT_RESOLVE        => [$target['host'] . ':443:' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
        // The response body is unused. Discard it without retaining it in memory.
        CURLOPT_WRITEFUNCTION  => static fn($ch, string $data): int => strlen($data),
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
