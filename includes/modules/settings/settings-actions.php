<?php
/**
 * Settings POST action router.
 */

function settings_handle_post_request(callable $settings_audit): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    require_csrf_token();

    // Save 2FA security settings
    if (isset($_POST['save_2fa_settings'])) {
        save_setting('2fa_required_user', !empty($_POST['2fa_required_user']) ? '1' : '0');
        save_setting('2fa_required_agent', !empty($_POST['2fa_required_agent']) ? '1' : '0');
        save_setting('2fa_required_admin', !empty($_POST['2fa_required_admin']) ? '1' : '0');
        $settings_audit('2fa_settings_changed', [
            'user' => !empty($_POST['2fa_required_user']) ? '1' : '0',
            'agent' => !empty($_POST['2fa_required_agent']) ? '1' : '0',
            'admin' => !empty($_POST['2fa_required_admin']) ? '1' : '0',
        ]);
        flash(t('Security settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'security']);
    }

    if (isset($_POST['clear_logs'])) {
        db_query("TRUNCATE TABLE debug_log");
        $settings_audit('debug_logs_cleared', [], 'warning');
        flash(t('Logs cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'logs']);
    }

    if (isset($_POST['clear_security_logs'])) {
        if (security_log_table_exists()) {
            db_query("TRUNCATE TABLE security_log");
        }
        $settings_audit('security_logs_cleared', [], 'warning');
        flash(t('Security logs cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'logs']);
    }

    if (isset($_POST['clear_update_history'])) {
        save_setting('update_history', '[]');
        save_setting('update_changelog_cache', '{}');
        $settings_audit('update_history_cleared', [], 'warning');
        flash(t('Update history cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Check for updates now (force)
    if (isset($_POST['check_updates_now'])) {
        $update_result = check_for_updates(true);
        if ($update_result) {
            flash(t('FoxDesk {version} is available!', ['version' => $update_result['version']]), 'info');
        } else {
            flash(t('You are running the latest version.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save auto-update check setting
    if (isset($_POST['save_update_check_settings'])) {
        $enabled = !empty($_POST['update_check_enabled']) ? '1' : '0';
        save_setting('update_check_enabled', $enabled);
        $settings_audit('update_check_settings_changed', ['enabled' => $enabled]);
        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    if (isset($_POST['save_kanban_settings'])) {
        $hide_closed_after_days = (int) ($_POST['kanban_hide_closed_after_days'] ?? 7);
        $hide_closed_after_days = max(1, min(365, $hide_closed_after_days));

        save_setting('kanban_hide_closed_after_days', (string) $hide_closed_after_days);
        $settings_audit('kanban_settings_changed', [
            'hide_closed_after_days' => $hide_closed_after_days,
        ]);
        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save pseudo-cron setting
    if (isset($_POST['save_pseudo_cron_settings'])) {
        $enabled = !empty($_POST['pseudo_cron_enabled']) ? '1' : '0';
        save_setting('pseudo_cron_enabled', $enabled);

        // Generate secret token on first enable
        if ($enabled === '1' && !get_setting('pseudo_cron_secret')) {
            save_setting('pseudo_cron_secret', bin2hex(random_bytes(20)));
        }

        $settings_audit('pseudo_cron_settings_changed', ['enabled' => $enabled]);
        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // One-click remote install: download + validate + apply
    if (isset($_POST['install_remote_update'])) {
        $update_info = get_cached_update_info();
        if (!$update_info || empty($update_info['download_url'])) {
            flash(t('No update available to download.'), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $local_path = download_remote_update($update_info['download_url']);
        if ($local_path === false) {
            flash(t('Failed to download update package. Please try again or upload manually.'), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $validation = validate_update_package($local_path);
        if (!$validation['valid']) {
            @unlink($local_path);
            $error_msg = $validation['error'] ?? implode(', ', $validation['errors'] ?? []);
            flash(t('Downloaded package is invalid: {error}', ['error' => $error_msg]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $settings_audit('remote_update_downloaded', ['version' => $validation['version']]);

        // Apply immediately
        try {
            $result = apply_update($local_path);
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => [$e->getMessage()],
                'backup_id' => null
            ];
            error_log('settings install_remote_update fatal: ' . $e->getMessage());
        }

        @unlink($local_path);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (function_exists('opcache_invalidate')) {
            foreach ([
                BASE_PATH . '/index.php',
                BASE_PATH . '/includes/header.php',
                BASE_PATH . '/includes/footer.php',
                BASE_PATH . '/includes/functions.php',
                BASE_PATH . '/includes/update-functions.php',
                BASE_PATH . '/pages/admin/settings.php',
            ] as $f) {
                @opcache_invalidate($f, true);
            }
        }

        if ($result['success']) {
            $settings_audit('update_applied_from_settings', [
                'backup_id' => $result['backup_id'] ?? null,
                'new_version' => $result['new_version'] ?? null,
            ]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['flash'] = [
                    'message' => t('Update installed successfully! Backup: {backup}', ['backup' => $result['backup_id']]),
                    'type' => 'success'
                ];
                session_write_close();
            }
            $redirect_url = url('admin', ['section' => 'settings', 'tab' => 'system']);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
            echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redirect_url) . '">';
            echo '<title>' . e(t('Updating...')) . '</title>';
            echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
            echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
            echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
            echo '<body><div class="box"><div class="spinner"></div>';
            echo '<div style="font-weight:600;font-size:1.1rem">' . e(t('Update complete')) . '</div>';
            echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">' . e(t('Redirecting...')) . '</div>';
            echo '</div></body></html>';
            exit;
        } else {
            $settings_audit('update_apply_failed_from_settings', [
                'error' => $result['error'] ?? '',
            ], 'error');
            flash(t('Update failed: {error}', ['error' => $result['error']]), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    if (isset($_POST['download_backup'])) {
        $backup_id = trim((string) ($_POST['backup_id'] ?? ''));
        $download_type = trim((string) ($_POST['download_type'] ?? 'bundle'));
        $download = prepare_backup_download($backup_id, $download_type);
        if (!$download['success']) {
            flash(t('Backup download failed: {error}', ['error' => $download['error'] ?? t('Unknown error')]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $settings_audit('backup_download', [
            'backup_id' => $backup_id,
            'type' => $download_type,
            'filename' => $download['filename'],
        ]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $path = (string) $download['path'];
        $filename = basename((string) $download['filename']);
        $mime = (string) ($download['mime'] ?? 'application/octet-stream');
        if (!file_exists($path) || !is_readable($path)) {
            if (!empty($download['cleanup'])) {
                @unlink($path);
            }
            flash(t('Backup download failed: {error}', ['error' => t('Backup file not available.')]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-transform, no-store, must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        readfile($path);

        if (!empty($download['cleanup'])) {
            @unlink($path);
        }
        exit;
    }

    // Handle update package upload
    if (isset($_POST['upload_update'])) {
        if (!empty($_FILES['update_package']['name']) && $_FILES['update_package']['error'] === UPLOAD_ERR_OK) {
            $tmp_path = $_FILES['update_package']['tmp_name'];
            $validation = validate_update_package($tmp_path);

            if ($validation['valid']) {
                // Store temp file info in session for confirmation
                $temp_dir = sys_get_temp_dir();
                $temp_file = $temp_dir . '/update_' . uniqid() . '.zip';
                move_uploaded_file($tmp_path, $temp_file);

                $_SESSION['pending_update'] = [
                    'file' => $temp_file,
                    'version' => $validation['version'],
                    'changelog' => $validation['changelog'] ?? [],
                    'uploaded_at' => time()
                ];

                $settings_audit('update_package_validated', [
                    'version' => $validation['version'],
                    'filename' => $_FILES['update_package']['name'] ?? '',
                ]);
                flash(t('Update package validated. Please confirm to apply.'), 'success');
            } else {
                $validation_error = trim((string) ($validation['error'] ?? ''));
                if ($validation_error === '' && !empty($validation['errors']) && is_array($validation['errors'])) {
                    $validation_error = implode(' | ', array_values(array_filter($validation['errors'], static function ($item) {
                        return is_string($item) && trim($item) !== '';
                    })));
                }
                if ($validation_error === '') {
                    $validation_error = t('Unknown error');
                }
                $settings_audit('update_package_validation_failed', [
                    'filename' => $_FILES['update_package']['name'] ?? '',
                    'error' => $validation_error,
                ], 'warning');
                flash(t('Invalid update package: {error}', ['error' => $validation_error]), 'error');
            }
        } else {
            flash(t('Please select an update package file.'), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Apply pending update
    if (isset($_POST['apply_update'])) {
        if (!empty($_SESSION['pending_update']['file']) && file_exists($_SESSION['pending_update']['file'])) {
            try {
                $result = apply_update($_SESSION['pending_update']['file']);
            } catch (Throwable $e) {
                $result = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'errors' => [$e->getMessage()],
                    'backup_id' => null
                ];
                error_log('settings apply_update fatal: ' . $e->getMessage());
            }

            // Cleanup temp file
            @unlink($_SESSION['pending_update']['file']);
            unset($_SESSION['pending_update']);

            // Aggressively flush opcache BEFORE any further code runs.
            // Older update-functions.php may not do this, so we ensure it here.
            // This prevents HTTP 500 when the next request loads stale cached bytecode.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            // Per-file invalidation for critical files that will be required next
            if (function_exists('opcache_invalidate')) {
                $critical_files = [
                    BASE_PATH . '/index.php',
                    BASE_PATH . '/includes/header.php',
                    BASE_PATH . '/includes/footer.php',
                    BASE_PATH . '/includes/functions.php',
                    BASE_PATH . '/includes/update-functions.php',
                    BASE_PATH . '/pages/admin/settings.php',
                ];
                foreach ($critical_files as $f) {
                    @opcache_invalidate($f, true);
                }
            }

            if ($result['success']) {
                $settings_audit('update_applied_from_settings', [
                    'backup_id' => $result['backup_id'] ?? null,
                    'new_version' => $result['new_version'] ?? null,
                ]);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['flash'] = [
                        'message' => t('Update applied successfully! Backup created: {backup}', ['backup' => $result['backup_id']]),
                        'type' => 'success'
                    ];
                    session_write_close();
                }

                // Output a minimal HTML redirect page and exit immediately.
                // This avoids loading any changed PHP files (header.php, footer.php)
                // which would crash because the in-memory code is old but files on disk are new.
                // NOTE: Newer apply_update() exits before reaching here.
                // This is a safety net for older update-functions.php versions.
                $redirect_url = url('admin', ['section' => 'settings', 'tab' => 'system']);
                echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
                echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redirect_url) . '">';
                echo '<title>' . e(t('Updating...')) . '</title>';
                echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
                echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
                echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
                echo '<body><div class="box"><div class="spinner"></div>';
                echo '<div style="font-weight:600;font-size:1.1rem">' . e(t('Update complete')) . '</div>';
                echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">' . e(t('Redirecting...')) . '</div>';
                echo '</div></body></html>';
                exit;
            } else {
                $settings_audit('update_apply_failed_from_settings', [
                    'error' => $result['error'] ?? '',
                ], 'error');
                flash(t('Update failed: {error}', ['error' => $result['error']]), 'error');
            }
        } else {
            flash(t('No pending update found.'), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Cancel pending update
    if (isset($_POST['cancel_update'])) {
        if (!empty($_SESSION['pending_update']['file'])) {
            @unlink($_SESSION['pending_update']['file']);
        }
        unset($_SESSION['pending_update']);
        $settings_audit('update_cancelled');
        flash(t('Update cancelled.'), 'info');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Rollback to backup
    if (isset($_POST['rollback_update'])) {
        $backup_id = $_POST['backup_id'] ?? '';
        if ($backup_id) {
            $result = rollback_update($backup_id);
            if ($result['success']) {
                $settings_audit('backup_restored_from_settings', ['backup_id' => $backup_id]);
                flash(t('Rollback completed successfully.'), 'success');
            } else {
                $settings_audit('backup_restore_failed_from_settings', ['backup_id' => $backup_id, 'error' => $result['error'] ?? ''], 'error');
                flash(t('Rollback failed: {error}', ['error' => $result['error']]), 'error');
            }
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Delete backup
    if (isset($_POST['delete_backup'])) {
        $backup_id = $_POST['backup_id'] ?? '';
        if ($backup_id) {
            $result = delete_backup($backup_id);
            if ($result['success']) {
                $settings_audit('backup_deleted_from_settings', ['backup_id' => $backup_id], 'warning');
                flash(t('Backup deleted.'), 'success');
            } else {
                $settings_audit('backup_delete_failed_from_settings', ['backup_id' => $backup_id, 'error' => $result['error'] ?? ''], 'error');
                flash(t('Failed to delete backup: {error}', ['error' => $result['error']]), 'error');
            }
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Create manual backup
    if (isset($_POST['create_backup'])) {
        $result = create_backup();
        if ($result['success']) {
            $settings_audit('manual_backup_created', ['backup_id' => $result['backup_id'] ?? null]);
            flash(t('Backup created successfully: {backup}', ['backup' => $result['backup_id']]), 'success');
        } else {
            $error_message = $result['error'] ?? implode(', ', (array) ($result['errors'] ?? []));
            $settings_audit('manual_backup_failed', ['error' => $error_message], 'error');
            flash(t('Failed to create backup: {error}', ['error' => $error_message]), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save upload settings
    if (isset($_POST['save_upload_settings'])) {
        $max_upload_size = (int) ($_POST['max_upload_size'] ?? 10);
        $max_upload_size = max(1, min(100, $max_upload_size)); // 1MB to 100MB
        save_setting('max_upload_size', (string) $max_upload_size);
        flash(t('Upload settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Update general settings
    if (isset($_POST['save_general'])) {
        $app_name = trim($_POST['app_name'] ?? 'FoxDesk');
        $ticket_prefix = trim($_POST['ticket_prefix'] ?? 'TK');
        $app_language = strtolower(trim($_POST['app_language'] ?? 'en'));
        if (!in_array($app_language, ['en', 'cs', 'de', 'it', 'es'], true)) {
            $app_language = 'en';
        }
        $time_format = trim($_POST['time_format'] ?? '24');
        if (!in_array($time_format, ['12', '24'], true)) {
            $time_format = '24';
        }
        $currency = trim($_POST['currency'] ?? 'CZK');
        if ($currency === '') {
            $currency = 'CZK';
        }
        $billing_rounding = (int) ($_POST['billing_rounding'] ?? 1);
        $rounding_allowed = [1, 5, 10, 15, 30, 60];
        if (!in_array($billing_rounding, $rounding_allowed, true)) {
            $billing_rounding = 1;
        }
        save_setting('app_name', $app_name);
        save_setting('ticket_prefix', strtoupper(preg_replace('/[^A-Za-z]/', '', $ticket_prefix)) ?: 'TK');
        save_setting('login_welcome_text', trim($_POST['login_welcome_text'] ?? ''));
        save_setting('app_language', $app_language);
        $_SESSION['lang'] = $app_language;
        unset($_SESSION['lang_override']);
        save_setting('time_format', $time_format);
        save_setting('currency', $currency);
        save_setting('billing_rounding', (string) $billing_rounding);

        // Time tracking alert settings
        save_setting('timer_alert_enabled', isset($_POST['timer_alert_enabled']) ? '1' : '0');
        $timer_alert_hours = max(1, min(24, (int) ($_POST['timer_alert_hours'] ?? 3)));
        save_setting('timer_alert_hours', (string) $timer_alert_hours);
        save_setting('timer_alert_email', isset($_POST['timer_alert_email']) ? '1' : '0');

        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings']);
    }

    // Handle favicon upload
    if (isset($_POST['save_favicon'])) {
        if (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/ico', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['favicon']['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed)) {
                $ext = pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
                $filename = 'favicon.' . ($ext ?: 'ico');
                $upload_path = BASE_PATH . '/uploads/' . $filename;

                if (move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_path)) {
                    save_setting('favicon', 'uploads/' . $filename . '?v=' . time());
                    flash(t('Favicon uploaded successfully.'), 'success');
                } else {
                    flash(t('Failed to upload favicon.'), 'error');
                }
            } else {
                flash(t('Invalid file type. Please upload a PNG, ICO, or GIF image.'), 'error');
            }
        } elseif (isset($_POST['remove_favicon'])) {
            // Remove custom favicon
            $current = get_setting('favicon', '');
            if ($current && file_exists(BASE_PATH . '/' . explode('?', $current)[0])) {
                @unlink(BASE_PATH . '/' . explode('?', $current)[0]);
            }
            save_setting('favicon', '');
            flash(t('Favicon removed.'), 'success');
        }
        redirect('admin', ['section' => 'settings']);
    }

    // Handle app logo upload
    if (isset($_POST['save_app_logo'])) {
        if (!empty($_FILES['app_logo']['name']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
            try {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
                $result = upload_file($_FILES['app_logo'], $allowed, 2 * 1024 * 1024, 'public');
                // Delete old logo
                $current = get_setting('app_logo', '');
                if ($current) {
                    $old_path = function_exists('upload_absolute_path') ? upload_absolute_path($current) : (BASE_PATH . '/' . explode('?', $current)[0]);
                    if ($old_path && file_exists($old_path)) {
                        @unlink($old_path);
                    }
                }
                save_setting('app_logo', UPLOAD_DIR . $result['filename'] . '?v=' . time());
                flash(t('Logo uploaded successfully.'), 'success');
            } catch (Exception $e) {
                flash($e->getMessage(), 'error');
            }
        } elseif (isset($_POST['remove_app_logo'])) {
            $current = get_setting('app_logo', '');
            if ($current) {
                $old_path = function_exists('upload_absolute_path') ? upload_absolute_path($current) : (BASE_PATH . '/' . explode('?', $current)[0]);
                if ($old_path && file_exists($old_path)) {
                    @unlink($old_path);
                }
            }
            save_setting('app_logo', '');
            flash(t('Logo removed.'), 'success');
        }
        redirect('admin', ['section' => 'settings']);
    }

    // Update email settings OR test/run SMTP/IMAP (all save first)
    if (isset($_POST['save_email']) || isset($_POST['test_smtp']) || isset($_POST['test_imap']) || isset($_POST['run_imap_now'])) {
        // Always save settings first
        save_setting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        save_setting('smtp_port', trim($_POST['smtp_port'] ?? '587'));
        save_setting('smtp_user', trim($_POST['smtp_user'] ?? ''));

        if (!empty($_POST['smtp_pass'])) {
            save_setting('smtp_pass', $_POST['smtp_pass']);
        }

        save_setting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        save_setting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        save_setting('smtp_encryption', $_POST['smtp_encryption'] ?? 'tls');
        save_setting('email_notifications_enabled', isset($_POST['email_notifications_enabled']) ? '1' : '0');
        save_setting('notify_on_status_change', isset($_POST['notify_on_status_change']) ? '1' : '0');
        save_setting('notify_on_new_comment', isset($_POST['notify_on_new_comment']) ? '1' : '0');
        save_setting('notify_on_new_ticket', isset($_POST['notify_on_new_ticket']) ? '1' : '0');

        $imap_encryption = strtolower(trim((string) ($_POST['imap_encryption'] ?? 'ssl')));
        if (!in_array($imap_encryption, ['ssl', 'tls', 'none'], true)) {
            $imap_encryption = 'ssl';
        }
        $imap_port = (int) ($_POST['imap_port'] ?? 993);
        if ($imap_port < 1 || $imap_port > 65535) {
            $imap_port = 993;
        }
        $imap_max_per_run = max(1, min(500, (int) ($_POST['imap_max_emails_per_run'] ?? 50)));
        $imap_max_attachment_mb = max(1, min(100, (int) ($_POST['imap_max_attachment_size_mb'] ?? 10)));

        save_setting('imap_enabled', isset($_POST['imap_enabled']) ? '1' : '0');
        save_setting('imap_host', trim((string) ($_POST['imap_host'] ?? '')));
        save_setting('imap_port', (string) $imap_port);
        save_setting('imap_encryption', $imap_encryption);
        save_setting('imap_username', trim((string) ($_POST['imap_username'] ?? '')));
        if (!empty($_POST['imap_password'])) {
            save_setting('imap_password', (string) $_POST['imap_password']);
        }
        save_setting('imap_folder', trim((string) ($_POST['imap_folder'] ?? 'INBOX')));
        save_setting('imap_processed_folder', trim((string) ($_POST['imap_processed_folder'] ?? 'Processed')));
        save_setting('imap_failed_folder', trim((string) ($_POST['imap_failed_folder'] ?? 'Failed')));
        save_setting('imap_max_emails_per_run', (string) $imap_max_per_run);
        save_setting('imap_max_attachment_size_mb', (string) $imap_max_attachment_mb);
        save_setting('imap_validate_cert', isset($_POST['imap_validate_cert']) ? '1' : '0');
        save_setting('imap_mark_seen_on_skip', isset($_POST['imap_mark_seen_on_skip']) ? '1' : '0');
        save_setting('imap_allow_unknown_senders', isset($_POST['imap_allow_unknown_senders']) ? '1' : '0');
        save_setting('imap_storage_base', trim((string) ($_POST['imap_storage_base'] ?? 'storage/tickets')));

        // If test SMTP requested
        if (isset($_POST['test_smtp'])) {
            require_once BASE_PATH . '/includes/mailer.php';

            // Use saved password if not provided
            $test_pass = !empty($_POST['smtp_pass']) ? $_POST['smtp_pass'] : get_setting('smtp_pass', '');

            $result = test_smtp_connection([
                'host' => trim($_POST['smtp_host'] ?? ''),
                'port' => (int) trim($_POST['smtp_port'] ?? '587'),
                'user' => trim($_POST['smtp_user'] ?? ''),
                'pass' => $test_pass,
                'encryption' => $_POST['smtp_encryption'] ?? 'tls'
            ]);

            if ($result['success']) {
                flash(t('Settings saved. {message}', ['message' => $result['message']]), 'success');
            } else {
                flash(t('Settings saved, but the test failed: {message}', ['message' => $result['message']]), 'error');
            }
        } elseif (isset($_POST['test_imap'])) {
            require_once BASE_PATH . '/includes/email-ingest-functions.php';

            $imap_password = !empty($_POST['imap_password']) ? (string) $_POST['imap_password'] : get_setting('imap_password', '');
            $imap_folder = trim((string) ($_POST['imap_folder'] ?? 'INBOX'));
            if ($imap_folder === '') {
                $imap_folder = 'INBOX';
            }

            try {
                $result = email_ingest_test_connection([
                    'enabled' => true,
                    'host' => trim((string) ($_POST['imap_host'] ?? '')),
                    'port' => $imap_port,
                    'encryption' => $imap_encryption,
                    'username' => trim((string) ($_POST['imap_username'] ?? '')),
                    'password' => $imap_password,
                    'folder' => $imap_folder,
                ]);

                flash(t('Settings saved. IMAP connection successful. Mailbox {mailbox} contains {count} messages.', [
                    'mailbox' => $result['mailbox'],
                    'count' => (string) $result['messages'],
                ]), 'success');
            } catch (Throwable $e) {
                flash(t('Settings saved, but IMAP test failed: {message}', ['message' => $e->getMessage()]), 'error');
            }
        } elseif (isset($_POST['run_imap_now'])) {
            require_once BASE_PATH . '/includes/email-ingest-functions.php';

            try {
                $result = email_ingest_run();
                if (!empty($result['disabled'])) {
                    flash(t('Settings saved, but IMAP run is disabled. Enable incoming email processing first.'), 'error');
                } else {
                    $failed_count = (int) ($result['failed'] ?? 0);
                    flash(
                        t(
                            'Settings saved. IMAP run completed: checked {checked}, processed {processed}, skipped {skipped}, failed {failed}.',
                            [
                                'checked' => (string) ((int) ($result['checked'] ?? 0)),
                                'processed' => (string) ((int) ($result['processed'] ?? 0)),
                                'skipped' => (string) ((int) ($result['skipped'] ?? 0)),
                                'failed' => (string) $failed_count,
                            ]
                        ),
                        $failed_count > 0 ? 'error' : 'success'
                    );
                }
            } catch (Throwable $e) {
                flash(t('Settings saved, but IMAP run failed: {message}', ['message' => $e->getMessage()]), 'error');
            }
        } else {
            flash(t('Email settings saved.'), 'success');
        }

        redirect('admin', ['section' => 'settings', 'tab' => 'email']);
    }

    // Update email template
    if (isset($_POST['save_template'])) {
        $key = $_POST['template_key'] ?? '';
        $subject = trim($_POST['template_subject'] ?? '');
        $body = trim($_POST['template_body'] ?? '');
        $lang = strtolower(trim((string) ($_POST['template_lang'] ?? 'en')));
        if (!in_array($lang, ['en', 'cs', 'de', 'it', 'es'], true)) {
            $lang = 'en';
        }

        if (!empty($key) && !empty($subject) && !empty($body)) {
            require_once BASE_PATH . '/includes/mailer.php';
            try {
                save_email_template($key, $subject, $body, $lang);
                flash(t('Template saved.'), 'success');
            } catch (Throwable $e) {
                flash(t('Failed to save template: {error}', ['error' => $e->getMessage()]), 'error');
            }
        }

        redirect('admin', ['section' => 'settings', 'tab' => 'templates', 'lang' => $lang]);
    }
}
