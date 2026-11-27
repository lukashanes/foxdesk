<?php
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/security-helpers.php';
function get_max_upload_size() { return 1024; }
function api_error($message, $status = 400) { throw new RuntimeException($message, $status); }
$_SERVER = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=test', 'CONTENT_LENGTH' => '100'];
$_POST = ['ticket_id' => '1'];
$file = tmpfile();
if (!$file) throw new RuntimeException('Cannot create isolated upload fixture.');
try {
    fwrite($file, 'first-image');
    $_FILES = ['file' => ['name' => 'image.png', 'type' => 'image/png', 'size' => 11,
        'error' => UPLOAD_ERR_OK, 'tmp_name' => stream_get_meta_data($file)['uri']]];
    $first = api_idempotency_request_hash('upload');
    $_SERVER['CONTENT_LENGTH'] = '100000';
    // Multipart framing is parsed by PHP; it is not the per-file upload limit.
    if ($first !== api_idempotency_request_hash('upload')) throw new RuntimeException('Identical retry must retain its hash.');
    rewind($file); fwrite($file, 'other-image'); fflush($file);
    if ($first === api_idempotency_request_hash('upload')) throw new RuntimeException('Different file contents reuse the same idempotency hash.');
    $_FILES['file']['name'] = 'renamed.png';
    $renamed = api_idempotency_request_hash('upload');
    $_FILES['file']['name'] = 'image.png';
    if ($renamed === api_idempotency_request_hash('upload')) throw new RuntimeException('File names must be part of the hash.');
    ftruncate($file, 2048);
    // The fixture changes within one request; filesize() otherwise returns its cached value.
    clearstatcache(true, $_FILES['file']['tmp_name']);
    try {
        api_idempotency_request_hash('upload');
        throw new LogicException('Oversized file was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getCode() !== 413) throw $e;
    }
} finally { fclose($file); }
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['CONTENT_LENGTH'] = '1048577';
try {
    api_idempotency_request_hash('agent-create-ticket');
    throw new LogicException('JSON body limit was bypassed.');
} catch (RuntimeException $e) {
    if ($e->getCode() !== 413) throw $e;
}
echo "Multipart upload content hashes, retry stability and JSON body limit OK\n";
