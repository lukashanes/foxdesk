<?php
/**
 * Settings workflow POST router.
 */

function settings_workflow_handlers(): array
{
    return [
        'add_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'update_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'delete_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'set_default' => BASE_PATH . '/pages/admin/statuses-content.php',
        'create_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'update_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'delete_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'create_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'update_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'delete_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'toggle_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
    ];
}

function settings_handle_workflow_post(string $tab, array $post): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $tab !== 'workflow') {
        return;
    }

    require_csrf_token();
    foreach (settings_workflow_handlers() as $key => $handler) {
        if (isset($post[$key]) && file_exists($handler)) {
            include $handler;
            return;
        }
    }
}
