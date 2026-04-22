<?php
/**
 * FoxDesk - Main Entry Point
 *
 * This file handles all routing and page loading.
 * Works on any PHP hosting (PHP 8.1+)
 */

// Define base path early so session storage can live inside the app directory.
define('BASE_PATH', __DIR__);

// Constants (must be defined before session init)
define('SESSION_LIFETIME', 2592000); // 30 days
define('REMEMBER_ME_DURATION', 30 * 86400); // 30 days

require_once BASE_PATH . '/includes/session-bootstrap.php';

define('APP_VERSION', '0.3.107');

// Check if installed
if (!file_exists(BASE_PATH . '/config.php')) {
    header('Location: install.php');
    exit;
}

// Prevent config warnings from breaking session headers during bootstrap.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load configuration
require_once BASE_PATH . '/config.php';

// Error reporting (debug on localhost or when APP_DEBUG is set)
$debug = defined('APP_DEBUG') ? APP_DEBUG : (
    strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
    ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1'
);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

require_once BASE_PATH . '/includes/database.php';
foxdesk_bootstrap_session();

// Force UTF-8 for all HTML responses to prevent mojibake in translations.
ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

// Maintenance mode – shown during update/rollback operations.
// The .maintenance file is created by apply_update()/rollback_update()
// and automatically removed on completion. If update crashes, auto-expires
// after 10 minutes so the app is never permanently locked out.
$maintenance_file = BASE_PATH . '/.maintenance';
if (file_exists($maintenance_file)) {
    $mtime = (int) @file_get_contents($maintenance_file);
    if ($mtime > 0 && (time() - $mtime) < 600) {
        // Allow the admin who started the update to pass through
        // (their session has 'maintenance_bypass' flag set by apply_update)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['maintenance_bypass'])) {
            // Admin performing the update — let them through
        } else {
            http_response_code(503);
            header('Retry-After: 120');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Maintenance</title>';
            echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
            echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
            echo '@keyframes spin{to{transform:rotate(360deg)}}</style>';
            echo '<meta http-equiv="refresh" content="15"></head>';
            echo '<body><div class="box"><div class="spinner"></div>';
            echo '<div style="font-weight:600;font-size:1.1rem">System is updating</div>';
            echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">Please try again in a few minutes. This page refreshes automatically.</div>';
            echo '</div></body></html>';
            exit;
        }
    } else {
        // Expired — auto-cleanup
        @unlink($maintenance_file);
    }
}

// If a docker placeholder DB host is present on non-docker hosting, reopen installer.
if (defined('DB_HOST')) {
    $configured_db_host = strtolower(trim((string) DB_HOST));
    if ($configured_db_host === 'db') {
        $resolved = gethostbyname('db');
        if ($resolved === 'db') {
            header('Location: install.php?force=1&reason=db_host');
            exit;
        }
    }
}

require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';
send_security_headers();

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Pages that don't require login
$public_pages = ['login', 'logout', 'forgot-password', 'reset-password', 'ticket-share', 'report-share', 'report-public', 'api', 'health', 'cron'];

