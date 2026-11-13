<?php
/**
 * FoxDesk - Database Upgrade Script
 *
 * Run this after updating files to apply database changes.
 *
 * Access control:
 *   - Logged-in admin: runs automatically
 *   - CLI: runs automatically (php upgrade.php)
 *   - Anonymous web: requires ?token=FIRST_16_CHARS_OF_SECRET_KEY
 *
 * Delete this file after use on shared hosting.
 */

define('BASE_PATH', __DIR__);
define('SESSION_LIFETIME', 2592000);

ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

// Check if installed
if (!file_exists(BASE_PATH . '/config.php')) {
    die('The app is not installed. Run install.php');
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/session-bootstrap.php';
foxdesk_bootstrap_session();

// ── Access control ──────────────────────────────────────────────────────────
// CLI always allowed; logged-in admins always allowed; anonymous web needs token.
$is_cli = (php_sapi_name() === 'cli');
$is_admin_session = false;

if (!$is_cli && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    try {
        $u = db_fetch_one("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $is_admin_session = ($u && ($u['role'] ?? '') === 'admin');
    } catch (Throwable $e) {
        // DB may be broken — allow token fallback
    }
}

if (!$is_cli && !$is_admin_session) {
    $token = trim($_GET['token'] ?? '');
    $secret = defined('SECRET_KEY') ? SECRET_KEY : '';
    if ($secret === '' || $token === '' || ($token !== substr($secret, 0, 16) && $token !== $secret)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Upgrade</title></head><body>';
        echo '<h2>Access Denied</h2>';
        echo '<p>Database upgrade requires authentication.</p>';
        echo '<p>Either log in as admin first, or use: <code>upgrade.php?token=FIRST_16_CHARS_OF_SECRET_KEY</code></p>';
        echo '</body></html>';
        exit;
    }
}

$messages = [];

function add_index_if_missing($table, $index_name, $sql)
{
    global $messages;
    try {
        $check = db_fetch_one("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$index_name]);
        if (!$check) {
            db_query($sql);
            $messages[] = "OK: Added index `$index_name` on `$table`";
        }
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add index $index_name on $table: " . $e->getMessage();
    }
}

// Create persistent sessions table
$check = db_fetch_one("SHOW TABLES LIKE 'app_sessions'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE app_sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                session_data MEDIUMBLOB NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_last_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `app_sessions`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table app_sessions: " . $e->getMessage();
    }
}

// Create organizations table
$check = db_fetch_one("SHOW TABLES LIKE 'organizations'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE organizations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                ico VARCHAR(20),
                address TEXT,
                contact_email VARCHAR(255),
                contact_phone VARCHAR(50),
                notes TEXT,
                logo TEXT,
                billable_rate DECIMAL(10,2) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `organizations`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table organizations: " . $e->getMessage();
    }
}

// Create priorities table
$check = db_fetch_one("SHOW TABLES LIKE 'priorities'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE priorities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                slug VARCHAR(100) NOT NULL UNIQUE,
                color VARCHAR(7) DEFAULT '#0a84ff',
                icon VARCHAR(50) DEFAULT 'fa-flag',
                sort_order INT DEFAULT 0,
                is_default TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `priorities`";

        // Insert default priorities
        $priorities = [
            ['Low', 'low', '#34c759', 'fa-arrow-down', 1, 0],
            ['Medium', 'medium', '#0a84ff', 'fa-minus', 2, 1],
            ['High', 'high', '#ff9f0a', 'fa-arrow-up', 3, 0],
            ['Urgent', 'urgent', '#ff3b30', 'fa-exclamation', 4, 0]
        ];

        $stmt = get_db()->prepare("INSERT INTO priorities (name, slug, color, icon, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($priorities as $p) {
            $stmt->execute($p);
        }
        $messages[] = "OK: Added default priorities";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table priorities: " . $e->getMessage();
    }
}

// Create ticket_types table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_types'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                slug VARCHAR(100) NOT NULL UNIQUE,
                icon VARCHAR(50) DEFAULT 'fa-file-alt',
                color VARCHAR(7) DEFAULT '#0a84ff',
                sort_order INT DEFAULT 0,
                is_default TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `ticket_types`";

        // Insert default ticket types
        $types = [
            ['General', 'general', 'fa-file-alt', '#0a84ff', 1, 1],
            ['Quote request', 'quote', 'fa-coins', '#ff9f0a', 2, 0],
            ['Inquiry', 'inquiry', 'fa-question-circle', '#5e5ce6', 3, 0],
            ['Bug report', 'bug', 'fa-bug', '#ff3b30', 4, 0]
        ];

        $stmt = get_db()->prepare("INSERT INTO ticket_types (name, slug, icon, color, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($types as $t) {
            $stmt->execute($t);
        }
        $messages[] = "OK: Added default ticket types";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table ticket_types: " . $e->getMessage();
    }
}

