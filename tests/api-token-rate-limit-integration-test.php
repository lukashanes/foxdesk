<?php

/**
 * API token rate limit against a real MariaDB whose session clock is UTC while
 * PHP runs in Europe/Prague (the production setup).
 *
 * Run (needs a disposable MariaDB; nothing else is touched):
 *   FOXDESK_RATE_LIMIT_INTEGRATION=1 DB_HOST=... DB_USER=... DB_PASS=... DB_NAME=... \
 *     php tests/api-token-rate-limit-integration-test.php
 */

if (getenv('FOXDESK_RATE_LIMIT_INTEGRATION') !== '1') {
    echo "API token rate limit integration skipped (set FOXDESK_RATE_LIMIT_INTEGRATION=1)\n";
    exit(0);
}

require __DIR__ . '/api-token-rate-limit-fixture.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

rate_limit_fixture_prepare_schema();
$db = get_db();
$db->exec('DELETE FROM api_token_audit_logs');

$offset_seconds = (int) (new DateTime())->getOffset();
$assert($offset_seconds !== 0, 'PHP must run in a non-UTC zone for this test.');
$assert(abs((strtotime(date('Y-m-d H:i:s')) - strtotime((string) $db->query('SELECT NOW()')->fetchColumn())) - $offset_seconds) <= 5,
    'Precondition: PHP date() and MySQL NOW() must differ by the zone offset (UTC DB, Prague PHP).');

$token_id = 42;
$GLOBALS['is_api_token_auth'] = true;
$GLOBALS['api_token_row'] = ['id' => $token_id, 'tenant_id' => null, 'user_id' => 1];
$GLOBALS['api_current_action'] = 'agent-me';

$call = static function (int $at) use ($token_id): ?RateLimitFixtureApiError {
    $GLOBALS['foxdesk_clock_override'] = $at;
    try {
        api_token_rate_limit_check('agent-me');
    } catch (RateLimitFixtureApiError $e) {
        return $e;
    }
    api_token_log_action('agent-me', [], 200);
    return null;
};
$counted = static function (int $at) use ($token_id): int {
    return api_token_rate_limit_state($token_id, 120, $at)['used'];
};

$t0 = time();

// 1) 120 calls within one minute pass (spread over 30 seconds).
for ($i = 0; $i < 120; $i++) {
    $error = $call($t0 + intdiv($i, 4));
    $assert($error === null, 'Call ' . ($i + 1) . ' of 120 must pass, got ' . ($error ? $error->getCode() : ''));
}

// 2) The 121st call gets 429 with Retry-After.
$error = $call($t0 + 30);
$assert($error !== null && $error->getCode() === 429, 'The 121st call within a minute must get 429.');
$assert(($error->details['retry_after_seconds'] ?? null) === 30, 'Retry-After must say when the oldest call leaves the 60 s window (30 s), got ' . json_encode($error->details));
$assert(($error->details['rate_limit']['limit'] ?? null) === 120, '429 must report the limit.');

// 3) Rejected 429 calls are logged for audit but do not count.
for ($i = 1; $i <= 5; $i++) {
    $retry = $call($t0 + 30 + $i);
    $assert($retry !== null && $retry->getCode() === 429, 'Calls over the limit keep getting 429.');
}
$logged_429 = (int) $db->query("SELECT COUNT(*) FROM api_token_audit_logs WHERE token_id = {$token_id} AND status_code = 429")->fetchColumn();
$assert($logged_429 === 6, '429 responses are still audit-logged.');
$assert($counted($t0 + 36) === 120, 'A 429 must not raise the counted calls (still 120).');

// 4) After a minute the window frees up and calls pass again.
$assert($call($t0 + 59) !== null, 'Still blocked just before the oldest calls are 60 s old.');
$error = $call($t0 + 60);
$assert($error === null, 'After 60 s from the first calls, calls must pass again.');
$assert($counted($t0 + 60) === 117, 'Only calls from the last 60 s count (116 remaining + this one).');

// 5) Window is 60 s although PHP is Prague and DB is UTC: one hour later nothing counts.
$assert($counted($t0 + 3600) === 0, 'An hour later no call may count toward the limit.');
$GLOBALS['foxdesk_clock_override'] = null;

// Regression proof: the old NOW()-based query counted Prague-time rows as recent for an hour.
$old_count = (int) $db->query(
    "SELECT COUNT(*) FROM api_token_audit_logs WHERE token_id = {$token_id} AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
)->fetchColumn();
$assert($old_count >= 120, 'Precondition check: the old query would still count the rows (window ~1 h), got ' . $old_count);

// 6) Headers over HTTP: X-RateLimit-Limit/Remaining on success, Retry-After on 429.
$port = 18765;
$env = array_merge(getenv(), ['FOXDESK_API_TOKEN_RATE_LIMIT_PER_MINUTE' => '3']);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/api-token-rate-limit-fixture.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    __DIR__,
    $env
);
$assert(is_resource($server), 'Could not start the PHP test server.');
$http = static function () use ($port): array {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . '/?token=901', false, $context);
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $headers['status'] = (int) $m[1];
        } elseif (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return [$headers, $body];
};
for ($wait = 0; $wait < 50; $wait++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}
$db->exec('DELETE FROM api_token_audit_logs WHERE token_id = 901');
$remaining_seen = [];
for ($i = 0; $i < 3; $i++) {
    [$headers] = $http();
    $assert(($headers['status'] ?? 0) === 200, 'HTTP call ' . ($i + 1) . ' must pass.');
    $assert(($headers['x-ratelimit-limit'] ?? '') === '3', 'X-RateLimit-Limit must be sent.');
    $remaining_seen[] = $headers['x-ratelimit-remaining'] ?? '';
}
$assert($remaining_seen === ['2', '1', '0'], 'X-RateLimit-Remaining must count down: ' . json_encode($remaining_seen));
[$headers, $body] = $http();
proc_terminate($server);
$assert(($headers['status'] ?? 0) === 429, 'HTTP call over the limit must get 429.');
$retry_after = (int) ($headers['retry-after'] ?? 0);
$assert($retry_after >= 1 && $retry_after <= 60, 'Retry-After header must be 1-60 seconds, got ' . ($headers['retry-after'] ?? 'none'));
$assert(($headers['x-ratelimit-remaining'] ?? '') === '0', '429 must send X-RateLimit-Remaining: 0.');
$decoded = json_decode((string) $body, true);
$assert(($decoded['retry_after_seconds'] ?? null) === $retry_after, 'Body retry_after_seconds must match the header.');

$db->exec('DELETE FROM api_token_audit_logs');
echo "API token rate limit integration OK\n";
