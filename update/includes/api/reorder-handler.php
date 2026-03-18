<?php
/**
 * API Handler: Reorder Operations
 *
 * Handles drag-drop reordering and move up/down for statuses and priorities.
 */

/**
 * Handle reorder statuses
 */
function api_reorder_statuses() {
    require_admin_post();

    $input = get_json_input();
    $order = $input['order'] ?? [];

    if (!empty($order) && is_array($order)) {
        reorder_items('statuses', $order);
        api_success();
    }

    api_error('Invalid data');
}

/**
 * Handle move status up
 */
function api_move_status_up() {
    require_admin_post();

    $id = (int)($_GET['id'] ?? 0);
    $result = move_item_up('statuses', $id);

    if ($result['success']) {
        api_success();
    }

    if (isset($result['message']) && $result['message'] === 'Item not found') {
        api_error('Status not found', 404);
    }

    api_success($result);
}

/**
 * Handle move status down
 */
function api_move_status_down() {
    require_admin_post();

    $id = (int)($_GET['id'] ?? 0);
    $result = move_item_down('statuses', $id);

    if ($result['success']) {
        api_success();
    }

    if (isset($result['message']) && $result['message'] === 'Item not found') {
        api_error('Status not found', 404);
    }

    api_success($result);
}

/**
 * Handle reorder priorities
 */
function api_reorder_priorities() {
    require_admin_post();

    $input = get_json_input();
    $order = $input['order'] ?? [];

    if (!empty($order) && is_array($order)) {
        reorder_items('priorities', $order);
        api_success();
    }

    api_error('Invalid data');
}

/**
 * Handle move priority up
 */
function api_move_priority_up() {
    require_admin_post();

    $id = (int)($_GET['id'] ?? 0);
    $result = move_item_up('priorities', $id);

    if ($result['success']) {
        api_success();
    }

    api_success($result);
}

/**
 * Handle move priority down
 */
function api_move_priority_down() {
    require_admin_post();

    $id = (int)($_GET['id'] ?? 0);
    $result = move_item_down('priorities', $id);

    if ($result['success']) {
        api_success();
    }

    api_success($result);
}

/**
 * Handle reorder ticket types
 */
function api_reorder_ticket_types() {
    require_admin_post();

    $input = get_json_input();
    $order = $input['order'] ?? [];

    if (!empty($order) && is_array($order)) {
        reorder_items('ticket_types', $order);
        api_success();
    }

    api_error('Invalid data');
}