// Create ticket_shares table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_shares'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_by INT,
                expires_at DATETIME,
                is_revoked TINYINT(1) DEFAULT 0,
                last_accessed_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_ticket (ticket_id),
                INDEX idx_revoked (is_revoked),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: OK: Created table `ticket_shares`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `ticket_shares`: " . $e->getMessage();
    }
}

// Create report_shares table
$check = db_fetch_one("SHOW TABLES LIKE 'report_shares'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE report_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_by INT,
                expires_at DATETIME,
                is_revoked TINYINT(1) DEFAULT 0,
                last_accessed_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org (organization_id),
                INDEX idx_revoked (is_revoked),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `report_shares`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `report_shares`: " . $e->getMessage();
    }
}

// Create ticket_access table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_access'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_access (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                created_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_user (ticket_id, user_id),
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_ticket (ticket_id),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `ticket_access`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `ticket_access`: " . $e->getMessage();
    }
}

// Create ticket_time_entries table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_time_entries'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_time_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                comment_id INT,
                started_at DATETIME NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                paused_at DATETIME DEFAULT NULL,
                paused_seconds INT DEFAULT 0,
                duration_minutes INT DEFAULT 0,
                is_billable TINYINT(1) DEFAULT 1,
                billable_rate DECIMAL(10,2) DEFAULT 0,
                cost_rate DECIMAL(10,2) DEFAULT 0,
                is_manual TINYINT(1) DEFAULT 0,
                summary TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL,
                INDEX idx_ticket (ticket_id),
                INDEX idx_user (user_id),
                INDEX idx_comment (comment_id),
                INDEX idx_started (started_at),
                INDEX idx_ended (ended_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `ticket_time_entries`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `ticket_time_entries`: " . $e->getMessage();
    }
}

// Create recurring_tasks table if not exists
$check = db_fetch_one("SHOW TABLES LIKE 'recurring_tasks'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE recurring_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                ticket_type_id INT,
                organization_id INT,
                assigned_user_id INT,
                priority_id INT,
                status_id INT NOT NULL,
                recurrence_type ENUM('daily', 'weekly', 'monthly', 'yearly') DEFAULT 'weekly',
                recurrence_interval INT DEFAULT 1,
                recurrence_day_of_week TINYINT,
                recurrence_day_of_month TINYINT,
                recurrence_month TINYINT,
                start_date DATE NOT NULL,
                end_date DATE,
                next_run_date DATETIME,
                last_run_date DATETIME,
                send_email_notification TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_by_user_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_next_run (next_run_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `recurring_tasks`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `recurring_tasks`: " . $e->getMessage();
    }
}

// Add comment_id to ticket_time_entries if missing
$check = db_fetch_one("SHOW COLUMNS FROM ticket_time_entries LIKE 'comment_id'");
if (!$check) {
    try {
        db_query("ALTER TABLE ticket_time_entries ADD COLUMN comment_id INT NULL AFTER user_id");
        db_query("ALTER TABLE ticket_time_entries ADD INDEX idx_comment (comment_id)");
        $messages[] = "OK: Added column `comment_id` to ticket_time_entries";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add comment_id to ticket_time_entries: " . $e->getMessage();
    }
}

