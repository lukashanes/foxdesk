<?php
/**
 * User Profile Page
 */

$page_title = t('My profile');
$page = 'profile';
$user = current_user();
$error = '';
$success = '';

$users_has_column = function ($column) {
    $safe_column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
    if ($safe_column === '') {
        return false;
    }
    try {
        return (bool) db_fetch_one("SHOW COLUMNS FROM users LIKE '{$safe_column}'");
    } catch (Throwable $e) {
        return false;
    }
};
$notification_preferences_available = $users_has_column('email_notifications_enabled')
    && $users_has_column('in_app_notifications_enabled')
    && $users_has_column('in_app_sound_enabled');
$contact_phone_column_exists = $users_has_column('contact_phone');
$notes_column_exists = $users_has_column('notes');

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
