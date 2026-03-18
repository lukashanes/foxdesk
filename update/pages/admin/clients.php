<?php
/**
 * Admin - Clients Management
 */

$page_title = t('Clients');
$page = 'admin';
$clients = get_clients();
$ticket_counts = [];
if (!empty($clients)) {
    $client_ids = array_map('intval', array_column($clients, 'id'));
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $rows = db_fetch_all(
        "SELECT user_id, COUNT(*) as count FROM tickets WHERE user_id IN ($placeholders) GROUP BY user_id",
        $client_ids
    );
    foreach ($rows as $row) {
        $ticket_counts[(int) $row['user_id']] = (int) $row['count'];
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Add new client
    if (isset($_POST['add_client'])) {
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($first_name) || empty($password)) {
            flash(t('Please fill in all required fields.'), 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash(t('Enter a valid email address.'), 'error');
        } else {
            $validation = validate_password($password);
            if (!$validation['valid']) {
                flash(implode(' ', $validation['errors']), 'error');
            } else {
                // Check if email exists
                $existing = db_fetch_one("SELECT id FROM users WHERE email = ?", [$email]);
                if ($existing) {
                    flash(t('A user with this email already exists.'), 'error');
                } else {
                    create_user($email, $password, $first_name, $last_name, 'user');
                    flash(t('Client created.'), 'success');
                }
            }
        }
        redirect('admin', ['section' => 'clients']);
    }

    // Update client
    if (isset($_POST['update_client'])) {
        $id = (int) $_POST['id'];
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');

        db_update('users', [
            'first_name' => $first_name,
            'last_name' => $last_name
        ], 'id = ?', [$id]);

        flash(t('Client updated.'), 'success');
        redirect('admin', ['section' => 'clients']);
    }

    // Toggle active status
    if (isset($_POST['toggle_active'])) {
        $id = (int) $_POST['id'];
        $client = get_user($id);

        if ($client) {
            $new_status = $client['is_active'] ? 0 : 1;
            db_update('users', ['is_active' => $new_status], 'id = ?', [$id]);
            flash($new_status ? t('Client activated.') : t('Client deactivated.'), 'success');
        }
        redirect('admin', ['section' => 'clients']);
    }

    // Reset password
    if (isset($_POST['reset_password'])) {
        $id = (int) $_POST['id'];
        $new_password = $_POST['new_password'] ?? '';

        $validation = validate_password($new_password);
        if ($validation['valid']) {
            update_password($id, $new_password);
            flash(t('Password updated.'), 'success');
        } else {
            flash(implode(' ', $validation['errors']), 'error');
        }
        redirect('admin', ['section' => 'clients']);
    }

    // Generate and send password
    if (isset($_POST['generate_password'])) {
        $id = (int) $_POST['id'];
        $client = get_user($id);

        if ($client) {
            $new_password = generate_password(10);
            update_password($id, $new_password);

            // Try to send email
            require_once BASE_PATH . '/includes/mailer.php';
            $settings = get_settings();
            $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

            $subject = t('New password') . " - $app_name";
            $body = t('Hello') . ",\n\n" . t('Your new password for {app} is:', ['app' => $app_name]) . "\n\n$new_password\n\n" . t('After signing in, you can change your password in your profile settings.') . "\n\n" . t('Regards') . ",\n$app_name";

            // Account emails are forced regardless of notification preferences.
            $sent = send_email($client['email'], $subject, $body, false, true);

            if ($sent) {
                flash(t('New password generated and sent to {email}.', ['email' => $client['email']]), 'success');
            } else {
                flash(t('New password generated: {password} (email could not be sent).', ['password' => $new_password]), 'success');
            }
        }
        redirect('admin', ['section' => 'clients']);
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage client accounts and access.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
    <!-- Clients List -->
    <div class="lg:col-span-2">
        <div class="card overflow-hidden">
            <div class="px-6 py-3 border-b flex items-center justify-between" style="border-color: var(--border-light);">
                <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Clients')); ?> (<?php echo count($clients); ?>)
                </h3>
            </div>

            <?php if (empty($clients)): ?>
                <div class="p-8 text-center" style="color: var(--text-muted);">
                    <?php echo get_icon('users', 'text-4xl mb-4 opacity-50'); ?>
                    <p><?php echo e(t('No clients yet.')); ?></p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b" style="background: var(--surface-secondary); border-color: var(--border-light);">
                            <tr>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Name')); ?>
                                </th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Email')); ?>
                                </th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Status')); ?>
                                </th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Tickets')); ?>
                                </th>
                                <th class="px-6 py-3 text-right th-label">
                                    <?php echo e(t('Actions')); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($clients as $client): ?>
                                <?php $ticket_count = $ticket_counts[(int) $client['id']] ?? 0; ?>
                                <tr class="tr-hover">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <?php if (!empty($client['avatar'])): ?>
                                                <img src="<?php echo e(upload_url($client['avatar'])); ?>" alt=""
                                                    class="w-8 h-8 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                                    <span
                                                        class="text-sm font-medium"><?php echo strtoupper(substr($client['first_name'], 0, 1)); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <span
                                                class="font-medium" style="color: var(--text-primary);"><?php echo e($client['first_name'] . ' ' . $client['last_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm" style="color: var(--text-muted);"><?php echo e($client['email']); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($client['is_active']): ?>
                                            <span
                                                class="badge text-xs bg-green-100 text-green-600"><?php echo e(t('Active')); ?></span>
                                        <?php else: ?>
                                            <span
                                                class="badge text-xs bg-red-100 text-red-600"><?php echo e(t('Inactive')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm" style="color: var(--text-muted);">
                                        <?php echo $ticket_count; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="table-actions inline-flex items-center gap-2">
                                            <button onclick="editClient(<?php echo htmlspecialchars(json_encode($client)); ?>)"
                                                class="text-blue-500 hover:text-blue-600" title="<?php echo e(t('Edit')); ?>"
                                                aria-label="<?php echo e(t('Edit')); ?>">
                                                <?php echo get_icon('edit'); ?>
                                            </button>
                                            <form method="post" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                                <button type="submit" name="toggle_active"
                                                    class="<?php echo $client['is_active'] ? 'text-yellow-500 hover:text-yellow-600' : 'text-green-500 hover:text-green-600'; ?>"
                                                    title="<?php echo e($client['is_active'] ? t('Deactivate') : t('Activate')); ?>"
                                                    aria-label="<?php echo e($client['is_active'] ? t('Deactivate') : t('Activate')); ?>">
                                                    <?php echo get_icon($client['is_active'] ? 'ban' : 'check'); ?>
                                                </button>
                                            </form>
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

    <!-- Add New Client -->
    <div>
        <div class="card card-body">
            <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Add client')); ?></h3>

            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Email')); ?> *</label>
                    <input type="email" name="email" required class="form-input">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('First name')); ?>
                            *</label>
                        <input type="text" name="first_name" required class="form-input">
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Last name')); ?></label>
                        <input type="text" name="last_name" class="form-input">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Password')); ?>
                        *</label>
                    <input type="password" name="password" required minlength="12" class="form-input">
                    <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Minimum 12 characters')); ?></p>
                </div>

                <button type="submit" name="add_client" class="btn btn-primary w-full">
                    <?php echo e(t('Add client')); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="rounded-xl shadow-xl max-w-md w-full mx-4 p-4" style="background: var(--bg-primary);">
        <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Edit client')); ?></h3>
        <form method="post" id="editForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_id">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('First name')); ?></label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-input">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Last name')); ?></label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-input">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Email')); ?></label>
                <input type="email" id="edit_email" disabled class="form-input" style="background: var(--surface-secondary); color: var(--text-muted);">
            </div>

            <div class="flex space-x-3">
                <button type="button" onclick="closeModal()" class="btn btn-secondary flex-1">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" name="update_client" class="btn btn-primary flex-1">
                    <?php echo e(t('Save')); ?>
                </button>
            </div>
        </form>

        <hr class="my-4 border-t" style="border-color: var(--border-light);">

        <h4 class="font-medium mb-3" style="color: var(--text-secondary);"><?php echo e(t('Change password')); ?></h4>

        <form method="post" class="space-y-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="reset_id">
            <div class="flex items-end space-x-3">
                <div class="flex-1">
                    <label
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('New password')); ?></label>
                    <input type="password" name="new_password" minlength="12" class="form-input">
                </div>
                <button type="submit" name="reset_password" class="btn btn-warning">
                    <?php echo e(t('Change')); ?>
                </button>
            </div>
        </form>

        <form method="post" class="mt-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="generate_id">
            <button type="submit" name="generate_password" class="btn btn-secondary w-full">
                <?php echo get_icon('magic', 'mr-2 inline-block'); ?><?php echo e(t('Generate password and email')); ?>
            </button>
        </form>
    </div>
</div>

<script>
    function editClient(client) {
        document.getElementById('edit_id').value = client.id;
        document.getElementById('reset_id').value = client.id;
        document.getElementById('generate_id').value = client.id;
        document.getElementById('edit_first_name').value = client.first_name;
        document.getElementById('edit_last_name').value = client.last_name || '';
        document.getElementById('edit_email').value = client.email;
        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('editModal').classList.add('flex');
    }

    function closeModal() {
        document.getElementById('editModal').classList.add('hidden');
        document.getElementById('editModal').classList.remove('flex');
    }

    // Close modal on backdrop click
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
