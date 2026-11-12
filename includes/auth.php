<?php
/**
 * Authentication Functions
 */

if (!function_exists('foxdesk_request_is_https')) {
    function foxdesk_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (!foxdesk_request_uses_trusted_proxy()) {
            return false;
        }

        $forwarded_proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwarded_proto === 'https') {
            return true;
        }

        $forwarded_ssl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if ($forwarded_ssl === 'on') {
            return true;
        }

        $cf_visitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cf_visitor !== '' && stripos($cf_visitor, '"scheme":"https"') !== false) {
            return true;
        }

        return false;
    }
}

if (!function_exists('foxdesk_request_uses_trusted_proxy')) {
    function foxdesk_request_uses_trusted_proxy(): bool
    {
        if (defined('TRUST_PROXY') && TRUST_PROXY) {
            return true;
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return false;
        }

        if ($remote === '127.0.0.1' || $remote === '::1' || str_starts_with($remote, '10.') || str_starts_with($remote, '192.168.')) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remote) === 1) {
            return true;
        }

        $trusted = trim((string) getenv('FOXDESK_TRUSTED_PROXIES'));
        if ($trusted === '') {
            return false;
        }

        $proxies = array_filter(array_map('trim', explode(',', $trusted)));
        return in_array($remote, $proxies, true);
    }
}

/**
 * Check if user is logged in
 */
function is_logged_in()
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

/**
 * Check if users.deleted_at column exists (for backward compatibility).
 */
function users_deleted_at_column_exists()
{
    return column_exists('users', 'deleted_at');
}

/**
 * Get current user
 */
function current_user($force_refresh = false)
{
    if (!is_logged_in()) {
        return null;
    }

    static $user = null;

    if ($user === null || $force_refresh) {
        $sql = "SELECT * FROM users WHERE id = ? AND is_active = 1";
        if (users_deleted_at_column_exists()) {
            $sql .= " AND deleted_at IS NULL";
        }
        $user = db_fetch_one($sql, [$_SESSION['user_id']]);
    }

    return $user;
}

/**
 * Update session with user data
 */
function refresh_user_session()
{
    $user = current_user(true);
    if ($user) {
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_avatar'] = $user['avatar'] ?? '';
        $lang = foxdesk_effective_user_language($user);
        $_SESSION['lang'] = $lang;
        unset($_SESSION['lang_override']);
    }
}

/**
 * Check if current user is admin
 */
function is_admin()
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

/**
 * Check if current user is agent or admin
 */
function is_agent()
{
    $user = current_user();
    return $user && in_array($user['role'], ['agent', 'admin']);
}

/**
 * Attempt login
 */
function login($email, $password)
{
    $sql = "SELECT * FROM users WHERE email = ? AND is_active = 1";
    if (users_deleted_at_column_exists()) {
        $sql .= " AND deleted_at IS NULL";
    }
    $user = db_fetch_one($sql, [$email]);

    if ($user && password_verify($password, $user['password'])) {
        // Clear any stale remember-me cookie from a previous user
        if (!empty($_COOKIE['foxdesk_remember'])) {
            clear_remember_cookie();
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role'] = $user['role'];
        $lang = foxdesk_effective_user_language($user);
        $_SESSION['lang'] = $lang;
        unset($_SESSION['lang_override']);
        return true;
    }

    return false;
}

/**
 * Logout user
 */
function logout()
{
    $user_id = $_SESSION['user_id'] ?? null;

    if ($user_id) {
        clear_remember_token($user_id);
    } else {
        clear_remember_cookie();
    }

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        session_destroy();
    }
}

// =============================================================================
// REMEMBER-ME (PERSISTENT LOGIN)
// =============================================================================

/**
 * Remember-me cookies are intentionally disabled for accounts protected by 2FA.
 * A persistent login token would otherwise bypass the second factor entirely.
 */
