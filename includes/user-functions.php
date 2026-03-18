<?php
/**
 * User and Permission Functions
 *
 * This file contains functions for managing user permissions,
 * authorization checks, and avatar generation.
 */

/**
 * Get user permissions
 */
function get_user_permissions($user_id = null)
{
    if ($user_id === null) {
        $user = current_user();
        if (!$user)
            return null;
        $user_id = $user['id'];
    } else {
        $user = get_user($user_id);
    }

    if (!$user)
        return null;

    // Default permissions
    $default_scope = 'own';
    if ($user['role'] === 'agent') {
        $default_scope = 'assigned';
    } elseif ($user['role'] === 'user' && !empty($user['organization_id'])) {
        $default_scope = 'organization';
    }

    $default = [
        'ticket_scope' => $default_scope,
        'organization_ids' => [],
        'can_archive' => false,
        'can_view_edit_history' => false,
        'can_import_md' => false,
        'can_view_time' => ($user['role'] === 'agent'),
        'can_view_timeline' => ($user['role'] === 'agent')
    ];

    if (empty($user['permissions'])) {
        return $default;
    }

    $permissions = json_decode($user['permissions'], true);
    return is_array($permissions) ? array_merge($default, $permissions) : $default;
}

/**
 * Check if user can archive tickets
 */
function can_archive_tickets($user = null)
{
    if (is_admin())
        return true;

    if ($user === null) {
        $user = current_user();
    }

    if (!$user || $user['role'] !== 'agent')
        return false;

    $permissions = get_user_permissions($user['id']);
    return !empty($permissions['can_archive']);
}

/**
 * Check whether user can view ticket/comment edit history indicators
 */
function can_view_edit_history($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (!in_array($user['role'], ['agent', 'user'], true)) {
        return false;
    }

    $permissions = get_user_permissions($user['id']);
    return !empty($permissions['can_view_edit_history']);
}

/**
 * Check whether user can view time entries on tickets.
 * Admin → always true. Agent → true by default. User → opt-in via permissions.
 */
function can_view_time($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (!in_array($user['role'] ?? '', ['agent', 'user'], true)) {
        return false;
    }

    $permissions = get_user_permissions($user['id']);

    // Agents default to true (can see time), users default to false
    if (!isset($permissions['can_view_time'])) {
        return ($user['role'] === 'agent');
    }

    return !empty($permissions['can_view_time']);
}

/**
 * Check whether user can view ticket activity timeline.
 * Admin → always true. Agent → true by default. User → opt-in via permissions.
 */
function can_view_timeline($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (!in_array($user['role'] ?? '', ['agent', 'user'], true)) {
        return false;
    }

    $permissions = get_user_permissions($user['id']);

    // Agents default to true, users default to false
    if (!isset($permissions['can_view_timeline'])) {
        return ($user['role'] === 'agent');
    }

    return !empty($permissions['can_view_timeline']);
}

/**
 * Per-user email notifications toggle (agent/user only; admins always enabled)
 */
function user_email_notifications_enabled($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return true;
    }

    if (!in_array($user['role'], ['agent', 'user'], true)) {
        return true;
    }

    return (int) ($user['email_notifications_enabled'] ?? 1) === 1;
}

/**
 * Per-user in-app toast notifications toggle
 */
function user_in_app_notifications_enabled($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return true;
    }

    return (int) ($user['in_app_notifications_enabled'] ?? 1) === 1;
}

/**
 * Per-user in-app notification sound toggle
 */
function user_in_app_sound_enabled($user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    return (int) ($user['in_app_sound_enabled'] ?? 0) === 1;
}

/**
 * Normalize organization IDs to unique positive integers.
 */
function normalize_organization_ids($organization_ids)
{
    $normalized = [];
    foreach ((array) $organization_ids as $org_id) {
        $id = (int) $org_id;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }
    return array_values($normalized);
}

/**
 * Parse organization IDs from permissions payload.
 */
function get_permissions_organization_ids($permissions)
{
    if (!is_array($permissions)) {
        return [];
    }

    $org_ids = [];
    if (!empty($permissions['organization_ids']) && is_array($permissions['organization_ids'])) {
        $org_ids = $permissions['organization_ids'];
    } elseif (!empty($permissions['organization_id'])) {
        $org_ids = [$permissions['organization_id']];
    }

    return normalize_organization_ids($org_ids);
}

/**
 * Save user organization memberships.
 * Primary organization is synced to users.organization_id.
 */
function set_user_organization_memberships($user_id, $organization_ids, $primary_organization_id = null)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return false;
    }

    $user = get_user($user_id);
    if (!$user) {
        return false;
    }

    $org_ids = normalize_organization_ids($organization_ids);

    if ($primary_organization_id !== null) {
        $primary_organization_id = (int) $primary_organization_id;
        if ($primary_organization_id > 0 && !in_array($primary_organization_id, $org_ids, true)) {
            array_unshift($org_ids, $primary_organization_id);
            $org_ids = normalize_organization_ids($org_ids);
        }
    }

    $primary = null;
    if ($primary_organization_id !== null && (int) $primary_organization_id > 0) {
        $primary = (int) $primary_organization_id;
    } elseif (!empty($org_ids)) {
        $primary = (int) $org_ids[0];
    }

    $updates = ['organization_id' => $primary];
    $permissions = [];
    if (!empty($user['permissions'])) {
        $decoded = json_decode((string) $user['permissions'], true);
        if (is_array($decoded)) {
            $permissions = $decoded;
        }
    }

    $default_scope = 'own';
    if ($user['role'] === 'agent') {
        $default_scope = 'assigned';
    } elseif ($user['role'] === 'admin') {
        $default_scope = 'all';
    } elseif (!empty($primary)) {
        $default_scope = 'organization';
    }
    $permissions = array_merge([
        'ticket_scope' => $default_scope,
        'organization_ids' => [],
        'can_archive' => $user['role'] === 'admin',
        'can_view_edit_history' => $user['role'] === 'admin',
        'can_import_md' => $user['role'] === 'admin'
    ], $permissions);
    $permissions['organization_ids'] = $org_ids;

    $updates['permissions'] = json_encode($permissions);

    db_update('users', $updates, 'id = ?', [$user_id]);
    return true;
}

