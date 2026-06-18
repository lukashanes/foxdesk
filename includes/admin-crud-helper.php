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
        $where = 'id = ?';
        $params = [(int)$id];
        if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
            $where .= ' AND tenant_id = ?';
            $params[] = current_tenant_id();
        }
        db_update($table, ['sort_order' => $index + 1], $where, $params);
    }

    return true;
}

function admin_crud_slug_from_name(string $name, string $separator = '_'): string
{
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', $separator, $name));
    return trim($slug, $separator);
}

function admin_crud_unique_slug(string $table, string $name, string $separator = '_'): string
{
    validate_sql_identifier($table);
    $slug = admin_crud_slug_from_name($name, $separator);
    $params = [$slug];
    $sql = "SELECT id FROM {$table} WHERE slug = ?";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $sql .= " AND tenant_id = ?";
        $params[] = current_tenant_id();
    }
    $existing = db_fetch_one($sql, $params);

    return $existing ? $slug . $separator . time() : $slug;
}

function admin_crud_next_sort_order(string $table): int
{
    validate_sql_identifier($table);
    $params = [];
    $sql = "SELECT MAX(sort_order) as max_order FROM {$table} WHERE 1=1";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $sql .= " AND tenant_id = ?";
        $params[] = current_tenant_id();
    }
    $max = db_fetch_one($sql, $params);

    return (int) (($max['max_order'] ?? 0) + 1);
}

function admin_crud_tenant_filter(string $table, array &$params, string $alias = ''): string
{
    if (function_exists('tenant_sql_filter')) {
        return tenant_sql_filter($table, $alias, $params);
    }

    return '';
}

function admin_crud_record_where(string $table, array &$params, string $alias = ''): string
{
    validate_sql_identifier($table);
    $prefix = $alias !== '' ? $alias . '.' : '';
    $where = "{$prefix}id = ?";

    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $where .= " AND {$prefix}tenant_id = ?";
        $params[] = current_tenant_id();
    }

    return $where;
}

function admin_crud_fetch_record(string $table, int $id): ?array
{
    validate_sql_identifier($table);
    $params = [$id];
    $where = admin_crud_record_where($table, $params);
    $row = db_fetch_one("SELECT * FROM {$table} WHERE {$where}", $params);

    return $row ?: null;
}

function admin_crud_fetch_ordered(string $table, string $extra_where = '', array $extra_params = []): array
{
    validate_sql_identifier($table);
    $params = $extra_params;
    $sql = "SELECT * FROM {$table} WHERE 1=1";

    if ($extra_where !== '') {
        $sql .= " AND {$extra_where}";
    }

    $sql .= admin_crud_tenant_filter($table, $params);
    $sql .= " ORDER BY sort_order";

    return db_fetch_all($sql, $params);
}

function admin_crud_update_record(string $table, int $id, array $data): int
{
    $params = [$id];
    $where = admin_crud_record_where($table, $params);

    return db_update($table, $data, $where, $params);
}

function admin_crud_delete_record(string $table, int $id): int
{
    $params = [$id];
    $where = admin_crud_record_where($table, $params);

    return db_delete($table, $where, $params);
}

function admin_crud_clear_default(string $table): void
{
    validate_sql_identifier($table);
    $params = [];
    $sql = "UPDATE {$table} SET is_default = 0";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $sql .= " WHERE tenant_id = ?";
        $params[] = current_tenant_id();
    }
    db_query($sql, $params);
}

function admin_crud_delete_if_unused(string $table, int $id, string $usage_sql, array $usage_params): bool
{
    validate_sql_identifier($table);
    $usage = db_fetch_one($usage_sql, $usage_params);
    if ($usage && (int) ($usage['count'] ?? $usage['c'] ?? 0) > 0) {
        return false;
    }

    $where = 'id = ?';
    $params = [$id];
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $where .= ' AND tenant_id = ?';
        $params[] = current_tenant_id();
    }
    db_delete($table, $where, $params);
    return true;
}

/**
 * Move an item up in sort order
 */
function move_item_up($table, $id) {
    validate_sql_identifier($table);
    $params = [$id];
    $sql = "SELECT id, sort_order FROM {$table} WHERE id = ?";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $sql .= " AND tenant_id = ?";
        $params[] = current_tenant_id();
    }
    $item = db_fetch_one($sql, $params);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $above_params = [$item['sort_order']];
    $above_sql = "SELECT id, sort_order FROM {$table} WHERE sort_order < ?";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $above_sql .= " AND tenant_id = ?";
        $above_params[] = current_tenant_id();
    }
    $above_sql .= " ORDER BY sort_order DESC LIMIT 1";
    $above = db_fetch_one($above_sql, $above_params);

    if (!$above) {
        return ['success' => false, 'message' => 'Already at top'];
    }

    // Swap sort orders
    $item_where = 'id = ?';
    $item_params = [$item['id']];
    $above_where = 'id = ?';
    $above_update_params = [$above['id']];
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $item_where .= ' AND tenant_id = ?';
        $above_where .= ' AND tenant_id = ?';
        $item_params[] = current_tenant_id();
        $above_update_params[] = current_tenant_id();
    }
    db_update($table, ['sort_order' => $above['sort_order']], $item_where, $item_params);
    db_update($table, ['sort_order' => $item['sort_order']], $above_where, $above_update_params);

    return ['success' => true];
}

/**
 * Move an item down in sort order
 */
function move_item_down($table, $id) {
    validate_sql_identifier($table);
    $params = [$id];
    $sql = "SELECT id, sort_order FROM {$table} WHERE id = ?";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $sql .= " AND tenant_id = ?";
        $params[] = current_tenant_id();
    }
    $item = db_fetch_one($sql, $params);

    if (!$item) {
        return ['success' => false, 'message' => 'Item not found'];
    }

    $below_params = [$item['sort_order']];
    $below_sql = "SELECT id, sort_order FROM {$table} WHERE sort_order > ?";
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $below_sql .= " AND tenant_id = ?";
        $below_params[] = current_tenant_id();
    }
    $below_sql .= " ORDER BY sort_order ASC LIMIT 1";
    $below = db_fetch_one($below_sql, $below_params);

    if (!$below) {
        return ['success' => false, 'message' => 'Already at bottom'];
    }

    // Swap sort orders
    $item_where = 'id = ?';
    $item_params = [$item['id']];
    $below_where = 'id = ?';
    $below_update_params = [$below['id']];
    if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column($table)) {
        $item_where .= ' AND tenant_id = ?';
        $below_where .= ' AND tenant_id = ?';
        $item_params[] = current_tenant_id();
        $below_update_params[] = current_tenant_id();
    }
    db_update($table, ['sort_order' => $below['sort_order']], $item_where, $item_params);
    db_update($table, ['sort_order' => $item['sort_order']], $below_where, $below_update_params);

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