// Check and add missing columns
$upgrades = [
    // Users table - organization_id
    ['users', 'organization_id', "ALTER TABLE users ADD COLUMN organization_id INT AFTER role"],

    // Organizations - logo & billable_rate
    ['organizations', 'logo', "ALTER TABLE organizations ADD COLUMN logo TEXT AFTER notes"],
    ['organizations', 'billable_rate', "ALTER TABLE organizations ADD COLUMN billable_rate DECIMAL(10,2) DEFAULT 0 AFTER logo"],

    // Tickets table - organization_id
    ['tickets', 'organization_id', "ALTER TABLE tickets ADD COLUMN organization_id INT AFTER user_id"],

    // Tickets table - priority_id (replace ENUM priority)
    ['tickets', 'priority_id', "ALTER TABLE tickets ADD COLUMN priority_id INT AFTER type"],

    // Tickets table - ticket_type_id
    ['tickets', 'ticket_type_id', "ALTER TABLE tickets ADD COLUMN ticket_type_id INT AFTER type"],

    // Tickets table - assignee_id
    ['tickets', 'assignee_id', "ALTER TABLE tickets ADD COLUMN assignee_id INT AFTER user_id"],

    // Tickets table - due_date
    ['tickets', 'due_date', "ALTER TABLE tickets ADD COLUMN due_date DATETIME AFTER assignee_id"],

    // Tickets table - tags
    ['tickets', 'tags', "ALTER TABLE tickets ADD COLUMN tags TEXT AFTER due_date"],

    // Attachments table - comment_id
    ['attachments', 'comment_id', "ALTER TABLE attachments ADD COLUMN comment_id INT AFTER ticket_id"],
    ['attachments', 'uploaded_by', "ALTER TABLE attachments ADD COLUMN uploaded_by INT AFTER file_size"],

    // Users - reset token
    ['users', 'reset_token', "ALTER TABLE users ADD COLUMN reset_token VARCHAR(100) AFTER avatar"],
    ['users', 'reset_token_expires', "ALTER TABLE users ADD COLUMN reset_token_expires DATETIME AFTER reset_token"],

    // Users - cost_rate
    ['users', 'cost_rate', "ALTER TABLE users ADD COLUMN cost_rate DECIMAL(10,2) DEFAULT 0 AFTER role"],

    // Priorities - icon
    ['priorities', 'icon', "ALTER TABLE priorities ADD COLUMN icon VARCHAR(50) DEFAULT 'fa-flag' AFTER color"],

    // Comments - time_spent
    ['comments', 'time_spent', "ALTER TABLE comments ADD COLUMN time_spent INT DEFAULT 0 AFTER is_internal"],

    // Ticket time entries - pause & billable fields
    ['ticket_time_entries', 'paused_at', "ALTER TABLE ticket_time_entries ADD COLUMN paused_at DATETIME DEFAULT NULL AFTER ended_at"],
    ['ticket_time_entries', 'paused_seconds', "ALTER TABLE ticket_time_entries ADD COLUMN paused_seconds INT DEFAULT 0 AFTER paused_at"],
    ['ticket_time_entries', 'is_billable', "ALTER TABLE ticket_time_entries ADD COLUMN is_billable TINYINT(1) DEFAULT 1 AFTER duration_minutes"],
    ['ticket_time_entries', 'billable_rate', "ALTER TABLE ticket_time_entries ADD COLUMN billable_rate DECIMAL(10,2) DEFAULT 0 AFTER is_billable"],
    ['ticket_time_entries', 'cost_rate', "ALTER TABLE ticket_time_entries ADD COLUMN cost_rate DECIMAL(10,2) DEFAULT 0 AFTER billable_rate"],
    ['ticket_time_entries', 'summary', "ALTER TABLE ticket_time_entries ADD COLUMN summary TEXT AFTER is_manual"],

    // Users - permissions (for agents)
    ['users', 'permissions', "ALTER TABLE users ADD COLUMN permissions TEXT AFTER role"],

    // Email templates - language column
    ['email_templates', 'language', "ALTER TABLE email_templates ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER template_key"],

    // Users - language preference
    ['users', 'language', "ALTER TABLE users ADD COLUMN language VARCHAR(5) DEFAULT 'en' AFTER avatar"],

    // Users - contact metadata
    ['users', 'contact_phone', "ALTER TABLE users ADD COLUMN contact_phone VARCHAR(50) AFTER last_name"],
    ['users', 'notes', "ALTER TABLE users ADD COLUMN notes TEXT AFTER contact_phone"],

    // Users - notification preferences
    ['users', 'email_notifications_enabled', "ALTER TABLE users ADD COLUMN email_notifications_enabled TINYINT(1) DEFAULT 1 AFTER language"],
    ['users', 'in_app_notifications_enabled', "ALTER TABLE users ADD COLUMN in_app_notifications_enabled TINYINT(1) DEFAULT 1 AFTER email_notifications_enabled"],
    ['users', 'in_app_sound_enabled', "ALTER TABLE users ADD COLUMN in_app_sound_enabled TINYINT(1) DEFAULT 0 AFTER in_app_notifications_enabled"],
    ['users', 'deleted_at', "ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER is_active"],

    // Users - dashboard layout preferences (drag-and-drop order)
    ['users', 'dashboard_layout', "ALTER TABLE users ADD COLUMN dashboard_layout TEXT NULL AFTER permissions"],
];

foreach ($upgrades as $upgrade) {
    list($table, $column, $sql) = $upgrade;

    $check = db_fetch_one("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$check) {
        try {
            db_query($sql);
            $messages[] = "OK: Added column `$column` to `$table`";
        } catch (Exception $e) {
            // Ignore if already exists
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                $messages[] = "ERROR: Failed to add column `$column`: " . $e->getMessage();
            }
        }
    }
}

