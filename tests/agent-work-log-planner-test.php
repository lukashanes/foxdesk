<?php

if (!defined('SECRET_KEY')) {
    define('SECRET_KEY', 'agent-work-log-planner-contract-secret');
}
function foxdesk_normalize_backdated_datetime_input($value)
{
    $timestamp = strtotime(trim((string) $value));
    return $timestamp === false ? false : date('Y-m-d H:i:s', $timestamp);
}
require_once dirname(__DIR__) . '/includes/modules/agent/work-log-planner.php';

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
        $assert($e->getMessage() !== '', 'Validation errors must explain the rejected plan.');
    }
};
$user = ['id' => 12, 'tenant_id' => 7];
$validInput = [
    'structure' => 'one_ticket',
    'allocation_basis' => 'approved_total',
    'total_minutes' => 278,
    'tickets' => [[
        'title' => 'Úpravy produktové stránky',
        'description' => 'Návrh a realizace vizuálních úprav produktové stránky.',
        'entries' => [
            ['worked_on' => '2026-07-21', 'content' => 'Upravená struktura hero sekce a hlavní vizuální hierarchie.', 'duration_minutes' => 83, 'time_precision' => 'allocated'],
            ['worked_on' => '2026-07-22', 'content' => 'Doplněné responzivní varianty produktových karet a kontaktního bloku.', 'duration_minutes' => 107, 'time_precision' => 'allocated'],
            ['worked_on' => '2026-07-23', 'content' => 'Doladěné rozestupy, štítky a finální kontrola na mobilu.', 'duration_minutes' => 88, 'time_precision' => 'allocated'],
        ],
    ]],
];
$plan = foxdesk_agent_normalize_work_log_plan($validInput, $user);
$preview = foxdesk_agent_work_log_preview($plan);
$assert($plan['total_minutes'] === 278, 'Plan must preserve the approved exact total.');
$assert($plan['tickets'][0]['entries'][0]['worked_on'] === '2026-07-21', 'Work date must stay in worked_on.');
$assert($plan['tickets'][0]['entries'][0]['started_at'] === null, 'Allocated entries must not invent a clock time.');
$assert($preview['tickets'][0]['ticket_total_minutes'] === 278, 'Preview must expose the complete ticket total.');
$assert($preview['requires_user_confirmation'] === true, 'Preview must require explicit confirmation.');
$assert(str_ends_with($plan['expires_at'], 'Z'), 'Plan expiry must include an explicit UTC timezone.');
$assert(strtotime($plan['expires_at']) > time(), 'A newly prepared plan must not be considered expired.');
$assert(foxdesk_agent_plan_signature($plan, $user) === foxdesk_agent_plan_signature($plan, $user), 'Plan signature must be stable.');

$withDate = $validInput;
$withDate['tickets'][0]['description'] .= ' Dokončeno 23. 7. 2026.';
$expectInvalid(static fn() => foxdesk_agent_normalize_work_log_plan($withDate, $user), 'Dates in ticket descriptions must be rejected.');
$withDuration = $validInput;
$withDuration['tickets'][0]['entries'][0]['content'] .= ' Práce zabrala 83 minut.';
$expectInvalid(static fn() => foxdesk_agent_normalize_work_log_plan($withDuration, $user), 'Durations in comments must be rejected.');
$rounded = $validInput;
$rounded['total_minutes'] = 180;
foreach ($rounded['tickets'][0]['entries'] as &$entry) {
    $entry['duration_minutes'] = 60;
}
unset($entry);
$expectInvalid(static fn() => foxdesk_agent_normalize_work_log_plan($rounded, $user), 'Repeated rounded allocations must be rejected.');
$overrideMissingReason = $validInput;
$overrideMissingReason['allow_temporal_text'] = true;
$expectInvalid(static fn() => foxdesk_agent_normalize_work_log_plan($overrideMissingReason, $user), 'Override needs a reason.');
$withDate['allow_temporal_text'] = true;
$withDate['temporal_text_reason'] = 'User explicitly requested a date.';
$assert(foxdesk_agent_normalize_work_log_plan($withDate, $user)['allow_temporal_text'] === true, 'Explicit override must work.');

