        <!-- Email Settings -->
        <div class="space-y-3">
            <form method="post">
                <?php echo csrf_field(); ?>
                <?php include BASE_PATH . '/includes/modules/settings/views/microsoft-mail.php'; ?>
                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-1 text-theme-primary"><?php echo e(t('Outgoing email')); ?>
                    </h3>
                    <p class="text-sm mb-4 text-theme-muted">
                        <?php echo e(t('Use your mail server to send ticket and account emails.')); ?>
                    </p>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('SMTP server')); ?></label>
                                <input type="text" name="smtp_host" value="<?php echo e($settings['smtp_host'] ?? ''); ?>"
                                    placeholder="smtp.gmail.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Port')); ?></label>
                                <input type="number" name="smtp_port"
                                    value="<?php echo e($settings['smtp_port'] ?? '587'); ?>" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Username')); ?></label>
                                <input type="text" name="smtp_user" value="<?php echo e($settings['smtp_user'] ?? ''); ?>"
                                    placeholder="user@gmail.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Password')); ?></label>
                                <input type="password" name="smtp_pass"
                                    placeholder="<?php echo empty($settings['smtp_pass']) ? '' : '********'; ?>"
                                    class="form-input">
                                <p class="text-xs mt-1 text-theme-muted">
                                    <?php echo e(t('Leave blank to keep current password.')); ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('From email')); ?></label>
                                <input type="email" name="smtp_from_email"
                                    value="<?php echo e($settings['smtp_from_email'] ?? ''); ?>" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('From name')); ?></label>
                                <input type="text" name="smtp_from_name"
                                    value="<?php echo e($settings['smtp_from_name'] ?? ''); ?>" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Encryption')); ?></label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>><?php echo e(t('TLS (port 587)')); ?></option>
                                <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>><?php echo e(t('SSL (port 465)')); ?></option>
                                <option value="" <?php echo empty($settings['smtp_encryption']) ? 'selected' : ''; ?>>
                                    <?php echo e(t('None (port 25)')); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card card-body mb-2">
                    <div class="mb-4">
                        <h3 class="font-semibold text-theme-primary">
                            <?php echo e(t('Incoming email')); ?>
                        </h3>
                        <p class="text-sm mt-1 text-theme-muted">
                            <?php echo e(t('Use this mailbox to create or update tickets from incoming emails.')); ?>
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                        <label class="flex items-center gap-3">
                                <input type="checkbox" name="imap_enabled" <?php echo ($imap_view['enabled'] ?? '0') === '1' ? 'checked' : ''; ?> class="w-5 h-5 rounded text-blue-500 focus:ring-blue-500">
                                <span class="font-medium text-theme-primary"><?php echo e(t('Enable incoming email processing')); ?></span>
                            </label>
                            <p class="text-xs ml-8 mt-1 text-theme-muted">
                                <?php echo e(t('When enabled, the system will automatically create tickets from incoming emails. Requires a cron job or background tasks to be active.')); ?>
                            </p>
                            <?php if (!$imap_extension_loaded): ?>
                                <div class="mt-4 rounded-lg border p-4 text-sm" style="border-color: rgba(217, 119, 6, 0.35); background: rgba(217, 119, 6, 0.08); color: var(--text-secondary);">
                                    <div class="font-semibold mb-1 text-theme-primary">
                                        <?php echo e(t('PHP IMAP extension is not loaded.')); ?>
                                    </div>
                                    <p class="mb-2">
                                        <?php echo e(t('Incoming email processing cannot run until the php-imap extension is installed and PHP is restarted.')); ?>
                                    </p>
                                    <code class="block text-xs p-2 rounded" style="background: var(--surface-secondary); color: var(--text-primary);">sudo apt install php-imap &amp;&amp; sudo systemctl restart apache2</code>
                                    <p class="mt-2 text-xs text-theme-muted">
                                        <?php echo e(t('On shared hosting, ask your provider to enable the PHP IMAP extension for this domain.')); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('IMAP server')); ?></label>
                                <input type="text" name="imap_host" value="<?php echo e($imap_view['host'] ?? ''); ?>"
                                    placeholder="imap.example.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('IMAP port')); ?></label>
                                <input type="number" name="imap_port" value="<?php echo e($imap_view['port'] ?? '993'); ?>"
                                    class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('IMAP username')); ?></label>
                                <input type="text" name="imap_username"
                                    value="<?php echo e($imap_view['username'] ?? ''); ?>" placeholder="support@example.com"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('IMAP password')); ?></label>
                                <input type="password" name="imap_password"
                                    placeholder="<?php echo !empty($imap_view['password_set']) ? '********' : ''; ?>"
                                    class="form-input">
                                <p class="text-xs mt-1 text-theme-muted">
                                    <?php echo e(t('Leave blank to keep current IMAP password.')); ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('IMAP encryption')); ?></label>
                                <select name="imap_encryption" class="form-select">
                                    <option value="ssl" <?php echo ($imap_view['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>><?php echo e(t('SSL')); ?></option>
                                    <option value="tls" <?php echo ($imap_view['encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>><?php echo e(t('TLS')); ?></option>
                                    <option value="none" <?php echo ($imap_view['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>><?php echo e(t('None')); ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Mailbox folder')); ?></label>
                                <input type="text" name="imap_folder"
                                    value="<?php echo e($imap_view['folder'] ?? 'INBOX'); ?>" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Processed folder')); ?></label>
                                <input type="text" name="imap_processed_folder"
                                    value="<?php echo e($imap_view['processed_folder'] ?? 'Processed'); ?>"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Failed folder')); ?></label>
                                <input type="text" name="imap_failed_folder"
                                    value="<?php echo e($imap_view['failed_folder'] ?? 'Failed'); ?>" class="form-input">
                            </div>
                        </div>

                        <details class="rounded-lg border p-3" style="border-color: var(--border-color);">
                            <summary class="cursor-pointer font-medium text-theme-primary">
                                <?php echo e(t('Advanced')); ?>
                            </summary>
                            <div class="space-y-4 mt-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Max emails per run')); ?></label>
                                        <input type="number" min="1" max="500" name="imap_max_emails_per_run"
                                            value="<?php echo e($imap_view['max_emails_per_run'] ?? '50'); ?>" class="form-input">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Max attachment size (MB)')); ?></label>
                                        <input type="number" min="1" max="100" name="imap_max_attachment_size_mb"
                                            value="<?php echo e($imap_view['max_attachment_size_mb'] ?? '10'); ?>"
                                            class="form-input">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Attachment storage path')); ?></label>
                                        <input type="text" name="imap_storage_base"
                                            value="<?php echo e($imap_view['storage_base'] ?? 'storage/tickets'); ?>"
                                            class="form-input">
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label class="flex items-center gap-3">
                                        <input type="checkbox" name="imap_validate_cert" <?php echo ($imap_view['validate_cert'] ?? '0') === '1' ? 'checked' : ''; ?>
                                            class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                        <span class="text-theme-secondary"><?php echo e(t('Validate TLS certificate')); ?></span>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input type="checkbox" name="imap_mark_seen_on_skip" <?php echo ($imap_view['mark_seen_on_skip'] ?? '1') === '1' ? 'checked' : ''; ?>
                                            class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                        <span class="text-theme-secondary"><?php echo e(t('Mark skipped emails as seen')); ?></span>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input type="checkbox" name="imap_allow_unknown_senders" <?php echo ($imap_view['allow_unknown_senders'] ?? '0') === '1' ? 'checked' : ''; ?>
                                            class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                        <span class="text-theme-secondary"><?php echo e(t('Allow unknown senders (without allowlist)')); ?></span>
                                    </label>
                                    <p class="text-xs ml-7 mt-0.5 text-theme-warning">
                                        <?php echo e(t('When enabled, anyone can create tickets by sending an email — not just addresses in the allowlist below.')); ?>
                                    </p>
                                </div>

                                <p class="text-xs text-theme-muted">
                                    <?php echo e(t('Cron command: php bin/ingest-emails.php')); ?>
                                </p>
                            </div>
                        </details>
                    </div>
                </div>

                <!-- Allowed Senders -->
                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-2 text-theme-primary">
                        <?php echo e(t('Allowed Senders')); ?>
                    </h3>

                    <!-- Add sender form -->
                    <div class="flex flex-wrap gap-2 mb-4 items-end">
                        <div>
                            <label class="block text-xs mb-1 text-theme-secondary"><?php echo e(t('Type')); ?></label>
                            <select id="as-type" class="input-field text-sm" style="width: auto; min-width: 120px;">
                                <option value="email"><?php echo e(t('Email')); ?></option>
                                <option value="domain"><?php echo e(t('Domain')); ?></option>
                            </select>
                        </div>
                        <div class="flex-1" style="min-width: 200px;">
                            <label class="block text-xs mb-1 text-theme-secondary"><?php echo e(t('Email or Domain')); ?></label>
                            <input type="text" id="as-value" class="input-field text-sm" placeholder="user@example.com">
                        </div>
                        <div>
                            <label class="block text-xs mb-1 text-theme-secondary"><?php echo e(t('Assign to user')); ?></label>
                            <select id="as-user" class="input-field text-sm" style="width: auto; min-width: 150px;">
                                <option value="">&mdash;</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" onclick="addAllowedSender()" class="btn btn-primary text-sm">
                            <?php echo e(t('Add Sender')); ?>
                        </button>
                    </div>

                    <!-- Senders table -->
                    <div class="overflow-x-auto border rounded-lg" style="border-color: var(--border-color);">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-theme-secondary">
                                    <th class="px-4 py-2 text-left text-xs font-medium text-theme-muted"><?php echo e(t('Type')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-theme-muted"><?php echo e(t('Value')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-theme-muted"><?php echo e(t('Assign to user')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-theme-muted"><?php echo e(t('Status')); ?></th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-theme-muted"></th>
                                </tr>
                            </thead>
                            <tbody id="allowed-senders-tbody">
                                <?php if (empty($allowed_senders)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-3 text-center text-xs text-theme-muted">
                                            <?php echo e(t('No entries')); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allowed_senders as $sender): ?>
                                        <tr class="border-t" style="border-color: var(--border-color);" id="as-row-<?php echo (int)$sender['id']; ?>">
                                            <td class="px-4 py-2 text-theme-secondary">
                                                <?php echo $sender['type'] === 'email' ? 'Email' : e(t('Domain')); ?>
                                            </td>
                                            <td class="px-4 py-2 font-mono text-xs text-theme-primary">
                                                <?php echo e($sender['value']); ?>
                                            </td>
                                            <td class="px-4 py-2 text-theme-secondary">
                                                <?php echo $sender['user_name'] ? e($sender['user_name']) : '&mdash;'; ?>
                                            </td>
                                            <td class="px-4 py-2">
                                                <?php if ($sender['active']): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo e(t('Active')); ?></span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600"><?php echo e(t('Inactive')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-right">
                                                <button type="button" onclick="toggleAllowedSender(<?php echo (int)$sender['id']; ?>)" class="text-xs hover:underline mr-2 text-theme-muted">
                                                    <?php echo $sender['active'] ? e(t('Disable')) : e(t('Enable')); ?>
                                                </button>
                                                <button type="button" onclick="deleteAllowedSender(<?php echo (int)$sender['id']; ?>)" class="text-xs text-red-600 hover:underline">
                                                    <?php echo e(t('Delete')); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-4 text-theme-primary">
                        <?php echo e(t('Notification settings')); ?>
                    </h3>

                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="email_notifications_enabled" <?php echo ($settings['email_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>
                                    class="w-5 h-5 rounded text-blue-500 focus:ring-blue-500">
                                <span class="font-medium text-theme-primary"><?php echo e(t('Enable email notifications')); ?></span>
                            </label>
                            <p class="text-sm ml-8 text-theme-muted">
                                <?php echo e(t('Master switch for all email notifications.')); ?>
                            </p>
                            <?php if (($settings['email_notifications_enabled'] ?? '0') === '1'): ?>
                                <p class="text-xs ml-8 mt-1 text-theme-warning">
                                    <?php echo e(t('Turning this off will stop all email notifications for all users — including ticket updates, status changes, and new ticket alerts.')); ?>
                                </p>
                            <?php else: ?>
                                <p class="text-xs ml-8 mt-1 text-theme-muted">
                                    <?php echo e(t('Currently off. No email notifications are being sent. Turn on to enable notifications for ticket updates, comments, and new tickets.')); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <hr class="my-4">

                        <div class="space-y-3">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="notify_on_status_change" <?php echo ($settings['notify_on_status_change'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span class="text-theme-secondary"><?php echo e(t('Notify on status change')); ?></span>
                            </label>

                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="notify_on_new_comment" <?php echo ($settings['notify_on_new_comment'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span class="text-theme-secondary"><?php echo e(t('Notify on new comment')); ?></span>
                            </label>

                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="notify_on_new_ticket" <?php echo ($settings['notify_on_new_ticket'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span class="text-theme-secondary"><?php echo e(t('Notify admins on new ticket')); ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <button type="submit" name="save_email" class="btn btn-primary">
                        <?php echo e(t('Save settings')); ?>
                    </button>
                    <button type="submit" name="test_smtp" class="btn btn-secondary">
                        <?php echo get_icon('plug', 'mr-2'); ?>     <?php echo e(t('Save and test SMTP')); ?>
                    </button>
                    <button type="submit" name="test_imap" class="btn btn-secondary">
                        <?php echo get_icon('plug', 'mr-2'); ?>     <?php echo e(t('Save and test IMAP')); ?>
                    </button>
                    <button type="submit" name="run_imap_now" class="btn btn-secondary">
                        <?php echo get_icon('play', 'mr-2'); ?>     <?php echo e(t('Save and run IMAP now')); ?>
                    </button>
                </div>
            </form>

            <div class="card card-body">
                <div class="flex items-center justify-between mb-4 gap-4">
                    <div>
                        <h3 class="font-semibold text-theme-primary">
                            <?php echo e(t('Incoming email log')); ?>
                        </h3>
                        <p class="text-sm text-theme-muted">
                            <?php echo e(t('Last {count} processed/skipped/failed incoming emails.', ['count' => '100'])); ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($incoming_mail_log_error)): ?>
                    <div class="text-sm text-red-600">
                        <?php echo e(t('Incoming email log is not available: {error}', ['error' => $incoming_mail_log_error])); ?>
                    </div>
                <?php elseif (empty($incoming_mail_logs)): ?>
                    <div class="text-sm text-theme-muted"><?php echo e(t('No incoming email records yet.')); ?>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-xs uppercase border-b"
                                    style="background: var(--surface-secondary); color: var(--text-muted);">
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Time')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Sender')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Subject')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Status')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Ticket')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Details')); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($incoming_mail_logs as $row): ?>
                                    <?php
                                    $status = (string) ($row['status'] ?? '');
                                    $status_class = 'bg-gray-100 text-gray-700';
                                    if ($status === 'processed') {
                                        $status_class = 'bg-green-100 text-green-700';
                                    } elseif ($status === 'skipped') {
                                        $status_class = 'bg-yellow-100 text-yellow-700';
                                    } elseif ($status === 'failed') {
                                        $status_class = 'bg-red-100 text-red-700';
                                    }
                                    $ticket_id = isset($row['ticket_id']) ? (int) $row['ticket_id'] : 0;
                                    $ticket_url_value = '';
                                    if ($ticket_id > 0) {
                                        if (!empty($row['ticket_hash'])) {
                                            $ticket_url_value = url('ticket', ['t' => $row['ticket_hash']]);
                                        } else {
                                            $ticket_url_value = url('ticket', ['id' => $ticket_id]);
                                        }
                                    }
                                    ?>
                                    <tr class="tr-hover text-sm">
                                        <td class="px-4 py-3 whitespace-nowrap text-theme-muted">
                                            <?php echo e(date('Y-m-d H:i:s', strtotime($row['created_at']))); ?>
                                        </td>
                                        <td class="px-4 py-3 text-theme-secondary">
                                            <?php echo e($row['sender_email'] ?? '-'); ?>
                                        </td>
                                        <td class="px-4 py-3 max-w-xs truncate text-theme-secondary"
                                            title="<?php echo e($row['subject'] ?? ''); ?>">
                                            <?php echo e($row['subject'] ?? '-'); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="px-2 py-1 rounded-full text-xs font-medium <?php echo e($status_class); ?>">
                                                <?php echo e(t(ucfirst($status))); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-theme-secondary">
                                            <?php if ($ticket_id > 0 && $ticket_url_value !== ''): ?>
                                                <a class="text-blue-600 hover:text-blue-800" href="<?php echo e($ticket_url_value); ?>">
                                                    #<?php echo e((string) $ticket_id); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-theme-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-theme-secondary">
                                            <?php if (!empty($row['reason'])): ?>
                                                <div><?php echo e($row['reason']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['error'])): ?>
                                                <div class="text-xs text-red-600 mt-1"><?php echo e($row['error']); ?></div>
                                            <?php endif; ?>
                                            <div class="text-xs mt-1 text-theme-muted">
                                                <?php echo e((string) ($row['mailbox'] ?? '')); ?> / UID
                                                <?php echo e((string) ($row['uid'] ?? '')); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>


    <script>
        function addAllowedSender() {
            const type = document.getElementById('as-type').value;
            const value = document.getElementById('as-value').value.trim();
            const userId = document.getElementById('as-user').value;

            if (!value) return;

            fetch('index.php?page=api&action=allowed-senders-add', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({type, value, user_id: userId || null})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || <?php echo json_encode(t('Error')); ?>);
                    return;
                }
                location.reload();
            })
            .catch(() => alert(<?php echo json_encode(t('Error')); ?>));
        }

        function deleteAllowedSender(id) {
            if (!confirm('<?php echo e(t('Are you sure?')); ?>')) return;

            fetch('index.php?page=api&action=allowed-senders-delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || <?php echo json_encode(t('Error')); ?>);
                    return;
                }
                const row = document.getElementById('as-row-' + id);
                if (row) row.remove();
            })
            .catch(() => alert(<?php echo json_encode(t('Error')); ?>));
        }

        function toggleAllowedSender(id) {
            fetch('index.php?page=api&action=allowed-senders-toggle', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || <?php echo json_encode(t('Error')); ?>);
                    return;
                }
                location.reload();
            })
            .catch(() => alert(<?php echo json_encode(t('Error')); ?>));
        }
    </script>