// Add performance indexes (safe to re-run)
add_index_if_missing('users', 'idx_name', "CREATE INDEX idx_name ON users (first_name, last_name)");
add_index_if_missing('users', 'idx_deleted_at', "CREATE INDEX idx_deleted_at ON users (deleted_at)");
add_index_if_missing('tickets', 'idx_assignee', "CREATE INDEX idx_assignee ON tickets (assignee_id)");
add_index_if_missing('comments', 'idx_ticket_created', "CREATE INDEX idx_ticket_created ON comments (ticket_id, created_at)");
add_index_if_missing('attachments', 'idx_comment', "CREATE INDEX idx_comment ON attachments (comment_id)");
add_index_if_missing('tickets', 'idx_ticket_search', "ALTER TABLE tickets ADD FULLTEXT INDEX idx_ticket_search (title, description)");
add_index_if_missing('tickets', 'idx_updated', "CREATE INDEX idx_updated ON tickets (updated_at)");
add_index_if_missing('comments', 'idx_user', "CREATE INDEX idx_user ON comments (user_id)");
add_index_if_missing('activity_log', 'idx_created', "CREATE INDEX idx_created ON activity_log (created_at)");
add_index_if_missing('activity_log', 'idx_user', "CREATE INDEX idx_user ON activity_log (user_id)");
add_index_if_missing('attachments', 'idx_uploaded_by', "CREATE INDEX idx_uploaded_by ON attachments (uploaded_by)");
add_index_if_missing('ticket_time_entries', 'idx_created', "CREATE INDEX idx_created ON ticket_time_entries (created_at)");

// Change avatar column to TEXT (fix for base64 avatars)
try {
    $column_info = db_fetch_one("SHOW COLUMNS FROM users WHERE Field = 'avatar'");
    if ($column_info && strpos(strtolower($column_info['Type']), 'varchar') !== false) {
        db_query("ALTER TABLE users MODIFY COLUMN avatar TEXT");
        $messages[] = "OK: Updated column `avatar` na TEXT";
    }
} catch (Exception $e) {
    $messages[] = "ERROR: Failed to update column type avatar: " . $e->getMessage();
}

// Migrate priority ENUM to priority_id
try {
    $column_info = db_fetch_one("SHOW COLUMNS FROM tickets WHERE Field = 'priority'");
    if ($column_info && strpos(strtolower($column_info['Type']), 'enum') !== false) {
        // Map existing priorities
        $priority_map = [
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'urgent' => 4
        ];

        // Get priorities from DB
        $db_priorities = db_fetch_all("SELECT id, slug FROM priorities");
        foreach ($db_priorities as $p) {
            $priority_map[$p['slug']] = $p['id'];
        }

        // Update tickets
        foreach ($priority_map as $slug => $id) {
            db_query("UPDATE tickets SET priority_id = ? WHERE priority = ?", [$id, $slug]);
        }

        // Set default for null
        $default_priority = db_fetch_one("SELECT id FROM priorities WHERE is_default = 1");
        if ($default_priority) {
            db_query("UPDATE tickets SET priority_id = ? WHERE priority_id IS NULL", [$default_priority['id']]);
        }

        $messages[] = "OK: Migrated priorities to new system";
    }
} catch (Exception $e) {
    // Ignore errors
}

// Create settings table if not exists
$check = db_fetch_one("SHOW TABLES LIKE 'settings'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `settings`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table settings: " . $e->getMessage();
    }
}

// Create email_templates table if not exists
$check = db_fetch_one("SHOW TABLES LIKE 'email_templates'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(100) NOT NULL,
                language VARCHAR(5) DEFAULT 'en',
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_key_lang (template_key, language),
                INDEX idx_key (template_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `email_templates`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table email_templates: " . $e->getMessage();
    }
}

// Create rate_limits table
$check = db_fetch_one("SHOW TABLES LIKE 'rate_limits'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_key VARCHAR(50) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                window_start DATETIME NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rate_ip (rate_key, ip_address),
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `rate_limits`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table rate_limits: " . $e->getMessage();
    }
}

// Create security_log table
$check = db_fetch_one("SHOW TABLES LIKE 'security_log'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE security_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                user_id INT NULL,
                ip_address VARCHAR(45) NOT NULL,
                context TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event (event_type),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `security_log`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table security_log: " . $e->getMessage();
    }
}