$exact = [
    'structure' => 'one_ticket',
    'allocation_basis' => 'actual',
    'total_minutes' => 47,
    'tickets' => [[
        'title' => 'Finální vizuální opravy',
        'description' => 'Ověření a doladění posledních detailů.',
        'entries' => [[
            'content' => 'Dokončené vizuální opravy a ověření zobrazení.',
            'worked_on' => '2026-07-25',
            'duration_minutes' => 47,
            'time_precision' => 'exact',
            'started_at' => '2026-07-25 09:00:00',
            'ended_at' => '2026-07-25 09:47:00',
        ]],
    ]],
];
$exactPlan = foxdesk_agent_normalize_work_log_plan($exact, $user);
$assert($exactPlan['tickets'][0]['entries'][0]['started_at'] === '2026-07-25 09:00:00', 'Exact times must be preserved.');
$exact['tickets'][0]['entries'][0]['duration_minutes'] = 46;
$exact['total_minutes'] = 46;
$expectInvalid(static fn() => foxdesk_agent_normalize_work_log_plan($exact, $user), 'Exact duration must match timestamps.');

// Restore the valid exact fixture used by the time-input cases below.
$exact['tickets'][0]['entries'][0]['duration_minutes'] = 47;
$exact['total_minutes'] = 47;

// 2026-11-12: manual_* times with derived precision were dropped and stored at 12:00.
$manualDerived = $exact;
$manualDerived['tickets'][0]['entries'][0] = [
    'content' => 'Dokončené vizuální opravy a ověření zobrazení.',
    'duration_minutes' => 47,
    'time_precision' => 'derived',
    'manual_date' => '2026-07-25',
    'manual_start_time' => '13:05',
    'manual_end_time' => '13:52',
];
$manualPlan = foxdesk_agent_normalize_work_log_plan($manualDerived, $user);
$manualEntry = $manualPlan['tickets'][0]['entries'][0];
$assert($manualEntry['started_at'] === '2026-07-25 13:05:00', 'manual_start_time must become started_at, not be dropped.');
$assert($manualEntry['ended_at'] === '2026-07-25 13:52:00', 'manual_end_time must become ended_at, not be dropped.');
$assert($manualEntry['worked_on'] === '2026-07-25', 'manual_date must become worked_on.');
$assert($manualEntry['time_precision'] === 'derived', 'Derived precision must be kept when times are known.');
$assert($manualEntry['has_clock_times'] === true, 'Entries with known times must be flagged.');
$manualPreview = foxdesk_agent_work_log_preview($manualPlan);
$assert($manualPreview['tickets'][0]['entries'][0]['started_at'] === '2026-07-25 13:05:00', 'Preview must show the times that will be saved.');

$derivedTimestamps = $exact;
$derivedTimestamps['tickets'][0]['entries'][0]['time_precision'] = 'derived';
$derivedPlan = foxdesk_agent_normalize_work_log_plan($derivedTimestamps, $user);
$assert($derivedPlan['tickets'][0]['entries'][0]['started_at'] === '2026-07-25 09:00:00', 'Derived entries may carry started_at/ended_at.');
$assert($derivedPlan['tickets'][0]['entries'][0]['ended_at'] === '2026-07-25 09:47:00', 'Derived ended_at must be preserved.');

$overMidnight = $manualDerived;
$overMidnight['tickets'][0]['entries'][0]['manual_start_time'] = '23:40';
$overMidnight['tickets'][0]['entries'][0]['manual_end_time'] = '00:27';
$overMidnightPlan = foxdesk_agent_normalize_work_log_plan($overMidnight, $user);
$assert($overMidnightPlan['tickets'][0]['entries'][0]['ended_at'] === '2026-07-26 00:27:00', 'An end before the start means the next day.');

$manualWrongDuration = $manualDerived;
$manualWrongDuration['tickets'][0]['entries'][0]['manual_end_time'] = '14:00';
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($manualWrongDuration, $user),
    'manual_* times that disagree with duration_minutes must return 422, not be dropped.'
);

