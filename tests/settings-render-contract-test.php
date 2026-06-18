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
$functions = file_get_contents($root . '/includes/functions.php');
$templates = file_get_contents($root . '/includes/modules/settings/settings-templates.php');
$workflowCard = file_get_contents($root . '/includes/components/admin-workflow-card.php');

$assert(
    $page !== false && $bootstrap !== false && $functions !== false && $templates !== false && $workflowCard !== false,
    'Settings render contract files must be readable.'
);

$assert(str_contains($bootstrap, '/settings/settings-templates.php'), 'Bootstrap must load settings template helpers.');
$assert(str_contains($functions, '/includes/components/admin-workflow-card.php'), 'Functions bootstrap must load workflow card component.');

foreach ([
    'settings_email_template_catalog()',
    'settings_email_template_display_rows($templates, $template_lang)',
    'admin_workflow_cards()',
    'render_admin_workflow_card($workflow_card)',
] as $needle) {
    $assert(str_contains($page, $needle), 'Settings page must delegate render logic: ' . $needle);
}

foreach ([
    '$template_info = [',
    '$default_templates = [',
    "include BASE_PATH . '/pages/admin/statuses-content.php';",
    "include BASE_PATH . '/pages/admin/priorities-content.php';",
    "include BASE_PATH . '/pages/admin/ticket-types-content.php';",
] as $needle) {
    $assert(!str_contains($page, $needle), 'Settings page must not own extracted render logic: ' . $needle);
}

foreach ([
    'function settings_email_template_catalog',
    'function settings_email_template_defaults',
    'function settings_email_template_display_rows',
    'get_builtin_email_template($key, $lang)',
] as $needle) {
    $assert(str_contains($templates, $needle), 'Settings template helper missing: ' . $needle);
}

foreach ([
    'function render_admin_workflow_card',
    'function admin_workflow_cards',
    "BASE_PATH . '/pages/admin/statuses-content.php'",
    "BASE_PATH . '/pages/admin/priorities-content.php'",
    "BASE_PATH . '/pages/admin/ticket-types-content.php'",
] as $needle) {
    $assert(str_contains($workflowCard, $needle), 'Workflow card component missing: ' . $needle);
}

echo "Settings render contract OK\n";
