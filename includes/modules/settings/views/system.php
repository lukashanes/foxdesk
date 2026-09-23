        <?php
        if (file_exists(BASE_PATH . '/install.php')) {
            @unlink(BASE_PATH . '/install.php');
        }

        $flash_msg = $_SESSION['flash']['message'] ?? '';
        $show_health = (
            stripos($flash_msg, 'Update applied') !== false
            || stripos($flash_msg, 'Update installed') !== false
            || stripos($flash_msg, 'Rollback') !== false
        );
        $health = ($show_health && function_exists('post_update_health_check')) ? post_update_health_check() : null;
        $mysql_version = db_fetch_one("SELECT VERSION() as v")['v'] ?? '-';
        $user_count = db_fetch_one("SELECT COUNT(*) as c FROM users")['c'] ?? 0;
        $ticket_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets")['c'] ?? 0;
        $remote_update = get_cached_update_info();
        $last_check = get_last_update_check_time();
        $pending_update = $_SESSION['pending_update'] ?? null;
        $backups = get_backups();
        $update_history = get_update_history();
        $backup_creator_names = [];
        $backup_creator_ids = [];
        foreach ($backups as $backup_row) {
            $creator_id = (int) ($backup_row['created_by_user_id'] ?? 0);
            if ($creator_id > 0) {
                $backup_creator_ids[$creator_id] = $creator_id;
            }
        }
        if (!empty($backup_creator_ids)) {
            $creator_placeholders = implode(',', array_fill(0, count($backup_creator_ids), '?'));
            $creator_rows = db_fetch_all(
                "SELECT id, first_name, last_name, email FROM users WHERE id IN ($creator_placeholders)",
                array_values($backup_creator_ids)
            );
            foreach ($creator_rows as $creator_row) {
                $creator_name = trim((string) (($creator_row['first_name'] ?? '') . ' ' . ($creator_row['last_name'] ?? '')));
                if ($creator_name === '') {
                    $creator_name = (string) ($creator_row['email'] ?? ('#' . $creator_row['id']));
                }
                $backup_creator_names[(int) $creator_row['id']] = $creator_name;
            }
        }
        ?>

        <div class="admin-system">
            <section class="admin-hero">
                <div>
                    <p class="admin-eyebrow"><?php echo e(t('System')); ?></p>
                    <h2><?php echo e(t('Operations overview')); ?></h2>
                    <p><?php echo e(t('Versions, updates, backups, background tasks, and upload limits.')); ?></p>
                </div>
                <div class="admin-hero-actions">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="check_updates_now" class="btn btn-secondary btn-sm">
                            <?php echo get_icon('refresh-cw', 'w-3.5 h-3.5'); ?>
                            <?php echo e(t('Check updates')); ?>
                        </button>
                    </form>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="create_backup" class="btn btn-primary btn-sm"
                            onclick="return confirm('<?php echo e(t('Create a new backup?')); ?>')">
                            <?php echo get_icon('save', 'w-3.5 h-3.5'); ?>
                            <?php echo e(t('Create backup')); ?>
                        </button>
                    </form>
                </div>
            </section>

            <?php if ($health !== null): ?>
                <div class="admin-notice <?php echo $health['ok'] ? 'is-success' : 'is-danger'; ?>">
                    <?php echo get_icon($health['ok'] ? 'check-circle' : 'exclamation-triangle', 'w-4 h-4'); ?>
                    <div>
                        <strong><?php echo e($health['ok'] ? t('Health check passed') : t('Post-update health check found issues')); ?></strong>
                        <span><?php echo e($health['ok'] ? t('Database, files, session, and uploads all OK.') : implode(' ', (array) ($health['errors'] ?? []))); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="admin-metric-grid">
                <div class="admin-metric"><span><?php echo e(t('App version')); ?></span><strong><?php echo e(defined('APP_VERSION') ? APP_VERSION : '-'); ?></strong></div>
                <div class="admin-metric"><span><?php echo e(t('PHP')); ?></span><strong><?php echo e(phpversion()); ?></strong></div>
                <div class="admin-metric"><span><?php echo e(t('MySQL')); ?></span><strong title="<?php echo e($mysql_version); ?>"><?php echo e(strtok((string) $mysql_version, '-')); ?></strong></div>
                <div class="admin-metric"><span><?php echo e(t('Tickets')); ?></span><strong><?php echo e((string) $ticket_count); ?></strong></div>
                <div class="admin-metric"><span><?php echo e(t('Users')); ?></span><strong><?php echo e((string) $user_count); ?></strong></div>
                <div class="admin-metric"><span><?php echo e(t('Upload')); ?></span><strong><?php echo e(($settings['max_upload_size'] ?? '10') . ' MB'); ?></strong></div>
            </div>

            <div class="admin-section-grid">
                <section class="admin-panel" id="updates">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Updates')); ?></h3>
                            <p><?php echo e($last_check ? t('Last checked') . ': ' . $last_check : t('Not checked yet')); ?></p>
                        </div>
                        <span class="admin-status <?php echo $remote_update ? 'is-info' : 'is-success'; ?>">
                            <?php echo e($remote_update ? t('Available') : t('Current')); ?>
                        </span>
                    </div>

                    <?php if ($remote_update): ?>
                        <div class="admin-callout">
                            <strong>FoxDesk <?php echo e($remote_update['version']); ?></strong>
                            <?php if (!empty($remote_update['released_at'])): ?>
                                <span><?php echo e(t('Released')); ?>: <?php echo e($remote_update['released_at']); ?></span>
                            <?php endif; ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="install_remote_update" value="1">
                                <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="if(!confirm('<?php echo e(t('Install update? A backup will be created automatically.')); ?>'))return false; this.disabled=true; this.textContent='<?php echo e(t('Installing...')); ?>'; this.form.submit();">
                                    <?php echo get_icon('download', 'w-3.5 h-3.5'); ?>
                                    <?php echo e(t('Install update')); ?>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="admin-toggle-row">
                        <?php echo csrf_field(); ?>
                        <label>
                            <input type="checkbox" name="update_check_enabled" value="1" <?php echo is_update_check_enabled() ? 'checked' : ''; ?> onchange="this.form.submit();">
                            <span><?php echo e(t('Automatically check for updates')); ?></span>
                        </label>
                        <input type="hidden" name="save_update_check_settings" value="1">
                    </form>

                    <details class="admin-disclosure">
                        <summary><?php echo e(t('Upload update package')); ?></summary>
                        <?php if ($pending_update): ?>
                            <div class="admin-callout">
                                <strong><?php echo e(t('Update ready to install')); ?> v<?php echo e($pending_update['version']); ?></strong>
                                <div class="admin-inline-actions">
                                    <form method="post">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" name="apply_update" class="btn btn-primary btn-sm"
                                            onclick="return confirm('<?php echo e(t('A backup will be created before updating. Continue?')); ?>')">
                                            <?php echo e(t('Apply update')); ?>
                                        </button>
                                    </form>
                                    <form method="post">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" name="cancel_update" class="btn btn-secondary btn-sm">
                                            <?php echo e(t('Cancel')); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="post" enctype="multipart/form-data" id="update-form">
                                <?php echo csrf_field(); ?>
                                <div id="update-upload-zone" class="admin-upload-zone">
                                    <input type="file" name="update_package" id="update-file-input" accept=".zip" class="hidden" required>
                                    <div>
                                        <?php echo get_icon('upload', 'w-4 h-4'); ?>
                                        <span id="update-file-name"><?php echo e(t('No file selected')); ?></span>
                                    </div>
                                    <button type="submit" name="upload_update" class="btn btn-secondary btn-sm" id="update-upload-btn" disabled>
                                        <?php echo e(t('Upload')); ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </details>
                </section>

                <section class="admin-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Background tasks')); ?> · PHP</h3>
                            <p><?php echo e(t('Tasks run in the background on page loads. No server cron job needed.')); ?></p>
                        </div>
                        <?php $pseudo_cron_enabled = (string) get_setting('pseudo_cron_enabled', '1') !== '0'; ?>
                        <span class="admin-status <?php echo $pseudo_cron_enabled ? 'is-success' : 'is-warning'; ?>">
                            <?php echo e($pseudo_cron_enabled ? t('On') : t('Off')); ?>
                        </span>
                    </div>
                    <form method="post" class="admin-toggle-row">
                        <?php echo csrf_field(); ?>
                        <label>
                            <input type="checkbox" name="pseudo_cron_enabled" value="1"
                                <?php echo $pseudo_cron_enabled ? 'checked' : ''; ?>
                                onchange="this.form.submit();">
                            <span><?php echo e(t('Enable background tasks')); ?></span>
                        </label>
                        <input type="hidden" name="save_pseudo_cron_settings" value="1">
                    </form>
                    <?php if ($pseudo_cron_enabled): ?>
                        <div class="admin-list">
                            <?php
                            $cron_tasks = [
                                ['key' => 'pseudo_cron_last_email', 'label' => t('Email ingestion success'), 'interval' => t('every 5 min')],
                                ['key' => 'pseudo_cron_last_email_attempt', 'label' => t('Email ingestion attempt'), 'interval' => t('last trigger')],
                                ['key' => 'pseudo_cron_last_recurring', 'label' => t('Recurring tasks'), 'interval' => t('every 60 min')],
                                ['key' => 'pseudo_cron_last_maintenance', 'label' => t('Maintenance'), 'interval' => t('every 24 hours')],
                            ];
                            foreach ($cron_tasks as $ct):
                                $last = get_setting($ct['key'], '');
                                $last_fmt = $last ? date('Y-m-d H:i:s', (int) $last) : '-';
                            ?>
                                <div><span><?php echo e($ct['label']); ?> <small><?php echo e($ct['interval']); ?></small></span><strong><?php echo e($last_fmt); ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        $email_result_raw = get_setting('pseudo_cron_last_email_result', '');
                        $email_result = $email_result_raw !== '' ? json_decode($email_result_raw, true) : null;
                        $email_error = trim((string) get_setting('pseudo_cron_last_email_error', ''));
                        $email_error_at = (int) get_setting('pseudo_cron_last_email_error_at', '0');
                        $email_source = trim((string) get_setting('pseudo_cron_last_email_source', ''));
                        ?>
                        <div class="mt-3 rounded-lg border p-3 text-xs space-y-1" style="border-color: var(--border-light); background: var(--surface-secondary); color: var(--text-muted);">
                            <div>
                                <strong><?php echo e(t('Email ingest source')); ?>:</strong>
                                <?php echo e($email_source !== '' ? $email_source : '-'); ?>
                            </div>
                            <?php if (is_array($email_result)): ?>
                                <div>
                                    <strong><?php echo e(t('Last email ingest result')); ?>:</strong>
                                    <?php echo e(t('checked {checked}, processed {processed}, skipped {skipped}, failed {failed}', [
                                        'checked' => (string) ((int) ($email_result['checked'] ?? 0)),
                                        'processed' => (string) ((int) ($email_result['processed'] ?? 0)),
                                        'skipped' => (string) ((int) ($email_result['skipped'] ?? 0)),
                                        'failed' => (string) ((int) ($email_result['failed'] ?? 0)),
                                    ])); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($email_error !== ''): ?>
                                <div style="color: var(--danger, #dc2626);">
                                    <strong><?php echo e(t('Last email ingest error')); ?>:</strong>
                                    <?php echo e($email_error); ?>
                                    <?php if ($email_error_at > 0): ?>
                                        <span>(<?php echo e(date('Y-m-d H:i:s', $email_error_at)); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Backups')); ?></h3>
                            <p><?php echo e(t('settings.system.backup_scope')); ?></p>
                        </div>
                        <span class="admin-status"><?php echo e((string) count($backups)); ?></span>
                    </div>
                    <?php if (!empty($backups)): ?>
                        <div class="admin-backup-list">
                            <?php foreach ($backups as $backup): ?>
                                <?php
                                $backup_creator_id = (int) ($backup['created_by_user_id'] ?? 0);
                                $backup_creator_name = $backup_creator_names[$backup_creator_id] ?? '';
                                ?>
                                <div class="admin-backup-row">
                                    <div>
                                        <strong><?php echo e($backup['version']); ?></strong>
                                        <span><?php echo e(date('Y-m-d H:i', strtotime($backup['date']))); ?><?php echo !empty($backup['size']) ? ' · ' . e(format_filesize($backup['size'])) : ''; ?></span>
                                        <?php if ($backup_creator_name !== ''): ?><small><?php echo e($backup_creator_name); ?></small><?php endif; ?>
                                    </div>
                                    <div class="admin-row-actions">
                                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>"><input type="hidden" name="download_type" value="bundle"><button type="submit" name="download_backup" class="td-tool-btn" title="<?php echo e(t('Download backup package')); ?>"><?php echo get_icon('download', 'w-3.5 h-3.5'); ?></button></form>
                                        <?php if (!empty($backup['has_database'])): ?>
                                            <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>"><input type="hidden" name="download_type" value="database"><button type="submit" name="download_backup" class="td-tool-btn" title="<?php echo e(t('Download database SQL')); ?>"><?php echo get_icon('file-alt', 'w-3.5 h-3.5'); ?></button></form>
                                        <?php endif; ?>
                                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>"><button type="submit" name="rollback_update" class="td-tool-btn" title="<?php echo e(t('Restore')); ?>" onclick="return confirm('<?php echo e(t('Restore this backup? Current files will be overwritten.')); ?>')"><?php echo get_icon('refresh', 'w-3.5 h-3.5'); ?></button></form>
                                        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>"><button type="submit" name="delete_backup" class="td-tool-btn" style="color: var(--danger);" title="<?php echo e(t('Delete')); ?>" onclick="return confirm('<?php echo e(t('Delete this backup permanently?')); ?>')"><?php echo get_icon('trash', 'w-3.5 h-3.5'); ?></button></form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="admin-empty"><?php echo e(t('No backups yet.')); ?></p>
                    <?php endif; ?>
                </section>

                <section class="admin-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Board and uploads')); ?></h3>
                            <p><?php echo e(t('Small operational defaults.')); ?></p>
                        </div>
                    </div>
                    <form method="post" class="admin-form-grid">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="save_kanban_settings" value="1">
                        <label>
                            <span><?php echo e(t('Hide completed cards after')); ?></span>
                            <div class="admin-input-row">
                                <input type="number" name="kanban_hide_closed_after_days" min="1" max="365"
                                    value="<?php echo (int) (function_exists('get_kanban_closed_archive_days') ? get_kanban_closed_archive_days() : 7); ?>"
                                    class="form-input form-input-sm">
                                <small><?php echo e(t('days')); ?></small>
                            </div>
                        </label>
                        <button type="submit" class="btn btn-secondary btn-sm"><?php echo e(t('Save board')); ?></button>
                    </form>
                    <form method="post" class="admin-form-grid">
                        <?php echo csrf_field(); ?>
                        <label>
                            <span><?php echo e(t('Max upload size (MB)')); ?></span>
                            <input type="number" name="max_upload_size"
                                value="<?php echo e($settings['max_upload_size'] ?? '10'); ?>" min="1" max="100" class="form-input form-input-sm">
                        </label>
                        <button type="submit" name="save_upload_settings" class="btn btn-secondary btn-sm"><?php echo e(t('Save uploads')); ?></button>
                    </form>
                    <p class="admin-help"><?php echo e(t('PHP limit is {limit}. Files above the PHP limit will fail regardless of this setting.', ['limit' => ini_get('upload_max_filesize')])); ?></p>
                </section>
            </div>

            <?php if (!empty($update_history)): ?>
                <section class="admin-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Update history')); ?></h3>
                            <p><?php echo e(t('Last 10 update, rollback, and backup events.')); ?></p>
                        </div>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="clear_update_history" class="btn btn-ghost btn-sm"
                                onclick="return confirm('<?php echo e(t('Clear all update history?')); ?>')">
                                <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                <?php echo e(t('Clear')); ?>
                            </button>
                        </form>
                    </div>
                    <div class="admin-history-list">
                        <?php foreach (array_slice($update_history, 0, 10) as $entry): ?>
                            <div class="admin-history-row">
                                <span class="admin-status <?php echo !empty($entry['success']) ? 'is-success' : 'is-danger'; ?>">
                                    <?php echo e(!empty($entry['success']) ? t('success') : t('failed')); ?>
                                </span>
                                <div>
                                    <strong>
                                        <?php
                                        if (($entry['action'] ?? '') === 'update') {
                                            echo e(t('Updated to {version}', ['version' => $entry['version'] ?? '-']));
                                        } elseif (($entry['action'] ?? '') === 'rollback') {
                                            echo e(t('Rolled back to {version}', ['version' => $entry['version'] ?? '-']));
                                        } else {
                                            echo e(t('Backup created: {version}', ['version' => $entry['version'] ?? '-']));
                                        }
                                        ?>
                                    </strong>
                                    <span><?php echo e(date('Y-m-d H:i', strtotime($entry['date'] ?? 'now'))); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const uploadZone = document.getElementById('update-upload-zone');
            const fileInput = document.getElementById('update-file-input');
            const fileName = document.getElementById('update-file-name');
            const uploadBtn = document.getElementById('update-upload-btn');

            if (!uploadZone || !fileInput) return;

            // Click to open file dialog
            uploadZone.addEventListener('click', function (e) {
                if (e.target !== uploadBtn && !uploadBtn.contains(e.target)) {
                    fileInput.click();
                }
            });

            // Handle file selection
            fileInput.addEventListener('change', function () {
                if (this.files.length > 0) {
                    fileName.textContent = this.files[0].name;
                    uploadBtn.disabled = false;
                } else {
                    fileName.textContent = '<?php echo e(t('No file selected')); ?>';
                    uploadBtn.disabled = true;
                }
            });

            // Drag and drop
            uploadZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                uploadZone.classList.add('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('dragleave', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('drop', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (file.name.endsWith('.zip')) {
                        fileInput.files = files;
                        fileName.textContent = file.name;
                        uploadBtn.disabled = false;
                    } else {
                        if (typeof window.showAppToast === 'function') {
                            window.showAppToast('<?php echo e(t('Please select a .zip file')); ?>', 'error');
                        } else {
                            alert('<?php echo e(t('Please select a .zip file')); ?>');
                        }
                    }
                }
            });
        });
    </script>
