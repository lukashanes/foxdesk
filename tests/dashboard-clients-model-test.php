<?php
require dirname(__DIR__) . '/includes/modules/work/dashboard-clients.php';
function format_date_localized($date, $format) { return date($format, strtotime($date)); }
function ticket_time_table_exists() { return true; }
function t($value) { return $value; }
function build_ticket_visibility_filters_for_user($user) { return ['visible_to' => $user['id']]; }
function build_ticket_where_clause($filters, &$params) { $params[] = $filters['visible_to']; return ' WHERE t.visible_to = ?'; }
function db_fetch_all($sql, $params) {
    $GLOBALS['query'] = [$sql, $params];
    return $GLOBALS['records'];
}
$assert = static function ($condition, $message) { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } };
$period = ['start' => date('Y-m-01 00:00:00'), 'end' => date('Y-m-t 23:59:59')];
$GLOBALS['records'] = [];
for ($i = 1; $i <= 100; $i++) {
    $GLOBALS['records'][] = ['day_key' => date('Y-m-d'), 'client_id' => $i, 'client_name' => 'Client ' . $i, 'minutes' => $i, 'record_count' => 101 - $i];
}
$model = dashboard_clients_model(['id' => 3, 'role' => 'agent'], $period, true);
$assert(count($model['clients']) === 100, 'All 100 positive clients must remain available');
$assert($model['clients'][0]['id'] === 1 && $model['clients'][99]['id'] === 100, 'Rank by positive record count before duration');
$assert($model['totals']['month'] === 5050 && $model['totals']['today'] === 5050, 'Exact saved totals');
$assert(array_sum(array_column($model['chart']['days'], 'minutes')) === $model['totals']['selected'], 'Graph and summary agree');
$projection = dashboard_clients_projection($model, null, 25);
$assert(count($projection['datasets']) === 6 && count($projection['columns']) === 4 && $projection['pages'] === 25, '100 clients use top five plus Other and four-column pagination');
$assert(array_sum(array_map(static fn($series) => array_sum($series['data']), $projection['datasets'])) === 5050, 'Other is additive, no client time lost');
$assert($projection['total'] === 5050 && $projection['columns'][0]['id'] === 97, 'Paged columns never truncate grand total');
$filtered = dashboard_clients_projection($model, 42);
$assert(count($filtered['clients']) === 1 && $filtered['total'] === 42, 'Exact client filter');
$assert(dashboard_clients_projection($model, 10001)['total'] === 0, 'Unknown client cannot expose unrelated time');
$assert(str_contains($GLOBALS['query'][0], 'tte.ended_at IS NOT NULL'), 'Do not double-count a running timer as saved work');
$assert(str_contains($GLOBALS['query'][0], 't.visible_to = ?'), 'Dashboard and ledger must share ticket visibility');
$assert(str_contains($GLOBALS['query'][0], 'tte.user_id = ?') && end($GLOBALS['query'][1]) === 3, 'Agents cannot elevate to team data');
dashboard_clients_model(['id' => 3, 'role' => 'admin'], $period, true);
$assert(!str_contains($GLOBALS['query'][0], 'tte.user_id = ?'), 'Administrators may request team data');
$empty = dashboard_clients_model(['id' => 0, 'role' => 'admin'], $period, true);
$assert($empty['totals']['selected'] === 0 && !$empty['clients'], 'Anonymous data is empty');
$invalid = dashboard_clients_model(['id' => 3, 'role' => 'admin'], ['start' => 'bad', 'end' => 'bad']);
$assert(!$invalid['chart']['days'], 'Invalid ranges fail closed');
echo "Dashboard clients model: 100-client capacity, totals and scope checks OK\n";
