<?php

$root = dirname(__DIR__);

function assert_quick_start(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function quick_start_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_quick_start($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$bootstrap = quick_start_file($root, 'includes/modules/bootstrap.php');
$module = quick_start_file($root, 'includes/modules/work/quick-start-work.php');
$handler = quick_start_file($root, 'includes/api/ticket-handler.php');
$header = quick_start_file($root, 'includes/header.php');
$footer_js = quick_start_file($root, 'assets/js/app-footer.js');
$detail_page = quick_start_file($root, 'pages/ticket-detail.php');
$detail_js = quick_start_file($root, 'assets/js/ticket-detail.js');

assert_quick_start(str_contains($bootstrap, "work/quick-start-work.php"), 'Module bootstrap must load quick-start work.');
foreach ([
    'function quick_start_work_status_id',
    'function quick_start_work_create',
    "ticket_status_group_from_status(\$status) !== 'active'",
    "'organization_id' => null",
    "'assignee_id' => (int) \$user['id']",
    "'source'] = 'timer'",
    'beginTransaction()',
    'rollBack()',
    "'quick_start=1'",
] as $needle) {
    assert_quick_start(str_contains($module, $needle), 'Quick-start module is missing: ' . $needle);
}

assert_quick_start(str_contains($handler, 'quick_start_work_create($user)'), 'API endpoint must delegate to the shared quick-start module.');
assert_quick_start(str_contains($header, 'data-quick-start-work'), 'Staff navigation must expose one visible Start work action.');
assert_quick_start(!str_contains($header, 'nav-item-flyout'), 'Staff navigation must not hide a second quick-start action in a flyout.');
assert_quick_start(!str_contains($header, "['auto_timer' => '1']"), 'Staff navigation must not use the old full-form timer flow.');
assert_quick_start(str_contains($footer_js, "action=quick-start"), 'Global app JS must call the quick-start endpoint.');
assert_quick_start(str_contains($footer_js, 'window.location.assign(result.url)'), 'Quick start must open the created draft.');
assert_quick_start(str_contains($detail_page, "'quickStart' => isset(\$_GET['quick_start'])"), 'Ticket detail must expose quick-start state.');
assert_quick_start(str_contains($detail_js, 'if (config.quickStart'), 'Ticket detail must open the completion flow for quick drafts.');
assert_quick_start(str_contains($detail_js, "#edit-ticket-modal input[name=\"edit_title\"]"), 'Quick draft completion must focus the actual edit title field.');
assert_quick_start(str_contains($detail_js, "classList.add('is-quick-start')"), 'Quick draft completion must use the focused quick-start modal state.');
$modals = quick_start_file($root, 'includes/components/ticket-detail-modals.php');
assert_quick_start(str_contains($modals, 'data-quick-start-optional'), 'Quick draft completion must hide nonessential ticket fields.');

foreach (['en', 'cs', 'de', 'es', 'it'] as $language) {
    $catalog = quick_start_file($root, 'includes/lang/' . $language . '.php');
    foreach (['Untitled work', 'Name this work', 'The timer is running. Add a subject and client now; everything else can wait.', 'Could not start work.', 'Work started. Add a title and client.'] as $key) {
        assert_quick_start(str_contains($catalog, "'" . $key . "' =>"), strtoupper($language) . ' translation is missing: ' . $key);
    }
}

echo "Quick-start work contract OK\n";
