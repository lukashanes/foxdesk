<?php
/**
 * User Profile Page
 */

$page_title = t('My profile');
$page = 'profile';
$user = current_user();
$error = '';
$success = '';

// Load 2FA functions
require_once BASE_PATH . '/includes/totp.php';
ensure_totp_columns();

// Refresh user to get TOTP columns
$user = current_user(true);

$notification_preferences_available = column_exists('users', 'email_notifications_enabled')
    && column_exists('users', 'in_app_notifications_enabled')
    && column_exists('users', 'in_app_sound_enabled');
$contact_phone_column_exists = column_exists('users', 'contact_phone');
$notes_column_exists = column_exists('users', 'notes');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Update profile
    if (isset($_POST['update_profile'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!empty($first_name)) {
            $updates = [
                'first_name' => $first_name,
                'last_name' => $last_name
            ];

            if (isset($_POST['language'])) {
                $updates['language'] = $_POST['language'];
            }

            if ($contact_phone_column_exists) {
                $updates['contact_phone'] = $contact_phone !== '' ? $contact_phone : null;
            }
            if ($notes_column_exists) {
                $updates['notes'] = $notes !== '' ? $notes : null;
            }

            db_update('users', $updates, 'id = ?', [$user['id']]);

            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            flash(t('Profile updated.'), 'success');
            redirect('profile');
        }
    }

    // Change email
    if (isset($_POST['change_email'])) {
        $new_email = trim($_POST['new_email'] ?? '');
        $password = $_POST['email_password'] ?? '';

        if (empty($new_email)) {
            flash(t('Enter a new email.'), 'error');
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            flash(t('Enter a valid email address.'), 'error');
        } elseif (!password_verify($password, $user['password'])) {
            flash(t('Incorrect password.'), 'error');
        } else {
            // Check if email is already used
            $existing = db_fetch_one("SELECT id FROM users WHERE email = ? AND id != ?", [$new_email, $user['id']]);
            if ($existing) {
                flash(t('This email is already in use.'), 'error');
            } else {
                db_update('users', ['email' => $new_email], 'id = ?', [$user['id']]);
                $_SESSION['user_email'] = $new_email;
                flash(t('Email updated.'), 'success');
            }
        }
        redirect('profile');
    }

    // Change password
    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            flash(t('Current password is incorrect.'), 'error');
        } elseif (strlen($new) < 6) {
            flash(t('New password must be at least 6 characters.'), 'error');
        } elseif ($new !== $confirm) {
            flash(t('Passwords do not match.'), 'error');
        } else {
            update_password($user['id'], $new);
            flash(t('Password updated.'), 'success');
        }
        redirect('profile');
    }

    // Notification preferences
    if (isset($_POST['update_notifications']) && $notification_preferences_available) {
        $updates = [
            'in_app_notifications_enabled' => isset($_POST['in_app_notifications_enabled']) ? 1 : 0,
            'in_app_sound_enabled' => isset($_POST['in_app_sound_enabled']) ? 1 : 0
        ];

        if (in_array($user['role'], ['user', 'agent'], true)) {
            $updates['email_notifications_enabled'] = isset($_POST['email_notifications_enabled']) ? 1 : 0;
        }

        db_update('users', $updates, 'id = ?', [$user['id']]);
        current_user(true);
        flash(t('Notification settings saved.'), 'success');
        redirect('profile');
    }

    // Per-type notification preferences
    if (isset($_POST['update_notification_types'])) {
        require_once BASE_PATH . '/includes/notification-functions.php';
        $type_labels = get_notification_type_labels();
        $prefs = [];
        foreach (array_keys($type_labels) as $type_key) {
            $prefs[$type_key] = isset($_POST['notif_type_' . $type_key]);
        }
        save_notification_preferences((int) $user['id'], $prefs);
        flash(t('Notification preferences saved.'), 'success');
        redirect('profile');
    }

    // Upload avatar
    if (isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {
        if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            try {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $result = upload_file($_FILES['avatar'], $allowed, 2 * 1024 * 1024);

                // Delete old avatar if exists
                if (!empty($user['avatar']) && strpos($user['avatar'], 'data:') !== 0) {
                    $old_path = BASE_PATH . '/' . UPLOAD_DIR . basename($user['avatar']);
                    if (file_exists($old_path)) {
                        @unlink($old_path);
                    }
                }

                $avatar_url = UPLOAD_DIR . $result['filename'];
                db_update('users', ['avatar' => $avatar_url], 'id = ?', [$user['id']]);
                refresh_user_session();
                flash(t('Avatar uploaded.'), 'success');
            } catch (Exception $e) {
                flash($e->getMessage(), 'error');
            }
        } else {
            flash(t('File upload failed.'), 'error');
        }
        redirect('profile');
    }

    // Generate avatar from initials
    if (isset($_POST['generate_avatar'])) {
        $name = $user['first_name'] . ' ' . $user['last_name'];
        $avatar = generate_avatar($name, 200);
        db_update('users', ['avatar' => $avatar], 'id = ?', [$user['id']]);
        refresh_user_session();
        flash(t('Avatar generated.'), 'success');
        redirect('profile');
    }

    // Remove avatar
    if (isset($_POST['remove_avatar'])) {
        // Delete file if exists
        if (!empty($user['avatar']) && strpos($user['avatar'], 'data:') !== 0) {
            $old_path = BASE_PATH . '/' . UPLOAD_DIR . basename($user['avatar']);
            if (file_exists($old_path)) {
                @unlink($old_path);
            }
        }

        db_update('users', ['avatar' => null], 'id = ?', [$user['id']]);
        refresh_user_session();
        flash(t('Avatar removed.'), 'success');
        redirect('profile');
    }

    // ─── 2FA: Start setup ────────────────────────────────────────────────
    if (isset($_POST['start_2fa_setup'])) {
        $secret = totp_generate_secret();
        $_SESSION['2fa_setup_secret'] = $secret;
        redirect('profile', ['setup2fa' => '1']);
    }

    // ─── 2FA: Verify & enable ────────────────────────────────────────────
    if (isset($_POST['verify_2fa_setup'])) {
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        $code = trim($_POST['setup_code'] ?? '');

        if (empty($secret)) {
            flash(t('Setup session expired. Please start again.'), 'error');
            redirect('profile');
        } elseif (totp_verify($secret, $code)) {
            // Generate backup codes
            $backup_codes = generate_backup_codes(8);
            $hashed_codes = array_map(fn($c) => hash('sha256', $c), $backup_codes);

            // Store secret + hashed backup codes in DB
            db_update('users', [
                'totp_secret' => $secret,
                'totp_enabled' => 1,
                'totp_backup_codes' => json_encode($hashed_codes)
            ], 'id = ?', [$user['id']]);

            // Show backup codes once
            $_SESSION['2fa_backup_codes_show'] = $backup_codes;
            unset($_SESSION['2fa_setup_secret']);

            // Clear forced setup flag if set
            if (!empty($_SESSION['2fa_setup_required'])) {
                unset($_SESSION['2fa_setup_required']);
            }

            if (function_exists('log_security_event')) {
                log_security_event('2fa_enabled', $user['id']);
            }

            flash(t('Two-factor authentication enabled.'), 'success');
            redirect('profile', ['2fa' => 'enabled']);
        } else {
            flash(t('Invalid code. Please try again.'), 'error');
            redirect('profile', ['setup2fa' => '1']);
        }
    }

    // ─── 2FA: Disable ────────────────────────────────────────────────────
    if (isset($_POST['disable_2fa'])) {
        $password = $_POST['disable_2fa_password'] ?? '';
        if (password_verify($password, $user['password'])) {
            db_update('users', [
                'totp_secret' => null,
                'totp_enabled' => 0,
                'totp_backup_codes' => null
            ], 'id = ?', [$user['id']]);

            if (function_exists('log_security_event')) {
                log_security_event('2fa_disabled', $user['id']);
            }

            flash(t('Two-factor authentication disabled.'), 'success');
        } else {
            flash(t('Incorrect password.'), 'error');
        }
        redirect('profile');
    }

    // ─── 2FA: Regenerate backup codes ────────────────────────────────────
    if (isset($_POST['regenerate_backup_codes'])) {
        $password = $_POST['regen_password'] ?? '';
        if (password_verify($password, $user['password'])) {
            $backup_codes = generate_backup_codes(8);
            $hashed_codes = array_map(fn($c) => hash('sha256', $c), $backup_codes);

            db_update('users', [
                'totp_backup_codes' => json_encode($hashed_codes)
            ], 'id = ?', [$user['id']]);

            $_SESSION['2fa_backup_codes_show'] = $backup_codes;

            if (function_exists('log_security_event')) {
                log_security_event('2fa_backup_regenerated', $user['id']);
            }

            flash(t('Backup codes regenerated.'), 'success');
            redirect('profile', ['2fa' => 'enabled']);
        } else {
            flash(t('Incorrect password.'), 'error');
            redirect('profile');
        }
    }
}