/**
 * Add a single organization membership to user.
 */
function add_user_organization_membership($user_id, $organization_id)
{
    $organization_id = (int) $organization_id;
    if ($organization_id <= 0) {
        return false;
    }

    $org_ids = get_user_organization_ids($user_id);
    if (!in_array($organization_id, $org_ids, true)) {
        $org_ids[] = $organization_id;
    }

    $current_user = get_user($user_id);
    if (!$current_user) {
        return false;
    }

    $primary = !empty($current_user['organization_id']) ? (int) $current_user['organization_id'] : null;
    if ($primary === null || $primary <= 0) {
        $primary = $organization_id;
    }

    return set_user_organization_memberships($user_id, $org_ids, $primary);
}

/**
 * Remove a single organization membership from user.
 */
function remove_user_organization_membership($user_id, $organization_id)
{
    $organization_id = (int) $organization_id;
    if ($organization_id <= 0) {
        return false;
    }

    $current_user = get_user($user_id);
    if (!$current_user) {
        return false;
    }

    $org_ids = array_values(array_filter(get_user_organization_ids($user_id), function ($id) use ($organization_id) {
        return (int) $id !== $organization_id;
    }));

    $primary = !empty($current_user['organization_id']) ? (int) $current_user['organization_id'] : null;
    if ($primary === $organization_id) {
        $primary = !empty($org_ids) ? (int) $org_ids[0] : null;
    }

    return set_user_organization_memberships($user_id, $org_ids, $primary);
}

/**
 * Check if user can see ticket based on permissions
 */
function can_see_ticket($ticket, $user = null)
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user)
        return false;

    // Admin sees everything
    if ($user['role'] === 'admin')
        return true;

    // Owner sees their tickets
    if ((int) $ticket['user_id'] === (int) $user['id'])
        return true;

    // Assignee always sees the ticket
    if (!empty($ticket['assignee_id']) && (int) $ticket['assignee_id'] === (int) $user['id'])
        return true;

    // Explicit shared access
    if (!empty($ticket['id']) && user_has_ticket_access($ticket['id'], $user['id'])) {
        return true;
    }

    // For agents, check permissions
    if ($user['role'] === 'agent') {
        $permissions = get_user_permissions($user['id']);

        switch ($permissions['ticket_scope']) {
            case 'all':
                return true;
            case 'assigned':
                return (int) ($ticket['assignee_id'] ?? 0) === (int) $user['id'];
            case 'organization':
                // Support multiple organizations
                $org_ids = !empty($permissions['organization_ids']) ? $permissions['organization_ids'] :
                    (!empty($permissions['organization_id']) ? [$permissions['organization_id']] : []);

                if (!empty($org_ids) && !empty($ticket['organization_id'])) {
                    return in_array($ticket['organization_id'], $org_ids);
                }
                return false;
            default:
                return false;
        }
    }

    // For regular users, check permissions
    if ($user['role'] === 'user') {
        $permissions = get_user_permissions($user['id']);
        $scope = $permissions['ticket_scope'] ?? 'own';

        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'organization') {
            $org_ids = get_user_organization_ids($user['id']);
            if (!empty($ticket['organization_id']) && in_array((int) $ticket['organization_id'], $org_ids, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Generate avatar from initials
 */
function generate_avatar($name, $size = 100)
{
    $initials = '';
    $words = explode(' ', trim($name));
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            if (mb_strlen($initials) >= 2)
                break;
        }
    }
    if (empty($initials))
        $initials = '?';

    // Generate unique color based on name
    $hash = md5($name);
    $hue = hexdec(substr($hash, 0, 4)) % 360;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">';
    $svg .= '<rect width="100%" height="100%" fill="hsl(' . $hue . ', 60%, 65%)"/>';
    $svg .= '<text x="50%" y="55%" dominant-baseline="middle" text-anchor="middle" ';
    $svg .= 'fill="white" font-family="Arial, sans-serif" font-size="' . ($size * 0.4) . 'px" font-weight="bold">';
    $svg .= htmlspecialchars($initials);
    $svg .= '</text></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}


/**
 * Get organization IDs for a user (works for both agents and regular users)
 * Checks permissions.organization_ids first, then falls back to user's organization_id
 */
function get_user_organization_ids($user_id)
{
    if (!function_exists('get_user_permissions')) {
        return [];
    }
    $permissions = get_user_permissions($user_id);
    $org_ids = get_permissions_organization_ids($permissions);

    // Always include primary organization_id as fallback/source of truth.
    $user = get_user($user_id);
    if ($user && !empty($user['organization_id'])) {
        $org_ids[] = (int) $user['organization_id'];
    }

    return normalize_organization_ids($org_ids);
}

