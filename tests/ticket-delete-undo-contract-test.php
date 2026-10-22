<?php
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) { fwrite(STDERR, "Unable to read {$path}\n"); exit(1); }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
};
$router = $read('includes/api/router.php');
$handler = $read('includes/api/ticket-handler.php');
$undo = $read('includes/modules/tickets/ticket-undo.php');
$cron = $read('pages/cron.php');
$schema = $read('includes/schema.sql');
$userFunctions = $read('includes/user-functions.php');
$detail = $read('assets/js/ticket-detail.js') . "\n" . $read('assets/js/ticket-detail-records.js');
$page = $read('pages/ticket-detail.php');
$editCommentStart = strpos($handler, 'function api_edit_comment()');
$deleteCommentStart = strpos($handler, 'function api_delete_comment()');
$assert($editCommentStart !== false && $deleteCommentStart !== false && $deleteCommentStart > $editCommentStart, 'Comment edit handler boundaries are missing.');
$editCommentHandler = substr($handler, $editCommentStart, $deleteCommentStart - $editCommentStart);

foreach (["'restore-time-entry' => 'api_restore_time_entry'", "'restore-comment' => 'api_restore_comment'", "'restore-attachment' => 'api_restore_attachment'"] as $route) {
    $assert(str_contains($router, $route), "Missing restore route: {$route}");
}
$assert(str_contains($undo, 'const FOXDESK_TICKET_UNDO_SECONDS = 10;'), 'Undo window must be ten seconds.');
$assert(str_contains($undo, "db_insert('pending_deletions'"), 'Undo must use durable pending deletion storage.');
$assert(str_contains($cron, 'ticket_undo_finalize_expired(250)'), 'Cron must finalize expired Undo rows without a browser request.');
$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS pending_deletions'), 'Pending deletion schema is missing.');
foreach (['time-entry', 'comment', 'attachment'] as $resource) {
    $assert(str_contains($handler, "'undo_action' => 'restore-{$resource}'"), "Missing {$resource} undo response.");
}
$assert(str_contains($userFunctions, 'function can_manage_comment'), 'Comment permission helper is missing.');
$assert(!str_contains($editCommentHandler, '!is_agent() && !is_admin()'), 'Comment edit must not reject an authenticated author before the shared ownership check.');
$assert(str_contains($editCommentHandler, 'if (!can_manage_comment($comment, $user))'), 'Comment edit must enforce shared creator-or-admin permission.');
$assert(str_contains($userFunctions, 'function can_manage_time_entry'), 'Time permission helper is missing.');
$assert(str_contains($detail, 'showUndoToast'), 'Ticket detail must show an Undo action.');
$assert(str_contains($detail, 'restoreDeletedItem'), 'Ticket detail must call restore endpoints.');
$assert(!str_contains($detail, 'confirmDeleteComment'), 'Comment deletion must not use confirm().');
$assert(!str_contains($page, 'Delete this time entry?'), 'Time deletion must not use confirm().');
foreach (['en', 'cs', 'de', 'es', 'it'] as $lang) {
    $langFile = $read("includes/lang/{$lang}.php");
    foreach (['Undo', 'Undo is no longer available.', 'Time entry restored.', 'Comment restored.', 'Attachment restored.'] as $key) {
        $assert(str_contains($langFile, "'{$key}' =>"), "Missing {$key} translation in {$lang}.");
    }
}
echo "Ticket delete undo contract OK\n";
