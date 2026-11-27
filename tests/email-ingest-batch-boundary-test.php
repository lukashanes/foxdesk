<?php
// Actual IMAP message processing/batch and Graph run; all transport and SQL are local substitutes.
define('BASE_PATH', __DIR__ . '/fixtures/ingest-no-mail');
foreach (['FT_UID', 'ST_UID', 'CP_UID'] as $constant) defined($constant) || define($constant, 1);
$logs = $states = $moved = $finished = $comments = [];
class IngestBatchTransaction {
    public bool $active = false;
    function inTransaction() { return $this->active; }
    function beginTransaction() { $this->active = true; }
    function commit() { $this->active = false; }
    function rollBack() { $this->active = false; }
}
$db = new IngestBatchTransaction();
function get_db() { return $GLOBALS['db']; }
function column_exists(...$args) { return true; }
function table_exists(...$args) { return true; }
function schema_require(...$args) {}
function db_fetch_all(...$args) { return []; }
function db_fetch_one($sql, $params = []) {
    if (str_contains($sql, 'FROM allowed_senders')) return ['user_id' => $params[0] === 'outsider@example.test' ? 11 : 10];
    if (str_contains($sql, 'FROM tickets')) return ['id' => 1, 'user_id' => 10];
    if (str_contains($sql, 'FROM users')) return ['id' => (int) $params[0], 'role' => 'user', 'is_active' => 1];
    return null;
}
function db_insert($table, $data) {
    if ($table === 'comments') $GLOBALS['comments'][] = $data;
    return 20;
}
function db_update(...$args) { return 1; }
function db_query($sql, $params = []) {
    if (str_contains($sql, 'INSERT INTO email_ingest_logs')) $GLOBALS['logs'][$params[0]][$params[1]] = $params;
    if (str_contains($sql, 'INSERT INTO email_ingest_state')) $GLOBALS['states'][] = $params[1];
    return true;
}
// No real IMAP functions, Graph client, SMTP client or database is invoked.
if (extension_loaded('imap')) {
    echo "SKIP: run this isolated IMAP substitute test without ext-imap\n";
    return;
} else {
function imap_fetch_overview($imap, $uid, $flags) { return [(object) ['subject' => '[TK-1] Reply', 'message_id' => '<imap-' . $uid . '@example.test>']]; }
function imap_fetchheader($imap, $uid, $flags) { return $uid; }
function imap_rfc822_parse_headers($uid) { return (object) ['from' => [(object) ['mailbox' => (int) $uid === 2 ? 'outsider' : 'owner', 'host' => 'example.test']]]; }
function imap_fetchstructure(...$args) { return false; }
function imap_body(...$args) { return 'Test reply'; }
function imap_mime_header_decode($text) { return [(object) ['text' => $text, 'charset' => 'UTF-8']]; }
function imap_getmailboxes(...$args) { return []; }
function imap_createmailbox(...$args) { return true; }
function imap_utf7_encode($text) { return $text; }
function imap_mail_move($imap, $uid, $folder, $flags) { $GLOBALS['moved'][(int) $uid] = $folder; return true; }
function imap_expunge(...$args) { return true; }
function imap_setflag_full(...$args) { throw new RuntimeException('Fixture should move the message.'); }
}
function microsoft_mail_is_active(...$args) { return true; }
function microsoft_mail_connection() { return ['mailbox_email' => 'inbox@example.test']; }
function microsoft_mail_list_unread($limit) {
    return array_map(static fn($id) => ['id' => 'graph-' . $id, 'subject' => '[TK-1] Reply',
        'from' => ['emailAddress' => ['address' => $id === 2 ? 'outsider@example.test' : 'owner@example.test']],
        'body' => ['contentType' => 'text', 'content' => 'Test reply']], [1, 2, 3]);
}
function microsoft_mail_finish_message($id, $success) { $GLOBALS['finished'][$id] = $success; }
function microsoft_mail_record_sync(...$args) {}
require dirname(__DIR__) . '/includes/email-ingest-functions.php';
require dirname(__DIR__) . '/includes/microsoft-email-ingest.php';
$cfg = ['folder' => 'INBOX', 'host' => 'example.test', 'port' => 993, 'encryption' => 'ssl',
    'validate_cert' => true, 'allow_unknown_senders' => false, 'processed_folder' => 'Processed', 'failed_folder' => 'Failed'];
$result = ['checked' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];
email_ingest_process_batch(null, [1, 2, 3], $cfg, false, $result);
if ($result['checked'] !== 3 || $result['processed'] !== 2 || $result['failed'] !== 1
    || $states !== [1, 2, 3] || $moved !== [1 => 'Processed', 2 => 'Failed', 3 => 'Processed']
    || ($logs['INBOX'][2][7] ?? '') !== 'processing_failed'
    || !str_contains($logs['INBOX'][2][8] ?? '', 'not authorized')) {
    throw new RuntimeException('IMAP failed to isolate/log/move the unauthorized middle message and advance state.');
}
$result = microsoft_email_ingest_run();
$failed_uid = microsoft_email_ingest_uid(['id' => 'graph-2']);
if ($result['checked'] !== 3 || $result['processed'] !== 2 || $result['failed'] !== 1
    || $finished !== ['graph-1' => true, 'graph-2' => false, 'graph-3' => true]
    || ($logs['microsoft:inbox@example.test'][$failed_uid][7] ?? '') !== 'processing_failed') {
    throw new RuntimeException('Graph failed to log/mark the unauthorized middle message and continue.');
}
if (count($comments) !== 4 || array_unique(array_column($comments, 'user_id')) !== [10]) {
    throw new RuntimeException('Unauthorized sender wrote a comment or a later message was lost.');
}
echo "IMAP and Graph unauthorized middle-message recovery OK (offline substitutes)\n";