function remember_me_allowed_for_user(array $user): bool
{
    if (empty($user)) {
        return false;
    }

    if (defined('BASE_PATH') && file_exists(BASE_PATH . '/includes/totp.php')) {
        require_once BASE_PATH . '/includes/totp.php';
    }

    $role = (string) ($user['role'] ?? '');
    $totp_enabled = function_exists('is_2fa_enabled')
        ? is_2fa_enabled($user)
        : !empty($user['totp_enabled']);
    $role_requires_2fa = $role !== '' && function_exists('is_2fa_required_for_role')
        ? is_2fa_required_for_role($role)
        : false;

    return !$totp_enabled && !$role_requires_2fa;
}

/** Ensure the installed schema supports remember-me tokens. */
function ensure_remember_token_column()
{
    static $checked = false;
    if ($checked) return true;
    $checked = true;

    return schema_is_ready('remember-me authentication', [], ['users' => ['remember_token']]);
}

/**
 * Create a remember-me token for the user and set a 30-day cookie.
 */
function set_remember_token($user_id)
{
    if (!ensure_remember_token_column()) return;

    $user = db_fetch_one("SELECT * FROM users WHERE id = ? AND is_active = 1", [(int) $user_id]);
    if (!$user || !remember_me_allowed_for_user($user)) {
        try {
            db_update('users', ['remember_token' => null], 'id = ?', [(int) $user_id]);
        } catch (Throwable $e) {
            // Non-critical
        }
        clear_remember_cookie();
        return;
    }

    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $token);

    db_update('users', ['remember_token' => $hash], 'id = ?', [$user_id]);

    $is_https = foxdesk_request_is_https();
    setcookie('foxdesk_remember', $token, [
        'expires'  => time() + (defined('REMEMBER_ME_DURATION') ? REMEMBER_ME_DURATION : 2592000),
        'path'     => '/',
        'httponly'  => true,
        'secure'   => $is_https,
        'samesite' => 'Lax',
    ]);
}

/**
 * Validate the remember-me cookie and auto-login the user.
 *
 * @return bool True if the user was successfully auto-logged in.
 */
function validate_remember_token()
{
    if (empty($_COOKIE['foxdesk_remember'])) return false;
    if (!ensure_remember_token_column()) return false;

    $token = $_COOKIE['foxdesk_remember'];
    if (strlen($token) !== 64) {
        clear_remember_cookie();
        return false;
    }

    $hash = hash('sha256', $token);

    $sql = "SELECT * FROM users WHERE remember_token = ? AND is_active = 1";
    if (users_deleted_at_column_exists()) {
        $sql .= " AND deleted_at IS NULL";
    }
    $user = db_fetch_one($sql, [$hash]);

    if (!$user) {
        clear_remember_cookie();
        return false;
    }

    if (!remember_me_allowed_for_user($user)) {
        clear_remember_token((int) $user['id']);
        return false;
    }

    // Auto-login: populate session
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_role']  = $user['role'];

    $lang = foxdesk_effective_user_language($user);
    $_SESSION['lang'] = $lang;
    unset($_SESSION['lang_override']);

    // Rotate token for extra security (token is single-use)
    set_remember_token($user['id']);

    return true;
}

/**
 * Clear the remember-me token from DB for a specific user.
 */
function clear_remember_token($user_id)
{
    if (!ensure_remember_token_column()) return;
    try {
        db_update('users', ['remember_token' => null], 'id = ?', [$user_id]);
    } catch (Throwable $e) {
        // Non-critical
    }
    clear_remember_cookie();
}

/**
 * Delete the remember-me cookie.
 */
function clear_remember_cookie()
{
    $is_https = foxdesk_request_is_https();
    setcookie('foxdesk_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly'  => true,
        'secure'   => $is_https,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['foxdesk_remember']);
}

/**
 * Get user by ID
 */
function get_user($id)
{
    static $cache = [];
    $id = (int) $id;
    if (!isset($cache[$id])) {
        $cache[$id] = db_fetch_one("SELECT * FROM users WHERE id = ?", [$id]);
    }
    return $cache[$id];
}

/**
 * Get all users
 */
function get_all_users()
{
    $sql = "SELECT * FROM users";
    $conditions = [];
    if (users_deleted_at_column_exists()) {
        $conditions[] = "deleted_at IS NULL";
    }
    $conditions[] = "email NOT LIKE 'deleted-user-%@invalid.local'";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY first_name, last_name";
    return db_fetch_all($sql);
}