// Refresh user data
$user = current_user();

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage your account details and security.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <!-- Left column: Avatar + Notifications -->
    <div class="lg:col-span-1 space-y-4">

        <!-- Avatar -->
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Profile picture')); ?></h3>

            <div class="flex flex-col items-center text-center">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?php echo e(upload_url($user['avatar'])); ?>" alt="Avatar"
                        class="w-20 h-20 rounded-full object-cover border-2 mb-3" style="border-color: var(--border-light);">
                <?php else: ?>
                    <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center border-2 mb-3" style="border-color: var(--border-light);">
                        <span class="text-blue-600 text-2xl font-bold"><?php echo strtoupper(substr($user['first_name'], 0, 1)); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Upload -->
                <form method="post" enctype="multipart/form-data" class="w-full space-y-2" id="avatar-upload-form">
                    <?php echo csrf_field(); ?>
                    <div id="avatar-upload-zone" class="upload-zone-compact p-2.5 cursor-pointer">
                        <input type="file" name="avatar" id="avatar-file-input" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                        <div class="flex items-center justify-center gap-2 text-xs" style="color: var(--text-secondary);">
                            <?php echo get_icon('cloud-upload-alt', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                            <span>
                                <span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span>
                                <?php echo e(t('or drag files')); ?>
                            </span>
                        </div>
                    </div>
                    <p id="avatar-file-name" class="hidden text-xs" style="color: var(--text-muted);"></p>
                    <button type="submit" name="upload_avatar" class="btn btn-primary btn-sm w-full">
                        <?php echo e(t('Upload')); ?>
                    </button>
                </form>
                <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('JPG, PNG, GIF, or WebP. Max 2MB.')); ?></p>

                <div class="flex items-center justify-center gap-2 mt-3 w-full">
                    <form method="post" class="inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="generate_avatar" class="btn btn-ghost btn-sm text-xs">
                            <?php echo get_icon('magic', 'mr-1'); ?><?php echo e(t('Generate')); ?>
                        </button>
                    </form>

                    <?php if (!empty($user['avatar'])): ?>
                        <form method="post" class="inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="remove_avatar" class="btn btn-danger btn-sm text-xs"
                                onclick="return confirm('<?php echo e(t('Are you sure you want to remove the avatar?')); ?>')">
                                <?php echo get_icon('trash', 'mr-1'); ?><?php echo e(t('Remove')); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($notification_preferences_available): ?>
        <!-- Notification Preferences -->
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Notification settings')); ?></h3>

            <form method="post" class="space-y-3">
                <?php echo csrf_field(); ?>

                <?php if (in_array($user['role'], ['user', 'agent'], true)): ?>
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="email_notifications_enabled" class="rounded"
                        <?php echo (int) ($user['email_notifications_enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    <?php echo e(t('Email notifications')); ?>
                </label>
                <?php endif; ?>

                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="in_app_notifications_enabled" id="profile_in_app_notifications_enabled"
                        class="rounded" <?php echo (int) ($user['in_app_notifications_enabled'] ?? 1) === 1 ? 'checked' : ''; ?>>
                    <?php echo e(t('In-app notifications')); ?>
                </label>

                <label class="flex items-center gap-2 text-sm cursor-pointer ml-5" style="color: var(--text-secondary);">
                    <input type="checkbox" name="in_app_sound_enabled" id="profile_in_app_sound_enabled"
                        class="rounded" <?php echo (int) ($user['in_app_sound_enabled'] ?? 0) === 1 ? 'checked' : ''; ?>>
                    <?php echo e(t('Play sound')); ?>
                </label>

                <button type="submit" name="update_notifications" class="btn btn-ghost btn-sm w-full mt-2">
                    <?php echo e(t('Save')); ?>
                </button>
            </form>
        </div>

        <!-- Per-type Notification Preferences -->
        <?php
        if (file_exists(BASE_PATH . '/includes/notification-functions.php')) {
            require_once BASE_PATH . '/includes/notification-functions.php';
        }
        if (function_exists('get_notification_type_labels')):
            $notif_type_labels = get_notification_type_labels();
            $notif_prefs = function_exists('get_notification_preferences')
                ? get_notification_preferences((int) $user['id'])
                : array_fill_keys(array_keys($notif_type_labels), true);
        ?>
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Notification types')); ?></h3>
            <p class="text-xs mb-3" style="color: var(--text-muted);"><?php echo e(t('Choose which notification types you want to receive.')); ?></p>

            <form method="post" class="space-y-2">
                <?php echo csrf_field(); ?>
                <?php foreach ($notif_type_labels as $type_key => $type_label): ?>
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="notif_type_<?php echo e($type_key); ?>" class="rounded"
                        <?php echo !empty($notif_prefs[$type_key]) ? 'checked' : ''; ?>>
                    <?php echo e($type_label); ?>
                </label>
                <?php endforeach; ?>

                <button type="submit" name="update_notification_types" class="btn btn-ghost btn-sm w-full mt-2">
                    <?php echo e(t('Save')); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Right column: Personal info + Email + Password -->
    <div class="lg:col-span-2 space-y-4">

        <!-- Personal Information -->
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Personal information')); ?></h3>

            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="profile-first-name"
                            class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('First name')); ?></label>
                        <input type="text" name="first_name" id="profile-first-name" value="<?php echo e($user['first_name']); ?>" required aria-required="true"
                            autocomplete="given-name" class="form-input">
                    </div>
                    <div>
                        <label for="profile-last-name" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Last name')); ?></label>
                        <input type="text" name="last_name" id="profile-last-name" value="<?php echo e($user['last_name']); ?>"
                            autocomplete="family-name" class="form-input">
                    </div>
                </div>

                <?php if ($contact_phone_column_exists): ?>
                    <div>
                        <label for="profile-phone" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Phone')); ?></label>
                        <input type="text" name="contact_phone" id="profile-phone" value="<?php echo e($user['contact_phone'] ?? ''); ?>"
                            autocomplete="tel" class="form-input">
                    </div>
                <?php endif; ?>

                <?php if ($notes_column_exists): ?>
                    <div>
                        <label for="profile-notes" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Notes')); ?></label>
                        <textarea name="notes" id="profile-notes" rows="3" class="form-textarea"><?php echo e($user['notes'] ?? ''); ?></textarea>
                    </div>
                <?php endif; ?>

                <div>
                    <label for="profile-language" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Language')); ?></label>
                    <select name="language" id="profile-language" class="form-select w-full sm:w-1/2">
                        <option value="en" <?php echo ($user['language'] ?? 'en') === 'en' ? 'selected' : ''; ?>><?php echo e(t('English')); ?></option>
                        <option value="cs" <?php echo ($user['language'] ?? '') === 'cs' ? 'selected' : ''; ?>><?php echo e(t('Czech')); ?></option>
                        <option value="de" <?php echo ($user['language'] ?? '') === 'de' ? 'selected' : ''; ?>><?php echo e(t('German')); ?></option>
                        <option value="it" <?php echo ($user['language'] ?? '') === 'it' ? 'selected' : ''; ?>><?php echo e(t('Italian')); ?></option>
                        <option value="es" <?php echo ($user['language'] ?? '') === 'es' ? 'selected' : ''; ?>><?php echo e(t('Spanish')); ?></option>
                    </select>
                    <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Changes the language of the entire application interface.')); ?></p>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">
                    <?php echo e(t('Save changes')); ?>
                </button>
            </form>
        </div>

        <!-- Change Email -->
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Change email')); ?></h3>

            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Current email')); ?></label>
                        <input type="email" value="<?php echo e($user['email']); ?>" disabled autocomplete="email"
                            inputmode="email" autocapitalize="none" class="form-input" style="background: var(--surface-secondary); color: var(--text-muted);">
                    </div>
                    <div>
                        <label for="profile-new-email" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('New email')); ?></label>
                        <input type="email" name="new_email" id="profile-new-email" required aria-required="true" autocomplete="email" inputmode="email"
                            autocapitalize="none" class="form-input">
                    </div>
                </div>

                <div class="sm:w-1/2">
                    <label for="profile-email-password"
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Password for verification')); ?></label>
                    <input type="password" name="email_password" id="profile-email-password" required aria-required="true" autocomplete="current-password"
                        class="form-input">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        <?php echo e(t('Enter your current password to change email.')); ?></p>
                </div>

                <button type="submit" name="change_email" class="btn btn-warning">
                    <?php echo e(t('Change email')); ?>
                </button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card card-body">
            <h3 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);"><?php echo e(t('Change password')); ?></h3>

            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="profile-current-password"
                            class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Current password')); ?></label>
                        <input type="password" name="current_password" id="profile-current-password" required aria-required="true" autocomplete="current-password"
                            class="form-input">
                    </div>
                    <div>
                        <label for="profile-new-password" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('New password')); ?></label>
                        <input type="password" name="new_password" id="profile-new-password" required aria-required="true" minlength="6" autocomplete="new-password"
                            class="form-input">
                        <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Minimum 6 characters')); ?></p>
                    </div>
                    <div>
                        <label for="profile-confirm-password"
                            class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Confirm password')); ?></label>
                        <input type="password" name="confirm_password" id="profile-confirm-password" required aria-required="true" autocomplete="new-password" class="form-input">
                    </div>
                </div>

                <button type="submit" name="change_password" class="btn btn-primary">
                    <?php echo e(t('Change password')); ?>
                </button>
            </form>
        </div>

        <!-- Two-Factor Authentication -->
        <?php
        $totp_enabled = is_2fa_enabled($user);
        $show_setup = isset($_GET['setup2fa']) && !$totp_enabled;
        $show_backup_codes = isset($_SESSION['2fa_backup_codes_show']);
        $setup_secret = $_SESSION['2fa_setup_secret'] ?? '';
        $forced_setup = !empty($_SESSION['2fa_setup_required']);
        ?>
        <div class="card card-body" id="two-factor-section">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Two-factor authentication')); ?></h3>
                <?php if ($totp_enabled): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        <?php echo e(t('Enabled')); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($forced_setup && !$totp_enabled): ?>
                <!-- Forced setup banner -->
                <div class="rounded-lg p-3 mb-4 text-sm" style="background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); border: 1px solid var(--warning-border, #fde68a);">
                    <?php echo get_icon('exclamation-triangle', 'w-4 h-4 inline mr-1'); ?>
                    <?php echo e(t('Your administrator requires two-factor authentication. Please set it up to continue.')); ?>
                </div>
            <?php endif; ?>

            <?php if ($show_backup_codes): ?>
                <!-- ═══ Backup codes display (shown once after enabling) ═══ -->
                <?php $backup_codes = $_SESSION['2fa_backup_codes_show']; unset($_SESSION['2fa_backup_codes_show']); ?>
                <div class="space-y-4">
                    <div class="rounded-lg p-3 text-sm" style="background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e); border: 1px solid var(--warning-border, #fde68a);">
                        <?php echo get_icon('exclamation-triangle', 'w-4 h-4 inline mr-1'); ?>
                        <?php echo e(t('Save these backup codes in a safe place. Each code can only be used once. This is the only time they will be shown.')); ?>
                    </div>

                    <div class="grid grid-cols-2 gap-2 p-4 rounded-lg font-mono text-sm" style="background: var(--surface-secondary);" id="backup-codes-list">
                        <?php foreach ($backup_codes as $code): ?>
                            <div class="py-1 px-2 text-center" style="color: var(--text-primary);"><?php echo e($code); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" onclick="downloadBackupCodes()" class="btn btn-ghost btn-sm">
                            <?php echo get_icon('download', 'mr-1'); ?><?php echo e(t('Download as .txt')); ?>
                        </button>
                        <button type="button" onclick="copyBackupCodes()" class="btn btn-ghost btn-sm">
                            <?php echo get_icon('copy', 'mr-1'); ?><?php echo e(t('Copy')); ?>
                        </button>
                    </div>
                </div>

                <script>
                function downloadBackupCodes() {
                    var codes = <?php echo json_encode($backup_codes); ?>;
                    var text = "FoxDesk - Two-Factor Authentication Backup Codes\n";
                    text += "Generated: " + new Date().toLocaleDateString() + "\n\n";
                    codes.forEach(function(c) { text += c + "\n"; });
                    text += "\nEach code can only be used once.";
                    var blob = new Blob([text], {type: 'text/plain'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'foxdesk-backup-codes.txt';
                    a.click();
                    URL.revokeObjectURL(a.href);
                }
                function copyBackupCodes() {
                    var codes = <?php echo json_encode($backup_codes); ?>;
                    navigator.clipboard.writeText(codes.join('\n')).then(function() {
                        if (typeof showToast === 'function') showToast('<?php echo e(t('Copied to clipboard')); ?>', 'success');
                    });
                }
                </script>

            <?php elseif ($show_setup): ?>
                <!-- ═══ Setup flow: QR code + verify ═══ -->
                <?php
                if (empty($setup_secret)) {
                    // Generate secret if not yet in session (direct URL access)
                    $setup_secret = totp_generate_secret();
                    $_SESSION['2fa_setup_secret'] = $setup_secret;
                }
                $settings = get_settings();
                $app_name = $settings['app_name'] ?? 'FoxDesk';
                $otpauth_uri = totp_get_uri($setup_secret, $user['email'], $app_name);
                ?>
                <div class="space-y-5">
                    <p class="text-sm" style="color: var(--text-secondary);">
                        <?php echo e(t('Scan the QR code below with your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code to verify.')); ?>
                    </p>

                    <!-- QR Code -->
                    <div class="flex flex-col items-center gap-3">
                        <div class="p-3 rounded-lg bg-white">
                            <canvas id="totp-qr-code"></canvas>
                        </div>
                        <details class="w-full">
                            <summary class="text-xs cursor-pointer" style="color: var(--text-muted);"><?php echo e(t("Can't scan? Enter code manually")); ?></summary>
                            <div class="mt-2 p-3 rounded-lg font-mono text-sm text-center tracking-wider break-all" style="background: var(--surface-secondary); color: var(--text-primary);">
                                <?php echo e(format_totp_secret($setup_secret)); ?>
                            </div>
                        </details>
                    </div>

                    <!-- Verify code -->
                    <form method="post" class="space-y-3">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label for="setup-code" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Verification code')); ?></label>
                            <input type="text" name="setup_code" id="setup-code"
                                maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                                pattern="[0-9]{6}" required aria-required="true"
                                placeholder="000000"
                                class="form-input font-mono text-xl text-center tracking-[0.3em] w-full sm:w-48">
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" name="verify_2fa_setup" class="btn btn-primary">
                                <?php echo get_icon('shield-alt', 'mr-1'); ?><?php echo e(t('Verify & Enable')); ?>
                            </button>
                            <?php if (!$forced_setup): ?>
                                <a href="index.php?page=profile" class="btn btn-ghost btn-sm"><?php echo e(t('Cancel')); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/qrcode@1/build/qrcode.min.js"></script>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var canvas = document.getElementById('totp-qr-code');
                    if (canvas && typeof QRCode !== 'undefined') {
                        QRCode.toCanvas(canvas, <?php echo json_encode($otpauth_uri); ?>, {
                            width: 200,
                            margin: 1,
                            color: { dark: '#000000', light: '#ffffff' }
                        });
                    }
                    // Auto-focus the code input
                    var input = document.getElementById('setup-code');
                    if (input) input.focus();
                });
                </script>

            <?php elseif ($totp_enabled): ?>
                <!-- ═══ 2FA is enabled — show status ═══ -->
                <div class="space-y-4">
                    <p class="text-sm" style="color: var(--text-secondary);">
                        <?php echo get_icon('check-circle', 'w-4 h-4 inline mr-1 text-green-500'); ?>
                        <?php echo e(t('Your account is protected with two-factor authentication.')); ?>
                    </p>

                    <?php $remaining = count_backup_codes($user); ?>
                    <p class="text-xs" style="color: var(--text-muted);">
                        <?php echo e(t('Backup codes remaining:')); ?> <strong><?php echo $remaining; ?>/8</strong>
                        <?php if ($remaining <= 2 && $remaining > 0): ?>
                            <span class="text-orange-500 ml-1"><?php echo get_icon('exclamation-triangle', 'w-3 h-3 inline'); ?> <?php echo e(t('Running low')); ?></span>
                        <?php elseif ($remaining === 0): ?>
                            <span class="text-red-500 ml-1"><?php echo get_icon('exclamation-circle', 'w-3 h-3 inline'); ?> <?php echo e(t('No backup codes left')); ?></span>
                        <?php endif; ?>
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Regenerate backup codes -->
                        <button type="button" onclick="document.getElementById('regen-modal').classList.remove('hidden')" class="btn btn-ghost btn-sm">
                            <?php echo get_icon('sync', 'mr-1'); ?><?php echo e(t('Regenerate backup codes')); ?>
                        </button>

                        <!-- Disable 2FA -->
                        <button type="button" onclick="document.getElementById('disable-2fa-modal').classList.remove('hidden')" class="btn btn-danger btn-sm">
                            <?php echo get_icon('times-circle', 'mr-1'); ?><?php echo e(t('Disable 2FA')); ?>
                        </button>
                    </div>
                </div>

                <!-- Disable 2FA modal -->
                <div id="disable-2fa-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
                    <div class="rounded-xl shadow-xl w-full max-w-sm p-6" style="background: var(--surface-primary);">
                        <h4 class="text-base font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('Disable two-factor authentication')); ?></h4>
                        <p class="text-sm mb-4" style="color: var(--text-secondary);"><?php echo e(t('Enter your password to confirm.')); ?></p>
                        <form method="post" class="space-y-3">
                            <?php echo csrf_field(); ?>
                            <input type="password" name="disable_2fa_password" required autocomplete="current-password"
                                placeholder="<?php echo e(t('Password')); ?>" class="form-input w-full">
                            <div class="flex items-center gap-2 justify-end">
                                <button type="button" onclick="this.closest('#disable-2fa-modal').classList.add('hidden')" class="btn btn-ghost btn-sm"><?php echo e(t('Cancel')); ?></button>
                                <button type="submit" name="disable_2fa" class="btn btn-danger btn-sm"><?php echo e(t('Disable')); ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Regenerate backup codes modal -->
                <div id="regen-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
                    <div class="rounded-xl shadow-xl w-full max-w-sm p-6" style="background: var(--surface-primary);">
                        <h4 class="text-base font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('Regenerate backup codes')); ?></h4>
                        <p class="text-sm mb-4" style="color: var(--text-secondary);"><?php echo e(t('This will invalidate all existing backup codes. Enter your password to confirm.')); ?></p>
                        <form method="post" class="space-y-3">
                            <?php echo csrf_field(); ?>
                            <input type="password" name="regen_password" required autocomplete="current-password"
                                placeholder="<?php echo e(t('Password')); ?>" class="form-input w-full">
                            <div class="flex items-center gap-2 justify-end">
                                <button type="button" onclick="this.closest('#regen-modal').classList.add('hidden')" class="btn btn-ghost btn-sm"><?php echo e(t('Cancel')); ?></button>
                                <button type="submit" name="regenerate_backup_codes" class="btn btn-primary btn-sm"><?php echo e(t('Regenerate')); ?></button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- ═══ 2FA is off — show enable button ═══ -->
                <div class="space-y-3">
                    <p class="text-sm" style="color: var(--text-secondary);">
                        <?php echo e(t('Add an extra layer of security to your account using an authenticator app.')); ?>
                    </p>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="start_2fa_setup" class="btn btn-primary btn-sm">
                            <?php echo get_icon('shield-alt', 'mr-1'); ?><?php echo e(t('Enable 2FA')); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if ($notification_preferences_available): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inApp = document.getElementById('profile_in_app_notifications_enabled');
    const sound = document.getElementById('profile_in_app_sound_enabled');
    if (!inApp || !sound) return;

    const sync = () => {
        sound.disabled = !inApp.checked;
        if (!inApp.checked) {
            sound.checked = false;
        }
    };

    inApp.addEventListener('change', sync);
    sync();
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const avatarInput = document.getElementById('avatar-file-input');
    const avatarFileName = document.getElementById('avatar-file-name');
    if (!avatarInput || !avatarFileName) {
        return;
    }

    const updateAvatarFileLabel = function (files) {
        const selected = files || avatarInput.files;
        if (!selected || selected.length === 0) {
            avatarFileName.textContent = '';
            avatarFileName.classList.add('hidden');
            return;
        }
        avatarFileName.textContent = selected[0].name;
        avatarFileName.classList.remove('hidden');
    };

    if (window.initFileDropzone) {
        window.initFileDropzone({
            zoneId: 'avatar-upload-zone',
            inputId: 'avatar-file-input',
            acceptedExtensions: ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
            invalidTypeMessage: '<?php echo e(t('Invalid file type.')); ?>',
            onFilesChanged: updateAvatarFileLabel
        });
    } else {
        avatarInput.addEventListener('change', function () {
            updateAvatarFileLabel(avatarInput.files);
        });
    }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php';
