<?php
define('APP_URL', 'https://support.example.test/helpdesk/');
function ticket_url($ticket) { return $ticket['canonical_url']; }
require dirname(__DIR__) . '/includes/api/agent-handler.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
};
foreach ([
    ['index.php?page=ticket&t=abcXYZ234567', 'https://support.example.test/helpdesk/index.php?page=ticket&t=abcXYZ234567'],
    ['index.php?page=ticket&id=123', 'https://support.example.test/helpdesk/index.php?page=ticket&id=123'],
    ['https://workspace.example.test/index.php?page=ticket&t=abcXYZ234567', 'https://workspace.example.test/index.php?page=ticket&t=abcXYZ234567'],
    ['http://localhost:8097/index.php?page=ticket&t=abcXYZ234567', 'http://localhost:8097/index.php?page=ticket&t=abcXYZ234567'],
] as [$canonical, $expected]) {
    $assert(api_agent_ticket_url(['canonical_url' => $canonical]) === $expected, 'Canonical ticket link must preserve absolute URLs and configured subpaths.');
}
echo "Agent ticket URL root/subpath/absolute/legacy-ID tests passed\n";
