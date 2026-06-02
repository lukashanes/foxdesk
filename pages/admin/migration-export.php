<?php
if (!is_admin()) {
    flash(t('Access denied.'), 'error');
    redirect('dashboard');
}

$page_title = t('Cloud migration');
$page = 'admin';
$tenant_name = get_setting('app_name', 'FoxDesk');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'download_export') {
    try {
        migration_download_export_package();
    } catch (Throwable $e) {
        if (function_exists('debug_log')) {
            debug_log('Migration export failed', ['error' => $e->getMessage()], 'error', 'migration');
        }
        flash(t('Migration export failed: {error}', ['error' => $e->getMessage()]), 'error');
        redirect('admin', ['section' => 'migration-export']);
    }
}

$readiness = migration_export_readiness();
$counts = [];
$count_errors = [];
foreach (migration_export_tables() as $table) {
    if (!table_exists($table)) {
        continue;
    }

    try {
        $counts[$table] = migration_count_rows($table);
    } catch (Throwable $e) {
        $counts[$table] = 0;
        $count_errors[$table] = $e->getMessage();
    }
}

$attachment_count = (int) ($counts['attachments'] ?? 0);
$storage_bytes = 0;
if (table_exists('attachments') && column_exists('attachments', 'file_size')) {
    try {
        $storage_bytes = (int) (db_fetch_one("SELECT COALESCE(SUM(file_size), 0) AS bytes FROM attachments")['bytes'] ?? 0);
    } catch (Throwable $e) {
        $storage_bytes = 0;
        $count_errors['attachments_storage'] = $e->getMessage();
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <?php if (!$readiness['ready']): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-5 text-sm text-red-900">
            <div class="font-semibold mb-2">Migration export is not ready on this server.</div>
            <ul class="list-disc pl-5 space-y-1">
                <?php if (!$readiness['zip_available']): ?>
                    <li>PHP extension <code>ZipArchive</code> is missing. Enable/install PHP zip support first.</li>
                <?php endif; ?>
                <?php if (empty($readiness['backup_dir']['writable'])): ?>
                    <li>Backup directory is not writable: <code><?php echo e($readiness['backup_dir']['path']); ?></code></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($count_errors): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
            <div class="font-semibold mb-2">Some export counts could not be read.</div>
            <div>The export can still run, but check the server log if the package is incomplete.</div>
        </div>
    <?php endif; ?>

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
                <button type="submit" class="btn btn-primary whitespace-nowrap" <?php echo $readiness['ready'] ? '' : 'disabled'; ?>>
                    Download migration package
                </button>
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

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
