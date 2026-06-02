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
assert_inbox_page(strpos($inbox, "redirect('work')") !== false, 'client users should be redirected away from inbox.');
assert_inbox_page(strpos($shortcuts, "navigateTo('inbox')") !== false, 'command palette should include inbox.');

echo "Inbox page contract tests passed\n";
