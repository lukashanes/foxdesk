<?php
require dirname(__DIR__) . '/includes/security-helpers.php';
if (!function_exists('security_read_limited_stream')) throw new RuntimeException('Bounded body reader missing.');
foreach ([0, 1, 4096] as $size) {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, str_repeat('T', $size)); rewind($stream);
    if (security_read_limited_stream($stream, 4096) !== str_repeat('T', $size)) throw new RuntimeException('Valid body was changed.');
    fclose($stream);
}
$stream = fopen('php://temp', 'w+b');
fwrite($stream, str_repeat('T', 200000)); rewind($stream);
try {
    security_read_limited_stream($stream, 65536);
    throw new LogicException('Oversized chunked body was accepted.');
} catch (LengthException $e) {
    if (ftell($stream) !== 65537) throw new RuntimeException('Reader consumed beyond limit plus one byte.');
}
fclose($stream);

foreach ([65535, 65536] as $length) {
    $_SERVER['CONTENT_LENGTH'] = (string) $length;
    if (security_read_request_body(65536) !== '') {
        throw new RuntimeException('An empty CLI body must remain empty at or below the declared limit.');
    }
}
$_SERVER['CONTENT_LENGTH'] = '65537';
try {
    security_read_request_body(65536);
    throw new LogicException('An oversized declared length was accepted before reading the body.');
} catch (LengthException $e) {
    // An empty CLI input proves this rejection came from the header check.
} finally {
    unset($_SERVER['CONTENT_LENGTH']);
}

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$guard = strpos($index, 'security_enforce_public_request_limit()');
$cron = strpos($index, 'pseudo_cron_check();');
if ($guard === false || $cron === false || $guard >= $cron) {
    throw new RuntimeException('Public body guard must run before pseudo cron.');
}
foreach (['includes/admin-crud-helper.php', 'includes/auth.php'] as $file) {
    if (str_contains(file_get_contents($root . '/' . $file), "file_get_contents('php://input')")) throw new RuntimeException('Unbounded raw body reader remains: ' . $file);
}
echo "Request body limit and chunked stream regression OK\n";