// Insert default settings if not exist
$default_settings = [
    ['app_name', defined('APP_NAME') ? APP_NAME : 'FoxDesk'],
    ['app_language', 'en'],
    ['time_format', '24'],
    ['currency', 'CZK'],
    ['billing_rounding', '15'],
    ['smtp_host', ''],
    ['smtp_port', '587'],
    ['smtp_user', ''],
    ['smtp_pass', ''],
    ['smtp_from_email', ''],
    ['smtp_from_name', defined('APP_NAME') ? APP_NAME : 'FoxDesk'],
    ['smtp_encryption', 'tls'],
    ['email_notifications_enabled', '0'],
    ['notify_on_status_change', '1'],
    ['notify_on_new_comment', '1'],
    ['notify_on_new_ticket', '1'],
    ['max_upload_size', '10'],
    ['app_logo', ''],
    ['favicon', ''],
    ['update_check_enabled', '1'],
    ['update_check_dismissed_version', '']
];

$settings_added = 0;
foreach ($default_settings as $setting) {
    $check = db_fetch_one("SELECT id FROM settings WHERE setting_key = ?", [$setting[0]]);
    if (!$check) {
        try {
            db_insert('settings', [
                'setting_key' => $setting[0],
                'setting_value' => $setting[1]
            ]);
            $settings_added++;
        } catch (Exception $e) {
            // Ignore duplicates
        }
    }
}
if ($settings_added > 0) {
    $messages[] = "OK: Added $settings_added default settings";
}

// Insert default email templates if not exist
$templates = [
    [
        'status_change',
        'Status changed for ticket #{ticket_id}: {ticket_title}',
        "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\nPrevious status: {old_status}\nNew status: {new_status}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
    ],
    [
        'new_comment',
        'New comment on ticket #{ticket_id}: {ticket_title}',
        "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\nFrom: {commenter_name}\nTime spent: {time_spent}\nAttachments: {attachments}\n\n---\n{comment_text}\n---\n\nView comment: {comment_url}\n\nRegards,\n{app_name}"
    ],
    [
        'new_ticket',
        'New ticket #{ticket_id}: {ticket_title}',
        "Hello,\n\nA new ticket has been created.\n\nSubject: {ticket_title}\nType: {ticket_type}\nPriority: {priority}\nFrom: {user_name} ({user_email})\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
    ],
    [
        'password_reset',
        'Password reset',
        "Hello,\n\nYou requested a password reset. Click the link below:\n{reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.\n\nRegards,\n{app_name}"
    ]
];

$templates_added = 0;
foreach ($templates as $template) {
    $check = db_fetch_one("SELECT id FROM email_templates WHERE template_key = ?", [$template[0]]);
    if (!$check) {
        try {
            db_insert('email_templates', [
                'template_key' => $template[0],
                'subject' => $template[1],
                'body' => $template[2],
                'is_active' => 1
            ]);
            $templates_added++;
        } catch (Exception $e) {
            // Ignore duplicates
        }
    }
}
if ($templates_added > 0) {
    $messages[] = "OK: Added $templates_added email templates";
}

// Add is_archived column to tickets
$check = db_fetch_one("SHOW COLUMNS FROM tickets LIKE 'is_archived'");
if (!$check) {
    try {
        db_query("ALTER TABLE tickets ADD COLUMN is_archived TINYINT(1) DEFAULT 0 AFTER status_id");
        db_query("ALTER TABLE tickets ADD INDEX idx_archived (is_archived)");
        $messages[] = "OK: Added column `is_archived` to tickets";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add column is_archived: " . $e->getMessage();
    }
}

// Add source column to tickets
$check = db_fetch_one("SHOW COLUMNS FROM tickets LIKE 'source'");
if (!$check) {
    try {
        db_query("ALTER TABLE tickets ADD COLUMN source VARCHAR(20) DEFAULT 'web' AFTER ticket_type_id");
        db_query("CREATE INDEX idx_source ON tickets (source)");
        $messages[] = "OK: Added column `source` to tickets";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add column source: " . $e->getMessage();
    }
}

// Add ticket_prefix setting if not exists
$check = db_fetch_one("SELECT id FROM settings WHERE setting_key = 'ticket_prefix'");
if (!$check) {
    try {
        db_insert('settings', ['setting_key' => 'ticket_prefix', 'setting_value' => 'TK']);
        $messages[] = "OK: Added setting `ticket_prefix`";
    } catch (Exception $e) {
        // Ignore
    }
}

// Initialize default permissions for existing agents
try {
    $agents = db_fetch_all("SELECT id, permissions FROM users WHERE role = 'agent' AND (permissions IS NULL OR permissions = '')");
    if (!empty($agents)) {
        $default_permissions = json_encode([
            'ticket_scope' => 'assigned',
            'organization_ids' => [],
            'can_archive' => false,
            'can_view_edit_history' => false,
            'can_import_md' => false
        ]);

        foreach ($agents as $agent) {
            db_update('users', ['permissions' => $default_permissions], 'id = ?', [$agent['id']]);
        }

        $messages[] = "OK: Initialized default permissions for " . count($agents) . " agent(s)";
    }
} catch (Exception $e) {
    $messages[] = "ERROR: Failed to initialize agent permissions: " . $e->getMessage();
}

