<?php

$root = dirname(__DIR__);

function assert_report_rates(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_report_rate_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_report_rates($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

if (!function_exists('table_exists')) {
    function table_exists($table): bool { return false; }
}
if (!function_exists('column_exists')) {
    function column_exists($table, $column): bool { return false; }
}
if (!function_exists('db_fetch_one')) {
    function db_fetch_one($sql, $params = []) { return null; }
}
if (!function_exists('db_fetch_all')) {
    function db_fetch_all($sql, $params = []) { return []; }
}
if (!function_exists('db_update')) {
    function db_update($table, $data, $where, $params = []) { return true; }
}

require_once $root . '/includes/ticket-time-functions.php';

$stored = get_time_entry_effective_billable_rate([
    'billable_rate' => 1200,
    'ticket_custom_billable_rate' => 1100,
    'organization_id' => 10,
    'user_id' => 20,
    'user_billable_rate' => 950,
    'org_billable_rate' => 800,
]);
assert_report_rates(abs($stored - 1200) < 0.001, 'Stored item rate must win over all fallbacks.');

$ticket_override = get_time_entry_effective_billable_rate([
    'billable_rate' => 0,
    'ticket_custom_billable_rate' => 1100,
    'organization_id' => 10,
    'user_id' => 20,
    'user_billable_rate' => 950,
    'org_billable_rate' => 800,
]);
assert_report_rates(abs($ticket_override - 1100) < 0.001, 'Ticket override rate must win over agent defaults and client defaults.');

$agent_default = get_time_entry_effective_billable_rate([
    'billable_rate' => 0,
    'ticket_custom_billable_rate' => null,
    'organization_id' => 10,
    'user_id' => 20,
    'user_billable_rate' => 950,
    'org_billable_rate' => 800,
]);
assert_report_rates(abs($agent_default - 950) < 0.001, 'Agent default billable rate must win over client default rate.');

$client_default = get_time_entry_effective_billable_rate([
    'billable_rate' => 0,
    'ticket_custom_billable_rate' => null,
    'organization_id' => 10,
    'user_id' => 20,
    'user_billable_rate' => 0,
    'org_billable_rate' => 800,
]);
assert_report_rates(abs($client_default - 800) < 0.001, 'Client default rate must remain the final billable fallback.');

$time = read_report_rate_file($root, 'includes/ticket-time-functions.php');
foreach ([
    'function ensure_user_billable_rate_column',
    'function get_user_default_billable_rate',
    'function get_agent_default_billable_rates',
    'function save_agent_default_billable_rate',
    "ALTER TABLE users ADD COLUMN billable_rate",
    "get_agent_client_billable_rate(\$organization_id, \$user_id)",
    "get_user_default_billable_rate(\$user_id)",
    "\$entry['user_billable_rate']",
] as $needle) {
    assert_report_rates(str_contains($time, $needle), 'Rate helper contract missing: ' . $needle);
}

$billing = read_report_rate_file($root, 'includes/modules/reports/billing-review.php');
foreach ([
    'u.billable_rate AS user_billable_rate',
    'billing_review_adjustment_actions',
    'billing_review_bulk_adjustment_actions',
    'billing_review_adjusted_rate',
    'billing_review_total_labels',
] as $needle) {
    assert_report_rates(str_contains($billing, $needle), 'Billing review contract missing: ' . $needle);
}

$report_functions = read_report_rate_file($root, 'includes/report-functions.php');
assert_report_rates(str_contains($report_functions, 'u.billable_rate as user_billable_rate'), 'Published report snapshots must include agent default billable rates.');

$reports_page = read_report_rate_file($root, 'pages/admin/reports.php');
$billing_js = read_report_rate_file($root, 'assets/js/report-billing-review.js');
foreach ([
    'save_agent_default_rate',
    'Agent default rates',
    'Default billable rate',
    'save_agent_client_rate',
    'adjust_billable_entry',
    'bulk_update_billable_entries',
    'data-report-total="billable_amount"',
    'data-report-entry-field="rate"',
] as $needle) {
    assert_report_rates(str_contains($reports_page, $needle), 'Reports page contract missing: ' . $needle);
}
foreach ([
    "selectedAction === 'discount_amount'",
    "selectedAction === 'target_total'",
] as $needle) {
    assert_report_rates(str_contains($billing_js, $needle), 'Billing review JS contract missing: ' . $needle);
}
assert_report_rates(str_contains($reports_page, 'report_filter_state_from_request($_GET, is_admin())'), 'Reports page must use shared report filter state.');
assert_report_rates(!str_contains($reports_page, '$normalize_tag_filters = static function'), 'Reports page must not define a local tag parser.');
assert_report_rates(!preg_match('/stripe|subscription_status|checkout/i', $reports_page), 'Customer report billing review must not mix SaaS Stripe billing state.');

$ticket_crud = read_report_rate_file($root, 'includes/ticket-crud-functions.php');
assert_report_rates(str_contains($ticket_crud, 'function normalize_ticket_tags($value, $as_array = false)'), 'Tag normalization helper must support array output.');
assert_report_rates(str_contains($ticket_crud, 'return $as_array ? [] :'), 'Empty tag normalization must preserve return type.');

$schema = read_report_rate_file($root, 'includes/schema.sql');
$upgrade = read_report_rate_file($root, 'upgrade.php');
assert_report_rates(str_contains($schema, 'billable_rate DECIMAL(10,2) DEFAULT 0'), 'Install schema must include user default billable rate.');
assert_report_rates(str_contains($upgrade, "['users', 'billable_rate'"), 'Upgrade path must add user default billable rate.');

echo "Report rate parity contract OK\n";
