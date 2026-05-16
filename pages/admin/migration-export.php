<?php
require_admin();

$page_title = t('Cloud migration');
$tenant_name = get_setting('app_name', 'FoxDesk');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'download_export') {
    migration_download_export_package();
}

$counts = [];
foreach (migration_export_tables() as $table) {
    if (!table_exists($table)) {
        continue;
    }

    try {
        $counts[$table] = count(migration_select_rows($table));
    } catch (Throwable $e) {
        $counts[$table] = 0;
    }
}

$attachment_count = (int) ($counts['attachments'] ?? 0);
$storage_bytes = table_exists('attachments')
    ? (int) (db_fetch_one("SELECT COALESCE(SUM(file_size), 0) AS bytes FROM attachments")['bytes'] ?? 0)
    : 0;
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-blue-600 mb-2">FoxDesk Cloud migration</p>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Export this self-hosted FoxDesk</h1>
                <p class="text-gray-600 max-w-3xl">
                    This creates a migration ZIP that the FoxDesk SaaS platform admin can import into a new hosted workspace.
                    It includes <?php echo e($tenant_name); ?> users, clients, tickets, comments, time entries, reports,
                    notification metadata, settings, and attachment files.
                </p>
            </div>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="migration_action" value="download_export">
                <button type="submit" class="btn btn-primary whitespace-nowrap">Download migration package</button>
            </form>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500">Attachments</div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo $attachment_count; ?></div>
            <div class="text-sm text-gray-500 mt-1"><?php echo e(format_file_size($storage_bytes)); ?> total</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500">Users</div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo (int) ($counts['users'] ?? 0); ?></div>
            <div class="text-sm text-gray-500 mt-1">Password hashes are preserved for import.</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500">Tickets</div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo (int) ($counts['tickets'] ?? 0); ?></div>
            <div class="text-sm text-gray-500 mt-1">IDs are remapped during SaaS import.</div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Package contents</h2>
        <div class="grid md:grid-cols-3 gap-3">
            <?php foreach ($counts as $table => $count): ?>
                <div class="flex items-center justify-between border border-gray-100 rounded-md px-3 py-2 text-sm">
                    <span class="font-medium text-gray-700"><?php echo e($table); ?></span>
                    <span class="text-gray-500"><?php echo (int) $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-lg p-5 text-sm text-blue-900">
        <strong>Next step:</strong> log in to the FoxDesk SaaS platform admin, open Platform console,
        upload this ZIP under Import self-hosted FoxDesk, and verify the hosted workspace before switching DNS.
    </div>
</div>
