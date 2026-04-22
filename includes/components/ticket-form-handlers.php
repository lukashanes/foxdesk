<?php
/**
 * Ticket Detail — POST Form Handlers
 *
 * Handles all form submissions on the ticket detail page:
 * - Share links, ticket edit
 * - Timer start/stop, comment submission
 * - Status change, assignment, due date, company
 * - Archive/restore, time entry CRUD
 *
 * Required variables (set by ticket-detail.php before include):
 *   $ticket, $ticket_id, $user,
 *   $organizations, $org_billable_rate, $user_cost_rate
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    // Create share link
    if (isset($_POST['create_share_link'])) {
        $expires_input = trim($_POST['share_expires_at'] ?? '');
        $expires_at = null;

        if ($expires_input !== '') {
            $timestamp = strtotime($expires_input);
            if ($timestamp === false) {
                flash(t('Invalid expiration date.'), 'error');
                redirect('ticket', ['id' => $ticket_id]);
            }
            if ($timestamp <= time()) {
                flash(t('Expiration must be in the future.'), 'error');
                redirect('ticket', ['id' => $ticket_id]);
            }
            $expires_at = date('Y-m-d H:i:s', $timestamp);
        }

        try {
            $share = create_ticket_share($ticket_id, $user['id'], $expires_at);
            $_SESSION['share_token'] = $share['token'];
            $_SESSION['share_token_ticket_id'] = $ticket_id;
            log_activity($ticket_id, $user['id'], 'share_created', 'Public share link created');
            flash(t('Share link created.'), 'success');
        } catch (Exception $e) {
            flash(t('Failed to create share link.'), 'error');
        }

        redirect('ticket', ['id' => $ticket_id]);
    }

    // Revoke share link
    if (isset($_POST['revoke_share_link'])) {
        $revoked = revoke_ticket_shares($ticket_id);
        if ($revoked > 0) {
            log_activity($ticket_id, $user['id'], 'share_revoked', 'Public share link revoked');
            flash(t('Share link revoked.'), 'success');
        } else {
            flash(t('No active share link to revoke.'), 'error');
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Add shared access user
    if (isset($_POST['add_shared_user']) && is_agent()) {
        $shared_user_id = (int) ($_POST['shared_user_id'] ?? 0);
        if ($shared_user_id > 0) {
            $added = add_ticket_access($ticket_id, $shared_user_id, $user['id']);
            if ($added) {
                $shared_user = get_user($shared_user_id);
                $label = $shared_user ? ($shared_user['first_name'] . ' ' . $shared_user['last_name']) : ('User ID ' . $shared_user_id);
                log_activity($ticket_id, $user['id'], 'access_granted', "Access granted to {$label}");
                flash(t('User access added.'), 'success');
            } else {
                flash(t('User already has access or could not be added.'), 'error');
            }
        } else {
            flash(t('Select a user to add.'), 'error');
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Remove shared access user
    if (isset($_POST['remove_shared_user']) && is_agent()) {
        $shared_user_id = (int) ($_POST['shared_user_id'] ?? 0);
        if ($shared_user_id > 0) {
            $removed = remove_ticket_access($ticket_id, $shared_user_id);
            if ($removed) {
                $shared_user = get_user($shared_user_id);
                $label = $shared_user ? ($shared_user['first_name'] . ' ' . $shared_user['last_name']) : ('User ID ' . $shared_user_id);
                log_activity($ticket_id, $user['id'], 'access_revoked', "Access revoked for {$label}");
                flash(t('User access removed.'), 'success');
            } else {
                flash(t('User access could not be removed.'), 'error');
            }
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Update ticket (edit)
    if (isset($_POST['update_ticket'])) {
        if (!can_edit_ticket($ticket, $user)) {
            flash(t('You do not have permission to edit this ticket.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        $new_title = trim($_POST['edit_title'] ?? '');
        $new_description = trim($_POST['edit_description'] ?? '');
        $new_tags = trim($_POST['edit_tags'] ?? '');

        if (empty($new_title)) {
            flash(t('Subject is required.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        // Prepare update data
        $update_data = [
            'title' => $new_title,
            'description' => $new_description
        ];
        if (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
            $normalized_tags = normalize_ticket_tags($new_tags);
            $update_data['tags'] = $normalized_tags !== '' ? $normalized_tags : null;
        }

        if (is_agent() && array_key_exists('edit_organization_id', $_POST)) {
            $new_org_id = null;
            $org_input = trim((string) ($_POST['edit_organization_id'] ?? ''));
            if ($org_input !== '') {
                $candidate_org_id = (int) $org_input;
                $org_allowed = false;
                foreach ($organizations as $org) {
                    if ((int) ($org['id'] ?? 0) === $candidate_org_id) {
                        $org_allowed = true;
                        break;
                    }
                }
                if (!$org_allowed) {
                    flash(t('Selected organization is not available.'), 'error');
                    redirect('ticket', ['id' => $ticket_id]);
                }
                $new_org_id = $candidate_org_id;
            }
            $update_data['organization_id'] = $new_org_id;
        }

        // Update with history tracking
        if (update_ticket_with_history($ticket_id, $update_data, $user['id'])) {
            log_activity($ticket_id, $user['id'], 'ticket_edited', 'Ticket details updated');
            flash(t('Ticket updated.'), 'success');
        } else {
            flash(t('Failed to update ticket.'), 'error');
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Start timer
    if (isset($_POST['start_timer']) && is_agent()) {
        if (!ticket_time_table_exists()) {
            flash(t('Time tracking is not available.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        $active = get_active_ticket_timer($ticket_id, $user['id']);
        if ($active) {
            flash(t('Timer is already running.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        $timer_insert = [
            'ticket_id' => $ticket_id,
            'user_id' => $user['id'],
            'started_at' => date('Y-m-d H:i:s'),
            'ended_at' => null,
            'duration_minutes' => 0,
            'is_billable' => 1,
            'billable_rate' => $org_billable_rate,
            'cost_rate' => $user_cost_rate,
            'is_manual' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        if (function_exists('time_entry_source_column_exists') && time_entry_source_column_exists()) {
            $timer_insert['source'] = 'timer';
        }
        db_insert('ticket_time_entries', $timer_insert);
        log_activity($ticket_id, $user['id'], 'time_started', 'Timer started');
        flash(t('Timer started.'), 'success');
        redirect('ticket', ['id' => $ticket_id]);
    }

    if (isset($_POST['stop_timer_now']) && is_agent()) {
        if (!ticket_time_table_exists()) {
            flash(t('Time tracking is not available.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        $active = get_active_ticket_timer($ticket_id, $user['id']);
        if (!$active) {
            flash(t('No active timer found.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        $start_ts = strtotime($active['started_at']);
        $end_ts = time();
        $duration = max(0, (int) floor(($end_ts - $start_ts) / 60));

        db_update('ticket_time_entries', [
            'ended_at' => date('Y-m-d H:i:s'),
            'duration_minutes' => $duration
        ], 'id = ?', [$active['id']]);

        log_activity($ticket_id, $user['id'], 'time_stopped', "Timer stopped ({$duration} min)");
        flash(t('Timer stopped.'), 'success');
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Add comment
    if (isset($_POST['add_comment'])) {
        $skip_notification = isset($_POST['skip_notification']) && $_POST['skip_notification'] == '1';
        $attachment_upload_errors = [];
        $has_uploadable_attachments = false;
        if (!empty($_FILES['comment_attachments']['name'][0])) {
            $attachment_names = $_FILES['comment_attachments']['name'] ?? [];
            $attachment_errors = $_FILES['comment_attachments']['error'] ?? [];
            foreach ($attachment_names as $attachment_index => $attachment_name) {
                if (trim((string) $attachment_name) === '') {
                    continue;
                }

                $error_code = (int) ($attachment_errors[$attachment_index] ?? UPLOAD_ERR_NO_FILE);
                if ($error_code === UPLOAD_ERR_OK) {
                    $has_uploadable_attachments = true;
                    continue;
                }

                if ($error_code !== UPLOAD_ERR_NO_FILE) {
                    $attachment_upload_errors[] = $attachment_name . ': ' . get_upload_error_message($error_code, get_max_upload_size());
                }
            }
        }
        $has_attachments = $has_uploadable_attachments;
        // Handle status change first (if agent)
        if (isset($_POST['change_status_with_comment']) && is_agent()) {
            $new_status_id = (int) ($_POST['status_id'] ?? $ticket['status_id']);

            // Only update if status changed
            if ($new_status_id != $ticket['status_id']) {
                $old_status = get_status($ticket['status_id']);
                $new_status = get_status($new_status_id);

                db_update('tickets', ['status_id' => $new_status_id], 'id = ?', [$ticket_id]);
                log_activity($ticket_id, $user['id'], 'status_changed', "Status changed from '{$old_status['name']}' to '{$new_status['name']}'");

                // Send status change notification (unless skipped)
                if (!$skip_notification) {
                    require_once BASE_PATH . '/includes/mailer.php';
                    send_status_change_notification($ticket, $old_status, $new_status, '', 0);
                }

                // In-app notification for status change
                if (function_exists('dispatch_ticket_notifications')) {
                    dispatch_ticket_notifications('status_changed', $ticket_id, $user['id'], [
                        'old_status' => $old_status['name'] ?? '',
                        'new_status' => $new_status['name'] ?? '',
                    ]);
                }

                // Auto-resolve action notifications if ticket is now closed
                if (!empty($new_status['is_closed']) && function_exists('resolve_action_notifications')) {
                    resolve_action_notifications($ticket_id);
                }
            }
        }

        $is_internal = isset($_POST['is_internal']) && is_agent() ? 1 : 0;

        // Use internal_text if internal comment is checked, otherwise use regular comment
        if ($is_internal && !empty($_POST['internal_text'])) {
            $content = trim($_POST['internal_text']);
        } else {
            $content = trim($_POST['comment'] ?? '');
        }

        $cc_users = isset($_POST['cc_users']) ? array_map('intval', $_POST['cc_users']) : [];
        $stop_timer = is_agent() && isset($_POST['stop_timer']);
        $manual_date_input = trim($_POST['manual_date'] ?? date('Y-m-d'));
        $manual_duration_input = trim((string) ($_POST['manual_duration_minutes'] ?? ''));
        $manual_start_time_input = trim($_POST['manual_start_time'] ?? '');
        $manual_end_time_input = trim($_POST['manual_end_time'] ?? '');
        $manual_start_at_input = trim((string) ($_POST['manual_start_at'] ?? ''));
        $manual_end_at_input = trim((string) ($_POST['manual_end_at'] ?? ''));
        $manual_start_input = '';
        $manual_end_input = '';
        $manual_duration_minutes = $manual_duration_input !== '' ? (int) $manual_duration_input : 0;
        $manual_quick_requested = is_agent() && $manual_duration_input !== '';

        if ($manual_start_time_input !== '' || $manual_end_time_input !== '') {
            $base_date = $manual_date_input !== '' ? $manual_date_input : date('Y-m-d');
            $manual_start_input = $base_date . 'T' . $manual_start_time_input;
            // Midnight overflow: if end time is earlier than start time, it's the next day
            $end_date = $base_date;
            if ($manual_end_time_input < $manual_start_time_input) {
                $end_date = date('Y-m-d', strtotime($base_date . ' +1 day'));
            }
            $manual_end_input = $end_date . 'T' . $manual_end_time_input;
        }

        $manual_range_requested = is_agent() && ($manual_start_input !== '' || $manual_end_input !== '' || $manual_start_time_input !== '' || $manual_end_time_input !== '');
        $manual_snapshot_requested = $manual_quick_requested && $manual_start_at_input !== '' && $manual_end_at_input !== '';
        $manual_requested = $manual_range_requested || $manual_quick_requested;
        $log_time_requested = $stop_timer || $manual_requested;
        $comment_time_spent = 0;
        $manual_start_dt = null;
        $manual_end_dt = null;
        $manual_duration = 0;
        $active_timer = null;
        $timer_duration = 0;

        if ($log_time_requested && !ticket_time_table_exists()) {
            flash(t('Time tracking is not available.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        if ($manual_requested) {
            if ($manual_quick_requested && ($manual_duration_minutes < 1 || $manual_duration_minutes > 1440)) {
                flash(t('Duration must be between 1 and 1440 minutes.'), 'error');
                redirect('ticket', ['id' => $ticket_id]);
            }

            if ($manual_snapshot_requested) {
                $manual_start_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $manual_start_at_input);
                $manual_end_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $manual_end_at_input);
            } elseif ($manual_quick_requested && !$manual_range_requested) {
                $manual_end_dt = new DateTime();
                $manual_start_dt = (clone $manual_end_dt)->modify('-' . $manual_duration_minutes . ' minutes');
            } else {
                if ($manual_start_input === '' || $manual_end_input === '') {
                    flash(t('Start and end time are required.'), 'error');
                    redirect('ticket', ['id' => $ticket_id]);
                }

                $manual_start_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $manual_start_input);
                $manual_end_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $manual_end_input);
            }

            if (!$manual_start_dt || !$manual_end_dt) {
                flash(t('Invalid time range.'), 'error');
                redirect('ticket', ['id' => $ticket_id]);
            }

            if ($manual_end_dt <= $manual_start_dt) {
                flash(t('End time must be after start time.'), 'error');
                redirect('ticket', ['id' => $ticket_id]);
            }

            $manual_duration = max(0, (int) floor(($manual_end_dt->getTimestamp() - $manual_start_dt->getTimestamp()) / 60));
            $comment_time_spent += $manual_duration;
        }

        if ($stop_timer) {
            $active_timer = get_active_ticket_timer($ticket_id, $user['id']);
            if (!$active_timer) {
                // No active timer — silently ignore stop request (stale checkbox)
                $stop_timer = false;
                $log_time_requested = $manual_requested;
            } else {
                // Calculate duration accounting for paused time
                $elapsed_seconds = calculate_timer_elapsed($active_timer);
                $timer_duration = max(0, (int) floor($elapsed_seconds / 60));
                $comment_time_spent += $timer_duration;
            }
        }

        // Add comment only if there's content or attachments
        $comment_id = null;
        $should_create_comment = (!empty($content) || $has_attachments);
        if ($should_create_comment) {
            $comment_id = db_insert('comments', [
                'ticket_id' => $ticket_id,
                'user_id' => $user['id'],
                'content' => $content,
                'is_internal' => $is_internal,
                'time_spent' => $comment_time_spent,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Touch ticket so "Last updated" sorting works
            db_query("UPDATE tickets SET updated_at = NOW() WHERE id = ?", [$ticket_id]);

            log_activity($ticket_id, $user['id'], 'commented', 'Comment added');
        }

        // Handle file uploads for comment (if any)
        $uploaded_attachments = [];
        if ($comment_id && !empty($_FILES['comment_attachments']['name'][0])) {
            $files = $_FILES['comment_attachments'];

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                try {
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];

                    $result = upload_file($file);

                    db_insert('attachments', [
                        'ticket_id' => $ticket_id,
                        'comment_id' => $comment_id,
                        'filename' => $result['filename'],
                        'original_name' => $result['original_name'],
                        'mime_type' => $result['mime_type'],
                        'file_size' => $result['file_size'],
                        'uploaded_by' => $user['id'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    $uploaded_attachments[] = $result;
                } catch (Exception $e) {
                    $attachment_upload_errors[] = $files['name'][$i] . ': ' . $e->getMessage();
                }
            }

            if (!empty($uploaded_attachments)) {
                $attachment_names = [];
                foreach ($uploaded_attachments as $attachment_row) {
                    $attachment_names[] = $attachment_row['original_name'] ?? $attachment_row['filename'] ?? '';
                }
                $attachment_names = array_values(array_filter($attachment_names, function ($name) {
                    return trim((string) $name) !== '';
                }));

                if (!empty($attachment_names)) {
                    log_activity($ticket_id, $user['id'], 'attachments_added', 'Added attachments: ' . implode(', ', $attachment_names));
                    if (function_exists('log_ticket_history')) {
                        foreach ($attachment_names as $attachment_name) {
                            log_ticket_history($ticket_id, $user['id'], 'attachment_added', null, $attachment_name);
                        }
                    }
                }
            }
        }

        // Log time entries (if requested)
        if ($log_time_requested) {
            if ($stop_timer && $active_timer) {
                db_update('ticket_time_entries', [
                    'ended_at' => date('Y-m-d H:i:s'),
                    'duration_minutes' => $timer_duration,
                    'comment_id' => $comment_id
                ], 'id = ?', [$active_timer['id']]);
                log_activity($ticket_id, $user['id'], 'time_stopped', "Timer stopped ({$timer_duration} min)");
            }

            if ($manual_requested && !($stop_timer && $active_timer) && $manual_start_dt && $manual_end_dt) {
                $manual_insert = [
                    'ticket_id' => $ticket_id,
                    'user_id' => $user['id'],
                    'comment_id' => $comment_id,
                    'started_at' => $manual_start_dt->format('Y-m-d H:i:s'),
                    'ended_at' => $manual_end_dt->format('Y-m-d H:i:s'),
                    'duration_minutes' => $manual_duration,
                    'is_billable' => 1,
                    'billable_rate' => $org_billable_rate,
                    'cost_rate' => $user_cost_rate,
                    'is_manual' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                if (function_exists('time_entry_source_column_exists') && time_entry_source_column_exists()) {
                    $manual_insert['source'] = 'manual';
                }
                db_insert('ticket_time_entries', $manual_insert);
                log_activity($ticket_id, $user['id'], 'time_manual', "Manual time added ({$manual_duration} min)");
            }
        }

        // Send notification (only for non-internal comments with content and if not skipped)

        if ($comment_id && !$is_internal && !empty($content) && !$skip_notification) {
            require_once BASE_PATH . '/includes/mailer.php';
            $comment_data = [
                'content' => $content,
                'time_spent' => $comment_time_spent
            ];
            send_new_comment_notification($ticket, $comment_data, $user, $comment_id, $uploaded_attachments, $cc_users);
        }

        // In-app notification for new comment
        if ($comment_id && !$is_internal && !empty($content) && function_exists('dispatch_ticket_notifications')) {
            $preview = mb_strlen($content) > 80 ? mb_substr($content, 0, 77) . '...' : $content;
            dispatch_ticket_notifications('new_comment', $ticket_id, $user['id'], [
                'comment_preview' => strip_tags($preview),
                'comment_id' => $comment_id,
            ]);
        }

        // If assignee is commenting, resolve their action-required notifications
        if ($comment_id && !empty($ticket['assignee_id']) && (int)$ticket['assignee_id'] === (int)$user['id']
            && function_exists('resolve_action_notifications')) {
            resolve_action_notifications($ticket_id, (int)$user['id']);
        }

        // Flash message based on what was done
        if (!empty($content)) {
            flash(t('Comment added.'), 'success');
        } elseif ($log_time_requested) {
            flash(t('Time entry added.'), 'success');
        } elseif (isset($_POST['change_status_with_comment']) && is_agent()) {
            flash(t('Status updated.'), 'success');
        }

        if (!empty($attachment_upload_errors)) {
            flash(t('Some attachments could not be uploaded: {errors}', ['errors' => implode(', ', $attachment_upload_errors)]), 'error');
        }

        // Redirect to referrer if provided (e.g. back to ticket list after status change)
        $redirect_back = trim($_POST['redirect_to'] ?? '');
        if ($redirect_back !== '' && str_starts_with($redirect_back, 'index.php')) {
            header('Location: ' . $redirect_back);
            exit;
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Change status
    if (isset($_POST['change_status']) && is_agent()) {
        $new_status_id = (int) $_POST['status_id'];
        $status_comment = trim($_POST['status_comment'] ?? '');
        $old_status = get_status($ticket['status_id']);
        $new_status = get_status($new_status_id);

        db_update('tickets', ['status_id' => $new_status_id], 'id = ?', [$ticket_id]);
        log_activity($ticket_id, $user['id'], 'status_changed', "Status changed from '{$old_status['name']}' to '{$new_status['name']}'");

        // Add comment about status change - NOT internal (visible to customer)
        if (!empty($status_comment)) {
            $comment_content = $status_comment;
            db_insert('comments', [
                'ticket_id' => $ticket_id,
                'user_id' => $user['id'],
                'content' => $comment_content,
                'is_internal' => 0, // Customer can see this
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Touch ticket so "Last updated" sorting works
            db_query("UPDATE tickets SET updated_at = NOW() WHERE id = ?", [$ticket_id]);
        }

        // Send notification
        require_once BASE_PATH . '/includes/mailer.php';
        send_status_change_notification($ticket, $old_status, $new_status, $status_comment, 0);

        // In-app notification for status change
        if (function_exists('dispatch_ticket_notifications')) {
            dispatch_ticket_notifications('status_changed', $ticket_id, $user['id'], [
                'old_status' => $old_status['name'] ?? '',
                'new_status' => $new_status['name'] ?? '',
            ]);
        }

        // Auto-resolve action notifications if ticket is now closed
        if (!empty($new_status['is_closed']) && function_exists('resolve_action_notifications')) {
            resolve_action_notifications($ticket_id);
        }

        flash(t('Status updated.'), 'success');
        // Redirect to referrer (tickets list / dashboard) if provided, otherwise stay on ticket
        $redirect_back = trim($_POST['redirect_to'] ?? '');
        if ($redirect_back !== '' && str_starts_with($redirect_back, 'index.php')) {
            header('Location: ' . $redirect_back);
            exit;
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Assign to agent
    if (isset($_POST['assign_agent']) && is_agent()) {
        $old_assignee_id = $ticket['assignee_id'] ?? null;
        $assignee_id = !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null;

        db_update('tickets', ['assignee_id' => $assignee_id], 'id = ?', [$ticket_id]);
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, $user['id'], 'assignee_id', $old_assignee_id, $assignee_id);
        }

        if ($assignee_id) {
            $assigned_user = get_user($assignee_id);
            log_activity($ticket_id, $user['id'], 'assigned', "Ticket assigned to {$assigned_user['first_name']} {$assigned_user['last_name']}");

            // Send email notification to assigned agent
            require_once BASE_PATH . '/includes/mailer.php';
            send_ticket_assignment_notification($ticket, $assigned_user, $user);

            // In-app notification for assignment
            if (function_exists('dispatch_ticket_notifications')) {
                dispatch_ticket_notifications('assigned_to_you', $ticket_id, $user['id'], [
                    'assignee_id' => $assignee_id,
                ]);
            }

            // Resolve old assignee's action notifications on reassign
            if ($old_assignee_id && function_exists('resolve_action_notifications')) {
                resolve_action_notifications($ticket_id, (int)$old_assignee_id);
            }

            flash(t('Ticket assigned.'), 'success');
        } else {
            log_activity($ticket_id, $user['id'], 'unassigned', "Assignment removed");
            flash(t('Assignment removed.'), 'success');
        }

        redirect('ticket', ['id' => $ticket_id]);
    }

    // Update due date
    if (isset($_POST['update_due_date']) && is_agent()) {
        $old_due_date = $ticket['due_date'] ?? null;
        $due_date_input = trim((string) ($_POST['due_date'] ?? ''));
        $due_date = normalize_due_date_input($due_date_input);
        if ($due_date_input !== '' && $due_date === false) {
            flash(t('Invalid due date.'), 'error');
            redirect('ticket', ['id' => $ticket_id]);
        }

        db_update('tickets', ['due_date' => $due_date], 'id = ?', [$ticket_id]);
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, $user['id'], 'due_date', $old_due_date, $due_date);
        }

        if ($due_date) {
            log_activity($ticket_id, $user['id'], 'due_date_updated', "Due date set to " . format_date($due_date));
            flash(t('Due date updated.'), 'success');
        } else {
            log_activity($ticket_id, $user['id'], 'due_date_removed', "Due date removed");
            flash(t('Due date removed.'), 'success');
        }

        redirect('ticket', ['id' => $ticket_id]);
    }

    // Update company (quick sidebar form)
    if (isset($_POST['update_company']) && is_agent()) {
        $new_org_id = null;
        $org_input = trim((string) ($_POST['organization_id'] ?? ''));
        if ($org_input !== '') {
            $new_org_id = (int) $org_input;
        }
        $old_org_id = $ticket['organization_id'] ?? null;
        db_update('tickets', ['organization_id' => $new_org_id], 'id = ?', [$ticket_id]);
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, $user['id'], 'organization_id', $old_org_id, $new_org_id);
        }
        log_activity($ticket_id, $user['id'], 'company_updated', 'Company updated');
        flash(t('Company updated.'), 'success');
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Archive ticket
    if (isset($_POST['archive_ticket']) && (is_admin() || (is_agent() && can_archive_tickets()))) {
        db_update('tickets', ['is_archived' => 1], 'id = ?', [$ticket_id]);
        log_activity($ticket_id, $user['id'], 'archived', 'Ticket archived');
        flash(t('Ticket moved to archive.'), 'success');
        redirect('tickets');
    }

    // Restore from archive
    if (isset($_POST['restore_ticket']) && (is_admin() || (is_agent() && can_archive_tickets()))) {
        db_update('tickets', ['is_archived' => 0], 'id = ?', [$ticket_id]);
        log_activity($ticket_id, $user['id'], 'restored', 'Ticket restored from archive');
        flash(t('Ticket restored from archive.'), 'success');
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Delete time entry (admin can delete any, agent can delete own only)
    if (isset($_POST['delete_time_entry']) && (is_agent() || is_admin())) {
        $entry_id = (int) $_POST['entry_id'];
        $entry = db_fetch_one("SELECT * FROM ticket_time_entries WHERE id = ?", [$entry_id]);

        if ($entry && ($entry['ticket_id'] == $ticket_id) && (is_admin() || (int) $entry['user_id'] === (int) $user['id'])) {
            // Optional: If linked to a comment, maybe update comment time?
            // For now, just delete the time entry using the function
            require_once BASE_PATH . '/includes/ticket-time-functions.php';
            if (delete_time_entry($entry_id)) {
                log_activity($ticket_id, $user['id'], 'time_deleted', "Deleted time entry (" . format_duration_minutes($entry['duration_minutes'] ?? 0) . ")");
                flash(t('Time entry deleted.'), 'success');
            } else {
                flash(t('Failed to delete time entry.'), 'error');
            }
        }
        redirect('ticket', ['id' => $ticket_id]);
    }

    // Update time entry (admin can edit any, agent can edit own only)
    if (isset($_POST['update_time_entry']) && (is_agent() || is_admin())) {
        $entry_id = (int) $_POST['entry_id'];
        $entry = db_fetch_one("SELECT * FROM ticket_time_entries WHERE id = ?", [$entry_id]);

        if ($entry && ($entry['ticket_id'] == $ticket_id) && (is_admin() || (int) $entry['user_id'] === (int) $user['id'])) {
            require_once BASE_PATH . '/includes/ticket-time-functions.php';

            $started_at = $_POST['started_at'] ?? '';
            $ended_at = $_POST['ended_at'] ?? '';
            $summary = trim($_POST['summary'] ?? '');
            $is_billable = isset($_POST['is_billable']) ? 1 : 0;

            if (!empty($started_at) && !empty($ended_at)) {
                $start_ts = strtotime($started_at);
                $end_ts = strtotime($ended_at);

                if ($start_ts && $end_ts && $end_ts > $start_ts) {
                    $duration_minutes = max(1, (int) floor(($end_ts - $start_ts) / 60));

                    $update_data = [
                        'started_at' => date('Y-m-d H:i:s', $start_ts),
                        'ended_at' => date('Y-m-d H:i:s', $end_ts),
                        'duration_minutes' => $duration_minutes,
                        'summary' => $summary ?: null,
                        'is_billable' => $is_billable
                    ];

                    if (update_time_entry($entry_id, $update_data)) {
                        log_activity($ticket_id, $user['id'], 'time_updated', "Updated time entry to " . format_duration_minutes($duration_minutes));
                        flash(t('Time entry updated.'), 'success');
                    } else {
                        flash(t('Failed to update time entry.'), 'error');
                    }
                } else {
                    flash(t('End time must be after start time.'), 'error');
                }
            } else {
                flash(t('Please provide both start and end times.'), 'error');
            }
        }
        redirect('ticket', ['id' => $ticket_id]);
    }
}