// Add ticket_confirmation email template if not exists
try {
    $check = db_fetch_one("SELECT id FROM email_templates WHERE template_key = 'ticket_confirmation'");
    if (!$check) {
        db_insert('email_templates', [
            'template_key' => 'ticket_confirmation',
            'subject' => 'Ticket received #{ticket_code}: {ticket_title}',
            'body' => "Hello,\n\nYour ticket #{ticket_code} \"{ticket_title}\" was received successfully.\nWe will keep you updated on its progress.\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}",
            'is_active' => 1
        ]);
        $messages[] = "OK: Added email template `ticket_confirmation`";
    }
} catch (Exception $e) {
    $messages[] = "ERROR: Failed to add template ticket_confirmation: " . $e->getMessage();
}

// Add ticket_assignment email template if not exists
try {
    $check = db_fetch_one("SELECT id FROM email_templates WHERE template_key = 'ticket_assignment'");
    if (!$check) {
        db_insert('email_templates', [
            'template_key' => 'ticket_assignment',
            'subject' => 'Ticket assigned #{ticket_code}: {ticket_title}',
            'body' => "Hello {agent_name},\n\nYou have been assigned a ticket to handle:\n\nTicket: #{ticket_code}\nSubject: {ticket_title}\nAssigned by: {assigner_name}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}",
            'is_active' => 1
        ]);
        $messages[] = "OK: Added email template `ticket_assignment`";
    }
} catch (Exception $e) {
    $messages[] = "ERROR: Failed to add template ticket_assignment: " . $e->getMessage();
}

// Create report_templates table
$check = db_fetch_one("SHOW TABLES LIKE 'report_templates'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE report_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE,
                organization_id INT NOT NULL,
                created_by_user_id INT,
                title VARCHAR(255) NOT NULL,
                report_language VARCHAR(5) DEFAULT 'en',
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                executive_summary TEXT,
                show_financials TINYINT(1) DEFAULT 1,
                show_team_attribution TINYINT(1) DEFAULT 1,
                show_cost_breakdown TINYINT(1) DEFAULT 0,
                group_by VARCHAR(50) DEFAULT 'none',
                rounding_minutes INT DEFAULT 15,
                theme_color VARCHAR(50),
                hide_branding TINYINT(1) DEFAULT 0,
                is_draft TINYINT(1) DEFAULT 1,
                is_archived TINYINT(1) DEFAULT 0,
                last_generated_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org (organization_id),
                INDEX idx_uuid (uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `report_templates`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `report_templates`: " . $e->getMessage();
    }
}

// Create report_snapshots table
$check = db_fetch_one("SHOW TABLES LIKE 'report_snapshots'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE report_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_template_id INT NOT NULL,
                kpi_data JSON,
                chart_data JSON,
                generation_time_ms INT,
                generated_by_user_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (report_template_id) REFERENCES report_templates(id) ON DELETE CASCADE,
                FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_template (report_template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `report_snapshots`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `report_snapshots`: " . $e->getMessage();
    }
}

// Create debug_log table
$check = db_fetch_one("SHOW TABLES LIKE 'debug_log'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE debug_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(50) DEFAULT 'general',
                level VARCHAR(20) DEFAULT 'info',
                message TEXT,
                context JSON,
                user_id INT,
                ip_address VARCHAR(45),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_channel (channel),
                INDEX idx_level (level),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `debug_log`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `debug_log`: " . $e->getMessage();
    }
}

// Create allowed_senders table
$check = db_fetch_one("SHOW TABLES LIKE 'allowed_senders'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE allowed_senders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('email','domain') NOT NULL,
                value VARCHAR(255) NOT NULL,
                user_id INT NULL,
                active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_type_value (type, value),
                INDEX idx_active (active),
                INDEX idx_user (user_id),
                CONSTRAINT fk_allowed_senders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `allowed_senders`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `allowed_senders`: " . $e->getMessage();
    }
}

// Create ticket_messages table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_messages'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                direction ENUM('in','out') NOT NULL DEFAULT 'in',
                user_id INT NULL,
                comment_id INT NULL,
                sender_email VARCHAR(255) NULL,
                subject VARCHAR(255) NULL,
                body_text MEDIUMTEXT,
                body_html MEDIUMTEXT NULL,
                body_html_raw MEDIUMTEXT NULL,
                raw_headers MEDIUMTEXT,
                message_id VARCHAR(255) NULL,
                in_reply_to VARCHAR(255) NULL,
                references_header TEXT NULL,
                mailbox VARCHAR(120) NULL,
                uid INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_messages_message_id (message_id),
                UNIQUE KEY uniq_ticket_messages_mailbox_uid (mailbox, uid),
                INDEX idx_ticket (ticket_id),
                INDEX idx_comment (comment_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_ticket_messages_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `ticket_messages`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `ticket_messages`: " . $e->getMessage();
    }
}