/**
 * Get all client users (role = user)
 */
function get_clients()
{
    $sql = "SELECT * FROM users WHERE role = 'user'";
    if (users_deleted_at_column_exists()) {
        $sql .= " AND deleted_at IS NULL";
    }
    $sql .= " AND email NOT LIKE 'deleted-user-%@invalid.local'";
    $sql .= " ORDER BY first_name, last_name";
    return db_fetch_all($sql);
}

/**
 * Create new user
 */
function create_user($email, $password, $first_name, $last_name = '', $role = 'user', $language = null)
{
    $hash = password_hash($password, PASSWORD_DEFAULT);

    return db_insert('users', [
        'email' => $email,
        'password' => $hash,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => $role,
        'language' => $language,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Update user password
 */
function update_password($user_id, $new_password)
{
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    return db_update('users', ['password' => $hash], 'id = ?', [$user_id]);
}
/**
 * Check if currently impersonating
 */
function is_impersonating()
{
    return isset($_SESSION['impersonator_id']);
}

// =============================================================================
// API TOKEN AUTHENTICATION
// =============================================================================

/**
 * Check if the current request uses Bearer token authentication
 */
function is_api_token_request()
{
    return bearer_token_from_request() !== '';
}

function bearer_token_from_request(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Bearer ') !== 0) {
        return '';
    }

    return trim(substr($header, 7));
}

/**
 * Check if the api_tokens table exists
 */
function api_tokens_table_exists()
{
    return table_exists('api_tokens');
}

function api_token_scope_catalog(array $user = null): array
{
    $user = $user ?: (current_user() ?: []);
    $is_staff = in_array((string) ($user['role'] ?? ''), ['admin', 'agent'], true);
    $can_time = function_exists('can_view_time') ? can_view_time($user) : $is_staff;

    $catalog = [
        'work:read' => 'Read Work queues',
        'tickets:read' => 'Read tickets',
        'tickets:write' => 'Create and update tickets',
        'comments:write' => 'Add ticket comments',
        'attachments:read' => 'Read attachment metadata',
        'attachments:write' => 'Upload attachments',
        'notifications:read' => 'Read notifications',
        'notifications:write' => 'Mark notifications read',
    ];

    if ($is_staff) {
        $catalog['users:read'] = 'Read users visible to this account';
        $catalog['clients:read'] = 'Read client overviews';
    }
    if ($is_staff || $can_time) {
        $catalog['time:read'] = 'Read time entries';
    }
    if ($is_staff) {
        $catalog['time:write'] = 'Add and control time entries';
        $catalog['delete:write'] = 'Delete comments and time entries; permanent ticket deletion also requires explicit user permission';
    }
    if (($user['role'] ?? '') === 'admin' || $can_time) {
        $catalog['reports:read'] = 'Read report billing reviews';
    }
    if (($user['role'] ?? '') === 'admin') {
        $catalog['reports:write'] = 'Prepare and publish reports';
    }

    return $catalog;
}

function api_token_allowed_scopes_for_user(array $user): array
{
    return array_keys(api_token_scope_catalog($user));
}

function api_token_normalize_scopes($scopes, array $user): array
{
    if ($scopes === null) {
        return ['*'];
    }
    if (is_string($scopes)) {
        $decoded = json_decode($scopes, true);
        $scopes = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $scopes);
    }

    $allowed = array_fill_keys(api_token_allowed_scopes_for_user($user), true);
    $normalized = [];
    foreach ((array) $scopes as $scope) {
        $scope = strtolower(trim((string) $scope));
        if ($scope !== '' && $scope !== '*' && isset($allowed[$scope])) {
            $normalized[$scope] = true;
        }
    }
    if (empty($normalized)) {
        foreach (['work:read', 'tickets:read'] as $scope) {
            if (isset($allowed[$scope])) {
                $normalized[$scope] = true;
            }
        }
    }

    return array_keys($normalized);
}

function api_token_current_row(): ?array
{
    return isset($GLOBALS['api_token_row']) && is_array($GLOBALS['api_token_row']) ? $GLOBALS['api_token_row'] : null;
}

function api_token_scopes_from_row(array $token_row): array
{
    if (!array_key_exists('scopes_json', $token_row) || trim((string) ($token_row['scopes_json'] ?? '')) === '') {
        return ['*'];
    }
    $decoded = json_decode((string) $token_row['scopes_json'], true);
    return is_array($decoded) && !empty($decoded)
        ? array_values(array_unique(array_map(static fn($scope) => strtolower(trim((string) $scope)), $decoded)))
        : ['*'];
}

function api_token_has_scope(string $scope): bool
{
    $token = api_token_current_row();
    if (!$token) {
        return false;
    }
    $scopes = api_token_scopes_from_row($token);
    if (in_array('*', $scopes, true)) {
        return true;
    }
    $scope = strtolower(trim($scope));
    if (in_array($scope, $scopes, true)) {
        return true;
    }
    [$resource] = array_pad(explode(':', $scope, 2), 2, '');
    return $resource !== '' && in_array($resource . ':*', $scopes, true);
}

function api_token_required_scope_for_action(string $action): ?string
{
    $map = [
        'upload' => 'attachments:write',
        'agent-docs' => null,
        'agent-me' => 'work:read',
        'agent-list-statuses' => 'tickets:read',
        'agent-list-priorities' => 'tickets:read',
        'agent-list-users' => 'users:read',
        'agent-create-ticket' => 'tickets:write',
        'agent-list-tickets' => 'tickets:read',
        'agent-get-ticket' => 'tickets:read',
        'agent-add-comment' => 'comments:write',
        'agent-add-update' => 'comments:write',
        'agent-add-work-entry' => 'comments:write',
        'agent-delete-comment' => 'comments:write',
        'agent-plan-work-log' => 'tickets:read',
        'agent-apply-work-log-plan' => 'tickets:write',
        'agent-update-status' => 'tickets:write',
        'agent-ticket-workflow' => 'tickets:write',
        'agent-log-time' => 'time:write',
        'agent-update-work-entry' => 'time:write',
        'agent-delete-ticket-preflight' => 'tickets:read',
        'agent-delete-ticket-permanently' => 'delete:write',
        'app-shell' => 'work:read',
        'app-home' => 'work:read',
        'app-ticket-list' => 'tickets:read',
        'app-ticket-detail' => 'tickets:read',
        'app-ticket-actions' => 'tickets:read',
        'app-create-ticket' => 'tickets:write',
        'app-add-comment' => 'comments:write',
        'app-attachment-metadata' => 'attachments:read',
        'app-ticket-timer' => 'time:read',
        'app-ticket-timer-action' => 'time:write',
        'app-log-time' => 'time:write',
        'app-update-time-entry' => 'time:write',
        'app-client-overview' => 'clients:read',
        'app-reporting-review' => 'reports:read',
        'app-notifications' => 'notifications:read',
        'app-notifications-summary' => 'notifications:read',
        'app-notification-read-state' => 'notifications:write',
    ];
    return $map[$action] ?? null;
}

function api_token_enforce_action_scope(string $action): void
{
    if (empty($GLOBALS['is_api_token_auth'])) {
        return;
    }
    // A valid token must always be able to introspect its own documentation,
    // allowed actions, and safety rules, even when it has only one narrow scope.
    if ($action === 'agent-docs') {
        return;
    }
    $required = api_token_required_scope_for_action($action);
    if ($required === null) {
        api_error('This endpoint is not available for API tokens.', 403);
    }
    if (!api_token_has_scope($required)) {
        api_error('API token scope is not allowed for this action.', 403);
    }
}

function api_token_action_is_write(string $action): bool
{
    return in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

const FOXDESK_API_TOKEN_RATE_LIMIT_WINDOW_SECONDS = 60;
const FOXDESK_API_TOKEN_RATE_LIMIT_DEFAULT_PER_MINUTE = 120;

function api_token_rate_limit_per_minute(): int
{
    $configured = getenv('FOXDESK_API_TOKEN_RATE_LIMIT_PER_MINUTE');
    if ($configured === false || trim((string) $configured) === '') {
        return FOXDESK_API_TOKEN_RATE_LIMIT_DEFAULT_PER_MINUTE;
    }

    return (int) $configured;
}

/**
 * Count the calls of one token inside the sliding 60-second window.
 *
 * Rows are written with PHP date() (see api_token_log_action()), so the window
 * start is computed with the same PHP clock instead of MySQL NOW(), which may
 * run in UTC. Rejected 429 responses are logged for audit but never counted,
 * otherwise retries would keep extending the block.
 */
function api_token_rate_limit_state(int $token_id, int $limit, ?int $now = null): array
{
    $now = $now ?? foxdesk_clock_time();
    $window = FOXDESK_API_TOKEN_RATE_LIMIT_WINDOW_SECONDS;
    $window_start = date('Y-m-d H:i:s', $now - $window);

    $row = db_fetch_one(
        "SELECT COUNT(*) AS total, MIN(created_at) AS oldest
         FROM api_token_audit_logs
         WHERE token_id = ? AND created_at > ? AND (status_code IS NULL OR status_code <> 429)",
        [$token_id, $window_start]
    );
    $used = (int) ($row['total'] ?? 0);

    $retry_after = 0;
    if ($used >= $limit) {
        // The window frees one slot when the oldest counted call is older than 60 s.
        $oldest_offset = max(0, $used - $limit);
        $oldest = db_fetch_one(
            "SELECT created_at FROM api_token_audit_logs
             WHERE token_id = ? AND created_at > ? AND (status_code IS NULL OR status_code <> 429)
             ORDER BY created_at ASC, id ASC LIMIT 1 OFFSET {$oldest_offset}",
            [$token_id, $window_start]
        );
        $oldest_ts = strtotime((string) ($oldest['created_at'] ?? $row['oldest'] ?? ''));
        $retry_after = $oldest_ts ? max(1, ($oldest_ts + $window) - $now) : $window;
        $retry_after = min($window, $retry_after);
    }

    return [
        'limit' => $limit,
        'used' => $used,
        'remaining' => max(0, $limit - $used),
        'retry_after' => $retry_after,
        'window_seconds' => $window,
    ];
}

function api_token_rate_limit_check(string $action): void
{
    $token = api_token_current_row();
    if (!$token || !table_exists('api_token_audit_logs')) {
        return;
    }

    $limit = api_token_rate_limit_per_minute();
    if ($limit <= 0) {
        return;
    }

    try {
        $state = api_token_rate_limit_state((int) $token['id'], $limit);
    } catch (Throwable $e) {
        // Never fail a valid request only because rate-limit bookkeeping failed.
        return;
    }

    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . $state['limit']);
    }

    if ($state['used'] >= $limit) {
        if (!headers_sent()) {
            header('X-RateLimit-Remaining: 0');
            header('Retry-After: ' . $state['retry_after']);
        }
        api_error('API token rate limit exceeded.', 429, [
            'error_code' => 'rate_limited',
            'retry_after_seconds' => $state['retry_after'],
            'rate_limit' => [
                'limit' => $state['limit'],
                'window_seconds' => $state['window_seconds'],
                'remaining' => 0,
            ],
        ]);
    }

    if (!headers_sent()) {
        // This request is counted once it finishes, so it uses one slot.
        header('X-RateLimit-Remaining: ' . max(0, $state['remaining'] - 1));
    }
}

