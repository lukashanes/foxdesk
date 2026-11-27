<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$company = 'Živé systémy, a.s.';
$title = 'Kontrola "pobočky"';

if (isset($argv[1])) {
    function t(string $text): string
    {
        return $text;
    }

    function format_duration_minutes(int $minutes): string
    {
        return $minutes . ' min';
    }

    require $root . '/includes/modules/reports/report-filters.php';
    require $root . '/includes/modules/reports/report-totals.php';
    require $root . '/includes/modules/reports/report-export.php';

    $entry = [
        'id' => 5,
        'ticket_id' => 42,
        'ticket_title' => $title,
        'organization_name' => $company,
        'ticket_tags' => 'local',
        'duration_minutes' => 30,
        'is_billable' => 1,
        'billable_rate' => 1200,
        'cost_amount' => 100,
        'profit' => 500,
        'started_at' => '2026-06-01 10:00:00',
        'ended_at' => '2026-06-01 10:30:00',
        'first_name' => 'Eva',
        'last_name' => 'Nováková',
        'source' => 'manual',
        'summary' => 'Kontrola nastavení',
        'comment_id' => 17,
        'comment_content' => '<p>Soukromý komentář</p>',
        'comment_is_internal' => 1,
    ];
    $by_org = [['name' => $company, 'minutes' => 45, 'billable_minutes' => 30, 'billable_amount' => 600]];
    [$request, $tab] = match ($argv[1]) {
        'no-export' => [[], 'time'],
        'published' => [['export' => 'csv'], 'published'],
        'summary' => [['export' => 'csv'], 'summary'],
        'detailed' => [['export' => 'csv'], 'detailed'],
    };
    report_export_csv_if_requested($request, $tab, [$entry], $by_org, true, false, $tab === 'detailed');
    return;
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $case) use ($check): string {
    // A CSV response ends the request, so capture it from a separate PHP process.
    $command = [PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, $case];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    $check(is_resource($process), 'Could not start the CSV fixture: ' . $case);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $check(proc_close($process) === 0 && is_string($output), 'CSV fixture failed: ' . $case);
    return $output;
};
$rows = static function (string $output) use ($check): array {
    $check(str_starts_with($output, "\xEF\xBB\xBF"), 'CSV is missing the UTF-8 BOM.');
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, substr($output, 3));
    rewind($stream);
    $result = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $result[] = $row;
    }
    fclose($stream);
    return $result;
};

foreach (['no-export', 'published'] as $case) {
    $check($run($case) === '', 'No CSV should be emitted for: ' . $case);
    echo "OK CSV request gate: $case\n";
}

$summary = $rows($run('summary'));
$check($summary === [
    ['Company', 'Time', 'Time (min)', 'Billable time', 'Billable (min)', 'Amount'],
    [$company, '45 min', '45', '30 min', '30', '600.00'],
], 'Summary CSV must preserve Unicode, commas, column order and totals.');
echo "OK summary CSV preserves Unicode and serializes company totals\n";

$detailed = $rows($run('detailed'));
$check($detailed === [
    ['Row type', 'Ticket', 'Company', 'Tags', 'Duration', 'Duration (min)', 'Billable', 'Billable (min)', 'Agent', 'Source', 'Start time', 'End time', 'Comment'],
    ['ticket_summary', $title, $company, 'local', '30 min', '30', '', '30', '', '', '', '', ''],
    ['comment_detail', $title, $company, 'local', '30 min', '30', 'Yes', '30', 'Eva Nováková', 'manual', '2026-06-01 10:00:00', '2026-06-01 10:30:00', 'Kontrola nastavení'],
], 'Detailed CSV must preserve work details and omit internal comments and disabled financial columns.');
echo "OK detailed CSV preserves exact times and omits internal comments and hidden financial columns\n";
