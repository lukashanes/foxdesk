<?php
/**
 * Ticket Access Functions
 *
 * Functions for managing user access to tickets.
 */

/**
 * Check if the ticket_access table exists
 */
function ticket_access_table_exists() {
    return table_exists('ticket_access');
}

/**
 * Get users with explicit access to a ticket
 */
function get_ticket_access_users($ticket_id) {
    if (!ticket_access_table_exists()) {
        return [];
    }
    try {
        return db_fetch_all("SELECT u.id, u.first_name, u.last_name, u.email, u.role
                             FROM ticket_access ta
                             JOIN users u ON ta.user_id = u.id
                             WHERE ta.ticket_id = ?
                             ORDER BY u.first_name, u.last_name", [$ticket_id]);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if a user has explicit access to a ticket
 */
function user_has_ticket_access($ticket_id, $user_id) {
    if (!ticket_access_table_exists()) {
        return false;
    }
    try {
        $row = db_fetch_one("SELECT id FROM ticket_access WHERE ticket_id = ? AND user_id = ? LIMIT 1", [$ticket_id, $user_id]);
        return !empty($row);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Add explicit access to a ticket for a user
 */
function add_ticket_access($ticket_id, $user_id, $created_by) {
    if (!ticket_access_table_exists()) {
        return false;
    }
    try {
        $exists = db_fetch_one("SELECT id FROM ticket_access WHERE ticket_id = ? AND user_id = ? LIMIT 1", [$ticket_id, $user_id]);
        if ($exists) {
            return false;
        }
        db_insert('ticket_access', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'created_by' => $created_by,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Remove explicit access to a ticket for a user
 */
function remove_ticket_access($ticket_id, $user_id) {
    if (!ticket_access_table_exists()) {
        return false;
    }
    try {
        return db_delete('ticket_access', 'ticket_id = ? AND user_id = ?', [$ticket_id, $user_id]) > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Note: Ticket permission checks use can_see_ticket() and can_edit_ticket()
// defined in user-functions.php. Legacy duplicates were removed in v0.3.42.

