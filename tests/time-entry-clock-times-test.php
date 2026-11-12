<?php

/**
 * Time entries without known clock times must never show or return invented
 * hours, and corrections must store exactly the times that were sent.
 */

date_default_timezone_set('Europe/Prague');

function table_exists($table) { return true; }
function column_exists($table, $column) { return true; }
function t($key) { return $key; }
function format_date($value, $format = 'd.m.Y H:i') { return date($format, strtotime((string) $value)); }
function foxdesk_normalize_backdated_datetime_input($value)
{
    $raw = trim((string) $value);
    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $dt = DateTime::createFromFormat($format, $raw);
        if ($dt && $dt->format($format) === $raw) {
            return $dt->getTimestamp() > time() + 60 ? false : $dt->format('Y-m-d H:i:s');
        }
    }
    return false;
}

require_once dirname(__DIR__) . '/includes/ticket-time-functions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};
$expectInvalid = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (InvalidArgumentException $e) {
        $assert($e->getMessage() !== '', 'Rejections must explain why.');
    }
};

// Entry written by the old planner: derived precision with a 12:00 anchor.
$anchored = [
    'id' => 501,
    'started_at' => '2026-11-03 10:30:00',
    'ended_at' => '2026-11-03 12:00:00',
    'worked_on' => '2026-11-03',
    'time_precision' => 'derived',
    'has_clock_times' => 0,
    'duration_minutes' => 90,
    'is_billable' => 1,
];
$assert(!ticket_time_entry_has_clock_times($anchored), 'An anchored entry has no clock times.');
$assert(ticket_time_entry_public_timestamps($anchored) === ['started_at' => null, 'ended_at' => null], 'The 12:00 anchor must never be returned.');
$assert(!str_contains(ticket_time_entry_display_range($anchored), '12:00'), 'The 12:00 anchor must never be displayed.');
$assert(ticket_time_entry_has_clock_times(['time_precision' => 'exact']), 'Without the flag, exact entries keep real times.');
$assert(!ticket_time_entry_has_clock_times(['time_precision' => 'derived']), 'Without the flag, derived entries are treated as anchored (legacy rows).');

$withTimes = ticket_time_entry_resolve_update($anchored, [
    'started_at' => '2026-11-03 08:15',
    'ended_at' => '2026-11-03 09:45',
]);
$assert($withTimes['started_at'] === '2026-11-03 08:15:00', 'A correction must store the exact start that was sent.');
$assert($withTimes['ended_at'] === '2026-11-03 09:45:00', 'A correction must store the exact end that was sent.');
$assert($withTimes['duration_minutes'] === 90, 'Duration follows the sent times.');
$assert($withTimes['has_clock_times'] === 1, 'The corrected entry has clock times.');
$assert($withTimes['time_precision'] === 'derived', 'Derived precision may carry times.');
$assert(ticket_time_entry_public_timestamps(array_merge($anchored, $withTimes))['started_at'] === '2026-11-03 08:15:00', 'Corrected times are returned.');

$manual = ticket_time_entry_resolve_update($anchored, [
    'manual_date' => '2026-11-04',
    'manual_start_time' => '13:00',
    'manual_end_time' => '14:10',
]);
$assert($manual['started_at'] === '2026-11-04 13:00:00' && $manual['ended_at'] === '2026-11-04 14:10:00', 'manual_* corrections become exact timestamps.');
$assert($manual['worked_on'] === '2026-11-04' && $manual['duration_minutes'] === 70, 'manual_* corrections move the day and duration.');

$durationOnly = ticket_time_entry_resolve_update($anchored, ['duration_minutes' => 45]);
$assert($durationOnly['has_clock_times'] === 0, 'Changing only the duration of an anchored entry must not invent times.');
$assert($durationOnly['duration_minutes'] === 45 && $durationOnly['worked_on'] === '2026-11-03', 'Duration-only change keeps the day.');

$exactEntry = [
    'started_at' => '2026-11-05 09:00:00',
    'ended_at' => '2026-11-05 10:00:00',
    'worked_on' => '2026-11-05',
    'time_precision' => 'exact',
    'has_clock_times' => 1,
    'duration_minutes' => 60,
];
$longer = ticket_time_entry_resolve_update($exactEntry, ['duration_minutes' => 75]);
$assert($longer['started_at'] === '2026-11-05 09:00:00' && $longer['ended_at'] === '2026-11-05 10:15:00', 'A new duration keeps the known start and moves the end.');
$newEnd = ticket_time_entry_resolve_update($exactEntry, ['ended_at' => '2026-11-05 10:30']);
$assert($newEnd['started_at'] === '2026-11-05 09:30:00', 'Only a new end keeps the duration and moves the start.');
$cleared = ticket_time_entry_resolve_update($exactEntry, ['started_at' => null, 'ended_at' => null]);
$assert($cleared['has_clock_times'] === 0 && $cleared['time_precision'] === 'duration_only', 'Removing times leaves a duration-only entry.');

$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'started_at' => '2026-11-03 08:15', 'ended_at' => '2026-11-03 09:45', 'duration_minutes' => 60,
]), 'Mismatched duration must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'started_at' => '2026-11-03 08:15', 'ended_at' => '2026-11-03 09:45', 'time_precision' => 'estimated',
]), 'Estimated entries cannot carry times.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, ['time_precision' => 'exact']), 'Exact precision without times must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'started_at' => '2026-11-03 10:00', 'ended_at' => '2026-11-03 09:00',
]), 'End before start must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, ['duration_minutes' => 0]), 'Zero duration must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'started_at' => date('Y-m-d H:i', time() + 7200), 'ended_at' => date('Y-m-d H:i', time() + 10800),
]), 'Future times must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'manual_date' => '2026-11-04', 'manual_start_time' => '13:00',
]), 'A start time without an end time must be rejected.');
$expectInvalid(static fn() => ticket_time_entry_resolve_update($anchored, [
    'worked_on' => '2026-11-06', 'started_at' => '2026-11-03 08:15', 'ended_at' => '2026-11-03 09:45',
]), 'worked_on must match the start day.');

$anchor = ticket_time_date_anchor('2026-11-03', 90);
$assert($anchor['ended_at'] === '2026-11-03 12:00:00', 'The internal anchor stays on the work day.');
$today = ticket_time_date_anchor(date('Y-m-d'), 30);
$assert(strtotime($today['ended_at']) <= time(), "Today's anchor never lies in the future.");

echo "Time entry clock times OK\n";
