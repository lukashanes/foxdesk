<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

if (!function_exists('t')) {
    function t($value, $params = []) {
        foreach ($params as $key => $replacement) {
            $value = str_replace('{' . $key . '}', (string) $replacement, $value);
        }
        return $value;
    }
}
if (!function_exists('round_minutes_nearest')) {
    function round_minutes_nearest($minutes, $rounding) {
        return (int) ceil($minutes / max(1, $rounding)) * max(1, $rounding);
    }
}
if (!function_exists('format_duration_minutes')) {
    function format_duration_minutes($minutes) {
        return (int) $minutes . ' min';
    }
}
if (!function_exists('get_ticket_code')) {
    function get_ticket_code($ticket_id) {
        return 'TK-' . (int) $ticket_id;
    }
}
if (!function_exists('format_time_range')) {
    function format_time_range($entry) {
        return trim(($entry['started_at'] ?? '') . ' - ' . ($entry['ended_at'] ?? ''));
    }
}

require_once $root . '/includes/modules/reports/report-totals.php';

$entries = [
    [
        'id' => 1,
        'ticket_id' => 42,
        'ticket_title' => 'VPN access',
        'organization_name' => 'Aenze',
        'duration_minutes' => 31,
        'is_billable' => 1,
        'billable_rate' => 1200,
        'started_at' => '2026-06-01 10:00:00',
        'ended_at' => '2026-06-01 10:31:00',
        'entry_date' => '2026-06-01',
        'first_name' => 'Eva',
        'last_name' => 'Novak',
        'comment_id' => 100,
        'comment_content' => '<p>Checked MFA logs.</p>',
        'comment_is_internal' => 0,
    ],
    [
        'id' => 2,
        'ticket_id' => 42,
        'ticket_title' => 'VPN access',
        'organization_name' => 'Aenze',
        'duration_minutes' => 15,
        'is_billable' => 1,
        'billable_rate' => 1200,
        'started_at' => '2026-06-01 11:00:00',
        'ended_at' => '2026-06-01 11:15:00',
        'entry_date' => '2026-06-01',
        'first_name' => 'Eva',
        'last_name' => 'Novak',
        'comment_id' => 101,
        'comment_content' => '<p>Internal diagnosis.</p>',
        'comment_is_internal' => 1,
    ],
    [
        'id' => 3,
        'ticket_id' => 77,
        'ticket_title' => 'Router setup',
        'organization_name' => 'Aenze',
        'duration_minutes' => 20,
        'is_billable' => 0,
        'billable_rate' => 1200,
        'started_at' => '2026-06-02 09:00:00',
        'ended_at' => '2026-06-02 09:20:00',
        'entry_date' => '2026-06-02',
        'first_name' => 'Lukas',
        'last_name' => 'Hanes',
        'summary' => 'Cable cleanup',
        'comment_id' => null,
        'comment_content' => '',
        'comment_is_internal' => 0,
    ],
];

$public_model = report_ticket_detail_model($entries, ['rounding_minutes' => 1], true);
$assert($public_model['ticket_count'] === 2, 'Model must group entries by ticket.');
$assert($public_model['tickets'][0]['entries_count'] === 2, 'Ticket summary must keep entry count.');
$assert($public_model['tickets'][0]['minutes'] === 46, 'Ticket total minutes must equal detailed entry minutes.');
$assert($public_model['tickets'][0]['entries'][0]['comment_text'] === 'Checked MFA logs.', 'Public model must expose public comment text.');
$assert($public_model['tickets'][0]['entries'][1]['comment_text'] === '', 'Public model must hide internal comment text.');
$assert($public_model['tickets'][1]['entries'][0]['summary'] === 'Cable cleanup', 'Entry without comment must fall back to time summary.');

$admin_model = report_ticket_detail_model($entries, ['rounding_minutes' => 1], false);
$assert($admin_model['tickets'][0]['entries'][1]['comment_text'] === 'Internal diagnosis.', 'Admin model may expose internal comment text.');

$query = file_get_contents($root . '/includes/modules/reports/report-query.php');
$public_query = file_get_contents($root . '/includes/report-functions.php');
$public_page = file_get_contents($root . '/pages/report-public.php');
$export = file_get_contents($root . '/includes/modules/reports/report-export.php');
$theme = file_get_contents($root . '/theme.css');

$assert(str_contains($query, 'c.content as comment_content'), 'Admin report query must select comment content.');
$assert(str_contains($public_query, 'c.content as comment_content'), 'Public report query must select comment content.');
$assert(str_contains($public_page, 'data-report-ticket-row'), 'Public report must render ticket summary rows.');
$assert(str_contains($public_page, 'data-report-ticket-details'), 'Public report must render expandable ticket details.');
$assert(str_contains($public_page, 'data-report-comment-row'), 'Public report must render comment detail rows.');
$assert(str_contains($export, "'ticket_summary'"), 'CSV export must include ticket_summary rows.');
$assert(str_contains($export, "'comment_detail'"), 'CSV export must include comment_detail rows.');
$assert(str_contains($theme, '.report-ticket-summary'), 'Ticket summary styling is missing.');
$assert(str_contains($theme, '.report-ticket-details') && str_contains($theme, 'display: block !important;'), 'Print CSS must expand report ticket details.');

echo "Report ticket detail model contract OK\n";