function api_token_request_id(): string
{
    $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $incoming)) {
        return $incoming;
    }
    if (empty($GLOBALS['api_request_id'])) {
        $GLOBALS['api_request_id'] = bin2hex(random_bytes(12));
    }
    return $GLOBALS['api_request_id'];
}

function api_token_idempotency_key(): string
{
    $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    return ($key !== '' && strlen($key) <= 128 && preg_match('/^[A-Za-z0-9_.:-]+$/', $key)) ? $key : '';
}

function api_idempotency_request_hash(string $action): string
{
    $raw = (string) file_get_contents('php://input');
    $post = !empty($_POST) ? json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return hash('sha256', strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) . "\n" . $action . "\n" . $raw . "\n" . $post);
}

function api_idempotency_replay_if_available(string $action): void
{
    $token = api_token_current_row();
    $key = api_token_idempotency_key();
    if (!$token || $key === '' || !api_token_action_is_write($action) || !table_exists('api_idempotency_keys')) {
        return;
    }
    $request_hash = api_idempotency_request_hash($action);
    $GLOBALS['api_idempotency'] = [
        'key' => $key,
        'request_hash' => $request_hash,
        'action' => $action,
        'owns_reservation' => false,
    ];
    $reservation = [
        'token_id' => (int) $token['id'],
        'user_id' => (int) $token['user_id'],
        'idempotency_key' => $key,
        'action' => $action,
        'request_hash' => $request_hash,
        'response_json' => null,
        'status_code' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 300),
    ];
    $db = get_db();
    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $GLOBALS['api_idempotency']['transaction_started'] = true;
        }
        $reservation_id = (int) db_insert('api_idempotency_keys', $reservation);
        $GLOBALS['api_idempotency']['reservation_id'] = $reservation_id;
        $GLOBALS['api_idempotency']['owns_reservation'] = true;
        return;
    } catch (Throwable $e) {
        if (!empty($GLOBALS['api_idempotency']['transaction_started']) && $db->inTransaction()) {
            $db->rollBack();
        }
        $GLOBALS['api_idempotency']['transaction_started'] = false;
        // A concurrent request may already own the unique key.
    }

    $row = db_fetch_one("SELECT * FROM api_idempotency_keys WHERE token_id = ? AND action = ? AND idempotency_key = ? LIMIT 1", [(int) $token['id'], $action, $key]);
    if ($row && strtotime((string) ($row['expires_at'] ?? '')) <= time()) {
        db_delete('api_idempotency_keys', 'id = ? AND expires_at <= ?', [(int) $row['id'], foxdesk_db_datetime()]);
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $GLOBALS['api_idempotency']['transaction_started'] = true;
            }
            $reservation_id = (int) db_insert('api_idempotency_keys', $reservation);
            $GLOBALS['api_idempotency']['reservation_id'] = $reservation_id;
            $GLOBALS['api_idempotency']['owns_reservation'] = true;
            return;
        } catch (Throwable $e) {
            if (!empty($GLOBALS['api_idempotency']['transaction_started']) && $db->inTransaction()) {
                $db->rollBack();
            }
            $GLOBALS['api_idempotency']['transaction_started'] = false;
            $row = db_fetch_one("SELECT * FROM api_idempotency_keys WHERE token_id = ? AND action = ? AND idempotency_key = ? LIMIT 1", [(int) $token['id'], $action, $key]);
        }
    }
    if (!$row) {
        api_error('Unable to reserve idempotency key.', 503);
    }
    if (!hash_equals((string) $row['request_hash'], $request_hash)) {
        api_error('Idempotency key was already used with a different request.', 409);
    }
    if (!empty($row['response_json'])) {
        http_response_code((int) ($row['status_code'] ?? 200));
        header('Content-Type: application/json');
        header('X-Idempotent-Replay: true');
        echo $row['response_json'];
        exit;
    }
    header('Retry-After: 1');
    api_error('A request with this idempotency key is already in progress.', 409);
}

