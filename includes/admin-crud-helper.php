<?php
/**
 * Admin CRUD Helper Functions
 *
 * Common utility functions for admin CRUD operations.
 * Provides standardized patterns for create, update, delete, and reorder.
 */

/**
 * Reorder items in a table via sort_order column
 */
function reorder_items($table, $order_array) {
    validate_sql_identifier($table);
    if (!is_array($order_array) || empty($order_array)) {
        return false;
    }

    foreach ($order_array as $index => $id) {
        db_update($table, ['sort_order' => $index + 1], 'id = ?', [(int)$id]);
    }

    return true;
}

/**
 * Move an item up in sort order
 */
function move_item_up($table, $id) {
    validate_sql_identifier($table);
    $item = db_fetch_one("SELECT id, sort_order FROM {$table} WHERE id = ?", [$id]);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $above = db_fetch_one(
        "SELECT id, sort_order FROM {$table} WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$item['sort_order']]
    );

    if (!$above) {
        return ['success' => false, 'message' => 'Already at top'];
    }

    // Swap sort orders
    db_update($table, ['sort_order' => $above['sort_order']], 'id = ?', [$item['id']]);
    db_update($table, ['sort_order' => $item['sort_order']], 'id = ?', [$above['id']]);

    return ['success' => true];
}

/**
 * Move an item down in sort order
 */
function move_item_down($table, $id) {
    validate_sql_identifier($table);
    $item = db_fetch_one("SELECT id, sort_order FROM {$table} WHERE id = ?", [$id]);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $below = db_fetch_one(
        "SELECT id, sort_order FROM {$table} WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$item['sort_order']]
    );

    if (!$below) {
        return ['success' => false, 'message' => 'Already at bottom'];
    }

    // Swap sort orders
    db_update($table, ['sort_order' => $below['sort_order']], 'id = ?', [$item['id']]);
    db_update($table, ['sort_order' => $item['sort_order']], 'id = ?', [$below['id']]);

    return ['success' => true];
}

/**
 * Standard API response helpers
 */
function api_success($data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function api_error($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'success' => false]);
    exit;
}

/**
 * Check for admin permission and POST method
 */
function require_admin_post() {
    if (!is_admin()) {
        api_error('Forbidden', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);
}

/**
 * Get JSON input from request body
 */
function get_json_input() {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}


