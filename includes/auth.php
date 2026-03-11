<?php
/**
 * Authentication Functions
 */

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
        $allowed_langs = ['en', 'cs', 'de', 'it', 'es'];
        $lang = strtolower(trim((string) ($user['language'] ?? '')));
        if (!in_array($lang, $allowed_langs, true)) {
            $lang = strtolower(trim((string) get_setting('app_language', 'en')));
            if (!in_array($lang, $allowed_langs, true)) {
                $lang = 'en';
            }
        }
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
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role'] = $user['role'];
        $allowed_langs = ['en', 'cs', 'de', 'it', 'es'];
        $lang = strtolower(trim((string) ($user['language'] ?? '')));
        if (!in_array($lang, $allowed_langs, true)) {
            $lang = strtolower(trim((string) get_setting('app_language', 'en')));
            if (!in_array($lang, $allowed_langs, true)) {
                $lang = 'en';
            }
        }
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
    $_SESSION = [];
    session_destroy();

    // Clear remember-me token
    if ($user_id) {
        clear_remember_token($user_id);
    }
}

// =============================================================================
// REMEMBER-ME (PERSISTENT LOGIN)
// =============================================================================

/**
 * Ensure the remember_token column exists on users table (auto-migration).
 */
function ensure_remember_token_column()
{
    static $checked = false;
    if ($checked) return true;
    $checked = true;

    if (!column_exists('users', 'remember_token')) {
        try {
            db_query("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) DEFAULT NULL");
        } catch (Throwable $e) {
            return false;
        }
    }
    return true;
}

/**
 * Create a remember-me token for the user and set a 30-day cookie.
 */
function set_remember_token($user_id)
{
    if (!ensure_remember_token_column()) return;

    $token = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $token);

    db_update('users', ['remember_token' => $hash], 'id = ?', [$user_id]);

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('foxdesk_remember', $token, [
        'expires'  => time() + (30 * 24 * 60 * 60), // 30 days
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

    // Auto-login: populate session
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_role']  = $user['role'];

    $allowed_langs = ['en', 'cs', 'de', 'it', 'es'];
    $lang = strtolower(trim((string) ($user['language'] ?? '')));
    if (!in_array($lang, $allowed_langs, true)) {
        $lang = strtolower(trim((string) get_setting('app_language', 'en')));
        if (!in_array($lang, $allowed_langs, true)) {
            $lang = 'en';
        }
    }
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
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
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
    return db_fetch_one("SELECT * FROM users WHERE id = ?", [$id]);
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
function create_user($email, $password, $first_name, $last_name = '', $role = 'user', $language = 'en')
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
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return stripos($header, 'Bearer ') === 0;
}

/**
 * Check if the api_tokens table exists
 */
function api_tokens_table_exists()
{
    return table_exists('api_tokens');
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

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Bearer ') !== 0) {
        return null;
    }

    $raw_token = trim(substr($header, 7));
    if ($raw_token === '' || strlen($raw_token) < 10) {
        return null;
    }

    $token_hash = hash('sha256', $raw_token);

    $token_row = db_fetch_one(
        "SELECT * FROM api_tokens WHERE token_hash = ? AND is_active = 1",
        [$token_hash]
    );

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
        db_update('api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$token_id]);
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
 * @return array  ['token' => full plain-text token, 'id' => row id]
 */
function generate_api_token($user_id, $name, $expires_at = null)
{
    $raw_token = 'ahd_' . bin2hex(random_bytes(20)); // 44 chars total
    $token_hash = hash('sha256', $raw_token);
    $token_prefix = substr($raw_token, 0, 8);

    $id = db_insert('api_tokens', [
        'user_id' => (int) $user_id,
        'name' => $name,
        'token_hash' => $token_hash,
        'token_prefix' => $token_prefix,
        'expires_at' => $expires_at,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return ['token' => $raw_token, 'id' => $id];
}

/**
 * Revoke an API token (soft-disable).
 */
function revoke_api_token($token_id)
{
    return db_update('api_tokens', ['is_active' => 0], 'id = ?', [$token_id]);
}


