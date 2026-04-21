<?php
/**
 * Admin - Users Management
 */

$page_title = t('Users');
$page = 'admin';

// Tab: 'users' (default) or 'ai_agents'
$tab = ($_GET['tab'] ?? '') === 'ai_agents' ? 'ai_agents' : 'users';
$ai_agent_col_exists = column_exists('users', 'is_ai_agent');

// Filter parameters
$filter_search = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';

$time_range = $_GET['time_range'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$range_data = get_time_range_bounds($time_range, $from_date, $to_date);
$time_range = $range_data['range'];
$range_start = $range_data['start'];
$range_end = $range_data['end'];
$time_tracking_available = ticket_time_table_exists();
$time_totals = [];

// Get organizations for dropdown
try {
    $organizations = get_organizations(true);
} catch (Exception $e) {
    $organizations = [];
}

$email_pref_column_exists = column_exists('users', 'email_notifications_enabled');
$in_app_pref_column_exists = column_exists('users', 'in_app_notifications_enabled');
$in_app_sound_column_exists = column_exists('users', 'in_app_sound_enabled');
$notification_preferences_available = $email_pref_column_exists && $in_app_pref_column_exists && $in_app_sound_column_exists;
$contact_phone_column_exists = column_exists('users', 'contact_phone');
$notes_column_exists = column_exists('users', 'notes');
$deleted_at_column_exists = column_exists('users', 'deleted_at');

$get_user_fk_references = function () {
    try {
        return db_fetch_all(
            "SELECT
                k.TABLE_NAME,
                k.COLUMN_NAME,
                c.IS_NULLABLE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             INNER JOIN INFORMATION_SCHEMA.COLUMNS c
                ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
               AND c.TABLE_NAME = k.TABLE_NAME
               AND c.COLUMN_NAME = k.COLUMN_NAME
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME = 'users'
               AND k.REFERENCED_COLUMN_NAME = 'id'"
        );
    } catch (Throwable $e) {
        return [];
    }
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Add new user
    if (isset($_POST['add_user'])) {
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $organization_id = !empty($_POST['organization_id']) ? (int) $_POST['organization_id'] : null;
        $organization_membership_ids = normalize_organization_ids($_POST['organization_membership_ids'] ?? []);
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;

        if ($organization_id && !in_array($organization_id, $organization_membership_ids, true)) {
            $organization_membership_ids[] = $organization_id;
        }
        $organization_membership_ids = normalize_organization_ids($organization_membership_ids);
        if (!$organization_id && !empty($organization_membership_ids)) {
            $organization_id = (int) $organization_membership_ids[0];
        }

        if (empty($email) || empty($first_name) || empty($password)) {
            flash(t('Please fill in all required fields.'), 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash(t('Enter a valid email address.'), 'error');
        } else {
            // Check if email exists
            $existing = db_fetch_one("SELECT id FROM users WHERE email = ?", [$email]);
            if ($existing) {
                flash(t('A user with this email already exists.'), 'error');
            } else {
                $language = $_POST['language'] ?? 'en';
                try {
                    $user_id = create_user($email, $password, $first_name, $last_name, $role, $language);
                } catch (Throwable $e) {
                    $user_id = false;
                    error_log('Admin user create failed: ' . $e->getMessage());
                }

                if ($user_id) {
                    $updates = [];
                    if ($organization_id) {
                        $updates['organization_id'] = $organization_id;
                    }
                    $updates['cost_rate'] = $cost_rate;
                    $updates['language'] = $_POST['language'] ?? 'en';
                    if ($contact_phone_column_exists) {
                        $updates['contact_phone'] = $contact_phone !== '' ? $contact_phone : null;
                    }
                    if ($notes_column_exists) {
                        $updates['notes'] = $notes !== '' ? $notes : null;
                    }

                    // Set default permissions for agents AND users
                    $permissions_data = null;

                    if ($role === 'agent' || $role === 'user') {
                        $ticket_scope = $_POST['ticket_scope'] ?? 'all';
                        $scope_organization_ids = [];

                        // Both agents and users can have multi-org selection via 'organization' scope
                        if (($role === 'agent' || $role === 'user') && $ticket_scope === 'organization' && !empty($_POST['scope_organization_ids'])) {
                            $scope_organization_ids = array_map('intval', (array) $_POST['scope_organization_ids']);
                        }
                        $scope_organization_ids = normalize_organization_ids($scope_organization_ids);

                        // Default scope for users if not set or invalid
                        if ($role === 'user' && !in_array($ticket_scope, ['organization', 'own', 'all'])) {
                            // Default to 'organization' if they have one, else 'own'
                            $ticket_scope = $organization_id ? 'organization' : 'own';
                        }

                        $effective_organization_ids = normalize_organization_ids(array_merge(
                            $organization_membership_ids,
                            $scope_organization_ids
                        ));

                        $permissions_data = [
                            'ticket_scope' => $ticket_scope,
                            'organization_ids' => $effective_organization_ids,
                            'can_archive' => ($role === 'agent' && isset($_POST['can_archive'])) ? true : false,
                            'can_view_edit_history' => isset($_POST['can_view_edit_history']) ? true : false,
                            'can_import_md' => ($role === 'agent' && isset($_POST['can_import_md'])) ? true : false,
                            'can_view_time' => isset($_POST['can_view_time']) ? true : false,
                            'can_view_timeline' => isset($_POST['can_view_timeline']) ? true : false
                        ];

                        $updates['permissions'] = json_encode($permissions_data);
                    } elseif ($role === 'admin' && !empty($organization_membership_ids)) {
                        $updates['permissions'] = json_encode([
                            'ticket_scope' => 'all',
                            'organization_ids' => $organization_membership_ids,
                            'can_archive' => true,
                            'can_view_edit_history' => true,
                            'can_import_md' => true,
                            'can_view_time' => true,
                            'can_view_timeline' => true
                        ]);
                    }

                    if ($notification_preferences_available) {
                        $updates['email_notifications_enabled'] = in_array($role, ['agent', 'user'], true)
                            ? (isset($_POST['email_notifications_enabled']) ? 1 : 0)
                            : 1;
                        $updates['in_app_notifications_enabled'] = isset($_POST['in_app_notifications_enabled']) ? 1 : 0;
                        $updates['in_app_sound_enabled'] = isset($_POST['in_app_sound_enabled']) ? 1 : 0;
                    }

                    if (!empty($updates)) {
                        db_update('users', $updates, 'id = ?', [$user_id]);
                    }

                    // Send welcome email with login credentials
                    if (!empty($_POST['send_welcome_email'])) {
                        require_once BASE_PATH . '/includes/mailer.php';
                        $settings = get_settings();
                        $app_name = !empty($settings['app_name']) ? $settings['app_name'] : 'FoxDesk';
                        $login_url = get_app_url();
                        $lang = $_POST['language'] ?? 'en';

                        $template = get_email_template('welcome_email', $lang);
                        if ($template) {
                            $placeholders = [
                                '{name}' => $first_name,
                                '{email}' => $email,
                                '{password}' => $password,
                                '{login_url}' => $login_url,
                                '{app_name}' => $app_name
                            ];

                            $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template['subject']);
                            $body = str_replace(array_keys($placeholders), array_values($placeholders), $template['body']);

                            $sent = send_email($email, $subject, $body, false, true);
                        } else {
                            $sent = false;
                        }

                        if ($sent) {
                            flash(t('User created. Login credentials sent to {email}.', ['email' => $email]), 'success');
                        } else {
                            flash(t('User created. Email could not be sent — credentials: {email} / {password}', ['email' => $email, 'password' => $password]), 'warning');
                        }
                    } else {
                        flash(t('User created.'), 'success');
                    }
                } else {
                    flash(t('Failed to create user.'), 'error');
                }
            }
        }
        redirect('admin', ['section' => 'users']);
    }

    // Update user
    if (isset($_POST['update_user'])) {
        $id = (int) $_POST['id'];
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $organization_id = !empty($_POST['organization_id']) ? (int) $_POST['organization_id'] : null;
        $organization_membership_ids = normalize_organization_ids($_POST['organization_membership_ids'] ?? []);
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;

        if ($organization_id && !in_array($organization_id, $organization_membership_ids, true)) {
            $organization_membership_ids[] = $organization_id;
        }
        $organization_membership_ids = normalize_organization_ids($organization_membership_ids);
        if (!$organization_id && !empty($organization_membership_ids)) {
            $organization_id = (int) $organization_membership_ids[0];
        }

        if ($email === '') {
            flash(t('Enter a valid email address.'), 'error');
            redirect('admin', ['section' => 'users']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash(t('Enter a valid email address.'), 'error');
            redirect('admin', ['section' => 'users']);
        }
        $existing = db_fetch_one("SELECT id FROM users WHERE email = ? AND id <> ?", [$email, $id]);
        if ($existing) {
            flash(t('A user with this email already exists.'), 'error');
            redirect('admin', ['section' => 'users']);
        }

        $target_user = db_fetch_one("SELECT id, role, is_active FROM users WHERE id = ?", [$id]);
        if (!$target_user) {
            flash(t('User not found.'), 'error');
            redirect('admin', ['section' => 'users']);
        }

        $is_currently_active_admin = ($target_user['role'] ?? '') === 'admin' && (int) ($target_user['is_active'] ?? 0) === 1;
        $will_be_active_admin = $role === 'admin' && $is_active === 1;
        if ($is_currently_active_admin && !$will_be_active_admin) {
            $active_admin_count_row = db_fetch_one("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1");
            $active_admin_count = (int) ($active_admin_count_row['c'] ?? 0);
            if ($active_admin_count <= 1) {
                flash(t('Cannot deactivate or demote the last active admin.'), 'error');
                redirect('admin', ['section' => 'users']);
            }
        }

        // Permissions (for agents and users)
        $permissions = null;
        if ($role === 'agent' || $role === 'user') {
            $ticket_scope = $_POST['ticket_scope'] ?? 'all';
            $scope_organization_ids = [];

            // Both agents and users can have multi-org selection
            if (($role === 'agent' || $role === 'user') && $ticket_scope === 'organization' && !empty($_POST['scope_organization_ids'])) {
                $scope_organization_ids = array_map('intval', (array) $_POST['scope_organization_ids']);
            }
            $scope_organization_ids = normalize_organization_ids($scope_organization_ids);

            // Validate user scope - allow 'all' for users too
            if ($role === 'user' && !in_array($ticket_scope, ['organization', 'own', 'all'])) {
                $ticket_scope = $organization_id ? 'organization' : 'own';
            }

            $effective_organization_ids = normalize_organization_ids(array_merge(
                $organization_membership_ids,
                $scope_organization_ids
            ));

            $permissions = json_encode([
                'ticket_scope' => $ticket_scope,
                'organization_ids' => $effective_organization_ids,
                'can_archive' => ($role === 'agent' && isset($_POST['can_archive'])) ? true : false,
                'can_view_edit_history' => isset($_POST['can_view_edit_history']) ? true : false,
                'can_import_md' => ($role === 'agent' && isset($_POST['can_import_md'])) ? true : false,
                'can_view_time' => isset($_POST['can_view_time']) ? true : false,
                'can_view_timeline' => isset($_POST['can_view_timeline']) ? true : false
            ]);
        } elseif ($role === 'admin') {
            $permissions = json_encode([
                'ticket_scope' => 'all',
                'organization_ids' => $organization_membership_ids,
                'can_archive' => true,
                'can_view_edit_history' => true,
                'can_import_md' => true,
                'can_view_time' => true,
                'can_view_timeline' => true
            ]);
        }

        $language = $_POST['language'] ?? 'en';

        $user_updates = [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => $role,
            'language' => $language,
            'is_active' => $is_active,
            'organization_id' => $organization_id,
            'permissions' => $permissions,
            'cost_rate' => $cost_rate
        ];

        if ($notification_preferences_available) {
            $user_updates['email_notifications_enabled'] = in_array($role, ['agent', 'user'], true)
                ? (isset($_POST['email_notifications_enabled']) ? 1 : 0)
                : 1;
            $user_updates['in_app_notifications_enabled'] = isset($_POST['in_app_notifications_enabled']) ? 1 : 0;
            $user_updates['in_app_sound_enabled'] = isset($_POST['in_app_sound_enabled']) ? 1 : 0;
        }
        if ($contact_phone_column_exists) {
            $user_updates['contact_phone'] = $contact_phone !== '' ? $contact_phone : null;
        }
        if ($notes_column_exists) {
            $user_updates['notes'] = $notes !== '' ? $notes : null;
        }

        db_update('users', $user_updates, 'id = ?', [$id]);
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            refresh_user_session();
            current_user(true);
        }

        flash(t('User updated.'), 'success');
        redirect('admin', ['section' => 'users']);
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
        redirect('admin', ['section' => 'users']);
    }

    // Send password reset email from admin
    if (isset($_POST['send_reset_email'])) {
        $id = (int) $_POST['id'];
        $user = db_fetch_one("SELECT id, first_name, email, is_active FROM users WHERE id = ?", [$id]);

        if ($user && $user['is_active']) {
            require_once BASE_PATH . '/includes/mailer.php';
            require_once BASE_PATH . '/includes/security-helpers.php';

            $token = generate_reset_token();
            $token_hash = hash_reset_token($token);
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            db_update('users', [
                'reset_token' => $token_hash,
                'reset_token_expires' => $expires
            ], 'id = ?', [$user['id']]);

            $reset_link = get_app_url() . '/index.php?page=reset-password&token=' . $token;
            $sent = send_password_reset_email($user['email'], $user['first_name'], $reset_link);

            if ($sent) {
                flash(t('Password reset email sent to {email}.', ['email' => $user['email']]), 'success');
            } else {
                flash(t('Failed to send password reset email.'), 'error');
            }
        } else {
            flash(t('User not found or inactive.'), 'error');
        }
        redirect('admin', ['section' => 'users']);
    }

    // Delete user
    if (isset($_POST['delete_user'])) {
        $id = (int) $_POST['id'];
        $is_permanent_delete = isset($_POST['purge']) && (string) $_POST['purge'] === '1';
        $actor_id = (int) ($_SESSION['user_id'] ?? 0);

        if (function_exists('debug_log')) {
            debug_log('User delete action requested', [
                'target_user_id' => $id,
                'is_permanent_delete' => $is_permanent_delete ? 1 : 0
            ], 'info', 'users');
        }

        $ai_agent_col = column_exists('users', 'is_ai_agent');
        $target_user_sql = "SELECT id, email, role, is_active"
            . ($ai_agent_col ? ", is_ai_agent" : "")
            . ($deleted_at_column_exists ? ", deleted_at" : "")
            . " FROM users WHERE id = ?";
        if ($deleted_at_column_exists) {
            $target_user_sql .= " AND deleted_at IS NULL";
        }
        $target_user = db_fetch_one($target_user_sql, [$id]);
        if (!$target_user) {
            flash(t('User not found.'), 'error');
            redirect('admin', ['section' => 'users']);
        }

        // Prevent archiving/deleting AI agents — use the AI Agents tab to deactivate
        $is_ai = !empty($target_user['is_ai_agent']);
        if ($is_ai) {
            flash(t('AI agents cannot be deleted. Deactivate instead.'), 'error');
            redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
        }

        // Prevent deleting self
        if ((int) $id === (int) $_SESSION['user_id']) {
            flash(t('You cannot archive or delete your own account while logged in.'), 'error');
        } else {
            // Prevent archiving/deleting the last active admin
            if ($target_user['role'] === 'admin' && (int) $target_user['is_active'] === 1) {
                $active_admin_count_row = db_fetch_one("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1");
                $active_admin_count = (int) ($active_admin_count_row['c'] ?? 0);
                if ($active_admin_count <= 1) {
                    flash(t('Cannot archive the last active admin.'), 'error');
                    redirect('admin', ['section' => 'users']);
                }
            }

            if (!$is_permanent_delete) {
                if ((int) $target_user['is_active'] === 0) {
                    flash(t('User is already archived.'), 'info');
                } else {
                    db_update('users', ['is_active' => 0], 'id = ?', [$id]);
                    if (function_exists('debug_log')) {
                        debug_log('User archived', [
                            'target_user_id' => $id,
                            'target_email' => $target_user['email'] ?? null
                        ], 'info', 'users');
                    }
                    if (function_exists('log_security_event')) {
                        log_security_event('user_archived', $actor_id > 0 ? $actor_id : null, json_encode([
                            'target_user_id' => $id,
                            'target_email' => $target_user['email'] ?? null
                        ]));
                    }
                    flash(t('User archived.'), 'success');
                }
            } else {
                if ((int) $target_user['is_active'] === 1) {
                    flash(t('Archive user first before permanent deletion.'), 'error');
                } else {
                    try {
                        $random_secret = uniqid('purged_', true);
                        try {
                            $random_secret = bin2hex(random_bytes(16));
                        } catch (Throwable $ignored) {
                            // Fallback to uniqid already set above.
                        }

                        $purged_email = 'deleted-user-' . $id . '-' . date('YmdHis') . '@invalid.local';
                        $purge_updates = [
                            'is_active' => 0,
                            'email' => $purged_email,
                            'first_name' => t('Deleted'),
                            'last_name' => 'User #' . $id,
                            'password' => password_hash($random_secret, PASSWORD_DEFAULT),
                            'permissions' => null
                        ];
                        if ($deleted_at_column_exists) {
                            $purge_updates['deleted_at'] = date('Y-m-d H:i:s');
                        }
                        if (column_exists('users', 'organization_id')) {
                            $purge_updates['organization_id'] = null;
                        }
                        if (column_exists('users', 'avatar')) {
                            $purge_updates['avatar'] = null;
                        }
                        if (column_exists('users', 'cost_rate')) {
                            $purge_updates['cost_rate'] = 0;
                        }
                        if ($contact_phone_column_exists) {
                            $purge_updates['contact_phone'] = null;
                        }
                        if ($notes_column_exists) {
                            $purge_updates['notes'] = null;
                        }
                        if ($email_pref_column_exists) {
                            $purge_updates['email_notifications_enabled'] = 0;
                        }
                        if ($in_app_pref_column_exists) {
                            $purge_updates['in_app_notifications_enabled'] = 0;
                        }
                        if ($in_app_sound_column_exists) {
                            $purge_updates['in_app_sound_enabled'] = 0;
                        }

                        db_update('users', $purge_updates, 'id = ?', [$id]);
                        if (function_exists('debug_log')) {
                            debug_log('Archived user purged', [
                                'target_user_id' => $id,
                                'new_email' => $purged_email
                            ], 'info', 'users');
                        }
                        if (function_exists('log_security_event')) {
                            log_security_event('user_purged', $actor_id > 0 ? $actor_id : null, json_encode([
                                'target_user_id' => $id,
                                'old_email' => $target_user['email'] ?? null,
                                'new_email' => $purged_email
                            ]));
                        }
                        flash(t('Archived user deleted.'), 'success');
                    } catch (Throwable $e) {
                        error_log('User purge failed for ID ' . $id . ': ' . $e->getMessage());
                        if (function_exists('debug_log')) {
                            debug_log('Archived user purge failed', [
                                'target_user_id' => $id,
                                'error' => $e->getMessage()
                            ], 'error', 'users');
                        }
                        if (function_exists('log_security_event')) {
                            log_security_event('user_purge_failed', $actor_id > 0 ? $actor_id : null, json_encode([
                                'target_user_id' => $id,
                                'error' => $e->getMessage()
                            ]));
                        }
                        flash(t('Could not delete archived user. They may have related data.'), 'error');
                    }
                }
            }
        }
        redirect('admin', ['section' => 'users']);
    }

    // === AI Agent handlers ===

    // Add AI agent
    if (isset($_POST['add_ai_agent']) && $ai_agent_col_exists) {
        $agent_name = trim($_POST['agent_name'] ?? '');
        $ai_model = trim($_POST['ai_model'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;

        if (empty($agent_name)) {
            flash(t('Please fill in all required fields.'), 'error');
        } else {
            // Generate unique email for system user
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($agent_name));
            $email = 'ai-' . $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '@system.local';
            $password = bin2hex(random_bytes(32));

            try {
                $user_id = create_user($email, $password, $agent_name, '', 'agent', 'en');
            } catch (Throwable $e) {
                $user_id = false;
                error_log('AI agent create failed: ' . $e->getMessage());
            }
            if ($user_id) {
                db_update('users', [
                    'is_ai_agent' => 1,
                    'ai_model' => $ai_model !== '' ? $ai_model : null,
                    'cost_rate' => $cost_rate,
                ], 'id = ?', [$user_id]);

                // Auto-generate API token
                if (function_exists('generate_api_token')) {
                    $token_result = generate_api_token($user_id, $agent_name);
                    if ($token_result && !empty($token_result['token'])) {
                        $_SESSION['new_ai_agent_token'] = $token_result['token'];
                        $_SESSION['new_ai_agent_id'] = $user_id;
                    }
                }

                flash(t('Agent created successfully.'), 'success');
            } else {
                flash(t('Failed to create AI agent.'), 'error');
            }
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Update AI agent
    if (isset($_POST['update_ai_agent']) && $ai_agent_col_exists) {
        $id = (int) $_POST['id'];
        $agent_name = trim($_POST['agent_name'] ?? '');
        $ai_model = trim($_POST['ai_model'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && !empty($agent_name)) {
            db_update('users', [
                'first_name' => $agent_name,
                'ai_model' => $ai_model !== '' ? $ai_model : null,
                'cost_rate' => $cost_rate,
                'is_active' => $is_active,
            ], 'id = ? AND is_ai_agent = 1', [$id]);
            flash(t('Settings saved.'), 'success');
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Generate token for AI agent
    if (isset($_POST['generate_agent_token']) && $ai_agent_col_exists) {
        $id = (int) $_POST['id'];
        $agent = db_fetch_one("SELECT id, first_name, is_ai_agent FROM users WHERE id = ? AND is_ai_agent = 1", [$id]);
        if ($agent && function_exists('generate_api_token')) {
            $token_result = generate_api_token($id, $agent['first_name']);
            if ($token_result && !empty($token_result['token'])) {
                $_SESSION['new_ai_agent_token'] = $token_result['token'];
                $_SESSION['new_ai_agent_id'] = $id;
                flash(t('API token generated for agent.'), 'success');
            }
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Revoke AI agent token
    if (isset($_POST['revoke_agent_token']) && $ai_agent_col_exists) {
        $token_id = (int) $_POST['token_id'];
        if ($token_id > 0 && function_exists('revoke_api_token')) {
            revoke_api_token($token_id);
            flash(t('Token revoked.'), 'success');
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Delete AI agent (and their tokens via CASCADE FK)
    if (isset($_POST['delete_ai_agent']) && $ai_agent_col_exists) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $agent = db_fetch_one("SELECT id, first_name FROM users WHERE id = ? AND is_ai_agent = 1", [$id]);
            if ($agent) {
                db_delete('users', 'id = ?', [$id]);
                flash(t('Agent deleted.'), 'success');
            }
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Upload user avatar (admin)
    if (isset($_POST['upload_user_avatar']) && isset($_FILES['user_avatar'])) {
        $target_user_id = (int) $_POST['user_id'];
        $target_user = db_fetch_one("SELECT * FROM users WHERE id = ?", [$target_user_id]);
        if ($target_user && $_FILES['user_avatar']['error'] === UPLOAD_ERR_OK) {
            try {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $result = upload_file($_FILES['user_avatar'], $allowed, 2 * 1024 * 1024);
                // Delete old avatar file if exists
                if (!empty($target_user['avatar']) && strpos($target_user['avatar'], 'data:') !== 0) {
                    $old_path = BASE_PATH . '/' . UPLOAD_DIR . basename($target_user['avatar']);
                    if (file_exists($old_path)) {
                        @unlink($old_path);
                    }
                }
                db_update('users', ['avatar' => UPLOAD_DIR . $result['filename']], 'id = ?', [$target_user_id]);
                if ($target_user_id === (int) ($_SESSION['user_id'] ?? 0)) {
                    refresh_user_session();
                }
                flash(t('Avatar uploaded.'), 'success');
            } catch (Exception $e) {
                flash($e->getMessage(), 'error');
            }
        }
        redirect('admin', ['section' => 'users']);
    }

    // Remove user avatar (admin)
    if (isset($_POST['remove_user_avatar'])) {
        $target_user_id = (int) $_POST['user_id'];
        $target_user = db_fetch_one("SELECT * FROM users WHERE id = ?", [$target_user_id]);
        if ($target_user && !empty($target_user['avatar'])) {
            if (strpos($target_user['avatar'], 'data:') !== 0) {
                $old_path = BASE_PATH . '/' . UPLOAD_DIR . basename($target_user['avatar']);
                if (file_exists($old_path)) {
                    @unlink($old_path);
                }
            }
            db_update('users', ['avatar' => null], 'id = ?', [$target_user_id]);
            if ($target_user_id === (int) ($_SESSION['user_id'] ?? 0)) {
                refresh_user_session();
            }
        }
        flash(t('Avatar removed.'), 'success');
        redirect('admin', ['section' => 'users']);
    }
}

// Get users with organization info - sorted by role (users first, then agents, then admins)
$sql = "SELECT u.*, o.name as organization_name
        FROM users u
        LEFT JOIN organizations o ON u.organization_id = o.id
        WHERE 1=1";
$params = [];
// Hide purged users also on old databases without deleted_at.
$sql .= " AND u.email NOT LIKE 'deleted-user-%@invalid.local'";
if ($deleted_at_column_exists) {
    $sql .= " AND u.deleted_at IS NULL";
}
// Exclude AI agents from normal user list
if ($ai_agent_col_exists) {
    $sql .= " AND (u.is_ai_agent = 0 OR u.is_ai_agent IS NULL)";
}

if ($filter_search !== '') {
    $search_parts = [
        "u.first_name LIKE ?",
        "u.last_name LIKE ?",
        "u.email LIKE ?"
    ];
    if ($contact_phone_column_exists) {
        $search_parts[] = "u.contact_phone LIKE ?";
    }
    if ($notes_column_exists) {
        $search_parts[] = "u.notes LIKE ?";
    }
    $sql .= " AND (" . implode(' OR ', $search_parts) . ")";
    $search_term = '%' . $filter_search . '%';
    foreach ($search_parts as $_) {
        $params[] = $search_term;
    }
}
if ($filter_role !== '') {
    $sql .= " AND u.role = ?";
    $params[] = $filter_role;
}
if ($filter_status !== '') {
    $sql .= " AND u.is_active = ?";
    $params[] = ($filter_status === 'active') ? 1 : 0;
}

$sql .= " ORDER BY FIELD(u.role, 'user', 'agent', 'admin'), u.first_name, u.last_name";
$users = db_fetch_all($sql, $params);

if ($time_tracking_available) {
    $dur = sql_timer_duration_minutes();
    $sql = "SELECT user_id, SUM({$dur}) as total_minutes FROM ticket_time_entries";
    $params = [];
    if ($range_start && $range_end) {
        $sql .= " WHERE started_at >= ? AND started_at <= ?";
        $params[] = $range_start;
        $params[] = $range_end;
    }
    $sql .= " GROUP BY user_id";
    $rows = db_fetch_all($sql, $params);
    foreach ($rows as $row) {
        $time_totals[(int) $row['user_id']] = (int) $row['total_minutes'];
    }
}

// Fetch AI agents
$ai_agents = [];
$ai_agent_tokens = [];
if ($ai_agent_col_exists) {
    $ai_sql = "SELECT u.* FROM users u WHERE u.is_ai_agent = 1";
    if ($deleted_at_column_exists) {
        $ai_sql .= " AND u.deleted_at IS NULL";
    }
    $ai_sql .= " ORDER BY u.is_active DESC, u.first_name";
    $ai_agents = db_fetch_all($ai_sql);

    // Fetch tokens for AI agents
    if (!empty($ai_agents)) {
        $ai_ids = array_column($ai_agents, 'id');
        $placeholders = implode(',', array_fill(0, count($ai_ids), '?'));
        try {
            $ai_agent_tokens = db_fetch_all(
                "SELECT * FROM api_tokens WHERE user_id IN ($placeholders) ORDER BY created_at DESC",
                $ai_ids
            );
        } catch (Throwable $e) {
            $ai_agent_tokens = [];
        }
    }
}

// Show new AI agent token if just generated
$new_ai_token = $_SESSION['new_ai_agent_token'] ?? null;
$new_ai_agent_id = $_SESSION['new_ai_agent_id'] ?? null;
unset($_SESSION['new_ai_agent_token'], $_SESSION['new_ai_agent_id']);

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage users, roles, and access.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<?php if ($ai_agent_col_exists): ?>
        <!-- Tab navigation -->
        <div class="flex flex-wrap gap-0.5 mb-3 p-0.5 rounded-lg w-fit" style="background: var(--surface-secondary);">
            <a href="<?php echo url('admin', ['section' => 'users']); ?>"
                class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors <?php echo $tab === 'users' ? 'shadow text-blue-600 dark:text-blue-400' : ''; ?>"
                style="<?php echo $tab === 'users' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
                <?php echo get_icon('users', 'w-4 h-4 inline mr-1'); ?>         <?php echo e(t('Users')); ?>
            </a>
            <a href="<?php echo url('admin', ['section' => 'users', 'tab' => 'ai_agents']); ?>"
                class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors <?php echo $tab === 'ai_agents' ? 'shadow text-purple-600 dark:text-purple-400' : ''; ?>"
                style="<?php echo $tab === 'ai_agents' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
                <?php echo get_icon('bot', 'w-4 h-4 inline mr-1'); ?>         <?php echo e(t('AI agents')); ?>
            </a>
        </div>
<?php endif; ?>

<?php if ($tab === 'ai_agents' && $ai_agent_col_exists): ?>
        <!-- ============================================= -->
        <!-- AI AGENTS TAB -->
        <!-- ============================================= -->

        <?php if ($new_ai_token): ?>
                <div class="mb-3 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm font-medium text-green-800 mb-1">
                        <?php echo e(t('New API token (copy now — it won\'t be shown again):')); ?>
                    </p>
                    <code class="block p-2 border rounded text-sm font-mono break-all select-all"
                        style="background: var(--bg-primary);"><?php echo e($new_ai_token); ?></code>
                    <?php if ($new_ai_agent_id): ?>
                            <a href="<?php echo url('admin', ['section' => 'agent-connect', 'id' => $new_ai_agent_id]); ?>"
                                class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-purple-700 hover:text-purple-900">
                                <?php echo get_icon('link', 'w-4 h-4'); ?>
                                <?php echo e(t('Get connection instructions for AI tools')); ?>
                            </a>
                    <?php endif; ?>
                </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <!-- AI Agents List -->
            <div class="lg:col-span-2">
                <div class="card overflow-hidden">
                    <div class="card-header">
                        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('AI agents')); ?>
                            (<?php echo count($ai_agents); ?>)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full tickets-table">
                            <thead style="background: var(--surface-secondary);">
                                <tr class="border-b">
                                    <th class="px-4 py-2 text-left th-label whitespace-nowrap"><?php echo e(t('Name')); ?></th>
                                    <th class="px-4 py-2 text-left th-label whitespace-nowrap w-28"><?php echo e(t('Model')); ?></th>
                                    <th class="px-4 py-2 text-left th-label whitespace-nowrap w-20"><?php echo e(t('Rate/h')); ?></th>
                                    <th class="px-4 py-2 text-left th-label whitespace-nowrap w-32"><?php echo e(t('API token')); ?></th>
                                    <th class="px-4 py-2 text-left th-label whitespace-nowrap w-20"><?php echo e(t('Status')); ?></th>
                                    <th class="px-4 py-2 text-right th-label whitespace-nowrap w-28"><?php echo e(t('Actions')); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (empty($ai_agents)): ?>
                                        <tr>
                                            <td colspan="6" class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">
                                                <?php echo e(t('No AI agents yet.')); ?>
                                            </td>
                                        </tr>
                                <?php endif; ?>
                                <?php foreach ($ai_agents as $agent):
                                    // Find tokens for this agent
                                    $agent_tks = array_filter($ai_agent_tokens, fn($tk) => (int) $tk['user_id'] === (int) $agent['id']);
                                    $active_token = null;
                                    foreach ($agent_tks as $tk) {
                                        if (!empty($tk['is_active'])) {
                                            $active_token = $tk;
                                            break;
                                        }
                                    }
                                    ?>
                                        <tr class="tr-hover <?php echo $agent['is_active'] ? '' : 'opacity-50'; ?>">
                                            <td class="px-4 py-2.5">
                                                <div class="flex items-center space-x-2">
                                                    <div
                                                        class="w-7 h-7 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                                                        <?php echo get_icon('bot', 'w-4 h-4 text-purple-600'); ?>
                                                    </div>
                                                    <span class="font-medium text-sm"
                                                        style="color: var(--text-primary);"><?php echo e($agent['first_name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <?php if (!empty($agent['ai_model'])): ?>
                                                        <span
                                                            class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded"><?php echo e($agent['ai_model']); ?></span>
                                                <?php else: ?>
                                                        <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2.5 text-sm" style="color: var(--text-secondary);">
                                                <?php echo (float) $agent['cost_rate'] > 0 ? e(format_money($agent['cost_rate'])) . '/h' : '<span style="color: var(--text-muted);">-</span>'; ?>
                                            </td>
                                            <td class="px-4 py-2.5 text-xs">
                                                <?php if ($active_token): ?>
                                                        <code
                                                            style="color: var(--text-muted);"><?php echo e($active_token['token_prefix'] ?? '???'); ?>...</code>
                                                <?php else: ?>
                                                        <span class="text-orange-500"><?php echo e(t('No token')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <?php if ($agent['is_active']): ?>
                                                        <span
                                                            class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo e(t('Active')); ?></span>
                                                <?php else: ?>
                                                        <span class="text-xs px-2 py-0.5 rounded"
                                                            style="background: var(--surface-tertiary); color: var(--text-secondary);"><?php echo e(t('Inactive')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2.5 text-right">
                                                <div class="flex items-center justify-end gap-1 relative z-10">
                                                    <a href="<?php echo url('admin', ['section' => 'agent-connect', 'id' => $agent['id']]); ?>"
                                                        class="p-1.5 rounded hover:bg-purple-50 text-purple-500 hover:text-purple-700 transition-colors"
                                                        title="<?php echo e(t('Connect')); ?>">
                                                        <?php echo get_icon('link', 'w-4 h-4'); ?>
                                                    </a>
                                                    <?php if (!$active_token): ?>
                                                            <form method="post" class="inline">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                                                                <button type="submit" name="generate_agent_token"
                                                                    class="p-1.5 rounded hover:bg-green-50 text-green-500 hover:text-green-700 transition-colors"
                                                                    title="<?php echo e(t('Generate token')); ?>">
                                                                    <?php echo get_icon('key', 'w-4 h-4'); ?>
                                                                </button>
                                                            </form>
                                                    <?php endif; ?>
                                                    <button type="button"
                                                        onclick='editAiAgent(<?php echo json_encode($agent, JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($active_token, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                        class="p-1.5 rounded hover:bg-blue-50 dark:bg-blue-900/20 text-blue-500 hover:text-blue-700 transition-colors"
                                                        title="<?php echo e(t('Edit')); ?>">
                                                        <?php echo get_icon('edit', 'w-4 h-4'); ?>
                                                    </button>
                                                    <form method="post" class="inline"
                                                        onsubmit="return confirmDeleteAgent('<?php echo addslashes(e($agent['first_name'])); ?>')">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                                                        <button type="submit" name="delete_ai_agent"
                                                            class="p-1.5 rounded hover:bg-red-50 text-red-400 hover:text-red-600 transition-colors"
                                                            title="<?php echo e(t('Delete agent')); ?>">
                                                            <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add AI Agent Form -->
            <div>
                <div class="card card-body">
                    <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Add AI agent')); ?></h3>
                    <form method="post" class="space-y-4">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Name')); ?> *</label>
                            <input type="text" name="agent_name" required class="form-input" placeholder="<?php echo e(t('e.g. Claude Sonnet')); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Model')); ?></label>
                            <input type="text" name="ai_model" class="form-input"
                                placeholder="<?php echo e(t('e.g. claude-sonnet-4-5, gpt-4o')); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Rate/h')); ?></label>
                            <input type="number" name="cost_rate" step="0.01" min="0" class="form-input" placeholder="0.00">
                        </div>
                        <button type="submit" name="add_ai_agent" class="btn btn-primary w-full">
                            <?php echo e(t('Add AI agent')); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit AI Agent Modal -->
        <div id="editAiAgentModal"
            class="fixed inset-0 bg-black bg-opacity-50 hidden items-start sm:items-center justify-center z-50 overflow-y-auto p-2 sm:p-3"
            role="dialog" aria-modal="true" aria-labelledby="edit-ai-agent-title">
            <div class="rounded-xl shadow-xl w-full max-w-md max-h-[calc(100vh-1rem)] overflow-hidden flex flex-col"
                style="background: var(--surface-primary);">
                <div class="px-4 sm:px-6 py-3.5 border-b flex items-center justify-between"
                    style="border-color: var(--border-light); background: var(--surface-primary);">
                    <h3 id="edit-ai-agent-title" class="font-semibold" style="color: var(--text-primary);">
                        <?php echo e(t('Edit AI agent')); ?>
                    </h3>
                    <button type="button" onclick="closeAiAgentModal()" class="p-1" style="color: var(--text-muted);">
                        <?php echo get_icon('x', 'w-5 h-5'); ?>
                    </button>
                </div>
                <div class="p-4 sm:p-5 overflow-y-auto space-y-4">
                    <form method="post" class="space-y-3.5">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" id="ai_edit_id">
                        <div>
                            <label for="ai_edit_name" class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Name')); ?> *</label>
                            <input type="text" name="agent_name" id="ai_edit_name" required aria-required="true"
                                class="form-input">
                        </div>
                        <div>
                            <label for="ai_edit_model" class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Model')); ?></label>
                            <input type="text" name="ai_model" id="ai_edit_model" class="form-input"
                                placeholder="<?php echo e(t('e.g. claude-sonnet-4-5, gpt-4o')); ?>">
                        </div>
                        <div>
                            <label for="ai_edit_cost_rate" class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Rate/h')); ?></label>
                            <input type="number" name="cost_rate" id="ai_edit_cost_rate" step="0.01" min="0" class="form-input">
                        </div>
                        <div>
                            <label class="flex items-center space-x-2 text-sm">
                                <input type="checkbox" name="is_active" id="ai_edit_is_active" value="1">
                                <span><?php echo e(t('Active')); ?></span>
                            </label>
                        </div>
                        <button type="submit" name="update_ai_agent" class="btn btn-primary w-full">
                            <?php echo e(t('Save')); ?>
                        </button>
                    </form>

                    <!-- Token management -->
                    <div class="border-t pt-3 mt-3">
                        <h4 class="text-sm font-medium mb-2" style="color: var(--text-secondary);">
                            <?php echo e(t('API token')); ?>
                        </h4>
                        <div id="ai_edit_token_status"></div>
                        <div id="ai_edit_token_actions"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            var _aiAgentReturnFocus = null;

            /**
             * Double-confirmation for deleting an agent.
             * Returns true only if user confirms both prompts.
             */
            function confirmDeleteAgent(name) {
                var q1 = '<?php echo addslashes(e(t('Delete agent "{name}" and all their records (API tokens, time entries)?'))); ?>'.replace('{name}', name);
                if (!confirm(q1)) return false;
                return confirm('<?php echo addslashes(e(t('Are you sure? This cannot be undone.'))); ?>');
            }

            function editAiAgent(agent, token) {
                _aiAgentReturnFocus = document.activeElement;
                document.getElementById('ai_edit_id').value = agent.id;
                document.getElementById('ai_edit_name').value = agent.first_name || '';
                document.getElementById('ai_edit_model').value = agent.ai_model || '';
                document.getElementById('ai_edit_cost_rate').value = agent.cost_rate || '';
                document.getElementById('ai_edit_is_active').checked = agent.is_active == 1;

                var statusEl = document.getElementById('ai_edit_token_status');
                var actionsEl = document.getElementById('ai_edit_token_actions');

                // Clear previous content safely
                while (statusEl.firstChild) statusEl.removeChild(statusEl.firstChild);
                while (actionsEl.firstChild) actionsEl.removeChild(actionsEl.firstChild);

                if (token && token.is_active == 1) {
                    var p = document.createElement('p');
                    p.className = 'text-xs mb-1';
                    p.style.color = 'var(--text-muted)';
                    p.textContent = '<?php echo e(t('Active token:')); ?> ';
                    var code = document.createElement('code');
                    code.textContent = (token.token_prefix || '???') + '...';
                    p.appendChild(code);
                    statusEl.appendChild(p);

                    // Revoke + Generate new buttons
                    var csrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
                    var revokeConfirm = '<?php echo addslashes(e(t('Revoke this token? The agent will lose API access.'))); ?>';
                    var revokeConfirm2 = '<?php echo addslashes(e(t('Are you sure? This cannot be undone.'))); ?>';
                    actionsEl.insertAdjacentHTML('beforeend',
                        '<form method="post" class="flex gap-2 mt-2" onsubmit="return confirm(\'' + revokeConfirm + '\') && confirm(\'' + revokeConfirm2 + '\')">' +
                        '<input type="hidden" name="csrf_token" value="' + csrf + '">' +
                        '<input type="hidden" name="id" value="' + agent.id + '">' +
                        '<input type="hidden" name="token_id" value="' + token.id + '">' +
                        '<button type="submit" name="revoke_agent_token" class="btn btn-warning btn-sm flex-1"><?php echo e(t('Revoke')); ?></button>' +
                        '<button type="submit" name="generate_agent_token" class="btn btn-secondary btn-sm flex-1"><?php echo e(t('Generate new')); ?></button>' +
                        '</form>');
                } else {
                    var p = document.createElement('p');
                    p.className = 'text-xs text-orange-500 mb-1';
                    p.textContent = '<?php echo e(t('No active token')); ?>';
                    statusEl.appendChild(p);

                    var csrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
                    actionsEl.insertAdjacentHTML('beforeend',
                        '<form method="post" class="mt-2">' +
                        '<input type="hidden" name="csrf_token" value="' + csrf + '">' +
                        '<input type="hidden" name="id" value="' + agent.id + '">' +
                        '<button type="submit" name="generate_agent_token" class="btn btn-primary btn-sm w-full"><?php echo e(t('Generate token')); ?></button>' +
                        '</form>');
                }

                var modal = document.getElementById('editAiAgentModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
                document.getElementById('ai_edit_name').focus();
                if (typeof trapFocus === 'function') trapFocus(modal);
            }

            function closeAiAgentModal() {
                var modal = document.getElementById('editAiAgentModal');
                if (typeof releaseFocus === 'function') releaseFocus(modal);
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
                if (_aiAgentReturnFocus) { _aiAgentReturnFocus.focus(); _aiAgentReturnFocus = null; }
            }

            document.addEventListener('DOMContentLoaded', function () {
                var modal = document.getElementById('editAiAgentModal');
                if (modal) {
                    modal.addEventListener('click', function (e) {
                        if (e.target === modal) closeAiAgentModal();
                    });
                }
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                        closeAiAgentModal();
                    }
                });
            });
        </script>

<?php else: ?>
        <!-- ============================================= -->
        <!-- USERS TAB (existing) -->
        <!-- ============================================= -->

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <!-- Users List -->
            <div class="lg:col-span-2">
                <div class="card overflow-hidden">
                    <div class="card-header">
                        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Users')); ?>
                            (<?php echo count($users); ?>)</h3>
                    </div>

                    <div class="card-header" style="background: var(--surface-secondary);">
                        <form method="get" class="flex flex-wrap items-end gap-3">
                            <input type="hidden" name="page" value="admin">
                            <input type="hidden" name="section" value="users">
                            <div>
                                <label class="block text-xs mb-1"
                                    style="color: var(--text-muted);"><?php echo e(t('Time range')); ?></label>
                                <select name="time_range" id="users-time-range" class="form-select">
                                    <option value="all" <?php echo $time_range === 'all' ? 'selected' : ''; ?>>
                                        <?php echo e(t('All time')); ?>
                                    </option>
                                    <option value="this_week" <?php echo $time_range === 'this_week' ? 'selected' : ''; ?>>
                                        <?php echo e(t('This week')); ?>
                                    </option>
                                    <option value="this_month" <?php echo $time_range === 'this_month' ? 'selected' : ''; ?>>
                                        <?php echo e(t('This month')); ?>
                                    </option>
                                    <option value="last_month" <?php echo $time_range === 'last_month' ? 'selected' : ''; ?>>
                                        <?php echo e(t('Last month')); ?>
                                    </option>
                                    <option value="custom" <?php echo $time_range === 'custom' ? 'selected' : ''; ?>>
                                        <?php echo e(t('Custom range')); ?>
                                    </option>
                                </select>
                            </div>
                            <div id="users-custom-range"
                                class="flex flex-wrap items-end gap-3 <?php echo $time_range === 'custom' ? '' : 'hidden'; ?>">
                                <div>
                                    <label class="block text-xs mb-1"
                                        style="color: var(--text-muted);"><?php echo e(t('From date')); ?></label>
                                    <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                                </div>
                                <div>
                                    <label class="block text-xs mb-1"
                                        style="color: var(--text-muted);"><?php echo e(t('To date')); ?></label>
                                    <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <form method="get" id="user-filter-form" class="hidden">
                            <input type="hidden" name="page" value="admin">
                            <input type="hidden" name="section" value="users">
                            <?php if ($time_range !== 'all'): ?>
                                    <input type="hidden" name="time_range" value="<?php echo e($time_range); ?>">
                            <?php endif; ?>
                            <?php if ($from_date !== ''): ?>
                                    <input type="hidden" name="from_date" value="<?php echo e($from_date); ?>">
                            <?php endif; ?>
                            <?php if ($to_date !== ''): ?>
                                    <input type="hidden" name="to_date" value="<?php echo e($to_date); ?>">
                            <?php endif; ?>
                        </form>
                        <table class="w-full tickets-table">
                            <thead style="background: var(--surface-secondary);">
                                <tr class="border-b">
                                    <th class="px-4 py-2 text-left th-label">
                                        <?php echo e(t('Name')); ?>
                                    </th>
                                    <th class="px-4 py-2 text-left th-label w-32">
                                        <?php echo e(t('Company')); ?>
                                    </th>
                                    <th class="px-4 py-2 text-left th-label w-24">
                                        <?php echo e(t('Role')); ?>
                                    </th>
                                    <th class="px-4 py-2 text-left th-label w-28">
                                        <?php echo e(t('Logged time')); ?>
                                    </th>
                                    <th class="px-4 py-2 text-left th-label w-20">
                                        <?php echo e(t('Status')); ?>
                                    </th>
                                    <th class="px-4 py-2 text-right th-label w-40">
                                        <?php echo e(t('Actions')); ?>
                                    </th>
                                </tr>
                                <!-- Inline Filter Row -->
                                <tr class="border-b" style="background: var(--surface-secondary);">
                                    <th class="px-3 py-1.5">
                                        <input type="text" name="search" value="<?php echo e($filter_search); ?>"
                                            form="user-filter-form" placeholder="<?php echo e(t('Name or email...')); ?>"
                                            class="form-input form-input-sm w-full text-xs">
                                    </th>
                                    <th class="px-3 py-1.5">
                                        <!-- No filter for company -->
                                    </th>
                                    <th class="px-3 py-1.5">
                                        <select name="role" form="user-filter-form"
                                            class="form-select form-select-sm w-full text-xs"
                                            onchange="document.getElementById('user-filter-form').submit()">
                                            <option value=""><?php echo e(t('All')); ?></option>
                                            <option value="user" <?php echo $filter_role === 'user' ? 'selected' : ''; ?>>
                                                <?php echo e(t('User')); ?>
                                            </option>
                                            <option value="agent" <?php echo $filter_role === 'agent' ? 'selected' : ''; ?>>
                                                <?php echo e(t('Agent')); ?>
                                            </option>
                                            <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>
                                                <?php echo e(t('Admin')); ?>
                                            </option>
                                        </select>
                                    </th>
                                    <th class="px-3 py-1.5">
                                        <!-- No filter for logged time -->
                                    </th>
                                    <th class="px-3 py-1.5">
                                        <select name="status" form="user-filter-form"
                                            class="form-select form-select-sm w-full text-xs"
                                            onchange="document.getElementById('user-filter-form').submit()">
                                            <option value=""><?php echo e(t('All')); ?></option>
                                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>
                                                <?php echo e(t('Active')); ?>
                                            </option>
                                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>><?php echo e(t('Archived')); ?></option>
                                        </select>
                                    </th>
                                    <th class="px-3 py-1.5 text-right">
                                        <button type="submit" form="user-filter-form" class="btn btn-primary btn-xs"
                                            title="<?php echo e(t('Apply')); ?>">
                                            <?php echo get_icon('search', 'w-3 h-3'); ?>
                                        </button>
                                        <?php if ($filter_search !== '' || $filter_role !== '' || $filter_status !== ''): ?>
                                                <a href="<?php echo url('admin', ['section' => 'users']); ?>"
                                                    class="btn btn-secondary btn-xs ml-1" title="<?php echo e(t('Clear')); ?>">
                                                    <?php echo get_icon('x', 'w-3 h-3'); ?>
                                                </a>
                                        <?php endif; ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($users as $u): ?>
                                        <tr class="tr-hover">
                                            <td class="px-4 py-2.5">
                                                <div class="flex items-center space-x-2">
                                                    <?php if (!empty($u['avatar'])): ?>
                                                            <img src="<?php echo e(upload_url($u['avatar'])); ?>" alt=""
                                                                class="w-7 h-7 rounded-full object-cover flex-shrink-0">
                                                    <?php else: ?>
                                                            <div
                                                                class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                                                <span
                                                                    class="text-blue-600 text-xs font-medium"><?php echo strtoupper(substr($u['first_name'], 0, 1)); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="font-medium text-sm"
                                                            style="color: var(--text-primary);"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></span>
                                                        <div class="text-xs" style="color: var(--text-muted);">
                                                            <?php echo e($u['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">
                                                <?php if (!empty($u['organization_name'])): ?>
                                                        <span
                                                            style="color: var(--text-secondary);"><?php echo e($u['organization_name']); ?></span>
                                                <?php else: ?>
                                                        <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <?php
                                                $role_labels = ['user' => t('User'), 'agent' => t('Agent'), 'admin' => t('Admin')];
                                                $role_badge = [
                                                    'user' => ['class' => '', 'style' => 'background: var(--surface-secondary); color: var(--text-secondary);'],
                                                    'agent' => ['class' => 'bg-blue-100 text-blue-600', 'style' => ''],
                                                    'admin' => ['class' => 'bg-purple-100 text-purple-600', 'style' => '']
                                                ];
                                                $badge = $role_badge[$u['role']] ?? $role_badge['user'];
                                                ?>
                                                <span class="badge text-xs <?php echo $badge['class']; ?>" <?php echo $badge['style'] ? ' style="' . $badge['style'] . '"' : ''; ?>>
                                                    <?php echo e($role_labels[$u['role']] ?? $u['role']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">
                                                <?php
                                                $total_minutes = $time_totals[$u['id']] ?? 0;
                                                echo $total_minutes > 0 ? e(format_duration_minutes($total_minutes)) : '<span style="color: var(--text-muted);">-</span>';
                                                ?>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <?php if ($u['is_active']): ?>
                                                        <span
                                                            class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo e(t('Active')); ?></span>
                                                <?php else: ?>
                                                        <span class="text-xs px-2 py-1 rounded"
                                                            style="background: var(--surface-tertiary); color: var(--text-secondary);"><?php echo e(t('Archived')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2.5 text-right">
                                                <div class="flex items-center justify-end gap-1 relative z-10">
                                                    <?php if ($u['id'] != $_SESSION['user_id'] && (int) ($u['is_active'] ?? 0) === 1): ?>
                                                            <form method="get" action="index.php" class="inline">
                                                                <input type="hidden" name="page" value="impersonate">
                                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit"
                                                                    class="p-1.5 rounded hover:bg-purple-50 text-purple-500 hover:text-purple-700 transition-colors"
                                                                    title="<?php echo e(t('Log in as user')); ?>">
                                                                    <?php echo get_icon('user-check', 'w-4 h-4'); ?>
                                                                </button>
                                                            </form>
                                                    <?php endif; ?>
                                                    <a href="<?php echo url('user-profile', ['id' => $u['id']]); ?>"
                                                        class="p-1.5 rounded transition-colors" style="color: var(--text-muted);"
                                                        title="<?php echo e(t('Ticket history')); ?>">
                                                        <?php echo get_icon('clock', 'w-4 h-4'); ?>
                                                    </a>
                                                    <button type="button"
                                                        onclick='editUser(<?php echo json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                        class="p-1.5 rounded hover:bg-blue-50 dark:bg-blue-900/20 text-blue-500 hover:text-blue-700 transition-colors"
                                                        title="<?php echo e(t('Edit')); ?>">
                                                        <?php echo get_icon('edit', 'w-4 h-4'); ?>
                                                    </button>
                                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                            <form method="post" class="inline"
                                                                onsubmit="return confirm('<?php echo e($u['is_active']
                                                                    ? t('Are you sure you want to archive this user?')
                                                                    : t('Are you sure you want to permanently delete this archived user? Ticket ownership, comments, and time entries will be transferred to your account.')); ?>');">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                                                <input type="hidden" name="purge"
                                                                    value="<?php echo $u['is_active'] ? '0' : '1'; ?>">
                                                                <button type="submit" name="delete_user"
                                                                    class="p-1.5 rounded <?php echo $u['is_active']
                                                                        ? 'hover:bg-orange-50 text-orange-500 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300 dark:hover:bg-orange-900/30'
                                                                        : 'hover:bg-red-50 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 dark:hover:bg-red-900/30'; ?> transition-colors"
                                                                    title="<?php echo e($u['is_active'] ? t('Archive user') : t('Delete permanently')); ?>">
                                                                    <?php echo get_icon($u['is_active'] ? 'archive' : 'trash', 'w-4 h-4'); ?>
                                                                </button>
                                                            </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add New User -->
            <div>
                <div class="card card-body">
                    <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Add user')); ?></h3>

                    <form method="post" class="space-y-4" id="addUserForm">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Email')); ?> *</label>
                            <input type="email" name="email" required class="form-input">
                        </div>

                        <?php if ($contact_phone_column_exists): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Phone')); ?></label>
                                    <input type="text" name="contact_phone" class="form-input">
                                </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('First name')); ?>
                                    *</label>
                                <input type="text" name="first_name" required class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Last name')); ?></label>
                                <input type="text" name="last_name" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Password')); ?>
                                *</label>
                            <input type="password" name="password" required minlength="6" class="form-input">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Cost rate (per hour)')); ?></label>
                            <input type="number" name="cost_rate" step="0.01" min="0" class="form-input">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Role')); ?></label>
                            <select name="role" id="add_role" onchange="togglePermissions('add')" class="form-select">
                                <option value="user"><?php echo e(t('User')); ?></option>
                                <option value="agent"><?php echo e(t('Agent')); ?></option>
                                <option value="admin"><?php echo e(t('Admin')); ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Language')); ?></label>
                            <select name="language" class="form-select">
                                <option value="en"><?php echo e(t('English')); ?></option>
                                <option value="cs"><?php echo e(t('Czech')); ?></option>
                                <option value="de"><?php echo e(t('German')); ?></option>
                                <option value="it"><?php echo e(t('Italian')); ?></option>
                                <option value="es"><?php echo e(t('Spanish')); ?></option>
                            </select>
                        </div>

                        <?php if (!empty($organizations)): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Company')); ?></label>
                                    <select name="organization_id" id="add_organization_id" class="form-select">
                                        <option value=""><?php echo e(t('-- No organization --')); ?></option>
                                        <?php foreach ($organizations as $org): ?>
                                                <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Organizations')); ?></label>
                                    <select name="organization_membership_ids[]" id="add_organization_membership_ids" multiple size="5"
                                        class="form-select text-sm">
                                        <?php foreach ($organizations as $org): ?>
                                                <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                                        <?php echo e(t('Ctrl+click to select multiple organizations.')); ?>
                                    </p>
                                </div>
                        <?php endif; ?>

                        <?php if ($notes_column_exists): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Notes')); ?></label>
                                    <textarea name="notes" rows="3" class="form-textarea"></textarea>
                                </div>
                        <?php endif; ?>

                        <?php if ($notification_preferences_available): ?>
                                <div class="border-t pt-4 space-y-2">
                                    <h4 class="text-sm font-semibold" style="color: var(--text-secondary);">
                                        <?php echo e(t('Notification settings')); ?>
                                    </h4>
                                    <label id="add_email_notifications_wrap" class="flex items-center text-sm">
                                        <input type="checkbox" name="email_notifications_enabled" id="add_email_notifications_enabled"
                                            class="mr-2" checked>
                                        <?php echo e(t('Enable email notifications')); ?>
                                    </label>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="in_app_notifications_enabled" id="add_in_app_notifications_enabled"
                                            class="mr-2" checked>
                                        <?php echo e(t('Enable in-app notifications')); ?>
                                    </label>
                                    <label class="flex items-center text-sm ml-6">
                                        <input type="checkbox" name="in_app_sound_enabled" id="add_in_app_sound_enabled" class="mr-2">
                                        <?php echo e(t('Play notification sound')); ?>
                                    </label>
                                </div>
                        <?php endif; ?>

                        <!-- Permissions (show for agents and users) -->
                        <div id="add_permissions" class="hidden border-t pt-4">
                            <h4 class="text-sm font-semibold mb-3" style="color: var(--text-secondary);">
                                <?php echo e(t('Permissions')); ?>
                            </h4>

                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs mb-2"
                                        style="color: var(--text-secondary);"><?php echo e(t('Ticket scope:')); ?></label>
                                    <div class="space-y-2">
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="all" class="mr-2">
                                            <?php echo e(t('All tickets')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="assigned" class="mr-2">
                                            <?php echo e(t('Assigned tickets only')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="organization" class="mr-2"
                                                id="add_scope_org">
                                            <?php echo e(t('Tickets from selected organizations')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="own" checked class="mr-2">
                                            <?php echo e(t('Own tickets only')); ?>
                                        </label>
                                    </div>

                                    <?php if (!empty($organizations)): ?>
                                            <div id="add_org_select" class="mt-2 hidden">
                                                <label class="block text-xs mb-1"
                                                    style="color: var(--text-muted);"><?php echo e(t('Select organizations (multiple allowed)')); ?></label>
                                                <select name="scope_organization_ids[]" multiple size="5" class="form-select text-sm">
                                                    <?php foreach ($organizations as $org): ?>
                                                            <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                                    <?php echo e(t('Ctrl+click to select multiple organizations.')); ?>
                                                </p>
                                            </div>
                                    <?php endif; ?>
                                </div>

                                <div id="add_can_archive_wrap">
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_archive" class="mr-2">
                                        <?php echo e(t('Can archive tickets')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_edit_history" class="mr-2">
                                        <?php echo e(t('Can view edit history')); ?>
                                    </label>
                                </div>
                                <div id="add_can_import_md_wrap">
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_import_md" class="mr-2">
                                        <?php echo e(t('Can import .md')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_time" class="mr-2">
                                        <?php echo e(t('Can view time entries')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_timeline" class="mr-2">
                                        <?php echo e(t('Can view activity timeline')); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="border-t pt-4 mt-4" style="border-color: var(--border-primary);">
                            <label class="flex items-center text-sm cursor-pointer">
                                <input type="checkbox" name="send_welcome_email" class="mr-2">
                                <?php echo e(t('Send login credentials via email')); ?>
                            </label>
                        </div>

                        <button type="submit" name="add_user" class="btn btn-primary w-full">
                            <?php echo e(t('Add user')); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Permissions Matrix -->
        <div class="mt-3">
            <div class="card overflow-hidden">
                <div class="card-header flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Permissions Matrix')); ?>
                        </h3>
                        <p class="text-sm mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Overview of user access and permissions')); ?>
                        </p>
                    </div>
                    <button type="button" onclick="toggleMatrix()"
                        class="text-sm text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                        <span id="matrix-toggle-text"><?php echo e(t('Show')); ?></span>
                    </button>
                </div>

                <div id="permissions-matrix" class="hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead style="background: var(--surface-secondary);">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('User')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-left font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Role')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-left font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Ticket Scope')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-left font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Organizations')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-center font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Can Archive')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-center font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Can view edit history')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-center font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Can import .md')); ?>
                                    </th>
                                    <th class="px-4 py-3 text-center font-medium" style="color: var(--text-secondary);">
                                        <?php echo e(t('Status')); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($users as $u):
                                    $perms = [];
                                    if (!empty($u['permissions'])) {
                                        $perms = json_decode($u['permissions'], true) ?? [];
                                    }
                                    $ticket_scope = $perms['ticket_scope'] ?? ($u['role'] === 'admin' ? 'all' : 'own');
                                    $org_ids = $perms['organization_ids'] ?? [];
                                    $can_archive = !empty($perms['can_archive']);
                                    $can_view_edit_history = !empty($perms['can_view_edit_history']);
                                    $can_import_md = !empty($perms['can_import_md']);

                                    // Get organization names for display
                                    $org_names = [];
                                    if (!empty($org_ids)) {
                                        foreach ($organizations as $org) {
                                            if (in_array($org['id'], $org_ids)) {
                                                $org_names[] = $org['name'];
                                            }
                                        }
                                    }
                                    // Also include primary organization if set
                                    if (!empty($u['organization_name']) && !in_array($u['organization_name'], $org_names)) {
                                        array_unshift($org_names, $u['organization_name'] . ' ★');
                                    }

                                    // Scope labels
                                    $scope_labels = [
                                        'all' => t('All tickets'),
                                        'assigned' => t('Assigned only'),
                                        'organization' => t('Organization'),
                                        'own' => t('Own tickets')
                                    ];
                                    $scope_label = $scope_labels[$ticket_scope] ?? $ticket_scope;

                                    // Scope colors (class + style pairs)
                                    $scope_colors = [
                                        'all' => ['class' => 'bg-green-100 text-green-700', 'style' => ''],
                                        'assigned' => ['class' => 'bg-yellow-100 text-yellow-700', 'style' => ''],
                                        'organization' => ['class' => 'bg-blue-100 text-blue-700', 'style' => ''],
                                        'own' => ['class' => '', 'style' => 'background: var(--surface-secondary); color: var(--text-secondary);']
                                    ];
                                    $scope_color_data = $scope_colors[$ticket_scope] ?? ['class' => '', 'style' => 'background: var(--surface-secondary); color: var(--text-secondary);'];

                                    // Role colors (class + style pairs)
                                    $role_colors = [
                                        'admin' => ['class' => 'bg-purple-100 text-purple-700', 'style' => ''],
                                        'agent' => ['class' => 'bg-blue-100 text-blue-700', 'style' => ''],
                                        'user' => ['class' => '', 'style' => 'background: var(--surface-secondary); color: var(--text-secondary);']
                                    ];
                                    $role_color_data = $role_colors[$u['role']] ?? ['class' => '', 'style' => 'background: var(--surface-secondary); color: var(--text-secondary);'];
                                    ?>
                                        <tr class="tr-hover <?php echo $u['is_active'] ? '' : 'opacity-50'; ?>">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <?php if (!empty($u['avatar'])): ?>
                                                            <img src="<?php echo e(upload_url($u['avatar'])); ?>" alt="" class="w-6 h-6 rounded-full">
                                                    <?php else: ?>
                                                            <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-medium"
                                                                style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                                                <?php echo strtoupper(substr($u['first_name'], 0, 1)); ?>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="font-medium" style="color: var(--text-primary);">
                                                            <?php echo e($u['first_name'] . ' ' . $u['last_name']); ?>
                                                        </div>
                                                        <div class="text-xs" style="color: var(--text-muted);">
                                                            <?php echo e($u['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-xs px-2 py-0.5 rounded <?php echo $role_color_data['class']; ?>" <?php echo $role_color_data['style'] ? ' style="' . $role_color_data['style'] . '"' : ''; ?>>
                                                    <?php echo e(ucfirst($u['role'])); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-xs px-2 py-0.5 rounded <?php echo $scope_color_data['class']; ?>"
                                                    <?php echo $scope_color_data['style'] ? ' style="' . $scope_color_data['style'] . '"' : ''; ?>>
                                                    <?php echo e($scope_label); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                        <span class="text-xs italic"
                                                            style="color: var(--text-muted);"><?php echo e(t('All organizations')); ?></span>
                                                <?php elseif (!empty($org_names)): ?>
                                                        <div class="flex flex-wrap gap-1">
                                                            <?php foreach (array_slice($org_names, 0, 3) as $name): ?>
                                                                    <span class="text-xs px-1.5 py-0.5 rounded"
                                                                        style="background: var(--surface-secondary); color: var(--text-secondary);"><?php echo e($name); ?></span>
                                                            <?php endforeach; ?>
                                                            <?php if (count($org_names) > 3): ?>
                                                                    <span class="text-xs"
                                                                        style="color: var(--text-muted);">+<?php echo count($org_names) - 3; ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                <?php else: ?>
                                                        <span class="text-xs" style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php elseif ($can_archive): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php else: ?>
                                                        <?php echo get_icon('x', 'w-4 h-4 text-gray-400 mx-auto'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php elseif ($can_view_edit_history): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php else: ?>
                                                        <?php echo get_icon('x', 'w-4 h-4 text-gray-400 mx-auto'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php elseif ($can_import_md): ?>
                                                        <?php echo get_icon('check', 'w-4 h-4 text-green-500 mx-auto'); ?>
                                                <?php else: ?>
                                                        <?php echo get_icon('x', 'w-4 h-4 text-gray-400 mx-auto'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <?php if ($u['is_active']): ?>
                                                        <span
                                                            class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo e(t('Active')); ?></span>
                                                <?php else: ?>
                                                        <span
                                                            class="text-xs px-2 py-0.5 rounded bg-red-100 text-red-600"><?php echo e(t('Inactive')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Legend -->
                    <div class="px-6 py-3 border-t"
                        style="background: var(--surface-secondary); border-color: var(--border-light);">
                        <div class="flex flex-wrap gap-3 text-xs" style="color: var(--text-secondary);">
                            <div>
                                <span class="font-medium"><?php echo e(t('Ticket Scope:')); ?></span>
                                <span
                                    class="ml-2 px-1.5 py-0.5 rounded bg-green-100 text-green-700"><?php echo e(t('All')); ?></span>
                                <span
                                    class="ml-1 px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700"><?php echo e(t('Assigned')); ?></span>
                                <span
                                    class="ml-1 px-1.5 py-0.5 rounded bg-blue-100 text-blue-700"><?php echo e(t('Org')); ?></span>
                                <span class="ml-1 px-1.5 py-0.5 rounded"
                                    style="background: var(--surface-secondary); color: var(--text-secondary);"><?php echo e(t('Own')); ?></span>
                            </div>
                            <div>
                                <span class="font-medium"><?php echo e(t('Organizations:')); ?></span>
                                <span class="ml-2">★ = <?php echo e(t('Primary organization')); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function toggleMatrix() {
                const matrix = document.getElementById('permissions-matrix');
                const toggleText = document.getElementById('matrix-toggle-text');
                if (matrix.classList.contains('hidden')) {
                    matrix.classList.remove('hidden');
                    toggleText.textContent = '<?php echo e(t('Hide')); ?>';
                } else {
                    matrix.classList.add('hidden');
                    toggleText.textContent = '<?php echo e(t('Show')); ?>';
                }
            }
        </script>

        <!-- Edit User Modal -->
        <div id="editModal"
            class="fixed inset-0 bg-black bg-opacity-50 hidden items-start sm:items-center justify-center z-50 overflow-y-auto p-2 sm:p-3">
            <div class="rounded-xl shadow-xl w-full max-w-2xl max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-1.5rem)] overflow-hidden flex flex-col"
                style="background: var(--surface-primary);">
                <div class="px-4 sm:px-6 py-3.5 border-b flex items-center justify-between sticky top-0 z-10"
                    style="border-color: var(--border-light); background: var(--surface-primary);">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Edit user')); ?></h3>
                    <button type="button" onclick="closeModal()" class="p-1" style="color: var(--text-muted);"
                        aria-label="<?php echo e(t('Cancel')); ?>">
                        <?php echo get_icon('x', 'w-5 h-5'); ?>
                    </button>
                </div>

                <div
                    class="p-4 sm:p-5 overflow-y-auto overscroll-contain max-h-[calc(100vh-8.5rem)] sm:max-h-[calc(100vh-9.5rem)]">

                    <!-- Avatar upload (separate form) -->
                    <div class="flex items-center gap-4 pb-3.5 mb-3.5 border-b" style="border-color: var(--border-light);">
                        <div id="edit_avatar_preview">
                            <div class="w-14 h-14 rounded-full flex items-center justify-center"
                                style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                <span class="text-lg font-bold" id="edit_avatar_initial"></span>
                            </div>
                        </div>
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-1.5"
                                style="color: var(--text-secondary);"><?php echo e(t('Avatar')); ?></label>
                            <form method="post" enctype="multipart/form-data" class="mb-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="user_id" id="edit_avatar_user_id">
                                <input type="hidden" name="upload_user_avatar" value="1">
                                <div id="user-avatar-edit-zone" class="rounded-lg p-2 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors" style="border-color: var(--border-light);">
                                    <input type="file" name="user_avatar" id="user-avatar-edit-input" accept="image/jpeg,image/png,image/gif,image/webp"
                                        class="hidden" onchange="this.form.submit()">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-base flex-shrink-0'); ?></span>
                                        <span><span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span> <?php echo e(t('or drag file')); ?></span>
                                    </div>
                                </div>
                                <p id="user-avatar-edit-filename" class="text-xs mt-1 hidden" style="color: var(--text-secondary);"></p>
                            </form>
                            <div class="flex items-center gap-2">
                                <form method="post" id="remove_avatar_form" class="inline" style="display:none;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="user_id" id="remove_avatar_user_id">
                                    <button type="submit" name="remove_user_avatar"
                                        class="btn btn-secondary btn-sm text-red-500 hover:text-red-700">
                                        <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?> <?php echo e(t('Remove')); ?>
                                    </button>
                                </form>
                            </div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">
                                <?php echo e(t('Square image recommended. Max 2 MB.')); ?>
                            </p>
                        </div>
                    </div>

                    <form method="post" id="editForm" class="space-y-3.5">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" id="edit_id">

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Email')); ?> *</label>
                            <input type="email" name="email" id="edit_email" required class="form-input">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('First name')); ?></label>
                                <input type="text" name="first_name" id="edit_first_name" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Last name')); ?></label>
                                <input type="text" name="last_name" id="edit_last_name" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Role')); ?></label>
                            <select name="role" id="edit_role" onchange="togglePermissions('edit')" class="form-select">
                                <option value="user"><?php echo e(t('User')); ?></option>
                                <option value="agent"><?php echo e(t('Agent')); ?></option>
                                <option value="admin"><?php echo e(t('Admin')); ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Language')); ?></label>
                            <select name="language" id="edit_language" class="form-select">
                                <option value="en"><?php echo e(t('English')); ?></option>
                                <option value="cs"><?php echo e(t('Czech')); ?></option>
                                <option value="de"><?php echo e(t('German')); ?></option>
                                <option value="it"><?php echo e(t('Italian')); ?></option>
                                <option value="es"><?php echo e(t('Spanish')); ?></option>
                            </select>
                        </div>

                        <?php if ($contact_phone_column_exists): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Phone')); ?></label>
                                    <input type="text" name="contact_phone" id="edit_contact_phone" class="form-input">
                                </div>
                        <?php endif; ?>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Cost rate (per hour)')); ?></label>
                            <input type="number" name="cost_rate" id="edit_cost_rate" step="0.01" min="0" class="form-input">
                        </div>

                        <?php if (!empty($organizations)): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Company')); ?></label>
                                    <select name="organization_id" id="edit_organization_id" class="form-select">
                                        <option value=""><?php echo e(t('-- No organization --')); ?></option>
                                        <?php foreach ($organizations as $org): ?>
                                                <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Organizations')); ?></label>
                                    <select name="organization_membership_ids[]" id="edit_organization_membership_ids" multiple size="5"
                                        class="form-select text-sm">
                                        <?php foreach ($organizations as $org): ?>
                                                <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                                        <?php echo e(t('Ctrl+click to select multiple organizations.')); ?>
                                    </p>
                                </div>
                        <?php endif; ?>

                        <?php if ($notes_column_exists): ?>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Notes')); ?></label>
                                    <textarea name="notes" id="edit_notes" rows="3" class="form-textarea"></textarea>
                                </div>
                        <?php endif; ?>

                        <?php if ($notification_preferences_available): ?>
                                <div class="border-t pt-4 space-y-2">
                                    <h4 class="text-sm font-semibold" style="color: var(--text-secondary);">
                                        <?php echo e(t('Notification settings')); ?>
                                    </h4>
                                    <label id="edit_email_notifications_wrap" class="flex items-center text-sm">
                                        <input type="checkbox" name="email_notifications_enabled" id="edit_email_notifications_enabled"
                                            class="mr-2">
                                        <?php echo e(t('Enable email notifications')); ?>
                                    </label>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="in_app_notifications_enabled"
                                            id="edit_in_app_notifications_enabled" class="mr-2">
                                        <?php echo e(t('Enable in-app notifications')); ?>
                                    </label>
                                    <label class="flex items-center text-sm ml-6">
                                        <input type="checkbox" name="in_app_sound_enabled" id="edit_in_app_sound_enabled" class="mr-2">
                                        <?php echo e(t('Play notification sound')); ?>
                                    </label>
                                </div>
                        <?php endif; ?>

                        <!-- Permissions (show for agents and users) -->
                        <div id="edit_permissions" class="hidden border-t pt-4">
                            <h4 class="text-sm font-semibold mb-3" style="color: var(--text-secondary);">
                                <?php echo e(t('Permissions')); ?>
                            </h4>

                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs mb-2"
                                        style="color: var(--text-secondary);"><?php echo e(t('Ticket scope:')); ?></label>
                                    <div class="space-y-2">
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="all" id="edit_scope_all"
                                                class="mr-2">
                                            <?php echo e(t('All tickets')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="assigned" id="edit_scope_assigned"
                                                class="mr-2">
                                            <?php echo e(t('Assigned tickets only')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="organization" id="edit_scope_org"
                                                class="mr-2">
                                            <?php echo e(t('Tickets from selected organizations')); ?>
                                        </label>
                                        <label class="flex items-center text-sm">
                                            <input type="radio" name="ticket_scope" value="own" id="edit_scope_own"
                                                class="mr-2">
                                            <?php echo e(t('Own tickets only')); ?>
                                        </label>
                                    </div>

                                    <?php if (!empty($organizations)): ?>
                                            <div id="edit_org_select" class="mt-2 hidden">
                                                <label class="block text-xs mb-1"
                                                    style="color: var(--text-muted);"><?php echo e(t('Select organizations (multiple allowed)')); ?></label>
                                                <select name="scope_organization_ids[]" id="edit_scope_organization_ids" multiple
                                                    size="5" class="form-select text-sm">
                                                    <?php foreach ($organizations as $org): ?>
                                                            <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                                    <?php echo e(t('Ctrl+click to select multiple organizations.')); ?>
                                                </p>
                                            </div>
                                    <?php endif; ?>
                                </div>

                                <div id="edit_can_archive_wrap">
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_archive" id="edit_can_archive" class="mr-2">
                                        <?php echo e(t('Can archive tickets')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_edit_history" id="edit_can_view_edit_history"
                                            class="mr-2">
                                        <?php echo e(t('Can view edit history')); ?>
                                    </label>
                                </div>
                                <div id="edit_can_import_md_wrap">
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_import_md" id="edit_can_import_md" class="mr-2">
                                        <?php echo e(t('Can import .md')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_time" id="edit_can_view_time" class="mr-2">
                                        <?php echo e(t('Can view time entries')); ?>
                                    </label>
                                </div>
                                <div>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="can_view_timeline" id="edit_can_view_timeline" class="mr-2">
                                        <?php echo e(t('Can view activity timeline')); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="flex items-center">
                                <input type="checkbox" name="is_active" id="edit_is_active" class="mr-2 rounded">
                                <span class="text-sm"
                                    style="color: var(--text-secondary);"><?php echo e(t('Active account')); ?></span>
                            </label>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="button" onclick="closeModal()" class="btn btn-secondary flex-1">
                                <?php echo e(t('Cancel')); ?>
                            </button>
                            <button type="submit" name="update_user" class="btn btn-primary flex-1">
                                <?php echo e(t('Save')); ?>
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <form method="post" class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" id="reset_id">
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('New password')); ?></label>
                            <input type="password" name="new_password" minlength="6" class="form-input">
                        </div>
                        <button type="submit" name="reset_password" class="btn btn-warning w-full sm:w-auto">
                            <?php echo e(t('Change password')); ?>
                        </button>
                    </form>

                    <form method="post" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" id="reset_email_id">
                        <button type="submit" name="send_reset_email" class="btn btn-secondary w-full sm:w-auto">
                            <?php echo e(t('Send password reset email')); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function togglePermissions(prefix) {
                const role = document.getElementById(prefix + '_role').value;
                const permissionsDiv = document.getElementById(prefix + '_permissions');
                const form = permissionsDiv ? permissionsDiv.closest('form') : null;
                const emailWrap = document.getElementById(prefix + '_email_notifications_wrap');
                const emailCheckbox = document.getElementById(prefix + '_email_notifications_enabled');
                const canArchiveWrap = document.getElementById(prefix + '_can_archive_wrap');
                const canArchiveInput = form ? form.querySelector('input[name="can_archive"]') : null;
                const canImportMdWrap = document.getElementById(prefix + '_can_import_md_wrap');
                const canImportMdInput = form ? form.querySelector('input[name="can_import_md"]') : null;

                // Show permissions for both agents and users (not admins)
                if ((role === 'agent' || role === 'user') && permissionsDiv) {
                    permissionsDiv.classList.remove('hidden');
                } else if (permissionsDiv) {
                    permissionsDiv.classList.add('hidden');
                }

                if (emailWrap) {
                    const allowEmailToggle = (role === 'agent' || role === 'user');
                    emailWrap.classList.toggle('hidden', !allowEmailToggle);
                    if (!allowEmailToggle && emailCheckbox) {
                        emailCheckbox.checked = true;
                    }
                }

                const isAgent = role === 'agent';
                if (canArchiveWrap) {
                    canArchiveWrap.classList.toggle('hidden', !isAgent);
                }
                if (!isAgent && canArchiveInput) {
                    canArchiveInput.checked = false;
                }
                if (canImportMdWrap) {
                    canImportMdWrap.classList.toggle('hidden', !isAgent);
                }
                if (!isAgent && canImportMdInput) {
                    canImportMdInput.checked = false;
                }
            }

            function toggleInAppSound(prefix) {
                const inAppCheckbox = document.getElementById(prefix + '_in_app_notifications_enabled');
                const soundCheckbox = document.getElementById(prefix + '_in_app_sound_enabled');
                if (!inAppCheckbox || !soundCheckbox) {
                    return;
                }
                const enabled = inAppCheckbox.checked;
                soundCheckbox.disabled = !enabled;
                if (!enabled) {
                    soundCheckbox.checked = false;
                }
            }

            // Toggle organization select based on ticket scope
            document.addEventListener('DOMContentLoaded', function () {
                const bindScopeToggle = (formId, orgContainerId) => {
                    const form = document.getElementById(formId);
                    const orgSelect = document.getElementById(orgContainerId);
                    if (!form || !orgSelect) {
                        return;
                    }
                    const sync = () => {
                        const checked = form.querySelector('input[name="ticket_scope"]:checked');
                        const isOrgScope = checked && checked.value === 'organization';
                        orgSelect.classList.toggle('hidden', !isOrgScope);
                    };
                    form.querySelectorAll('input[name="ticket_scope"]').forEach((radio) => {
                        radio.addEventListener('change', sync);
                    });
                    sync();
                };
                const bindPrimaryOrganizationSync = (primarySelectId, membershipsSelectId) => {
                    const primarySelect = document.getElementById(primarySelectId);
                    const membershipsSelect = document.getElementById(membershipsSelectId);
                    if (!primarySelect || !membershipsSelect) {
                        return;
                    }

                    primarySelect.addEventListener('change', () => {
                        const value = primarySelect.value;
                        if (!value) {
                            return;
                        }
                        for (const option of membershipsSelect.options) {
                            if (option.value === value) {
                                option.selected = true;
                            }
                        }
                    });

                    membershipsSelect.addEventListener('change', () => {
                        if (primarySelect.value) {
                            return;
                        }
                        const firstSelected = Array.from(membershipsSelect.options).find((option) => option.selected);
                        if (firstSelected) {
                            primarySelect.value = firstSelected.value;
                        }
                    });
                };

                bindScopeToggle('editForm', 'edit_org_select');
                bindScopeToggle('addUserForm', 'add_org_select');
                bindPrimaryOrganizationSync('add_organization_id', 'add_organization_membership_ids');
                bindPrimaryOrganizationSync('edit_organization_id', 'edit_organization_membership_ids');

                const addInAppCheckbox = document.getElementById('add_in_app_notifications_enabled');
                if (addInAppCheckbox) {
                    addInAppCheckbox.addEventListener('change', function () {
                        toggleInAppSound('add');
                    });
                    toggleInAppSound('add');
                }

                const editInAppCheckbox = document.getElementById('edit_in_app_notifications_enabled');
                if (editInAppCheckbox) {
                    editInAppCheckbox.addEventListener('change', function () {
                        toggleInAppSound('edit');
                    });
                    toggleInAppSound('edit');
                }

                const timeRangeSelect = document.getElementById('users-time-range');
                const customRange = document.getElementById('users-custom-range');
                if (timeRangeSelect && customRange) {
                    const toggleCustom = () => {
                        customRange.classList.toggle('hidden', timeRangeSelect.value !== 'custom');
                    };
                    timeRangeSelect.addEventListener('change', toggleCustom);
                    toggleCustom();
                }

                const editModal = document.getElementById('editModal');
                if (editModal) {
                    editModal.addEventListener('click', function (event) {
                        if (event.target === editModal) {
                            closeModal();
                        }
                    });
                }

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && editModal && !editModal.classList.contains('hidden')) {
                        closeModal();
                    }
                });

                togglePermissions('add');
                togglePermissions('edit');
                toggleInAppSound('add');
                toggleInAppSound('edit');
            });

            function editUser(user) {
                document.getElementById('edit_id').value = user.id;
                document.getElementById('reset_id').value = user.id;
                document.getElementById('reset_email_id').value = user.id;
                document.getElementById('edit_email').value = user.email || '';
                document.getElementById('edit_first_name').value = user.first_name;
                document.getElementById('edit_last_name').value = user.last_name || '';
                document.getElementById('edit_role').value = user.role;
                document.getElementById('edit_language').value = user.language || 'en';
                document.getElementById('edit_is_active').checked = user.is_active == 1;

                // Avatar preview
                document.getElementById('edit_avatar_user_id').value = user.id;
                document.getElementById('remove_avatar_user_id').value = user.id;
                const avatarPreview = document.getElementById('edit_avatar_preview');
                const removeAvatarForm = document.getElementById('remove_avatar_form');
                while (avatarPreview.firstChild) avatarPreview.removeChild(avatarPreview.firstChild);
                if (user.avatar) {
                    const img = document.createElement('img');
                    img.src = user.avatar;
                    img.alt = '';
                    img.className = 'w-14 h-14 rounded-full object-cover';
                    avatarPreview.appendChild(img);
                    removeAvatarForm.style.display = 'inline';
                } else {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'w-14 h-14 rounded-full flex items-center justify-center';
                    wrapper.style.cssText = 'background: var(--surface-tertiary); color: var(--text-secondary);';
                    const span = document.createElement('span');
                    span.className = 'text-lg font-bold';
                    span.textContent = ((user.first_name || '?').charAt(0)).toUpperCase();
                    wrapper.appendChild(span);
                    avatarPreview.appendChild(wrapper);
                    removeAvatarForm.style.display = 'none';
                }

                const contactPhoneInput = document.getElementById('edit_contact_phone');
                if (contactPhoneInput) {
                    contactPhoneInput.value = user.contact_phone || '';
                }
                const notesInput = document.getElementById('edit_notes');
                if (notesInput) {
                    notesInput.value = user.notes || '';
                }

                const emailCheckbox = document.getElementById('edit_email_notifications_enabled');
                if (emailCheckbox) {
                    emailCheckbox.checked = parseInt(user.email_notifications_enabled ?? 1, 10) === 1;
                }
                const inAppCheckbox = document.getElementById('edit_in_app_notifications_enabled');
                if (inAppCheckbox) {
                    inAppCheckbox.checked = parseInt(user.in_app_notifications_enabled ?? 1, 10) === 1;
                }
                const inAppSoundCheckbox = document.getElementById('edit_in_app_sound_enabled');
                if (inAppSoundCheckbox) {
                    inAppSoundCheckbox.checked = parseInt(user.in_app_sound_enabled ?? 0, 10) === 1;
                }
                const costRateInput = document.getElementById('edit_cost_rate');
                if (costRateInput) {
                    costRateInput.value = user.cost_rate || '';
                }

                const orgSelect = document.getElementById('edit_organization_id');
                if (orgSelect) {
                    orgSelect.value = user.organization_id || '';
                }

                const canArchive = document.getElementById('edit_can_archive');
                if (canArchive) {
                    canArchive.checked = false;
                }
                const canViewEditHistory = document.getElementById('edit_can_view_edit_history');
                if (canViewEditHistory) {
                    canViewEditHistory.checked = false;
                }
                const canImportMd = document.getElementById('edit_can_import_md');
                if (canImportMd) {
                    canImportMd.checked = false;
                }
                const canViewTime = document.getElementById('edit_can_view_time');
                if (canViewTime) {
                    canViewTime.checked = false;
                }
                const canViewTimeline = document.getElementById('edit_can_view_timeline');
                if (canViewTimeline) {
                    canViewTimeline.checked = false;
                }

                const defaultScopeRadios = document.querySelectorAll('#editForm input[name="ticket_scope"]');
                defaultScopeRadios.forEach((radio) => {
                    radio.checked = radio.value === 'all';
                });

                const defaultScopeOrgSelect = document.getElementById('edit_scope_organization_ids');
                if (defaultScopeOrgSelect) {
                    for (const option of defaultScopeOrgSelect.options) {
                        option.selected = false;
                    }
                }

                const membershipSelect = document.getElementById('edit_organization_membership_ids');
                if (membershipSelect) {
                    for (const option of membershipSelect.options) {
                        option.selected = false;
                    }
                }

                let permissions = {};
                if ((user.role === 'agent' || user.role === 'user') && user.permissions) {
                    try {
                        permissions = typeof user.permissions === 'string' ? JSON.parse(user.permissions) : user.permissions;
                    } catch (e) {
                        permissions = {};
                    }
                }
                if (typeof permissions !== 'object' || permissions === null) {
                    permissions = {};
                }

                if (user.role === 'user' && !permissions.ticket_scope) {
                    permissions.ticket_scope = user.organization_id ? 'organization' : 'own';
                }

                const scopeValue = permissions.ticket_scope || 'all';
                defaultScopeRadios.forEach((radio) => {
                    radio.checked = radio.value === scopeValue;
                });

                const permissionOrgIds = Array.isArray(permissions.organization_ids)
                    ? permissions.organization_ids
                    : (permissions.organization_ids ? [permissions.organization_ids] : []);

                if (scopeValue === 'organization' && defaultScopeOrgSelect) {
                    const selected = new Set(permissionOrgIds.map((id) => parseInt(id, 10)).filter((id) => !Number.isNaN(id) && id > 0));
                    for (const option of defaultScopeOrgSelect.options) {
                        option.selected = selected.has(parseInt(option.value, 10));
                    }
                    const editOrgSelect = document.getElementById('edit_org_select');
                    if (editOrgSelect) {
                        editOrgSelect.classList.remove('hidden');
                    }
                } else {
                    const editOrgSelect = document.getElementById('edit_org_select');
                    if (editOrgSelect) {
                        editOrgSelect.classList.add('hidden');
                    }
                }

                if (canArchive) {
                    canArchive.checked = permissions.can_archive === true;
                }
                if (canViewEditHistory) {
                    canViewEditHistory.checked = permissions.can_view_edit_history === true;
                }
                if (canImportMd) {
                    canImportMd.checked = permissions.can_import_md === true;
                }
                if (canViewTime) {
                    canViewTime.checked = permissions.can_view_time === true;
                }
                if (canViewTimeline) {
                    canViewTimeline.checked = permissions.can_view_timeline === true;
                }

                if (membershipSelect) {
                    const membershipIds = new Set();
                    if (user.organization_id) {
                        membershipIds.add(parseInt(user.organization_id, 10));
                    }
                    permissionOrgIds.forEach((id) => {
                        const parsed = parseInt(id, 10);
                        if (!Number.isNaN(parsed) && parsed > 0) {
                            membershipIds.add(parsed);
                        }
                    });
                    for (const option of membershipSelect.options) {
                        option.selected = membershipIds.has(parseInt(option.value, 10));
                    }
                }

                togglePermissions('edit');
                toggleInAppSound('edit');

                document.getElementById('editModal').classList.remove('hidden');
                document.getElementById('editModal').classList.add('flex');
                document.body.classList.add('overflow-hidden');

                const editModalBody = document.querySelector('#editModal .overflow-y-auto');
                if (editModalBody) {
                    editModalBody.scrollTop = 0;
                }

                const emailInput = document.getElementById('edit_email');
                if (emailInput) {
                    emailInput.focus();
                    emailInput.select();
                }
            }

            function closeModal() {
                document.getElementById('editModal').classList.add('hidden');
                document.getElementById('editModal').classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
            }

            // User avatar edit – drag & drop (must wait for DOMContentLoaded so app-footer.js defer script is loaded)
            function initUserAvatarDropzone() {
                if (!window.initFileDropzone) return;
                window.initFileDropzone({
                    zoneId: 'user-avatar-edit-zone',
                    inputId: 'user-avatar-edit-input',
                    acceptedExtensions: ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
                    invalidTypeMessage: '<?php echo e(t('Invalid file type.')); ?>',
                    onFilesChanged: function(files) {
                        var label = document.getElementById('user-avatar-edit-filename');
                        if (label && files.length > 0) {
                            label.textContent = files[0].name;
                            label.classList.remove('hidden');
                            var form = document.getElementById('user-avatar-edit-input')?.closest('form');
                            if (form) form.submit();
                        }
                    }
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initUserAvatarDropzone);
            } else {
                initUserAvatarDropzone();
            }
        </script>

<?php endif; // end AI Agents tab vs Users tab ?>

<?php require_once BASE_PATH . '/includes/footer.php';
