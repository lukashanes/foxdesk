<?php

$root = dirname(__DIR__);

if (!function_exists('get_time_range_bounds')) {
    function get_time_range_bounds($range, $from = '', $to = ''): array
    {
        return [
            'range' => $range === 'custom' ? 'custom' : 'this_month',
            'start' => $from !== '' ? $from : '2026-06-01',
            'end' => $to !== '' ? $to : '2026-06-30',
        ];
    }
}

if (!function_exists('normalize_ticket_tags')) {
    function normalize_ticket_tags($value, $as_array = false)
    {
        $tags = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) $value))));
        return $as_array ? $tags : implode(', ', $tags);
    }
}

require_once $root . '/includes/modules/reports/report-filters.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$controller = file_get_contents($root . '/includes/modules/reports/report-page-controller.php');
$module = file_get_contents($root . '/includes/modules/reports/report-filters.php');
$assert($bootstrap !== false && $controller !== false && $module !== false, 'Report filter files must be readable.');

$state = report_filter_state_from_request([
    'tab' => 'bad-tab',
    'time_range' => 'custom',
    'from_date' => '2026-06-10',
    'to_date' => '2026-06-12',
    'organizations' => ['7', '0'],
    'agents' => ['3'],
    'tags' => 'urgent, paid',
], true);

$assert($state['tab'] === 'time', 'Invalid tabs must fall back to time overview.');
$assert($state['time_range'] === 'custom', 'Time range must come from shared bounds helper.');
$assert($state['range_start'] === '2026-06-10', 'Custom range start must be preserved.');
$assert($state['selected_orgs'] === [7, 0], 'Selected organizations must be normalized to integers.');
$assert($state['selected_agents'] === [3], 'Selected agents must be normalized to integers.');
$assert($state['selected_tags'] === ['urgent', 'paid'], 'Selected tags must be normalized through shared tag helper.');
$assert($state['show_money'] === 0, 'Filtered request without show_money must hide money columns.');

$agent_state = report_filter_state_from_request([], false);
$assert($agent_state['show_money'] === 0, 'Non-admin users must never see money columns by default.');
$agent_billing_state = report_filter_state_from_request(['tab' => 'billing'], false);
$assert($agent_billing_state['tab'] === 'time', 'Non-admin users must be kept in time overview.');

$all_work_state = report_filter_state_from_request([
    'tab' => 'billing',
    'time_range' => 'this_month',
    'agents' => ['9'],
], true);
$assert($all_work_state['tab'] === 'time', 'Billing without one client must show the overall time overview.');
$assert($all_work_state['selected_agents'] === [9], 'Overall time overview must preserve the selected agent.');

$client_review_state = report_filter_state_from_request([
    'tab' => 'billing',
    'organizations' => ['7'],
    'agents' => ['9'],
    'show_money' => '1',
], true);
$assert($client_review_state['tab'] === 'billing', 'One selected client must keep the billing review mode.');
$assert($client_review_state['show_money'] === 1, 'Client billing review must keep financial columns enabled.');

$blank_option_state = report_filter_state_from_request([
    'tab' => 'billing',
    'organizations' => [''],
    'agents' => [''],
], true);
$assert($blank_option_state['selected_orgs'] === [], 'The All clients option must not become the unassigned-client sentinel.');
$assert($blank_option_state['selected_agents'] === [], 'The All agents option must not become an invalid agent filter.');
$assert($blank_option_state['tab'] === 'time', 'The All clients option must open the overall time overview.');

$assert(str_contains($bootstrap, '/reports/report-filters.php'), 'Module bootstrap must load report filters.');
$assert(str_contains($controller, 'report_filter_state_from_request($request, is_admin())'), 'Reports controller must consume report filter state.');
$assert(!str_contains($controller, '$allowed_tabs ='), 'Reports controller must not own tab allow-list logic.');
$assert(!str_contains($controller, '$range_data = get_time_range_bounds'), 'Reports controller must not own range bound logic.');

echo "Report filter contract OK\n";