function api_idempotency_store_success(array $response): void
{
    $token = api_token_current_row();
    $state = $GLOBALS['api_idempotency'] ?? null;
    if (!$token || !is_array($state) || empty($state['key']) || !table_exists('api_idempotency_keys')) {
        return;
    }
    if (empty($state['owns_reservation']) || empty($state['reservation_id'])) {
        return;
    }
    try {
        db_update('api_idempotency_keys', [
            'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status_code' => http_response_code() ?: 200,
            'expires_at' => date('Y-m-d H:i:s', time() + 2592000),
        ], 'id = ? AND request_hash = ?', [(int) $state['reservation_id'], (string) $state['request_hash']]);
        $db = get_db();
        if (!empty($state['transaction_started']) && $db->inTransaction()) {
            $db->commit();
        }
        if (function_exists('ticket_permanent_delete_run_after_commit_callbacks')) {
            ticket_permanent_delete_run_after_commit_callbacks();
        }
        $GLOBALS['api_idempotency']['owns_reservation'] = false;
        $GLOBALS['api_idempotency']['transaction_started'] = false;
    } catch (Throwable $e) {
        $db = get_db();
        if (!empty($state['transaction_started']) && $db->inTransaction()) {
            $db->rollBack();
        }
        $GLOBALS['api_idempotency']['owns_reservation'] = false;
        $GLOBALS['api_idempotency']['transaction_started'] = false;
        $GLOBALS['ticket_after_commit_callbacks'] = [];
        throw new RuntimeException('FoxDesk could not atomically persist the API operation.', 0, $e);
    }
}

