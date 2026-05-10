<?php
/**
 * Ticket & Report Share Functions
 *
 * Functions for managing public share links for tickets and reports.
 */

// =============================================================================
// TICKET SHARES (Public Links)
// =============================================================================

/**
 * Generate a share token for public ticket links
 */
function generate_ticket_share_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Hash a share token for storage
 */
function hash_ticket_share_token($token) {
    return hash('sha256', $token);
}

/**
 * Build a public share URL from a token
 */
function get_ticket_share_url($token) {
    return get_base_url() . '/' . url('ticket-share', ['token' => $token]);
}

/**
 * Create a new share link for a ticket (revokes previous active shares)
 */
function create_ticket_share($ticket_id, $created_by, $expires_at = null) {
    $token = generate_ticket_share_token();
    $token_hash = hash_ticket_share_token($token);

    try {
        db_update('ticket_shares', ['is_revoked' => 1], 'ticket_id = ? AND is_revoked = 0', [$ticket_id]);
    } catch (Exception $e) {
        // Ignore if table is missing
    }

    $share_id = db_insert('ticket_shares', [
        'ticket_id' => $ticket_id,
        'token_hash' => $token_hash,
        'created_by' => $created_by,
        'expires_at' => $expires_at ?: null,
        'is_revoked' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return [
        'id' => $share_id,
        'token' => $token,
        'expires_at' => $expires_at ?: null
    ];
}

/**
 * Get the latest share record for a ticket
 */
function get_latest_ticket_share($ticket_id) {
    try {
        return db_fetch_one("SELECT * FROM ticket_shares WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 1", [$ticket_id]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get a share record by token
 */
function get_ticket_share_by_token($token) {
    if (empty($token)) {
        return null;
    }

    $token_hash = hash_ticket_share_token($token);
    try {
        return db_fetch_one("SELECT * FROM ticket_shares WHERE token_hash = ? LIMIT 1", [$token_hash]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check if a share is active (not revoked or expired)
 */
function is_ticket_share_active($share) {
    if (empty($share)) {
        return false;
    }

    if (!empty($share['is_revoked'])) {
        return false;
    }

    if (!empty($share['expires_at']) && strtotime($share['expires_at']) <= time()) {
        return false;
    }

    return true;
}

/**
 * Revoke all active shares for a ticket
 */
function revoke_ticket_shares($ticket_id) {
    try {
        return db_update('ticket_shares', ['is_revoked' => 1], 'ticket_id = ? AND is_revoked = 0', [$ticket_id]);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Track share access time
 */
function mark_ticket_share_accessed($share_id) {
    try {
        db_update('ticket_shares', ['last_accessed_at' => date('Y-m-d H:i:s')], 'id = ?', [$share_id]);
    } catch (Exception $e) {
        // Ignore
    }
}

// =============================================================================
// REPORT SHARES (Public Links)
// =============================================================================

/**
 * Generate a share token for public report links
 */
function generate_report_share_token() {
    return bin2hex(random_bytes(32));
}

/**
 * Hash a report token for storage
 */
function hash_report_share_token($token) {
    return hash('sha256', $token);
}

/**
 * Build a public report share URL from a token
 */
function get_report_share_url($token) {
    return get_base_url() . '/' . url('report-share', ['token' => $token]);
}

function ensure_report_template_share_columns(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (!column_exists('report_shares', 'report_template_id')) {
            db_query("ALTER TABLE report_shares ADD COLUMN report_template_id INT NULL AFTER organization_id");
            db_query("ALTER TABLE report_shares ADD INDEX idx_report_template (report_template_id)");
        }
    } catch (Throwable $e) {
        // Older/shared hosts may not support online DDL; callers fail gracefully.
    }

    try {
        if (!column_exists('report_shares', 'share_secret')) {
            db_query("ALTER TABLE report_shares ADD COLUMN share_secret VARCHAR(64) NULL AFTER token_hash");
        }
    } catch (Throwable $e) {
        // Older/shared hosts may not support online DDL; callers fail gracefully.
    }
}

function report_template_share_token_from_secret($share_secret): string
{
    $secret = defined('SECRET_KEY') ? (string) SECRET_KEY : '';
    if ($secret === '') {
        $secret = 'foxdesk-report-share-fallback';
    }

    return hash_hmac('sha256', 'report-template-share:' . (string) $share_secret, $secret);
}

function attach_report_template_share_token($share)
{
    if (!$share || empty($share['share_secret'])) {
        return $share;
    }

    $share['token'] = report_template_share_token_from_secret($share['share_secret']);
    return $share;
}

/**
 * Create a new share link for an organization report (revokes previous active shares)
 */
function create_report_share($organization_id, $created_by, $expires_at = null) {
    $token = generate_report_share_token();
    $token_hash = hash_report_share_token($token);

    try {
        db_update('report_shares', ['is_revoked' => 1], 'organization_id = ? AND is_revoked = 0', [$organization_id]);
    } catch (Exception $e) {
        // Ignore if table is missing
    }

    $share_id = db_insert('report_shares', [
        'organization_id' => $organization_id,
        'token_hash' => $token_hash,
        'created_by' => $created_by,
        'expires_at' => $expires_at ?: null,
        'is_revoked' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return [
        'id' => $share_id,
        'token' => $token,
        'expires_at' => $expires_at ?: null
    ];
}

function create_report_template_share_record($report_template_id, $organization_id, $created_by, $expires_at = null) {
    ensure_report_template_share_columns();

    $share_secret = bin2hex(random_bytes(32));
    $token = report_template_share_token_from_secret($share_secret);
    $token_hash = hash_report_share_token($token);

    try {
        db_update('report_shares', ['is_revoked' => 1], 'report_template_id = ? AND is_revoked = 0', [$report_template_id]);
    } catch (Exception $e) {
        // Ignore if columns/table are missing.
    }

    $share_id = db_insert('report_shares', [
        'organization_id' => $organization_id,
        'report_template_id' => $report_template_id,
        'token_hash' => $token_hash,
        'share_secret' => $share_secret,
        'created_by' => $created_by,
        'expires_at' => $expires_at ?: null,
        'is_revoked' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return [
        'id' => $share_id,
        'token' => $token,
        'expires_at' => $expires_at ?: null
    ];
}

/**
 * Get an active share for an organization
 */
function get_active_report_share($organization_id) {
    try {
        return db_fetch_one("SELECT * FROM report_shares WHERE organization_id = ? AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1", [$organization_id]);
    } catch (Exception $e) {
        return null;
    }
}

function get_active_report_template_share($report_template_id) {
    ensure_report_template_share_columns();

    try {
        $share = db_fetch_one("SELECT * FROM report_shares WHERE report_template_id = ? AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1", [$report_template_id]);
        return attach_report_template_share_token($share);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get a report share record by token
 */
function get_report_share_by_token($token) {
    if (empty($token)) {
        return null;
    }

    $token_hash = hash_report_share_token($token);
    try {
        return db_fetch_one("SELECT * FROM report_shares WHERE token_hash = ? LIMIT 1", [$token_hash]);
    } catch (Exception $e) {
        return null;
    }
}

function get_report_template_share_by_token($token) {
    if (empty($token)) {
        return null;
    }

    ensure_report_template_share_columns();

    $token_hash = hash_report_share_token($token);
    try {
        return db_fetch_one("SELECT * FROM report_shares WHERE report_template_id IS NOT NULL AND token_hash = ? LIMIT 1", [$token_hash]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check if a report share is active (not revoked or expired)
 */
function is_report_share_active($share) {
    if (empty($share)) {
        return false;
    }

    if (!empty($share['is_revoked'])) {
        return false;
    }

    if (!empty($share['expires_at']) && strtotime($share['expires_at']) <= time()) {
        return false;
    }

    return true;
}

/**
 * Revoke all active shares for an organization
 */
function revoke_report_shares($organization_id) {
    try {
        return db_update('report_shares', ['is_revoked' => 1], 'organization_id = ? AND is_revoked = 0', [$organization_id]);
    } catch (Exception $e) {
        return 0;
    }
}

function revoke_report_template_shares($report_template_id) {
    ensure_report_template_share_columns();

    try {
        return db_update('report_shares', ['is_revoked' => 1], 'report_template_id = ? AND is_revoked = 0', [$report_template_id]);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Track report share access time
 */
function mark_report_share_accessed($share_id) {
    try {
        db_update('report_shares', ['last_accessed_at' => date('Y-m-d H:i:s')], 'id = ?', [$share_id]);
    } catch (Exception $e) {
        // Ignore
    }
}