// Create ticket_message_attachments table
$check = db_fetch_one("SHOW TABLES LIKE 'ticket_message_attachments'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE ticket_message_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_message_id INT NOT NULL,
                attachment_id INT NULL,
                filename VARCHAR(255) NOT NULL,
                mime VARCHAR(120) NULL,
                size INT DEFAULT 0,
                storage_path VARCHAR(500) NOT NULL,
                content_id VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_message (ticket_message_id),
                INDEX idx_attachment (attachment_id),
                CONSTRAINT fk_tma_message FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE,
                CONSTRAINT fk_tma_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `ticket_message_attachments`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `ticket_message_attachments`: " . $e->getMessage();
    }
}

// Create email_ingest_logs table
$check = db_fetch_one("SHOW TABLES LIKE 'email_ingest_logs'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE email_ingest_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mailbox VARCHAR(120) NOT NULL,
                uid INT NOT NULL,
                message_id VARCHAR(255) NULL,
                status ENUM('processed','skipped','failed') NOT NULL,
                reason VARCHAR(100) NULL,
                error TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_mailbox_uid (mailbox, uid),
                INDEX idx_message_id (message_id),
                INDEX idx_status_created (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `email_ingest_logs`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `email_ingest_logs`: " . $e->getMessage();
    }
}

// Create email_ingest_state table
$check = db_fetch_one("SHOW TABLES LIKE 'email_ingest_state'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE email_ingest_state (
                mailbox VARCHAR(120) PRIMARY KEY,
                last_seen_uid INT DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `email_ingest_state`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `email_ingest_state`: " . $e->getMessage();
    }
}

// Add UNIQUE constraint on statuses.name (if not already unique)
try {
    $idx = db_fetch_one("SHOW INDEX FROM statuses WHERE Column_name = 'name' AND Non_unique = 0");
    if (!$idx) {
        // Check for duplicate names before adding constraint
        $dupes = db_fetch_one("SELECT name, COUNT(*) as cnt FROM statuses GROUP BY name HAVING cnt > 1");
        if (!$dupes) {
            db_query("ALTER TABLE statuses ADD UNIQUE INDEX uniq_name (name)");
            $messages[] = "OK: Added UNIQUE constraint on `statuses.name`";
        } else {
            $messages[] = "WARN: Skipped UNIQUE on statuses.name — duplicates exist";
        }
    }
} catch (Exception $e) {
    // Ignore if already exists
}

// Add UNIQUE constraint on priorities.name
try {
    $idx = db_fetch_one("SHOW INDEX FROM priorities WHERE Column_name = 'name' AND Non_unique = 0");
    if (!$idx) {
        $dupes = db_fetch_one("SELECT name, COUNT(*) as cnt FROM priorities GROUP BY name HAVING cnt > 1");
        if (!$dupes) {
            db_query("ALTER TABLE priorities ADD UNIQUE INDEX uniq_name (name)");
            $messages[] = "OK: Added UNIQUE constraint on `priorities.name`";
        } else {
            $messages[] = "WARN: Skipped UNIQUE on priorities.name — duplicates exist";
        }
    }
} catch (Exception $e) {
    // Ignore if already exists
}

// Add UNIQUE constraint on ticket_types.name
try {
    $idx = db_fetch_one("SHOW INDEX FROM ticket_types WHERE Column_name = 'name' AND Non_unique = 0");
    if (!$idx) {
        $dupes = db_fetch_one("SELECT name, COUNT(*) as cnt FROM ticket_types GROUP BY name HAVING cnt > 1");
        if (!$dupes) {
            db_query("ALTER TABLE ticket_types ADD UNIQUE INDEX uniq_name (name)");
            $messages[] = "OK: Added UNIQUE constraint on `ticket_types.name`";
        } else {
            $messages[] = "WARN: Skipped UNIQUE on ticket_types.name — duplicates exist";
        }
    }
} catch (Exception $e) {
    // Ignore if already exists
}

