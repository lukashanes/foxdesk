<?php
/**
 * New Ticket Page
 */

$page_title = t('New ticket');
$page = 'new-ticket';
$user = current_user();
$priorities = get_priorities();
$statuses = get_statuses();
$ticket_types = get_ticket_types();
$tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
$organizations = [];
$allowed_organization_ids = [];

try {
    if (is_admin()) {
        $organizations = get_organizations();
        $allowed_organization_ids = array_map(static function ($org) {
            return (int) ($org['id'] ?? 0);
        }, $organizations);
    } else {
        $allowed_organization_ids = get_user_organization_ids($user['id']);
        if (!empty($allowed_organization_ids)) {
            $lookup = array_flip($allowed_organization_ids);
            $organizations = array_values(array_filter(get_organizations(), static function ($org) use ($lookup) {
                return isset($lookup[(int) ($org['id'] ?? 0)]);
            }));
        }
    }
    $allowed_organization_ids = normalize_organization_ids($allowed_organization_ids);
} catch (Throwable $e) {
    $organizations = [];
    $allowed_organization_ids = [];
}

// Load staff users for "Assign to" and all users for "On behalf of" (admin/agent only)
$staff_users = [];
$all_users_list = [];
if (is_admin() || is_agent()) {
    $all_users_raw = get_all_users();
    foreach ($all_users_raw as $u) {
        if (in_array($u['role'], ['admin', 'agent'])) {
            $staff_users[] = $u;
        }
        $all_users_list[] = $u;
    }
}

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'general';
        $priority_id = !empty($_POST['priority_id']) ? (int) $_POST['priority_id'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $tags = $tags_supported ? trim($_POST['tags'] ?? '') : '';
        $organization_id = !empty($_POST['organization_id']) ? (int) $_POST['organization_id'] : null;
        $assignee_id = (is_admin() || is_agent()) && !empty($_POST['assignee_id']) ? (int) $_POST['assignee_id'] : null;
        $on_behalf_of = (is_admin() || is_agent()) && !empty($_POST['on_behalf_of']) ? (int) $_POST['on_behalf_of'] : null;
        $status_id = (is_admin() || is_agent()) && !empty($_POST['status_id']) ? (int) $_POST['status_id'] : null;
        $created_at_input = trim((string) ($_POST['created_at'] ?? ''));
        $created_at = null;
        // Manual time entry (quick minutes or exact start/end range)
        $manual_duration_input = trim((string) ($_POST['manual_duration_minutes'] ?? ''));
        $manual_duration_minutes = $manual_duration_input !== '' ? (int) $manual_duration_input : 0;
        $manual_date = trim($_POST['manual_date'] ?? '');
        $manual_start_time = trim($_POST['manual_start_time'] ?? '');
        $manual_end_time = trim($_POST['manual_end_time'] ?? '');

        // Resolve ticket owner: agent can create on behalf of another user
        $ticket_owner_id = $user['id'];
        if ($on_behalf_of) {
            $behalf_user = get_user($on_behalf_of);
            if ($behalf_user && can_user_create_ticket_for($behalf_user, $user)) {
                $ticket_owner_id = (int) $behalf_user['id'];
            } else {
                $error = t('Selected user is not available.');
            }
        }

        if ($assignee_id) {
            $assignee_user = get_user($assignee_id);
            if (!$assignee_user || !can_user_assign_to_staff($assignee_user, $user)) {
                $error = t('Invalid assignee.');
            }
        }

        if ($due_date) {
            $due_date = normalize_due_date_input($due_date);
            if ($due_date === false) {
                $error = t('Invalid due date.');
            }
        }

        if ($created_at_input !== '') {
            if (!foxdesk_can_backdate_records($user)) {
                $error = t('Only admins and agents can set historical dates.');
            } else {
                $created_at = foxdesk_normalize_backdated_datetime_input($created_at_input);
                if ($created_at === false) {
                    $error = t('Invalid created date.');
                }
            }
        }

        if (!empty($error)) {
            // Keep the validation error raised above.
        } elseif (empty($title)) {
            $error = t('Enter a subject.');
        } elseif ($organization_id !== null && $organization_id > 0 && !in_array($organization_id, $allowed_organization_ids, true)) {
            $error = t('Selected organization is not available.');
        } elseif (
            is_agent()
            && function_exists('ticket_time_table_exists')
            && ticket_time_table_exists()
            && $manual_duration_input !== ''
            && ($manual_duration_minutes < 1 || $manual_duration_minutes > 1440)
        ) {
            $error = t('Duration must be between 1 and 1440 minutes.');
        } else {
            $upload_errors = [];
            $create_data = [
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'priority_id' => $priority_id,
                'user_id' => $ticket_owner_id,
                'organization_id' => $organization_id,
                'due_date' => $due_date,
                'tags' => $tags,
                'assignee_id' => $assignee_id
            ];
            if ($created_at !== null) {
                $create_data['created_at'] = $created_at;
                $create_data['allow_backdated_created_at'] = true;
            }
            if ($status_id) {
                $create_data['status_id'] = $status_id;
            }
            $ticket_id = create_ticket($create_data);

            log_activity($ticket_id, $user['id'], 'created', 'Ticket created');

            // Save time entry — manual start/end takes priority over simple minutes, which takes priority over timer
            $timer_elapsed = (int) ($_POST['timer_elapsed_seconds'] ?? 0);
            if (is_agent() && function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
                $manual_time_logged = false;

                // Manual time entry with start/end times
                if ($manual_start_time !== '' && $manual_end_time !== '') {
                    $base_date = $manual_date !== '' ? $manual_date : date('Y-m-d');
                    $end_date = $base_date;
                    // Midnight overflow: if end time < start time, it's the next day
                    if ($manual_end_time < $manual_start_time) {
                        $end_date = date('Y-m-d', strtotime($base_date . ' +1 day'));
                    }
                    $start_dt = DateTime::createFromFormat('Y-m-d H:i', $base_date . ' ' . $manual_start_time);
                    $end_dt = DateTime::createFromFormat('Y-m-d H:i', $end_date . ' ' . $manual_end_time);
                    if ($start_dt && $end_dt && $end_dt > $start_dt) {
                        $duration = max(0, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));
                        if ($duration > 0 && function_exists('add_manual_time_entry')) {
                            add_manual_time_entry($ticket_id, $user['id'], [
                                'started_at' => $start_dt->format('Y-m-d H:i:s'),
                                'ended_at' => $end_dt->format('Y-m-d H:i:s'),
                                'duration_minutes' => $duration,
                                'summary' => t('Ticket creation'),
                                'is_billable' => 1,
                            ]);
                            $manual_time_logged = true;
                        }
                    }
                }

                if (!$manual_time_logged && $manual_duration_input !== '' && $manual_duration_minutes > 0 && function_exists('add_manual_time_entry')) {
                    $end_dt = $created_at !== null ? new DateTime($created_at) : new DateTime();
                    $start_dt = (clone $end_dt)->modify('-' . $manual_duration_minutes . ' minutes');
                    add_manual_time_entry($ticket_id, $user['id'], [
                        'started_at' => $start_dt->format('Y-m-d H:i:s'),
                        'ended_at' => $end_dt->format('Y-m-d H:i:s'),
                        'duration_minutes' => $manual_duration_minutes,
                        'summary' => t('Ticket creation'),
                        'is_billable' => 1,
                    ]);
                    $manual_time_logged = true;
                }

                if (!$manual_time_logged && $timer_elapsed > 0) {
                    // Timer was running — save as completed entry
                    $ticket_billable_rate = function_exists('get_ticket_effective_billable_rate')
                        ? get_ticket_effective_billable_rate($ticket_id)
                        : 0.0;
                    $user_cost_rate = (float)($user['cost_rate'] ?? 0);
                    $timer_started = date('Y-m-d H:i:s', time() - $timer_elapsed);
                    $timer_duration = max(1, (int) floor($timer_elapsed / 60));
                    $timer_entry = [
                        'ticket_id' => $ticket_id,
                        'user_id' => $user['id'],
                        'started_at' => $timer_started,
                        'ended_at' => date('Y-m-d H:i:s'),
                        'duration_minutes' => $timer_duration,
                        'is_billable' => 1,
                        'billable_rate' => $ticket_billable_rate,
                        'cost_rate' => $user_cost_rate,
                        'is_manual' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    if (function_exists('time_entry_source_column_exists') && time_entry_source_column_exists()) {
                        $timer_entry['source'] = 'timer';
                    }
                    db_insert('ticket_time_entries', $timer_entry);
                    log_activity($ticket_id, $user['id'], 'time_stopped', "Timer stopped ({$timer_duration} min)");
                }
            }

            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $files = $_FILES['attachments'];

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $upload_errors[] = $files['name'][$i] . ': ' . get_upload_error_message((int) $files['error'][$i], get_max_upload_size());
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

                        // Save attachment record
                        db_insert('attachments', [
                            'ticket_id' => $ticket_id,
                            'filename' => $result['filename'],
                            'original_name' => $result['original_name'],
                            'mime_type' => $result['mime_type'],
                            'file_size' => $result['file_size'],
                            'uploaded_by' => $user['id'],
                            'created_at' => $created_at ?? date('Y-m-d H:i:s')
                        ]);
                    } catch (Exception $e) {
                        $upload_errors[] = $files['name'][$i] . ': ' . $e->getMessage();
                    }
                }

                if (!empty($upload_errors)) {
                    flash(t('Ticket created, but some files could not be uploaded: {errors}', ['errors' => implode(', ', $upload_errors)]), 'error');
                }
            }

            // Send notifications
            require_once BASE_PATH . '/includes/mailer.php';
            $ticket = get_ticket($ticket_id);
            send_new_ticket_notification($ticket);
            send_ticket_confirmation_to_user($ticket); // Confirmation to user

            // Send assignment notification if ticket was assigned on creation
            if ($assignee_id && function_exists('send_ticket_assignment_notification')) {
                $assigned_agent = get_user($assignee_id);
                if ($assigned_agent) {
                    send_ticket_assignment_notification($ticket, $assigned_agent, $user, [
                        'created_with_ticket' => true,
                    ]);
                }
            }

            // In-app notifications
            if (function_exists('ticket_event_dispatch_in_app')) {
                $desc_preview = strip_tags($description);
                $desc_preview = mb_strlen($desc_preview) > 80 ? mb_substr($desc_preview, 0, 77) . '...' : $desc_preview;
                ticket_event_dispatch_in_app('ticket.created', $ticket_id, $user['id'], [
                    'comment_preview' => $desc_preview,
                ]);
                if ($assignee_id) {
                    ticket_event_dispatch_in_app('ticket.assigned', $ticket_id, $user['id'], [
                        'assignee_id' => $assignee_id,
                    ]);
                }
            }

            if (empty($upload_errors)) {
                flash(t('Ticket created successfully.'), 'success');
            }
            redirect('ticket', ['id' => $ticket_id]);
        }
}

