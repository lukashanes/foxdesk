<?php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/includes/functions.php';
date_default_timezone_set('Europe/Prague');
$failures = [];
function verify_range($ok, $message) { if (!$ok) $GLOBALS['failures'][] = $message; }
foreach ([['2026-09-01', '2026-09-28'], ['2026-09-28', '2026-09-01']] as [$from, $to]) {
    $range = get_time_range_bounds('custom', $from, $to);
    verify_range($range === ['range' => 'custom', 'start' => '2026-09-01 00:00:00', 'end' => '2026-09-28 23:59:59'], 'Custom range must include both complete boundary days in either order.');
}
foreach (['2026-02-30', '2026-13-01', "2026-09-01\0", '', 'invalid', ['2026-09-01']] as $invalid) {
    try {
        $range = get_time_range_bounds('custom', $invalid, '2026-09-28');
        verify_range($range === ['range' => 'all', 'start' => null, 'end' => null], 'Invalid date must use the shared invalid-range contract.');
    } catch (Throwable $e) { $failures[] = 'Invalid date must not throw: ' . get_class($e); }
}
$leap = get_time_range_bounds('custom', '2024-02-29', '2024-02-29');
verify_range($leap['start'] === '2024-02-29 00:00:00' && $leap['end'] === '2024-02-29 23:59:59', 'Valid leap day must remain accepted.');
$dst = get_time_range_bounds('custom', '2026-03-29', '2026-03-29');
verify_range($dst['start'] === '2026-03-29 00:00:00' && $dst['end'] === '2026-03-29 23:59:59', 'DST day must include the entire calendar day.');
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Real time-range validation and boundary tests passed\n";
