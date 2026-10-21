<?php
/**
 * Flight deck dashboard read model: Me/Team scope enforcement, saved-only
 * aggregates, report coverage, feed collapsing and the JSON endpoint wiring.
 */
$root = dirname(__DIR__);
define('BASE_PATH', $root);
require $root . '/includes/modules/work/flight-deck.php';

$queries = [];
function t($key, $params = []) {
    foreach ($params as $name => $value) {
        $key .= ' ' . $name . '=' . $value;
    }
    return $key;
}
function format_date_localized($date, $format) { return date($format, strtotime($date)); }
function format_duration_minutes($minutes) { return $minutes . ' min'; }
function ticket_time_table_exists() { return true; }
function table_exists($table) { return true; }
function column_exists($table, $column) { return true; }
function build_ticket_visibility_filters_for_user($user, $filters = []) { return $filters + ['visible_to' => (int) ($user['id'] ?? 0)]; }
function build_ticket_where_clause($filters, &$params) { $params[] = $filters['visible_to']; return ' WHERE t.visible_to = ?'; }
function db_fetch_all($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    if (str_contains($sql, 'FROM report_templates')) {
        return $GLOBALS['templates'] ?? [];
    }
    return $GLOBALS['entries'] ?? [];
}
function billing_review_entry_rate($entry) { return 600.0; }
function billing_review_entry_billable_minutes($entry, $rounding) { return empty($entry['is_billable']) ? 0 : (int) $entry['duration_minutes']; }
function billing_review_amount_from_rate($minutes, $rate) { return round($minutes * $rate / 60, 2); }
function get_ticket_code($id) { return '#' . $id; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$period = ['period' => 'this_month', 'start' => '2026-10-01 00:00:00', 'end' => '2026-10-22 23:59:59'];

// Agents never aggregate the team, even when the team scope is requested directly.
$queries = [];
work_flight_deck_entries(['id' => 4, 'role' => 'agent'], true, $period['start'], $period['end']);
$assert(str_contains($queries[0][0], 'tte.user_id = ?') && end($queries[0][1]) === 4, 'Agents must be limited to their own saved work.');
$assert(str_contains($queries[0][0], 't.visible_to = ?'), 'Entries must use the shared ticket visibility predicate.');
$assert(str_contains($queries[0][0], 'tte.ended_at IS NOT NULL AND tte.duration_minutes > 0'), 'A running timer must never count as saved work.');

$queries = [];
work_flight_deck_entries(['id' => 1, 'role' => 'admin'], true, $period['start'], $period['end']);
$assert(!str_contains($queries[0][0], 'tte.user_id = ?'), 'Administrators may aggregate the whole team.');
$assert(work_flight_deck_entries(['id' => 0, 'role' => 'admin'], true, $period['start'], $period['end']) === [], 'Anonymous viewers get no data.');

// Billable money: actual saved minutes times the billing review rate; reports split reported/unreported.
$entries = [
    ['id' => 1, 'ticket_id' => 10, 'user_id' => 1, 'started_at' => '2026-10-02 09:00:00', 'duration_minutes' => 60, 'is_billable' => 1, 'organization_id' => 7, 'organization_name' => 'Atlas', 'status_id' => 3, 'ticket_tags' => '', 'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test', 'avatar' => ''],
    ['id' => 2, 'ticket_id' => 11, 'user_id' => 1, 'started_at' => '2026-10-20 09:00:00', 'duration_minutes' => 30, 'is_billable' => 1, 'organization_id' => 8, 'organization_name' => 'Beta', 'status_id' => 3, 'ticket_tags' => '', 'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test', 'avatar' => ''],
    ['id' => 3, 'ticket_id' => 11, 'user_id' => 1, 'started_at' => '2026-10-20 10:00:00', 'duration_minutes' => 15, 'is_billable' => 0, 'organization_id' => 8, 'organization_name' => 'Beta', 'status_id' => 3, 'ticket_tags' => '', 'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test', 'avatar' => ''],
];
$GLOBALS['entries'] = $entries;
$GLOBALS['templates'] = [['id' => 1, 'organization_id' => 7, 'date_from' => '2026-10-01', 'date_to' => '2026-10-10', 'agent_ids' => '', 'tags' => '']];
$coverage = work_flight_deck_report_coverage($entries, $period['start'], $period['end']);
$assert($coverage === [1 => true, 2 => false, 3 => false], 'Only entries inside a published report for the same client are reported.');
$money = work_flight_deck_money(['id' => 1, 'role' => 'admin'], $period, true);
$assert($money['minutes'] === 105, 'Saved minutes must include non-billable work.');
$assert(abs($money['amount'] - 900.0) < 0.001, 'Money uses actual billable minutes and the billing review rate.');
$assert(abs($money['reported_amount'] - 600.0) < 0.001 && abs($money['unreported_amount'] - 300.0) < 0.001, 'Reported and unreported amounts must add up.');
$assert($money['client_count'] === 2 && $money['clients'][0]['name'] === 'Atlas', 'Clients are ranked by amount.');

// Feed collapsing: bursts of the same action on one ticket become one row with a count.
$now = time();
$items = [
    ['timestamp' => $now, 'type' => 'reply', 'minutes' => 0, 'count' => 1, 'actor_id' => 2, 'code' => '#5', 'verb' => 'r'],
    ['timestamp' => $now - 600, 'type' => 'reply', 'minutes' => 0, 'count' => 1, 'actor_id' => 2, 'code' => '#5', 'verb' => 'r'],
    ['timestamp' => $now - 900, 'type' => 'status', 'minutes' => 0, 'count' => 1, 'actor_id' => 2, 'code' => '#5', 'verb' => 's'],
    ['timestamp' => $now - 1200, 'type' => 'time', 'minutes' => 20, 'count' => 1, 'actor_id' => 2, 'code' => '#6', 'verb' => 't'],
    ['timestamp' => $now - 1300, 'type' => 'time', 'minutes' => 25, 'count' => 1, 'actor_id' => 2, 'code' => '#6', 'verb' => 't'],
];
$collapsed = work_flight_deck_feed_collapse($items);
$assert(count($collapsed) === 2, 'Replies collapse and nearby status noise is dropped.');
$assert($collapsed[0]['count'] === 2 && $collapsed[1]['minutes'] === 45, 'Collapsed rows keep counts and summed minutes.');
$assert(work_flight_deck_initials('Atlas Freight Lines') === 'AF' && work_flight_deck_initials('Zeta') === 'ZE', 'Client marks use two letters.');

// The read model is SaaS-free and exposed through the session-only JSON endpoint.
$source = (string) file_get_contents($root . '/includes/modules/work/flight-deck.php');
$assert(!str_contains($source, 'tenant'), 'The self-hosted read model must not reference SaaS tenant helpers.');
$router = (string) file_get_contents($root . '/includes/api/router.php');
$handler = (string) file_get_contents($root . '/includes/api/work-handler.php');
$assert(str_contains($router, "'work-time-dashboard' => 'api_work_time_dashboard'"), 'The dashboard read endpoint must be routed.');
$assert(str_contains($handler, "time_activity_period_from_request(\$_GET + ['period' => 'this_month'], false)"), 'Reading the dashboard must not change the remembered period.');
$assert(str_contains($handler, "in_array(\$user['role'] ?? '', ['admin', 'agent'], true)"), 'Only staff may read the dashboard model.');
$auth = (string) file_get_contents($root . '/includes/auth.php');
$assert(!str_contains($auth, "'work-time-dashboard' =>"), 'API tokens must not gain the dashboard endpoint implicitly.');

echo "Flight deck read model: scope, saved totals, report coverage, feed and endpoint checks passed\n";
