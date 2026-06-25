<?php

$root = dirname(__DIR__);

function assert_admin_ui(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_admin_ui_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_admin_ui($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$header = read_admin_ui_file($root, 'includes/header.php');
$theme = read_admin_ui_file($root, 'theme.css');
$tickets = read_admin_ui_file($root, 'pages/tickets.php');
$page_header = read_admin_ui_file($root, 'includes/components/page-header.php');
$admin_nav = read_admin_ui_file($root, 'includes/components/admin-nav.php');
$admin_settings_tabs = read_admin_ui_file($root, 'includes/components/admin-settings-tabs.php');
$admin_settings = read_admin_ui_file($root, 'pages/admin/settings.php');
$admin_users = read_admin_ui_file($root, 'pages/admin/users.php');
$admin_clients = read_admin_ui_file($root, 'pages/admin/clients.php');
$ui_css = $theme . "\n" . $tickets;

foreach ([
    'class="header-search-form relative"',
    'class="form-input pr-4 header-search-input"',
    'class="header-search-icon absolute top-1/2 transform -translate-y-1/2"',
] as $needle) {
    assert_admin_ui(str_contains($header, $needle), 'Header search markup missing stable contract: ' . $needle);
}

assert_admin_ui(str_contains($header, "title=\"<?php echo e(t('Dashboard')); ?>\""), 'Primary home navigation must be labeled Dashboard.');
assert_admin_ui(!str_contains($header, "title=\"<?php echo e(t('Work')); ?>\""), 'Primary home navigation must not use the Work label.');
assert_admin_ui(
    strpos($header, "url('admin', ['section' => 'settings'])") > strpos($header, '<!-- Active timers'),
    'Settings must live in the lower sidebar area, not the primary navigation block.'
);

foreach ([
    '.header-search-form',
    'flex: 0 1 360px',
    '.header-search-input',
    'box-sizing: border-box',
    'padding-left: 2.75rem !important',
    '.header-search-icon',
    'pointer-events: none',
    'transform: translateY(-50%)',
] as $needle) {
    assert_admin_ui(str_contains($theme, $needle), 'theme.css missing header search rule: ' . $needle);
}

foreach ([
    '.ticket-search-wrap',
    'width: 10rem',
    '.ticket-search-wrap .search-icon',
    'left: 0.65rem',
    '.ticket-search-input',
    'padding: 0.35rem 0.5rem 0.35rem 1.85rem',
    'width: 100%',
    '.ticket-search-wrap { width: 12rem; }',
] as $needle) {
    assert_admin_ui(str_contains($ui_css, $needle), 'Ticket search UI contract missing: ' . $needle);
}

preg_match('/\\.ticket-search-input\\s*\\{(?P<block>.*?)\\}/s', $ui_css, $ticket_input_match);
preg_match('/\\.ticket-search-input::placeholder\\s*\\{(?P<block>.*?)\\}/s', $ui_css, $ticket_placeholder_match);
preg_match('/\\.ticket-search-input:focus\\s*\\{(?P<block>.*?)\\}/s', $ui_css, $ticket_focus_match);
assert_admin_ui(!str_contains($ticket_input_match['block'] ?? '', 'width: 2rem;'), 'Ticket search must not collapse to an icon-only field.');
assert_admin_ui(!str_contains($ticket_placeholder_match['block'] ?? '', 'color: transparent;'), 'Ticket search placeholder must stay readable.');
assert_admin_ui(!str_contains($ticket_focus_match['block'] ?? '', 'width:'), 'Ticket search width must not jump on focus.');

foreach ([
    '.admin-tabs',
    '.admin-page-nav',
    '.admin-page-nav__group',
    '.admin-page-nav__item',
    '.admin-list-card',
    '.admin-filter-bar',
    '.admin-two-column',
    '.admin-responsive-table-wrap',
    '.admin-responsive-table',
    '.admin-responsive-primary',
    '.admin-responsive-actions',
    '.admin-cell-title',
    '.admin-cell-subtitle',
    '.admin-cell-muted',
    'content: attr(data-label)',
    'width: 100% !important',
    'max-width: none !important',
    'justify-items: start',
    '@media (max-width: 720px)',
] as $needle) {
    assert_admin_ui(str_contains($theme, $needle), 'Admin surface token missing from theme.css: ' . $needle);
}

foreach ([
    'admin-page-nav',
] as $needle) {
    assert_admin_ui(str_contains($admin_nav, $needle), 'Legacy admin navigation component must remain readable for compatibility: ' . $needle);
}
assert_admin_ui(!str_contains($page_header, "include BASE_PATH . '/includes/components/admin-nav.php'"), 'Page header must not auto-render the old horizontal admin navigation.');
assert_admin_ui(str_contains($page_header, "\$is_admin_child_page"), 'Admin child pages must use the compact Settings breadcrumb contract.');
assert_admin_ui(str_contains($page_header, "url('admin', ['section' => 'settings'])"), 'Admin child breadcrumbs must link back to Settings.');

foreach ([
    'data-settings-management',
    'render_admin_settings_management_links',
    "url('admin', ['section' => 'users'])",
    "url('admin', ['section' => 'clients'])",
    "url('admin', ['section' => 'organizations'])",
    "url('admin', ['section' => 'statuses'])",
    "url('admin', ['section' => 'recurring-tasks'])",
    "url('admin', ['section' => 'reports'])",
    "url('admin', ['section' => 'reports-list'])",
    "url('admin', ['section' => 'activity'])",
] as $needle) {
    assert_admin_ui(str_contains($admin_settings . "\n" . $admin_nav . "\n" . $admin_settings_tabs, $needle), 'Settings must expose moved admin area: ' . $needle);
}

foreach ([
    '.settings-management-panel',
    '.settings-management-panel__head',
    '.settings-section-nav--management',
] as $needle) {
    assert_admin_ui(str_contains($theme, $needle), 'Settings management CSS missing: ' . $needle);
}

foreach ([
    'admin-responsive-table admin-users-table',
    'admin-responsive-table admin-ai-agents-table',
    'admin-responsive-primary',
    'admin-responsive-actions',
    'admin-cell-title',
    'admin-cell-subtitle',
    'data-label="<?php echo e(t(\'Name\')); ?>"',
    'data-label="<?php echo e(t(\'Company\')); ?>"',
    'data-label="<?php echo e(t(\'Actions\')); ?>"',
] as $needle) {
    assert_admin_ui(str_contains($admin_users, $needle), 'Users admin responsive contract missing: ' . $needle);
}

foreach ([
    'admin-responsive-table admin-clients-table',
    'admin-responsive-primary',
    'admin-responsive-actions',
    'admin-cell-title',
    'admin-cell-muted',
    'data-label="<?php echo e(t(\'Name\')); ?>"',
    'data-label="<?php echo e(t(\'Email\')); ?>"',
    'data-label="<?php echo e(t(\'Actions\')); ?>"',
] as $needle) {
    assert_admin_ui(str_contains($admin_clients, $needle), 'Clients admin responsive contract missing: ' . $needle);
}

assert_admin_ui(!str_contains($admin_users . $admin_clients, 'class="admin-cell-title"' . "\n" . '                                                class='), 'Admin tables must not contain duplicate class attributes.');

echo "Admin UI contract OK\n";
