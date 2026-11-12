<?php

/**
 * PHP clock helpers used for SQL bounds instead of MySQL NOW()/CURDATE().
 * Pure PHP, no database. Pins the application clock with
 * $GLOBALS['foxdesk_clock_override'] and runs in Europe/Prague.
 */

date_default_timezone_set('Europe/Prague');
require dirname(__DIR__) . '/includes/database.php';

$failures = [];
$same = static function ($actual, $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')';
    }
};
$pin = static function (string $local): void {
    $GLOBALS['foxdesk_clock_override'] = strtotime($local);
};

// Plain local wall-clock values, not UTC.
$pin('2026-11-12 00:30:00');
$same(foxdesk_db_datetime(), '2026-11-12 00:30:00', 'foxdesk_db_datetime() is local time');
$same(foxdesk_db_date(), '2026-11-12', 'Just after local midnight the date is already the new day');
$same(foxdesk_db_date(-1), '2026-11-11', 'foxdesk_db_date(-1) is yesterday');
$same(foxdesk_db_datetime_shift(-24, 'hour'), '2026-11-11 00:30:00', '24 hours back');
$same(foxdesk_db_datetime_shift(-20, 'minute'), '2026-11-12 00:10:00', '20 minutes back');
$same(foxdesk_db_datetime_shift(3, 'day'), '2026-11-15 00:30:00', '3 days ahead');
$same(foxdesk_db_datetime_shift(0, 'day'), '2026-11-12 00:30:00', 'Zero shift is now');

// Calendar days keep the wall-clock time across DST changes (like DATE_ADD on DATETIME).
$pin('2026-03-30 10:00:00'); // day after the spring change
$same(foxdesk_db_datetime_shift(-1, 'day'), '2026-03-29 10:00:00', 'One day back across spring DST keeps 10:00');
$pin('2026-10-26 10:00:00'); // day after the autumn change
$same(foxdesk_db_datetime_shift(-1, 'day'), '2026-10-25 10:00:00', 'One day back across autumn DST keeps 10:00');
$same(foxdesk_db_datetime_shift(-90, 'day'), '2026-07-28 10:00:00', '90 days back across DST keeps 10:00');

// Week bounds: Monday..Sunday, like MySQL WEEKDAY().
$pin('2026-11-15 23:59:00'); // Sunday
$same(foxdesk_db_week_bounds(), ['2026-11-09', '2026-11-15'], 'Sunday belongs to the week that started on Monday');
$pin('2026-11-16 00:00:01'); // Monday
$same(foxdesk_db_week_bounds(), ['2026-11-16', '2026-11-22'], 'Monday starts a new week');
$pin('2026-03-29 12:00:00'); // Sunday of the spring DST change
$same(foxdesk_db_week_bounds(), ['2026-03-23', '2026-03-29'], 'Week bounds survive the DST change');

try {
    foxdesk_db_datetime_shift(1, 'year; DROP TABLE x');
    $failures[] = 'Unknown units must be rejected';
} catch (InvalidArgumentException $e) {
    // expected
}

unset($GLOBALS['foxdesk_clock_override']);
$same(abs(strtotime(foxdesk_db_datetime()) - time()) <= 2, true, 'Without an override the helpers use the real clock');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "DB clock helpers OK\n";