function api_idempotency_release_pending(): void
{
    $state = $GLOBALS['api_idempotency'] ?? null;
    if (!is_array($state) || empty($state['owns_reservation']) || empty($state['reservation_id'])) {
        return;
    }
    try {
        $db = get_db();
        if (!empty($state['transaction_started']) && $db->inTransaction()) {
            $db->rollBack();
            $GLOBALS['ticket_after_commit_callbacks'] = [];
        } else {
            db_delete('api_idempotency_keys', 'id = ? AND response_json IS NULL', [(int) $state['reservation_id']]);
        }
    } catch (Throwable $e) {
        error_log('FoxDesk could not release pending idempotency key: ' . $e->getMessage());
    }
    $GLOBALS['api_idempotency']['owns_reservation'] = false;
    $GLOBALS['api_idempotency']['transaction_started'] = false;
}

function api_token_resource_from_response(string $action, array $response): array
{
    if ($action === 'agent-delete-ticket-permanently') {
        return ['ticket_deletion', null];
    }
    foreach (['ticket_id' => 'ticket', 'comment_id' => 'comment', 'time_entry_id' => 'time_entry', 'attachment_id' => 'attachment'] as $key => $type) {
        if (isset($response[$key])) {
            return [$type, (int) $response[$key]];
        }
    }
    if (isset($response['data']) && is_array($response['data'])) {
        return api_token_resource_from_response($action, $response['data']);
    }
    return [str_starts_with($action, 'app-') ? substr($action, 4) : $action, null];
}

