<?php
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$header = file_get_contents($root . '/includes/header.php');
$inbox = file_get_contents($root . '/pages/inbox.php');
$shortcuts = file_get_contents($root . '/assets/js/shortcuts.js');

function assert_inbox_page($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_inbox_page(strpos($index, "case 'inbox'") !== false, 'inbox route is not registered.');
assert_inbox_page(strpos($header, "url('inbox')") !== false, 'sidebar should link to inbox.');
assert_inbox_page(strpos($inbox, 'inbox_summary') !== false, 'inbox page should use inbox service summary.');
assert_inbox_page(strpos($inbox, 'workspace_render_queue_page') !== false, 'inbox page should use the shared workspace queue renderer.');
assert_inbox_page(strpos($inbox, "redirect('work')") !== false, 'client users should be redirected away from inbox.');
assert_inbox_page(strpos($shortcuts, "navigateTo('inbox')") !== false, 'command palette should include inbox.');
foreach ([
    'Decide what should be assigned, started, merged, or closed.',
    'Current inbox',
    'New or unassigned tickets that need a decision.',
    'Tickets where the latest public reply came from a client user.',
    'Tickets created from inbound email that still need triage.',
    'No ticket needs triage in this queue.',
] as $forbidden_copy) {
    assert_inbox_page(strpos($inbox, $forbidden_copy) === false, 'inbox page should not render redundant helper copy: ' . $forbidden_copy);
}
$workspaceSurface = file_get_contents($root . '/includes/components/workspace-surface.php');
assert_inbox_page($workspaceSurface !== false && strpos($workspaceSurface, "t('All clear')") !== false, 'empty inbox queue should use concise state copy from the shared renderer.');

echo "Inbox page contract tests passed\n";
