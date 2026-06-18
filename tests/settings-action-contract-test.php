<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$page = file_get_contents($root . '/pages/admin/settings.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$actions = file_get_contents($root . '/includes/modules/settings/settings-actions.php');
$workflow = file_get_contents($root . '/includes/modules/settings/settings-workflow.php');
$viewModel = file_get_contents($root . '/includes/modules/settings/settings-view-model.php');
$tabs = file_get_contents($root . '/includes/components/admin-settings-tabs.php');

$assert($page !== false && $bootstrap !== false && $actions !== false && $workflow !== false && $viewModel !== false && $tabs !== false, 'Settings files must be readable.');

foreach ([
    '/settings/settings-actions.php',
    '/settings/settings-email.php',
    '/settings/settings-updates.php',
    '/settings/settings-security.php',
    '/settings/settings-workflow.php',
    '/settings/settings-view-model.php',
] as $needle) {
    $assert(str_contains($bootstrap, $needle), 'Settings module bootstrap missing: ' . $needle);
}

$assert(str_contains($page, 'settings_handle_post_request($settings_audit)'), 'Settings page must delegate main POST actions.');
$assert(str_contains($page, 'settings_handle_workflow_post($tab, $_POST)'), 'Settings page must delegate workflow POST actions.');
$assert(str_contains($page, 'settings_tab_from_request($_GET)'), 'Settings page must use settings tab view model.');
$assert(str_contains($page, 'render_admin_settings_tabs($tab)'), 'Settings page must render tabs through the component.');

foreach ([
    "if (isset(\$_POST['save_email'])",
    "if (isset(\$_POST['save_2fa_settings'])",
    "if (isset(\$_POST['install_remote_update'])",
    '$workflow_handlers = [',
    '$settings_tabs = [',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Settings page must not own extracted settings logic: ' . $needle);
}

foreach ([
    'function settings_handle_post_request',
    'save_email',
    'save_2fa_settings',
    'install_remote_update',
    'save_template',
] as $needle) {
    $assert(str_contains($actions, $needle), 'Settings action module missing: ' . $needle);
}

$assert(str_contains($workflow, 'function settings_handle_workflow_post'), 'Settings workflow module must expose workflow handler.');
$assert(str_contains($viewModel, 'function settings_tab_from_request'), 'Settings view model must expose tab helper.');
$assert(str_contains($tabs, 'function render_admin_settings_tabs'), 'Settings tabs component must expose renderer.');

echo "Settings action contract OK\n";
