<?php

final class AppLogTimeValidationException extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function api_error($message, $status = 400): void
{
    throw new AppLogTimeValidationException((string) $message, (int) $status);
}

function foxdesk_normalize_backdated_datetime_input($value)
{
    $timestamp = strtotime(trim((string) $value));
    return $timestamp === false ? false : date('Y-m-d H:i:s', $timestamp);
}

require_once dirname(__DIR__) . '/includes/ticket-time-functions.php';
require_once dirname(__DIR__) . '/includes/api/app-handler.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$default = api_app_resolve_log_time_input([
    'duration_minutes' => 17,
    'worked_on' => '2026-07-24',
    'time_precision' => 'duration_only',
]);
$default_start = strtotime($default['started_at']);
$default_end = strtotime($default['ended_at']);
$assert(($default_end - $default_start) === 1020, 'Duration-only logging must preserve the structured duration.');
$assert($default['worked_on'] === '2026-07-24', 'Duration-only logging must preserve worked_on.');
$assert($default['time_precision'] === 'duration_only', 'Duration-only logging must preserve its precision.');
$assert($default['ended_at'] === '2026-07-24 12:00:00', 'Compatibility timestamps must remain deterministic and internal.');
$assert($default['has_clock_times'] === 0, 'The internal date anchor must be flagged as not a real time.');

$explicit = api_app_resolve_log_time_input([
    'duration_minutes' => 48,
    'started_at' => '2026-05-25 21:18:00',
    'ended_at' => '2026-05-25 22:06:00',
]);
$assert($explicit['started_at'] === '2026-05-25 21:18:00', 'Explicit start time must be preserved.');
$assert($explicit['ended_at'] === '2026-05-25 22:06:00', 'Explicit end time must be preserved.');
$assert($explicit['time_precision'] === 'exact', 'Explicit timestamp ranges must be marked exact.');
$assert($explicit['has_clock_times'] === 1, 'Explicit timestamp ranges carry clock times.');

$derived = api_app_resolve_log_time_input([
    'duration_minutes' => 48,
    'started_at' => '2026-05-25 21:18:00',
    'ended_at' => '2026-05-25 22:06:00',
    'time_precision' => 'derived',
]);
$assert($derived['time_precision'] === 'derived' && $derived['started_at'] === '2026-05-25 21:18:00', 'Derived precision may carry known times.');

foreach ([
    ['duration_minutes' => 1441],
    ['duration_minutes' => 20, 'started_at' => '2026-05-25 22:06:00', 'ended_at' => '2026-05-25 21:18:00'],
    ['duration_minutes' => 30, 'started_at' => '2026-05-25 21:18:00', 'ended_at' => '2026-05-25 22:06:00'],
] as $invalid) {
    try {
        api_app_resolve_log_time_input($invalid);
        $assert(false, 'Invalid time input must be rejected.');
    } catch (AppLogTimeValidationException $e) {
        $assert($e->status === 422, 'Invalid time input must return HTTP 422 semantics.');
    }
}

echo "App log-time validation OK\n";
