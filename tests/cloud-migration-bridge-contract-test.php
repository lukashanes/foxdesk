<?php

$root = dirname(__DIR__);

$migration = file_get_contents($root . '/includes/migration-functions.php');
$page = file_get_contents($root . '/pages/admin/migration-export.php');
$index = file_get_contents($root . '/index.php');
$ingest = file_get_contents($root . '/includes/email-ingest-functions.php');
$cli = file_get_contents($root . '/bin/sync-to-cloud.php');

if ($migration === false || $page === false || $index === false || $ingest === false || $cli === false) {
    fwrite(STDERR, "Unable to read self-hosted migration bridge files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($migration, 'function migration_inventory'), 'Self-hosted migration inventory helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_connect'), 'Self-hosted cloud connect helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_plan'), 'Self-hosted cloud plan helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_push_table_chunk'), 'Self-hosted chunk push helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_push_attachment'), 'Self-hosted attachment push helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_sync_attachments'), 'Self-hosted attachment sync helper is missing.');
$assert(str_contains($migration, 'function migration_cloud_sync_all_tables'), 'Self-hosted table sync helper is missing.');
$assert(str_contains($migration, 'migration-push-attachment'), 'Self-hosted sync must call the attachment upload endpoint.');
$assert(str_contains($migration, 'function migration_cloud_mark_cutover'), 'Self-hosted cutover helper is missing.');
$assert(str_contains($migration, 'self_hosted_to_saas'), 'Migration direction must be explicit.');
$assert(str_contains($page, 'Sync to SaaS, then cut over once'), 'Cloud migration page must expose sync-first workflow.');
$assert(str_contains($page, 'Final cutover'), 'Cloud migration page must expose final cutover.');
$assert(str_contains($index, 'migration_cloud_should_redirect_after_cutover'), 'Index must redirect after cloud cutover.');
$assert(str_contains($index, 'migration_cloud_cutover_active'), 'Pseudo-cron must be disabled after cloud cutover.');
$assert(str_contains($ingest, 'cloud_cutover_complete'), 'Email ingest must stop after cloud cutover.');
$assert(str_contains($cli, 'migration_cloud_sync_all_tables'), 'Self-hosted CLI sync command must run table sync.');
$assert(str_contains($cli, 'attachments'), 'Self-hosted CLI sync command must report attachment sync.');

echo "Self-hosted cloud migration bridge contract OK\n";
