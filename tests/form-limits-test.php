<?php

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/functions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$mib = 1024 * 1024;
$assert(get_php_post_max_size() === 8 * $mib && get_php_upload_max_size() === 6 * $mib,
    'Run with post_max_size=8M and upload_max_filesize=6M.');
$limit = get_request_upload_limit();
$_SESSION = ['lang' => 'en'];
$cached_settings = [];

foreach ([
    'one byte below the limit' => ['POST', $limit - 1, [], [], false],
    'exactly at the limit' => ['POST', $limit, [], [], false],
    'one byte over the limit with dropped inputs' => ['POST', $limit + 1, [], [], true],
    'one byte over the limit with parsed POST data' => ['POST', $limit + 1, ['title' => 'Example'], [], false],
    'one byte over the limit with parsed files' => ['POST', $limit + 1, [], ['attachment' => []], false],
    'GET with a large Content-Length' => ['GET', $limit + 1, [], [], false],
    'no Content-Length' => ['POST', null, [], [], false],
] as $case => [$method, $length, $fields, $files, $expected]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['CONTENT_LENGTH']);
    if ($length !== null) {
        $_SERVER['CONTENT_LENGTH'] = (string) $length;
    }
    $_POST = $fields;
    $_FILES = $files;
    $assert(foxdesk_request_exceeded_post_max_size() === $expected, $case);
    $message = foxdesk_post_max_size_error_message();
    $assert($expected ? str_contains($message ?? '', format_file_size($limit)) : $message === null,
        'Only an oversized request with dropped inputs should report the server limit: ' . $case);
}

foreach ([
    'configured file limit is the smallest' => ['2', 2],
    'configuration is clamped to at least one MiB' => ['0', 1],
    'PHP file limit is the smallest' => ['10', 6],
] as $case => [$configured, $expected]) {
    $cached_settings = ['max_upload_size' => $configured];
    $assert(get_max_upload_size() === $expected * $mib, $case);
}

$cached_settings = ['max_upload_size' => '2'];
foreach ([
    'attachment exceeds application limit' => [UPLOAD_ERR_OK, 2 * $mib + 1, 'File is too large.'],
    'PHP rejects an oversized attachment' => [UPLOAD_ERR_INI_SIZE, 0, 'File exceeds the server upload limit'],
    'attachment arrives only partially' => [UPLOAD_ERR_PARTIAL, 0, 'File was only partially uploaded.'],
] as $case => [$error, $size, $message]) {
    $actual = null;
    try {
        upload_file(['error' => $error, 'size' => $size]);
    } catch (Exception $exception) {
        $actual = $exception->getMessage();
    }
    $assert($actual !== null && str_starts_with($actual, $message), $case);
}

echo "Form limits tests passed (13 cases).\n";