// Get default values
$default_priority = get_default_priority();
$default_priority_id = $default_priority ? $default_priority['id'] : (!empty($priorities) ? $priorities[0]['id'] : null);

$default_type = null;
foreach ($ticket_types as $tt) {
    if (!empty($tt['is_default'])) {
        $default_type = $tt;
        break;
    }
}
if (!$default_type && !empty($ticket_types)) {
    $default_type = $ticket_types[0];
}
$default_type_slug = $default_type ? $default_type['slug'] : 'general';
$default_organization_id = null;
$default_assignee_id = null;
$is_postback = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($is_postback && !empty($_POST['import_organization_id'])) {
    $posted_org_id = (int) $_POST['import_organization_id'];
    if ($posted_org_id > 0 && in_array($posted_org_id, $allowed_organization_ids, true)) {
        $default_organization_id = $posted_org_id;
    }
} elseif ($is_postback && !empty($_POST['organization_id'])) {
    $posted_org_id = (int) $_POST['organization_id'];
    if ($posted_org_id > 0 && in_array($posted_org_id, $allowed_organization_ids, true)) {
        $default_organization_id = $posted_org_id;
    }
}
if ($is_postback && (is_admin() || is_agent()) && !empty($_POST['assignee_id'])) {
    $posted_assignee_id = (int) $_POST['assignee_id'];
    foreach ($staff_users as $staff_user) {
        if ((int) ($staff_user['id'] ?? 0) === $posted_assignee_id) {
            $default_assignee_id = $posted_assignee_id;
            break;
        }
    }
}

require_once BASE_PATH . '/includes/header.php';
require BASE_PATH . '/includes/components/new-ticket-form.php';
require BASE_PATH . '/includes/components/new-ticket-assets.php';
require_once BASE_PATH . '/includes/footer.php';