// Check authentication
if (!in_array($page, $public_pages)) {
    if (!is_logged_in()) {
        // Try auto-login from remember-me cookie
        if (!validate_remember_token()) {
            header('Location: index.php?page=login');
            exit;
        }
    }

    // Security: Ensure session user actually exists in DB
    if (!current_user()) {
        // If an impersonated account became inactive/deleted, restore admin session instead of hard logout.
        if (!empty($_SESSION['impersonator_id'])) {
            $admin_id = (int) $_SESSION['impersonator_id'];
            if ($admin_id > 0) {
                $admin_sql = "SELECT * FROM users WHERE id = ? AND is_active = 1";
                $deleted_at_supported = function_exists('users_deleted_at_column_exists')
                    ? users_deleted_at_column_exists()
                    : false;
                if ($deleted_at_supported) {
                    $admin_sql .= " AND deleted_at IS NULL";
                }
                $admin = db_fetch_one($admin_sql, [$admin_id]);

                if ($admin) {
                    if (function_exists('log_security_event')) {
                        log_security_event('impersonation_auto_restored', $admin_id, json_encode([
                            'admin_id' => $admin_id,
                            'reason' => 'impersonated_user_inactive_or_deleted'
                        ]));
                    }

                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_email'] = $admin['email'];
                    $_SESSION['user_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                    $_SESSION['user_role'] = $admin['role'];
                    $_SESSION['user_avatar'] = $admin['avatar'] ?? '';

                    flash(t('Archived users cannot be impersonated.'), 'warning');
                    header('Location: index.php?page=admin&section=users');
                    exit;
                }
            }
        }

        logout();
        header('Location: index.php?page=login');
        exit;
    }
}

// Live 2FA enforcement: check on every page load whether user's role requires 2FA
// Handles both: (a) flag already set from login, (b) admin enabling requirement while user is logged in
// Also clears the flag if admin removes the requirement or user completes setup
if (!empty($_SESSION['user_id']) && $page !== 'login' && $page !== 'logout' && $page !== 'api' && $page !== 'cron' && $page !== 'health') {
    require_once BASE_PATH . '/includes/totp.php';
    ensure_totp_columns();
    $role = $_SESSION['user_role'] ?? '';
    $role_requires = ($role !== '' && is_2fa_required_for_role($role));

    if ($role_requires) {
        $u = db_fetch_one("SELECT totp_enabled FROM users WHERE id = ?", [$_SESSION['user_id']]);
        if ($u && empty($u['totp_enabled'])) {
            // 2FA required but not set up — lock user to profile page
            $_SESSION['2fa_setup_required'] = true;
            if ($page !== 'profile') {
                header('Location: index.php?page=profile&setup2fa=1');
                exit;
            }
        } else {
            // User has completed 2FA setup — clear the flag
            unset($_SESSION['2fa_setup_required']);
        }
    } else {
        // Requirement was removed by admin — clear the flag
        if (!empty($_SESSION['2fa_setup_required'])) {
            unset($_SESSION['2fa_setup_required']);
        }
    }
}

// Health check endpoint (for uptime monitoring)
if ($page === 'health') {
    header('Content-Type: application/json');
    $health = ['status' => 'ok', 'version' => APP_VERSION];
    try {
        db_fetch_one("SELECT 1");
        $health['db'] = true;
    } catch (Throwable $e) {
        $health['status'] = 'error';
        $health['db'] = false;
    }
    $health['php'] = PHP_VERSION;
    $health['timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
    echo json_encode($health);
    exit;
}

// Pseudo-cron endpoint (background task runner)
if ($page === 'cron') {
    require_once BASE_PATH . '/pages/cron.php';
    exit;
}

// API endpoints
if ($page === 'api') {
    header('Content-Type: application/json');
    require_once BASE_PATH . '/includes/api.php';
    exit;
}

// Log page view for authenticated users (lightweight activity tracking)
if (is_logged_in() && function_exists('page_views_table_exists')) {
    try {
        if (ensure_page_views_table()) {
            $pv_section = ($page === 'admin' && isset($_GET['section'])) ? $_GET['section'] : null;
            db_insert('page_views', [
                'user_id' => (int) $_SESSION['user_id'],
                'page' => $page,
                'section' => $pv_section,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (Throwable $e) { /* silent */ }
}

// Log request to debug_log if available
if (function_exists('debug_log')) {
    $log_post = $_POST;
    // Sanitize sensitive fields
    foreach (['password', 'smtp_pass', 'current_password', 'confirm_password', 'password_confirmation'] as $field) {
        if (isset($log_post[$field]))
            $log_post[$field] = '***';
    }

    debug_log("Request: $page" . ($action ? " action=$action" : ""), [
        'method' => $_SERVER['REQUEST_METHOD'],
        'page' => $page,
        'action' => $action,
        'get' => $_GET,
        'post' => $log_post
    ], 'info', 'request');
}

// Route to appropriate page
switch ($page) {
    case 'login':
        require_once BASE_PATH . '/pages/login.php';
        break;

    case 'logout':
        logout();
        header('Location: index.php?page=login');
        exit;
        break;

    case 'forgot-password':
        require_once BASE_PATH . '/pages/forgot-password.php';
        break;

    case 'reset-password':
        require_once BASE_PATH . '/pages/reset-password.php';
        break;

    case 'dashboard':
        require_once BASE_PATH . '/pages/dashboard.php';
        break;

    case 'tickets':
        require_once BASE_PATH . '/pages/tickets.php';
        break;

    case 'ticket':
        require_once BASE_PATH . '/pages/ticket-detail.php';
        break;

    case 'ticket-share':
        require_once BASE_PATH . '/pages/ticket-share.php';
        break;

    case 'report-share':
        require_once BASE_PATH . '/pages/report-share.php';
        break;

    case 'report-public':
        require_once BASE_PATH . '/pages/report-public.php';
        break;

    case 'new-ticket':
        require_once BASE_PATH . '/pages/new-ticket.php';
        break;

    case 'admin':
        $admin_page = isset($_GET['section']) ? $_GET['section'] : 'statuses';

        // Agents can access reports section; everything else requires admin
        $agent_allowed_sections = ['reports'];
        if (!is_admin() && !(is_agent() && in_array($admin_page, $agent_allowed_sections, true))) {
            header('Location: index.php?page=dashboard');
            exit;
        }

        switch ($admin_page) {
            case 'statuses':
                require_once BASE_PATH . '/pages/admin/statuses.php';
                break;
            case 'priorities':
                require_once BASE_PATH . '/pages/admin/priorities.php';
                break;
            case 'ticket-types':
                require_once BASE_PATH . '/pages/admin/ticket-types.php';
                break;
            case 'organizations':
                require_once BASE_PATH . '/pages/admin/organizations.php';
                break;
            case 'clients':
                require_once BASE_PATH . '/pages/admin/clients.php';
                break;
            case 'users':
                require_once BASE_PATH . '/pages/admin/users.php';
                break;
            case 'settings':
                require_once BASE_PATH . '/pages/admin/settings.php';
                break;
            case 'reports':
                require_once BASE_PATH . '/pages/admin/reports.php';
                break;
            case 'reports-list':
                require_once BASE_PATH . '/pages/admin/reports-list.php';
                break;
            case 'report-builder':
                require_once BASE_PATH . '/pages/admin/report-builder.php';
                break;
            case 'recurring-tasks':
                require_once BASE_PATH . '/pages/admin/recurring-tasks.php';
                break;
            case 'agent-connect':
                require_once BASE_PATH . '/pages/admin/agent-connect.php';
                break;
            case 'activity':
                require_once BASE_PATH . '/pages/admin/activity.php';
                break;
            default:
                require_once BASE_PATH . '/pages/admin/statuses.php';
        }
        break;

    case 'notifications':
        require_once BASE_PATH . '/pages/notifications.php';
        break;

    case 'profile':
        require_once BASE_PATH . '/pages/profile.php';
        break;

    case 'user-profile':
        require_once BASE_PATH . '/pages/user-profile.php';
        break;

    case 'impersonate':
        // Handle stop impersonation
        if (isset($_GET['stop'])) {
            if (isset($_SESSION['impersonator_id'])) {
                // Restore original session
                $admin_id = $_SESSION['impersonator_id'];
                $impersonated_user_id = $_SESSION['user_id'];

                // Fetch admin user data to re-populate session correctly
                $admin = db_fetch_one("SELECT * FROM users WHERE id = ?", [$admin_id]);

                if ($admin) {
                    // Log impersonation end for security audit
                    log_security_event('impersonation_ended', $admin_id, json_encode([
                        'admin_id' => $admin_id,
                        'impersonated_user_id' => $impersonated_user_id,
                        'admin_email' => $admin['email']
                    ]));

                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_email'] = $admin['email'];
                    $_SESSION['user_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                    $_SESSION['user_role'] = $admin['role'];
                    $_SESSION['user_avatar'] = $admin['avatar'] ?? '';

                    flash(t('Welcome back, Admin.'), 'success');
                }
            }
            // Always redirect to users list after stopping
            if (ob_get_level() > 0) {
                ob_clean();
            }
            header('Location: index.php?page=admin&section=users');
            exit;
        }

        // Handle start impersonation (Admin only)
        if (!is_admin()) {
            // Log unauthorized impersonation attempt
            log_security_event('impersonation_denied', $_SESSION['user_id'] ?? null, json_encode([
                'attempted_by' => $_SESSION['user_id'] ?? 'unknown',
                'reason' => 'not_admin'
            ]));
            flash(t('Access denied.'), 'error');
            header('Location: index.php?page=dashboard');
            exit;
        }

        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if ($user_id > 0) {
            $target_user = get_user($user_id);

            if ($target_user) {
                if ((int) ($target_user['is_active'] ?? 0) !== 1) {
                    flash(t('Archived users cannot be impersonated.'), 'warning');
                    header('Location: index.php?page=admin&section=users');
                    exit;
                }

                // Don't allow impersonating yourself
                if ($target_user['id'] == $_SESSION['user_id']) {
                    flash(t('You cannot impersonate yourself.'), 'warning');
                    header('Location: index.php?page=admin&section=users');
                    exit;
                }

                // Store original admin ID if not already stored
                $original_admin_id = $_SESSION['impersonator_id'] ?? $_SESSION['user_id'];
                if (!isset($_SESSION['impersonator_id'])) {
                    $_SESSION['impersonator_id'] = $_SESSION['user_id'];
                }

                // Log impersonation start for security audit
                log_security_event('impersonation_started', $original_admin_id, json_encode([
                    'admin_id' => $original_admin_id,
                    'admin_email' => $_SESSION['user_email'],
                    'target_user_id' => $target_user['id'],
                    'target_user_email' => $target_user['email'],
                    'target_user_role' => $target_user['role']
                ]));

                // Set session to target user
                session_regenerate_id(true);
                $_SESSION['user_id'] = $target_user['id'];
                $_SESSION['user_email'] = $target_user['email'];
                $_SESSION['user_name'] = $target_user['first_name'] . ' ' . $target_user['last_name'];
                $_SESSION['user_role'] = $target_user['role'];
                $_SESSION['user_avatar'] = $target_user['avatar'] ?? '';
                // Persist impersonator_id is automatic as $_SESSION is preserved
                // Here we are NOT clearing $_SESSION, just overwriting keys.
                // So $_SESSION['impersonator_id'] is safe.

                flash(t('You are now logged in as') . ' ' . $target_user['first_name'], 'success');
                session_write_close(); // Ensure write before redirect
                if (ob_get_level() > 0) {
                    ob_clean(); // Clean any accidental output before headers
                }
                header('Location: index.php?page=dashboard');
                exit;
            }
        }

        header('Location: index.php?page=admin&section=users');
        exit;
        break;

    default:
        require_once BASE_PATH . '/pages/dashboard.php';
}