$manualHalf = $manualDerived;
unset($manualHalf['tickets'][0]['entries'][0]['manual_end_time']);
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($manualHalf, $user),
    'A start time without an end time must be rejected.'
);

$manualNoDate = $manualDerived;
unset($manualNoDate['tickets'][0]['entries'][0]['manual_date']);
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($manualNoDate, $user),
    'manual times without a day must be rejected.'
);

$manualBadClock = $manualDerived;
$manualBadClock['tickets'][0]['entries'][0]['manual_start_time'] = 'C:\\temp';
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($manualBadClock, $user),
    'manual_start_time must be a clock time.'
);

$manualAndTimestamps = $manualDerived;
$manualAndTimestamps['tickets'][0]['entries'][0]['started_at'] = '2026-07-25 13:05:00';
$manualAndTimestamps['tickets'][0]['entries'][0]['ended_at'] = '2026-07-25 13:52:00';
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($manualAndTimestamps, $user),
    'Two different time inputs on one entry must be rejected.'
);

$estimatedWithTimes = $manualDerived;
$estimatedWithTimes['tickets'][0]['entries'][0]['time_precision'] = 'estimated';
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($estimatedWithTimes, $user),
    'Estimated entries cannot claim clock times.'
);

$defaultPrecisionWithTimes = $manualDerived;
unset($defaultPrecisionWithTimes['tickets'][0]['entries'][0]['time_precision']);
$defaultPrecisionPlan = foxdesk_agent_normalize_work_log_plan($defaultPrecisionWithTimes, $user);
$assert($defaultPrecisionPlan['tickets'][0]['entries'][0]['time_precision'] === 'exact', 'Known times without a precision default to exact.');

$derivedNoTimes = $manualDerived;
$derivedNoTimes['tickets'][0]['entries'][0] = [
    'content' => 'Dokončené vizuální opravy a ověření zobrazení.',
    'worked_on' => '2026-07-25',
    'duration_minutes' => 47,
    'time_precision' => 'derived',
];
$derivedNoTimesPlan = foxdesk_agent_normalize_work_log_plan($derivedNoTimes, $user);
$assert($derivedNoTimesPlan['tickets'][0]['entries'][0]['started_at'] === null, 'Entries without known times must not get a start time.');
$assert($derivedNoTimesPlan['tickets'][0]['entries'][0]['ended_at'] === null, 'Entries without known times must not get an end time.');
$assert($derivedNoTimesPlan['tickets'][0]['entries'][0]['has_clock_times'] === false, 'Entries without known times must be flagged as such.');

$exactWithoutTimes = $derivedNoTimes;
$exactWithoutTimes['tickets'][0]['entries'][0]['time_precision'] = 'exact';
$expectInvalid(
    static fn() => foxdesk_agent_normalize_work_log_plan($exactWithoutTimes, $user),
    'Exact entries still require times.'
);

$videoLength = $validInput;
$videoLength['tickets'][0]['entries'][0]['content'] = 'Hotový první střih 3 min 8 s.';
try {
    foxdesk_agent_normalize_work_log_plan($videoLength, $user);
    $assert(false, 'Video lengths are caught by the duration rule unless explicitly allowed.');
} catch (InvalidArgumentException $e) {
    $assert(str_contains($e->getMessage(), 'allow_temporal_text'), 'The temporal-text error must point to allow_temporal_text.');
    $assert(str_contains($e->getMessage(), '3 min'), 'The temporal-text error must name the matched text.');
}
$videoLength['allow_temporal_text'] = true;
$videoLength['temporal_text_reason'] = 'Video lengths describe the delivered cut, not worked time.';
$assert(foxdesk_agent_normalize_work_log_plan($videoLength, $user)['allow_temporal_text'] === true, 'A justified override must accept video lengths.');

$plannerSource = (string) file_get_contents(dirname(__DIR__) . '/includes/modules/agent/work-log-planner.php');
$assert(!str_contains($plannerSource, "' 12:00:00'"), 'The planner must not invent 12:00 clock times.');
$assert(str_contains($plannerSource, "'has_clock_times' => \$has_clock_times ? 1 : 0"), 'Applied entries must store whether clock times are known.');

echo "Agent work-log planner OK\n";
