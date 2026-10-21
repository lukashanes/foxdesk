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
    ['https://helpdesk.example.test/index.php?page=ticket&t=abcXYZ234567', 'https://helpdesk.example.test/index.php?page=ticket&t=abcXYZ234567'],
    ['http://localhost:8097/index.php?page=ticket&t=abcXYZ234567', 'http://localhost:8097/index.php?page=ticket&t=abcXYZ234567'],
] as [$canonical, $expected]) {
    $assert(api_agent_ticket_url(['canonical_url' => $canonical]) === $expected, 'Canonical ticket link must preserve absolute URLs and configured subpaths.');
}

$root = dirname(__DIR__);
$planner = file_get_contents($root . '/includes/modules/agent/work-log-planner.php');
$detail = file_get_contents($root . '/pages/ticket-detail.php');
$crud = file_get_contents($root . '/includes/ticket-crud-functions.php');
$assert(str_contains((string) $planner, "'ticket_url' => \$ticket_row && function_exists('api_agent_ticket_url') ? api_agent_ticket_url(\$ticket_row) : null"), 'Applied work logs must return the canonical ticket link.');
$assert(str_contains((string) $detail, "\$_GET['t'] ?? \$_GET['hash'] ?? null"), 'Ticket detail must keep previously issued hash= agent links usable.');
$assert(preg_match('/function get_ticket_by_hash\(.*?s\.is_closed/s', (string) $crud) === 1, 'Tickets opened by hash must carry the closed state so completed tickets render as done.');

echo "Agent ticket URL root/subpath/absolute/legacy-ID tests passed\n";