function api_token_log_action(string $action, array $response = [], int $status_code = 200): void
{
    $token = api_token_current_row();
    $user = current_user();
    if (!$token || !$user || !table_exists('api_token_audit_logs')) {
        return;
    }
    [$resource_type, $resource_id] = api_token_resource_from_response($action, $response);
    try {
        db_insert('api_token_audit_logs', [
            'token_id' => (int) $token['id'],
            'user_id' => (int) $user['id'],
            'action' => $action,
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'resource_type' => $resource_type,
            'resource_id' => $resource_id,
            'status_code' => $status_code,
            'request_id' => api_token_request_id(),
            'idempotency_key' => api_token_idempotency_key() ?: null,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            // Same clock as api_token_rate_limit_state(); never compare with MySQL NOW().
            'created_at' => foxdesk_db_datetime(),
        ]);
    } catch (Throwable $e) {
    }
}

/**
 * Authenticate a request using a Bearer API token.
 *
 * Extracts the token from the Authorization header, hashes it, and looks up
 * the hash in the api_tokens table. On success, populates $_SESSION so that
 * current_user(), is_admin(), is_agent() etc. work transparently.
 *
 * @return array|null  The user row on success, null on failure.
 */
function authenticate_api_token()
{
    if (!api_tokens_table_exists()) {
        return null;
    }

    $raw_token = bearer_token_from_request();
    if ($raw_token === '' || strlen($raw_token) < 10) {
        return null;
    }

    $token_hash = hash('sha256', $raw_token);

    $sql = "SELECT * FROM api_tokens WHERE token_hash = ? AND is_active = 1";
    if (column_exists('api_tokens', 'revoked_at')) {
        $sql .= " AND revoked_at IS NULL";
    }
    $token_row = db_fetch_one($sql, [$token_hash]);

    if (!$token_row) {
        return null;
    }

    // Check expiration
    if (!empty($token_row['expires_at']) && strtotime($token_row['expires_at']) < time()) {
        return null;
    }

    // Load the linked user
    $sql = "SELECT * FROM users WHERE id = ? AND is_active = 1";
    if (users_deleted_at_column_exists()) {
        $sql .= " AND deleted_at IS NULL";
    }
    $user = db_fetch_one($sql, [$token_row['user_id']]);

    if (!$user) {
        return null;
    }

    // Populate session so existing helpers work
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['api_token_id'] = (int) $token_row['id'];
    $GLOBALS['api_token_row'] = $token_row;

    // Update last_used_at (fire-and-forget, don't fail on error)
    update_token_last_used((int) $token_row['id']);

    return $user;
}