// Add updated_at to statuses
$check = db_fetch_one("SHOW COLUMNS FROM statuses LIKE 'updated_at'");
if (!$check) {
    try {
        db_query("ALTER TABLE statuses ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        $messages[] = "OK: Added column `updated_at` to `statuses`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add updated_at to statuses: " . $e->getMessage();
    }
}

// Add updated_at to priorities
$check = db_fetch_one("SHOW COLUMNS FROM priorities LIKE 'updated_at'");
if (!$check) {
    try {
        db_query("ALTER TABLE priorities ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        $messages[] = "OK: Added column `updated_at` to `priorities`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add updated_at to priorities: " . $e->getMessage();
    }
}

// Add updated_at to ticket_types
$check = db_fetch_one("SHOW COLUMNS FROM ticket_types LIKE 'updated_at'");
if (!$check) {
    try {
        db_query("ALTER TABLE ticket_types ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        $messages[] = "OK: Added column `updated_at` to `ticket_types`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add updated_at to ticket_types: " . $e->getMessage();
    }
}

// Widen attachments.file_size from INT to BIGINT for large files
try {
    $column_info = db_fetch_one("SHOW COLUMNS FROM attachments WHERE Field = 'file_size'");
    if ($column_info && stripos($column_info['Type'], 'bigint') === false) {
        db_query("ALTER TABLE attachments MODIFY COLUMN file_size BIGINT");
        $messages[] = "OK: Widened `attachments.file_size` to BIGINT";
    }
} catch (Exception $e) {
    $messages[] = "ERROR: Failed to widen file_size: " . $e->getMessage();
}

// Create uploads directory if not exists
if (!is_dir('uploads')) {
    if (mkdir('uploads', 0755, true)) {
        $messages[] = "OK: Created directory `uploads`";
    }
}

// Create api_tokens table
$check = db_fetch_one("SHOW TABLES LIKE 'api_tokens'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                token_prefix VARCHAR(10) NOT NULL,
                expires_at DATETIME NULL,
                is_active TINYINT(1) DEFAULT 1,
                last_used_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_token_hash (token_hash),
                INDEX idx_user (user_id),
                INDEX idx_active (is_active),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `api_tokens`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `api_tokens`: " . $e->getMessage();
    }
}

// Add AI agent columns to users table
$check = db_fetch_one("SHOW COLUMNS FROM users LIKE 'is_ai_agent'");
if (!$check) {
    try {
        db_query("ALTER TABLE users ADD COLUMN is_ai_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        $messages[] = "OK: Added column `is_ai_agent` to users table";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add column `is_ai_agent`: " . $e->getMessage();
    }
}
$check = db_fetch_one("SHOW COLUMNS FROM users LIKE 'ai_model'");
if (!$check) {
    try {
        db_query("ALTER TABLE users ADD COLUMN ai_model VARCHAR(100) NULL DEFAULT NULL AFTER is_ai_agent");
        $messages[] = "OK: Added column `ai_model` to users table";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add column `ai_model`: " . $e->getMessage();
    }
}

// Create notifications table
$check = db_fetch_one("SHOW TABLES LIKE 'notifications'");
if (!$check) {
    try {
        db_query("
            CREATE TABLE notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                ticket_id INT UNSIGNED NULL,
                type VARCHAR(50) NOT NULL,
                actor_id INT UNSIGNED NULL,
                data JSON NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_read_created (user_id, is_read, created_at DESC),
                INDEX idx_user_created (user_id, created_at DESC),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "OK: Created table `notifications`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to create table `notifications`: " . $e->getMessage();
    }
}

// Add last_notifications_seen_at to users
$check = db_fetch_one("SHOW COLUMNS FROM `users` LIKE 'last_notifications_seen_at'");
if (!$check) {
    try {
        db_query("ALTER TABLE `users` ADD COLUMN `last_notifications_seen_at` DATETIME NULL");
        $messages[] = "OK: Added column `last_notifications_seen_at` to `users`";
    } catch (Exception $e) {
        $messages[] = "ERROR: Failed to add column last_notifications_seen_at: " . $e->getMessage();
    }
}

if (empty($messages)) {
    $messages[] = "Database is up to date; no changes were required.";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade - FoxDesk</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-lg max-w-lg w-full p-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Upgrade complete</h1>

        <div class="space-y-2 max-h-96 overflow-y-auto">
            <?php foreach ($messages as $msg): ?>
                <div
                    class="flex items-start space-x-2 <?php echo strpos($msg, 'OK:') === 0 ? 'text-green-600' : (strpos($msg, 'ERROR:') === 0 ? 'text-red-600' : 'text-gray-600'); ?>">
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 pt-6 border-t">
            <a href="index.php"
                class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition">
                Go to app ->
            </a>
        </div>

        <p class="text-sm text-gray-500 mt-6">
            <strong>Tip:</strong> For better security, delete <code class="bg-gray-100 px-1 rounded">upgrade.php</code>
        </p>
    </div>
</body>

</html>
