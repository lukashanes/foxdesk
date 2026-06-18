<?php

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/includes/functions.php');
$component = file_get_contents($root . '/includes/components/workspace-surface.php');
$theme = file_get_contents($root . '/theme.css');
$work = file_get_contents($root . '/pages/work.php');
$inbox = file_get_contents($root . '/pages/inbox.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($functions !== false && $component !== false && $theme !== false && $work !== false && $inbox !== false, 'Workspace surface files must be readable.');
$assert(str_contains($functions, '/includes/components/workspace-surface.php'), 'Workspace surface component must be loaded globally.');

foreach ([
    'function workspace_surface_action',
    'function workspace_render_queue_page',
    'function workspace_render_ticket_rows',
    'function workspace_render_ticket_row',
    'data-workspace-queue-surface',
    'data-app-contract-surface',
    'data-app-contract-collection',
    'data-work-active-key',
    'data-work-ticket-list',
    'data-work-empty-label',
    "t('All clear')",
] as $needle) {
    $assert(str_contains($component, $needle), 'Workspace surface component is missing: ' . $needle);
}

foreach ([
    '.workspace-surface-head',
    '.workspace-queue-shell',
    '.workspace-queue-rail',
    '.workspace-queue-panel',
    '.workspace-ticket-row',
    '.workspace-ticket-row__dot--waiting',
    '.workspace-empty',
] as $needle) {
    $assert(str_contains($theme, $needle), 'theme.css missing workspace surface selector: ' . $needle);
}

$assert(str_contains($work, 'workspace_render_queue_page'), 'Work page must use workspace queue renderer.');
$assert(str_contains($inbox, 'workspace_render_queue_page'), 'Inbox page must use workspace queue renderer.');
$assert(str_contains($inbox, "'contract_surface' => 'inbox'"), 'Inbox page must use the inbox contract surface.');
$assert(str_contains($inbox, "'contract_collection' => 'inbox'"), 'Inbox page must hydrate from home.inbox.');

foreach (['work-shell', 'inbox-shell', 'work-ticket-list', 'inbox-ticket__title', '<style>'] as $oldSelector) {
    $assert(!str_contains($work, $oldSelector), 'Work page should not keep old queue markup: ' . $oldSelector);
    $assert(!str_contains($inbox, $oldSelector), 'Inbox page should not keep old queue markup: ' . $oldSelector);
}

echo "Workspace surface contract OK\n";