/**
 * Update the last_used_at timestamp of an API token.
 */
function update_token_last_used($token_id)
{
    try {
        $data = ['last_used_at' => date('Y-m-d H:i:s')];
        if (column_exists('api_tokens', 'last_used_ip')) {
            $data['last_used_ip'] = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
        }
        if (column_exists('api_tokens', 'last_used_user_agent')) {
            $data['last_used_user_agent'] = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;
        }
        db_update('api_tokens', $data, 'id = ?', [$token_id]);
    } catch (Throwable $e) {
        // Non-critical — don't break the request
    }
}

/**
 * Generate a new API token.
 *
 * @param int    $user_id  The user this token belongs to.
 * @param string $name     A human-readable label.
 * @param string|null $expires_at  Optional expiration datetime.
 * @return array  ['token' => full plain-text token, 'id' => row id, 'scopes' => granted scopes]
 */
function generate_api_token($user_id, $name, $expires_at = null, $scopes = null)
{
    $token_user = get_user((int) $user_id);
    if (!$token_user) {
        return ['token' => null, 'id' => null, 'scopes' => []];
    }

    $granted_scopes = api_token_normalize_scopes($scopes, $token_user);
    $expires_at = $expires_at !== null && trim((string) $expires_at) !== '' ? $expires_at : null;
    $raw_token = 'fdx_' . bin2hex(random_bytes(24));
    $token_hash = hash('sha256', $raw_token);
    $token_prefix = substr($raw_token, 0, 8);

    $data = [
        'user_id' => (int) $user_id,
        'name' => $name,
        'token_hash' => $token_hash,
        'token_prefix' => $token_prefix,
        'expires_at' => $expires_at,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if (column_exists('api_tokens', 'scopes_json')) {
        $data['scopes_json'] = json_encode($granted_scopes, JSON_UNESCAPED_SLASHES);
    }

    $id = db_insert('api_tokens', $data);

    return ['token' => $raw_token, 'id' => $id, 'scopes' => $granted_scopes];
}

/**
 * Change the scopes of an existing, active API token without issuing a new key.
 *
 * The token must belong to $owner. Scopes are normalized against what the
 * owner may grant, exactly as on creation, so an edit can never widen a token
 * beyond its owner's role. Returns the stored scopes, or null when the token
 * is not found, inactive, or not owned.
 */
function update_api_token_scopes(int $token_id, array $owner, $scopes): ?array
{
    if ($token_id <= 0 || empty($owner['id']) || !api_tokens_table_exists()
        || !column_exists('api_tokens', 'scopes_json')) {
        return null;
    }

    $where = 'id = ? AND user_id = ? AND is_active = 1';
    $params = [$token_id, (int) $owner['id']];
    if (column_exists('api_tokens', 'revoked_at')) {
        $where .= ' AND revoked_at IS NULL';
    }

    $token = db_fetch_one("SELECT id, scopes_json FROM api_tokens WHERE {$where} LIMIT 1", $params);
    if (!$token) {
        return null;
    }

    $granted = api_token_normalize_scopes(is_array($scopes) ? array_values($scopes) : $scopes, $owner);
    db_update(
        'api_tokens',
        ['scopes_json' => json_encode($granted, JSON_UNESCAPED_SLASHES)],
        $where,
        $params
    );

    return $granted;
}

/**
 * Revoke an API token (soft-disable).
 */
function revoke_api_token($token_id)
{
    $data = ['is_active' => 0];
    if (column_exists('api_tokens', 'revoked_at')) {
        $data['revoked_at'] = date('Y-m-d H:i:s');
    }
    return db_update('api_tokens', $data, 'id = ?', [$token_id]);
}
