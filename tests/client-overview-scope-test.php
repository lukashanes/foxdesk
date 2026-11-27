<?php

// In-memory fixture. The production visibility predicates and aggregate SQL run unchanged.
$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec("CREATE TABLE tickets (id INTEGER, hash TEXT, title TEXT, status_id INTEGER, is_archived INTEGER,
    organization_id INTEGER, user_id INTEGER, assignee_id INTEGER, updated_at TEXT, created_at TEXT);
    CREATE TABLE statuses (id INTEGER, name TEXT, color TEXT, status_group TEXT);
    CREATE TABLE users (id INTEGER, first_name TEXT, last_name TEXT);
    CREATE TABLE ticket_access (ticket_id INTEGER, user_id INTEGER);
    CREATE TABLE organizations (id INTEGER, billable_rate REAL);
    CREATE TABLE ticket_time_entries (ticket_id INTEGER, user_id INTEGER, started_at TEXT, ended_at TEXT,
        duration_minutes INTEGER, is_billable INTEGER, billable_rate REAL);
    INSERT INTO organizations VALUES (7, 120), (8, 300);
    INSERT INTO statuses VALUES (1, 'Open', '', 'active'), (2, 'Waiting', '', 'waiting'), (3, 'Done', '', 'done');
    INSERT INTO tickets VALUES
        (1, 'one', 'Own', 1, 0, 7, 10, 10, '2026-10-01', '2026-10-01'),
        (2, 'two', 'Shared', 2, 0, 7, 11, 11, '2026-10-01', '2026-10-01'),
        (3, 'three', 'Private', 1, 0, 7, 11, 11, '2026-10-02', '2026-10-02'),
        (4, 'four', 'Other organization', 1, 0, 8, 10, 10, '2026-10-02', '2026-10-02'),
        (5, 'five', 'Archived', 3, 1, 7, 10, 10, '2026-10-03', '2026-10-03');
    INSERT INTO ticket_access VALUES (2, 10)");

$viewer = ['id' => 10, 'role' => 'agent'];
$time_table_exists = true;
function current_user(): array { return $GLOBALS['viewer']; }
function get_user_permissions($id): array { return ['ticket_scope' => 'own']; }
function ticket_access_table_exists(): bool { return true; }
function column_exists($table, $column): bool { return false; }
function ticket_time_table_exists(): bool { return $GLOBALS['time_table_exists']; }
function get_statuses(): array { return db_fetch_all('SELECT * FROM statuses'); }
function get_status($id) { return db_fetch_one('SELECT * FROM statuses WHERE id = ?', [$id]); }
function db_fetch_all($sql, $params = []): array
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}
function db_fetch_one($sql, $params = []) { return db_fetch_all($sql, $params)[0] ?? null; }

$includes = dirname(__DIR__) . '/includes';
require_once $includes . '/ticket-query-functions.php';
require_once $includes . '/modules/tickets/ticket-status-groups.php';
require_once $includes . '/modules/tickets/ticket-list-views.php';
require_once $includes . '/modules/work/work-queues.php';
require_once $includes . '/modules/clients/client-overview.php';

$today = date('Y-m-d 12:00:00');
[$month_start, $month_end] = client_overview_month_bounds();
$insert = $db->prepare('INSERT INTO ticket_time_entries VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 10, $month_start, $month_start, 30, 1, null], // fallback to organization rate
    [2, 10, $today, $today, 15, 0, 120],
    [2, 11, $today, $today, 40, 1, 60],
    [3, 10, $today, $today, 25, 1, 48], // viewer's entry, but outside ticket scope
    [4, 10, $today, $today, 35, 1, 60], // viewer's entry, but at a different client
    [1, 10, $today, null, 90, 1, 120],
    [1, 10, $today, $today, 0, 1, 600],
    [1, 10, $month_end, $month_end, 55, 1, 120],
] as $entry) {
    $insert->execute($entry);
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $condition ? 0 : 1;
};

$check(client_overview_ticket_counts(7) === ['open' => 2, 'waiting' => 1, 'done' => 0, 'archived' => 1, 'all' => 3],
    'Agent counts include shared tickets and exclude private or cross-client records.');
$check(array_column(client_overview_recent_tickets(7, 'open', 1, 0), 'id') === [2]
    && array_column(client_overview_recent_tickets(7, 'open', 1, 1), 'id') === [1],
    'Pagination uses a deterministic order within the visible open tickets.');
$check(array_column(client_overview_recent_tickets(7, 'archived'), 'id') === [5],
    'Archived tickets remain accessible in the archived view.');

$time = client_overview_time_summary(7);
$check($time['minutes'] === 45 && $time['billable_minutes'] === 30 && $time['billable_amount'] === 60.0,
    'Agent time uses their own completed work on visible tickets only, including the month start.');

$viewer = ['id' => 99, 'role' => 'admin'];
$time = client_overview_time_summary(7);
$check($time['minutes'] === 110 && $time['billable_minutes'] === 95 && $time['billable_amount'] === 120.0,
    'Admin time includes team work at the same client and excludes the next month and running timers.');
$time_table_exists = false;
$check(client_overview_time_summary(7) === ['minutes' => 0, 'today' => 0, 'week' => 0, 'billable_minutes' => 0, 'billable_amount' => 0.0],
    'A schema without time entries returns an empty aggregate.');

if ($failures > 0) {
    exit(1);
}
