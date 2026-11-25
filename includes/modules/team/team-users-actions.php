<?php
/**
 * Team management POST actions.
 *
 * Included by the users controller after its request context is prepared.
 */

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
        $organization_assignment = team_users_normalize_organization_assignment(
            $_POST['organization_id'] ?? null,
            $_POST['organization_membership_ids'] ?? [],
            $valid_organization_ids
        );
        $organization_id = $organization_assignment['organization_id'];
        $organization_membership_ids = $organization_assignment['organization_membership_ids'];
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;

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
                $language_input = trim((string) ($_POST['language'] ?? ''));
                $language = $language_input === '' ? null : normalize_locale_tag($language_input);
                if ($language_input !== '' && $language === null) {
                    flash(t('Language not supported.'), 'error');
                    redirect('admin', ['section' => 'users']);
                }
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
                    $updates['language'] = $language;
                    if ($contact_phone_column_exists) {
                        $updates['contact_phone'] = $contact_phone !== '' ? $contact_phone : null;
                    }
                    if ($notes_column_exists) {
                        $updates['notes'] = $notes !== '' ? $notes : null;
                    }

                    $permissions_data = team_users_permission_payload(
                        $role,
                        $organization_id,
                        $organization_membership_ids,
                        $_POST,
                        $valid_organization_ids
                    );
                    if ($permissions_data !== null) {
                        $updates['permissions'] = json_encode($permissions_data);
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
                        $lang = $language ?? foxdesk_workspace_language();

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

                            $sent = send_ticket_notification_email($email, $subject, $body, [
                                'language' => $lang,
                                'eyebrow' => 'Welcome to FoxDesk',
                                'title' => $subject,
                                'cta_label' => 'Log in',
                                'cta_url' => $login_url,
                            ], true);
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
        $organization_assignment = team_users_normalize_organization_assignment(
            $_POST['organization_id'] ?? null,
            $_POST['organization_membership_ids'] ?? [],
            $valid_organization_ids
        );
        $organization_id = $organization_assignment['organization_id'];
        $organization_membership_ids = $organization_assignment['organization_membership_ids'];
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;

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

        $permission_payload = team_users_permission_payload(
            $role,
            $organization_id,
            $organization_membership_ids,
            $_POST,
            $valid_organization_ids
        );
        $permissions = $permission_payload !== null ? json_encode($permission_payload) : null;

        $language_input = trim((string) ($_POST['language'] ?? ''));
        $language = $language_input === '' ? null : normalize_locale_tag($language_input);
        if ($language_input !== '' && $language === null) {
            flash(t('Language not supported.'), 'error');
            redirect('admin', ['section' => 'users']);
        }

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
            if (update_password($id, $new_password) !== false) {
                flash(t('Password updated.'), 'success');
            } else {
                flash(t('Security check failed. Please try again.'), 'error');
            }
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
        $permission_input = $_POST;
        if (!isset($permission_input['ticket_scope'])) {
            $permission_input['ticket_scope'] = 'assigned';
        }
        $organization_assignment = team_users_normalize_organization_assignment(
            $permission_input['organization_id'] ?? null,
            $permission_input['scope_organization_ids'] ?? [],
            $valid_organization_ids
        );
        $organization_id = $organization_assignment['organization_id'];
        $organization_membership_ids = $organization_assignment['organization_membership_ids'];
        $permissions_data = team_users_permission_payload(
            'agent',
            $organization_id,
            $organization_membership_ids,
            $permission_input,
            $valid_organization_ids
        );

        if (empty($agent_name)) {
            flash(t('Please fill in all required fields.'), 'error');
        } else {
            // Generate unique email for system user
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($agent_name));
            $email = 'ai-' . $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '@system.local';
            $password = bin2hex(random_bytes(32));

            try {
                $user_id = create_user($email, $password, $agent_name, '', 'agent', null);
            } catch (Throwable $e) {
                $user_id = false;
                error_log('AI agent create failed: ' . $e->getMessage());
            }
            if ($user_id) {
                db_update('users', [
                    'is_ai_agent' => 1,
                    'ai_model' => $ai_model !== '' ? $ai_model : null,
                    'cost_rate' => $cost_rate,
                    'organization_id' => $organization_id,
                    'permissions' => $permissions_data !== null ? json_encode($permissions_data) : null,
                ], 'id = ?', [$user_id]);

                // Auto-generate API token
                if (function_exists('generate_api_token')) {
                    $token_scopes = function_exists('team_ai_agent_token_scopes_from_input')
                        ? team_ai_agent_token_scopes_from_input($permission_input)
                        : null;
                    $token_result = generate_api_token($user_id, $agent_name, null, $token_scopes);
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
    if ((isset($_POST['update_ai_agent']) || isset($_POST['save_and_generate_agent_token'])) && $ai_agent_col_exists) {
        $id = (int) $_POST['id'];
        $agent_name = trim($_POST['agent_name'] ?? '');
        $ai_model = trim($_POST['ai_model'] ?? '');
        $cost_rate_input = trim($_POST['cost_rate'] ?? '');
        $cost_rate = $cost_rate_input !== '' ? (float) str_replace(',', '.', $cost_rate_input) : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $permission_input = $_POST;
        if (!isset($permission_input['ticket_scope'])) {
            $permission_input['ticket_scope'] = 'assigned';
        }
        $organization_assignment = team_users_normalize_organization_assignment(
            $permission_input['organization_id'] ?? null,
            $permission_input['scope_organization_ids'] ?? [],
            $valid_organization_ids
        );
        $organization_id = $organization_assignment['organization_id'];
        $organization_membership_ids = $organization_assignment['organization_membership_ids'];
        $permissions_data = team_users_permission_payload(
            'agent',
            $organization_id,
            $organization_membership_ids,
            $permission_input,
            $valid_organization_ids
        );

        if ($id > 0 && !empty($agent_name)) {
            db_update('users', [
                'first_name' => $agent_name,
                'ai_model' => $ai_model !== '' ? $ai_model : null,
                'cost_rate' => $cost_rate,
                'is_active' => $is_active,
                'organization_id' => $organization_id,
                'permissions' => $permissions_data !== null ? json_encode($permissions_data) : null,
            ], 'id = ? AND is_ai_agent = 1', [$id]);

            if (isset($_POST['save_and_generate_agent_token']) && function_exists('generate_api_token')) {
                if (function_exists('team_ai_agent_revoke_active_tokens')) {
                    team_ai_agent_revoke_active_tokens($id);
                }
                $token_scopes = function_exists('team_ai_agent_token_scopes_from_input')
                    ? team_ai_agent_token_scopes_from_input($permission_input)
                    : null;
                $token_result = generate_api_token($id, $agent_name, null, $token_scopes);
                if ($token_result && !empty($token_result['token'])) {
                    $_SESSION['new_ai_agent_token'] = $token_result['token'];
                    $_SESSION['new_ai_agent_id'] = $id;
                    flash(t('API token generated. Copy it now.'), 'success');
                } else {
                    flash(t('Failed to generate token.'), 'error');
                }
            } else {
                flash(t('Settings saved.'), 'success');
            }
        }
        redirect('admin', ['section' => 'users', 'tab' => 'ai_agents']);
    }

    // Generate token for AI agent
    if (isset($_POST['generate_agent_token']) && $ai_agent_col_exists) {
        $id = (int) $_POST['id'];
        $agent = db_fetch_one("SELECT id, first_name, is_ai_agent FROM users WHERE id = ? AND is_ai_agent = 1", [$id]);
        if ($agent && function_exists('generate_api_token')) {
            if (function_exists('team_ai_agent_revoke_active_tokens')) {
                team_ai_agent_revoke_active_tokens($id);
            }
            $token_scopes = function_exists('team_ai_agent_token_scopes_from_input')
                ? team_ai_agent_token_scopes_from_input($_POST)
                : null;
            $token_result = generate_api_token($id, $agent['first_name'], null, $token_scopes);
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
                $result = upload_file($_FILES['user_avatar'], $allowed, 2 * 1024 * 1024, 'public');
                // Delete old avatar file if exists
                if (!empty($target_user['avatar']) && strpos($target_user['avatar'], 'data:') !== 0) {
                    $old_path = function_exists('upload_absolute_path') ? upload_absolute_path($target_user['avatar']) : (BASE_PATH . '/' . UPLOAD_DIR . basename($target_user['avatar']));
                    if ($old_path && file_exists($old_path)) {
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
                $old_path = function_exists('upload_absolute_path') ? upload_absolute_path($target_user['avatar']) : (BASE_PATH . '/' . UPLOAD_DIR . basename($target_user['avatar']));
                if ($old_path && file_exists($old_path)) {
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
