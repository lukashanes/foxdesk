<?php

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }
    return $contents;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$bootstrap = $read('includes/modules/bootstrap.php');
$module = $read('includes/modules/reports/time-overview.php');
$reports = $read('pages/admin/reports.php');
$theme = $read('theme.css');
$cs = $read('includes/lang/cs.php');

$assert(str_contains($bootstrap, '/reports/time-overview.php'), 'Module bootstrap must load the time overview read model.');
$assert(str_contains($module, 'function report_time_overview_work_log_rows'), 'Time overview work log row model is missing.');
$assert(str_contains($module, "'summary' => \$summary !== '' ? \$summary : t('No note')"), 'Work log rows must expose the time entry summary.');
$assert(str_contains($reports, 'report_time_overview_work_log_rows($entries, 120)'), 'Time overview must build concrete work log rows.');
$assert(str_contains($reports, 'data-report-time-overview-log'), 'Time overview must render a concrete work log surface.');
$assert(str_contains($reports, 'class="data-table report-worklog-table"'), 'Time overview work log must use the styled data table surface.');
$assert(str_contains($reports, "url('ticket', ['id' => \$row['ticket_id']])"), 'Work log rows must link back to the ticket.');
$assert(str_contains($theme, '.report-summary-strip'), 'Report summary strip styling is missing.');
$assert(str_contains($theme, '.report-worklog-card'), 'Work log card styling is missing.');
$assert(str_contains($theme, '.report-worklog-table__ticket'), 'Work log ticket link styling is missing.');
$assert(str_contains($cs, "'What was done' => 'Co se dělalo'"), 'Czech work log heading translation is missing.');

echo "Report time overview contract OK\n";
