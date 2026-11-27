<?php

$root = dirname(__DIR__);

$cases = [
    'nested Unicode' => [['items' => ["\u{010D}" => "\u{1F98A}", 7, false, null]], 200],
    'malformed top-level value' => [['query' => "\xC3"], 422],
    'malformed nested value' => [['items' => [['name' => "\xC3"]]], 422],
    'malformed nested key' => [['items' => ["\xC3" => 'name']], 422],
];

// The API error handler terminates the request. Capture its status and JSON in a CLI child.
if (isset($argv[1])) {
    [$input] = $cases[$argv[1]];
    require_once $root . '/includes/admin-crud-helper.php';
    http_response_code(200);
    ob_start();
    register_shutdown_function(static function (): void {
        echo json_encode(['status' => http_response_code(), 'body' => ob_get_clean()], JSON_THROW_ON_ERROR);
    });
    api_validate_utf8_values($input);
    exit;
}

$helper = (string) file_get_contents($root . '/includes/admin-crud-helper.php');
$router = (string) file_get_contents($root . '/includes/api/router.php');

$assertions = [
    'JSON input validates UTF-8' => str_contains($helper, "mb_check_encoding(\$raw, 'UTF-8')")
        && str_contains($helper, 'JSON_THROW_ON_ERROR'),
    'Request parameters are recursively validated' => str_contains($helper, 'function api_validate_utf8_values(array $values): void')
        && str_contains($helper, 'api_validate_utf8_values($candidate);'),
    'Router validates GET and POST before dispatch' => str_contains($router, 'api_validate_utf8_values($_GET);')
        && str_contains($router, 'api_validate_utf8_values($_POST);'),
];

$failed = array_keys(array_filter($assertions, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "API UTF-8 boundary contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

foreach ($cases as $case => [, $expected_status]) {
    $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start API validation case: ' . $case);
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit_code = proc_close($process);
    $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $expected_body = $expected_status === 200 ? '' : json_encode([
        'error' => 'Request parameters must be valid UTF-8.',
        'success' => false,
    ], JSON_THROW_ON_ERROR);
    if ($exit_code !== 0 || $response !== ['status' => $expected_status, 'body' => $expected_body]) {
        throw new RuntimeException('Unexpected API validation result: ' . $case);
    }
}

echo "API UTF-8 boundary contract and 4 runtime cases passed.\n";
