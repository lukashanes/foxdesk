<?php
if (!is_admin()) {
    flash(t('Access denied.'), 'error');
    redirect(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
}

$page_title = t('Cloud migration');
$page = 'admin';
$tenant_name = get_setting('app_name', 'FoxDesk');
$cloud_url = trim((string) get_setting('migration_cloud_url', 'https://app.foxdesk.net'));
$stored_token = trim((string) get_setting('migration_cloud_token', ''));
$cutover_target = trim((string) get_setting('migration_cloud_target_url', ''));
$migration_response = null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'connect_cloud') {
    require_csrf_token();
    $cloud_url = trim((string) ($_POST['cloud_url'] ?? $cloud_url));
    $token = trim((string) ($_POST['migration_token'] ?? $stored_token));
    try {
        $migration_response = migration_cloud_connect($cloud_url, $token);
        $stored_token = $token;
        flash('FoxDesk Cloud connection verified.', 'success');
    } catch (Throwable $e) {
        flash('Cloud connection failed: ' . $e->getMessage(), 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'analyze_cloud') {
    require_csrf_token();
    $cloud_url = trim((string) ($_POST['cloud_url'] ?? $cloud_url));
    $token = trim((string) ($_POST['migration_token'] ?? $stored_token));
    try {
        $migration_response = migration_cloud_plan($cloud_url, $token);
        $stored_token = $token;
        flash('Migration plan refreshed.', 'success');
    } catch (Throwable $e) {
        flash('Migration analysis failed: ' . $e->getMessage(), 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'mark_cutover') {
    require_csrf_token();
    $target = trim((string) ($_POST['cutover_target_url'] ?? ''));
    if ($target === '') {
        flash('Cloud workspace URL is required before cutover.', 'error');
    } else {
        migration_cloud_mark_cutover($target, ['source' => 'admin_page']);
        flash('Cutover completed. This self-hosted FoxDesk is no longer the active instance.', 'success');
        header('Location: ' . $target);
        exit;
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

$inventory = migration_inventory();
$last_plan = json_decode((string) get_setting('migration_cloud_last_plan_json', ''), true);
$last_plan = is_array($last_plan) ? $last_plan : null;
$display_plan = is_array($migration_response) && isset($migration_response['plan']) ? $migration_response : $last_plan;

require_once BASE_PATH . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
            <div>
                <p class="text-sm font-semibold text-blue-600 mb-2">FoxDesk Cloud sync</p>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Sync to SaaS, then cut over once</h1>
                <p class="text-gray-600 max-w-3xl">
                    Use this bridge for a controlled one-way migration. This self-hosted FoxDesk stays active during sync.
                    After final cutover, it redirects users to FoxDesk Cloud and disables local IMAP/email notification processing
                    so only one instance remains active.
                </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-gray-100 text-gray-700">
                Mode: <?php echo e(migration_cloud_mode()); ?>
            </span>
        </div>

        <form method="post" class="grid lg:grid-cols-[1fr_1fr_auto_auto] gap-3 mt-6 items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="migration_action" value="analyze_cloud">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">FoxDesk Cloud URL</label>
                <input class="form-input" name="cloud_url" value="<?php echo e($cloud_url); ?>" placeholder="https://app.foxdesk.net">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Migration token</label>
                <input class="form-input" name="migration_token" value="<?php echo e($stored_token); ?>" placeholder="fdmig_..." autocomplete="off">
            </div>
            <button class="btn btn-secondary" type="submit" name="migration_action" value="connect_cloud">Connect</button>
            <button class="btn btn-primary" type="submit" name="migration_action" value="analyze_cloud">Analyze sync</button>
        </form>

        <?php if ($display_plan && !empty($display_plan['plan'])): ?>
            <?php $plan = $display_plan['plan']; ?>
            <div class="grid md:grid-cols-4 gap-3 mt-6">
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500">Rows</div>
                    <div class="text-2xl font-bold text-gray-900"><?php echo (int) ($plan['total_rows'] ?? 0); ?></div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500">Attachments</div>
                    <div class="text-2xl font-bold text-gray-900"><?php echo (int) ($plan['attachments']['rows'] ?? 0); ?></div>
                    <div class="text-sm text-gray-500"><?php echo e(format_file_size((int) ($plan['attachments']['bytes'] ?? 0))); ?></div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500">Direction</div>
                    <div class="text-base font-bold text-gray-900">Self-hosted → SaaS</div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500">Cutover</div>
                    <div class="text-base font-bold text-gray-900">Single active instance</div>
                </div>
            </div>
            <div class="mt-4 bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-900">
                Core data and attachments can be synced from CLI with
                <code>php bin/sync-to-cloud.php --cloud-url=<?php echo e($cloud_url); ?> --token=TOKEN</code>.
                Files are streamed over the migration API and stored through the SaaS storage driver. ZIP export remains available below as a fallback.
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
        <div class="font-semibold mb-2">Final cutover is destructive operationally.</div>
        <p class="mb-3">
            Use this only after the final sync is complete and the SaaS workspace has been verified. It disables local IMAP/email
            processing and redirects users to the cloud workspace.
        </p>
        <form method="post" class="grid md:grid-cols-[1fr_auto] gap-3 items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="migration_action" value="mark_cutover">
            <div>
                <label class="block text-sm font-semibold mb-1">Cloud workspace URL</label>
                <input class="form-input" name="cutover_target_url" value="<?php echo e($cutover_target); ?>" placeholder="https://app.foxdesk.net/">
            </div>
            <button class="btn btn-secondary" type="submit" onclick="return confirm('Final cutover will stop this self-hosted FoxDesk from acting as the active instance. Continue?')">
                Final cutover
            </button>
        </form>
    </div>

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
                <p class="text-sm font-semibold text-blue-600 mb-2">Fallback package</p>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Export this self-hosted FoxDesk</h1>
                <p class="text-gray-600 max-w-3xl">
                    This creates a migration ZIP that the FoxDesk SaaS platform admin can import into a new hosted workspace if API sync is not available.
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
        <strong>Fallback path:</strong> if API sync is unavailable, log in to the FoxDesk SaaS platform admin,
        import this ZIP, verify the hosted workspace, then return here for final cutover.
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
