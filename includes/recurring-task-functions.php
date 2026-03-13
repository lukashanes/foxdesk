<?php
/**
 * Recurring Task Management Functions
 */

// ── Auto-migration ──────────────────────────────────────────────────────────

function ensure_recurring_task_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db = get_db();

    // Add due_days column (configurable due date instead of hardcoded 7)
    try {
        $cols = $db->query("SHOW COLUMNS FROM recurring_tasks LIKE 'due_days'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE recurring_tasks ADD COLUMN due_days INT DEFAULT 7 AFTER status_id");
        }
    } catch (Throwable $e) { /* ignore */ }

    // Create run history table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS recurring_task_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recurring_task_id INT NOT NULL,
                ticket_id INT NULL,
                status ENUM('success','failed') DEFAULT 'success',
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_task_id (recurring_task_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) { /* ignore */ }

    // Add paused_at and resume_date columns for pause-with-resume
    try {
        $cols = $db->query("SHOW COLUMNS FROM recurring_tasks LIKE 'paused_at'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE recurring_tasks ADD COLUMN paused_at DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }

    try {
        $cols = $db->query("SHOW COLUMNS FROM recurring_tasks LIKE 'resume_date'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE recurring_tasks ADD COLUMN resume_date DATE NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }

    // Add tags column for auto-tagging generated tickets
    try {
        $cols = $db->query("SHOW COLUMNS FROM recurring_tasks LIKE 'tags'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE recurring_tasks ADD COLUMN tags TEXT NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }
}

// ── Next Run Preview ────────────────────────────────────────────────────────

/**
 * Get a human-readable relative time string for the next run date
 */
function get_next_run_relative(string $next_run_date): string
{
    $now = new DateTime();
    $next = new DateTime($next_run_date);
    $diff = $now->diff($next);

    // Past due
    if ($next < $now) {
        if ($diff->days === 0) return t('Due now');
        if ($diff->days === 1) return t('Overdue by 1 day');
        return t('Overdue by') . ' ' . $diff->days . ' ' . t('days');
    }

    // Future
    $total_hours = ($diff->days * 24) + $diff->h;
    if ($total_hours < 1) return t('Less than 1 hour');
    if ($total_hours < 24) return t('Today');
    if ($diff->days === 1) return t('Tomorrow');
    if ($diff->days < 7) return t('In') . ' ' . $diff->days . ' ' . t('days');
    if ($diff->days < 14) return t('In') . ' 1 ' . t('week');
    if ($diff->days < 30) return t('In') . ' ' . floor($diff->days / 7) . ' ' . t('weeks');
    if ($diff->days < 60) return t('In') . ' 1 ' . t('month');
    return t('In') . ' ' . floor($diff->days / 30) . ' ' . t('months');
}

// ── Pause / Resume ──────────────────────────────────────────────────────────

/**
 * Pause a recurring task with an optional resume date
 */
function pause_recurring_task(int $task_id, ?string $resume_date = null): bool
{
    $data = [
        'is_active' => 0,
        'paused_at' => date('Y-m-d H:i:s'),
        'resume_date' => $resume_date ?: null,
    ];
    return (bool) db_update('recurring_tasks', $data, 'id = ?', [$task_id]);
}

/**
 * Resume a paused recurring task (clear pause state)
 */
function resume_recurring_task(int $task_id): bool
{
    $task = get_recurring_task($task_id);
    if (!$task) return false;

    $data = [
        'is_active' => 1,
        'paused_at' => null,
        'resume_date' => null,
    ];

    // Recalculate next_run_date from now if it's in the past
    $next = new DateTime($task['next_run_date']);
    if ($next < new DateTime()) {
        $data['next_run_date'] = calculate_next_run_date($task);
    }

    return (bool) db_update('recurring_tasks', $data, 'id = ?', [$task_id]);
}

/**
 * Check and auto-resume tasks whose resume_date has arrived (called by cron)
 */
function process_recurring_task_resumes(): int
{
    ensure_recurring_task_columns();
    $today = date('Y-m-d');

    $tasks = db_fetch_all("
        SELECT * FROM recurring_tasks
        WHERE is_active = 0
        AND resume_date IS NOT NULL
        AND resume_date <= ?
    ", [$today]);

    $resumed = 0;
    foreach ($tasks as $task) {
        if (resume_recurring_task((int) $task['id'])) {
            $resumed++;
        }
    }

    return $resumed;
}

// ── Run History ─────────────────────────────────────────────────────────────

/**
 * Log a recurring task run result
 */
function log_recurring_task_run(int $task_id, ?int $ticket_id, string $status = 'success', ?string $error = null): int
{
    ensure_recurring_task_columns();
    return db_insert('recurring_task_runs', [
        'recurring_task_id' => $task_id,
        'ticket_id' => $ticket_id,
        'status' => $status,
        'error_message' => $error,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Get run history for a task (most recent first)
 */
function get_recurring_task_runs(int $task_id, int $limit = 20): array
{
    ensure_recurring_task_columns();
    return db_fetch_all("
        SELECT r.*, t.title as ticket_title, t.hash as ticket_hash
        FROM recurring_task_runs r
        LEFT JOIN tickets t ON r.ticket_id = t.id
        WHERE r.recurring_task_id = ?
        ORDER BY r.created_at DESC
        LIMIT ?
    ", [$task_id, $limit]);
}

/**
 * Get total run count for a task
 */
function get_recurring_task_run_count(int $task_id): int
{
    ensure_recurring_task_columns();
    $row = db_fetch_one("SELECT COUNT(*) as cnt FROM recurring_task_runs WHERE recurring_task_id = ?", [$task_id]);
    return (int) ($row['cnt'] ?? 0);
}

// ── Duplicate ───────────────────────────────────────────────────────────────

/**
 * Duplicate a recurring task
 */
function duplicate_recurring_task(int $task_id, int $created_by): int|false
{
    $task = get_recurring_task($task_id);
    if (!$task) return false;

    $data = [
        'title' => $task['title'] . ' (' . t('Copy') . ')',
        'description' => $task['description'],
        'ticket_type_id' => $task['ticket_type_id'],
        'organization_id' => $task['organization_id'],
        'assigned_user_id' => $task['assigned_user_id'],
        'priority_id' => $task['priority_id'],
        'status_id' => $task['status_id'],
        'recurrence_type' => $task['recurrence_type'],
        'recurrence_interval' => $task['recurrence_interval'],
        'recurrence_day_of_week' => $task['recurrence_day_of_week'],
        'recurrence_day_of_month' => $task['recurrence_day_of_month'],
        'recurrence_month' => $task['recurrence_month'],
        'start_date' => date('Y-m-d'),
        'end_date' => $task['end_date'],
        'send_email_notification' => $task['send_email_notification'],
        'is_active' => 0, // Start inactive so admin can review
        'created_by_user_id' => $created_by,
    ];

    // Copy due_days if column exists
    if (isset($task['due_days'])) {
        $data['due_days'] = $task['due_days'];
    }

    // Copy tags if set
    if (!empty($task['tags'])) {
        $data['tags'] = $task['tags'];
    }

    return create_recurring_task($data);
}

// ── CRUD ────────────────────────────────────────────────────────────────────

/**
 * Get all recurring tasks
 */
function get_recurring_tasks($active_only = true)
{
    $sql = "
        SELECT rt.*,
               tt.name as ticket_type_name,
               o.name as organization_name,
               u.first_name, u.last_name,
               p.name as priority_name,
               s.name as status_name
        FROM recurring_tasks rt
        LEFT JOIN ticket_types tt ON rt.ticket_type_id = tt.id
        LEFT JOIN organizations o ON rt.organization_id = o.id
        LEFT JOIN users u ON rt.assigned_user_id = u.id
        LEFT JOIN priorities p ON rt.priority_id = p.id
        LEFT JOIN statuses s ON rt.status_id = s.id
    ";

    if ($active_only) {
        $sql .= " WHERE rt.is_active = 1";
    }

    $sql .= " ORDER BY rt.next_run_date ASC";

    return db_fetch_all($sql);
}

/**
 * Get single recurring task
 */
function get_recurring_task($id)
{
    return db_fetch_one("SELECT * FROM recurring_tasks WHERE id = ?", [$id]);
}

/**
 * Create recurring task
 */
function create_recurring_task($data)
{
    // Calculate initial next_run_date
    $data['next_run_date'] = calculate_next_run_date($data);
    $data['created_at'] = date('Y-m-d H:i:s');

    return db_insert('recurring_tasks', $data);
}

/**
 * Update recurring task
 */
function update_recurring_task($id, $data)
{
    // Recalculate next_run_date if recurrence settings changed
    if (
        isset($data['recurrence_type']) || isset($data['recurrence_interval']) ||
        isset($data['recurrence_day_of_week']) || isset($data['recurrence_day_of_month'])
    ) {
        $task = get_recurring_task($id);
        $merged_data = array_merge($task, $data);
        $data['next_run_date'] = calculate_next_run_date($merged_data);
    }

    return db_update('recurring_tasks', $data, 'id = ?', [$id]);
}

/**
 * Delete recurring task
 */
function delete_recurring_task($id)
{
    return db_delete('recurring_tasks', 'id = ?', [$id]);
}

/**
 * Calculate next run date based on recurrence settings
 */
function calculate_next_run_date($task, $from_date = null)
{
    $from = $from_date ? new DateTime($from_date) : new DateTime();

    // Start from start_date if it's in the future
    if (isset($task['start_date'])) {
        $start = new DateTime($task['start_date']);
        if ($start > $from) {
            $from = $start;
        }
    }

    $interval = (int) ($task['recurrence_interval'] ?? 1);

    switch ($task['recurrence_type']) {
        case 'daily':
            $from->modify("+{$interval} days");
            break;

        case 'weekly':
            $target_day = (int) ($task['recurrence_day_of_week'] ?? 1); // Default Monday
            $from->modify("+{$interval} weeks");

            // Adjust to target day of week
            $current_day = (int) $from->format('w');
            $days_diff = $target_day - $current_day;
            if ($days_diff < 0) {
                $days_diff += 7;
            }
            $from->modify("+{$days_diff} days");
            break;

        case 'monthly':
            $target_day = (int) ($task['recurrence_day_of_month'] ?? 1);
            $from->modify("+{$interval} months");

            // Adjust to target day of month
            $from->setDate((int) $from->format('Y'), (int) $from->format('m'), min($target_day, (int) $from->format('t')));
            break;

        case 'yearly':
            $target_month = (int) ($task['recurrence_month'] ?? 1);
            $target_day = (int) ($task['recurrence_day_of_month'] ?? 1);
            $from->modify("+{$interval} years");

            // Adjust to target month and day
            $from->setDate((int) $from->format('Y'), $target_month, 1);
            $max_day = (int) $from->format('t');
            $from->setDate((int) $from->format('Y'), $target_month, min($target_day, $max_day));
            break;
    }

    return $from->format('Y-m-d H:i:s');
}

/**
 * Process due recurring tasks (called by cron)
 */
function process_recurring_tasks()
{
    $now = date('Y-m-d H:i:s');

    ensure_recurring_task_columns();

    // Auto-resume paused tasks whose resume_date has arrived
    process_recurring_task_resumes();

    $tasks = db_fetch_all("
        SELECT * FROM recurring_tasks
        WHERE is_active = 1
        AND next_run_date <= ?
        AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY next_run_date ASC
    ", [$now]);

    $processed = 0;

    foreach ($tasks as $task) {
        $ticket_id = generate_ticket_from_recurring_task($task);
        if ($ticket_id) {
            // Log successful run
            log_recurring_task_run((int) $task['id'], (int) $ticket_id, 'success');

            // Update last_run_date and calculate next_run_date
            $next_run = calculate_next_run_date($task, $now);

            $update_data = [
                'last_run_date' => $now,
                'next_run_date' => $next_run
            ];

            // Check if we've passed the end_date
            if (!empty($task['end_date'])) {
                $end_date = new DateTime($task['end_date']);
                $next_run_dt = new DateTime($next_run);
                if ($next_run_dt > $end_date) {
                    $update_data['is_active'] = 0;
                }
            }

            update_recurring_task($task['id'], $update_data);
            $processed++;
        } else {
            // Log failed run
            log_recurring_task_run((int) $task['id'], null, 'failed', 'Failed to generate ticket');

            // Notify admins about the failure (R8)
            if (function_exists('create_notifications_for_users')) {
                $admin_ids = array_column(
                    db_fetch_all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1"),
                    'id'
                );
                if (!empty($admin_ids)) {
                    create_notifications_for_users(
                        $admin_ids,
                        'system',
                        null,
                        null,
                        [
                            'message' => t('Recurring task "{title}" failed to generate a ticket.', ['title' => ($task['title'] ?? t('Unknown'))]),
                            'recurring_task_id' => (int) $task['id'],
                        ]
                    );
                }
            }
        }
    }

    return $processed;
}

/**
 * Generate a ticket from a recurring task
 */
function generate_ticket_from_recurring_task($task)
{
    // Calculate due date using configurable due_days (default 7)
    $due_date = new DateTime();
    $due_days = (int) ($task['due_days'] ?? 7);
    if ($due_days < 1) $due_days = 7;
    $due_date->modify("+{$due_days} days");

    $requester_id = !empty($task['created_by_user_id']) ? (int) $task['created_by_user_id'] : 0;
    if ($requester_id <= 0 && !empty($task['assigned_user_id'])) {
        $requester_id = (int) $task['assigned_user_id'];
    }
    if ($requester_id <= 0) {
        $fallback_user = db_fetch_one("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        if ($fallback_user) {
            $requester_id = (int) $fallback_user['id'];
        }
    }
    if ($requester_id <= 0) {
        return false;
    }

    $ticket_data = [
        'hash' => function_exists('generate_ticket_hash') ? generate_ticket_hash() : substr(bin2hex(random_bytes(6)), 0, 12),
        'title' => $task['title'],
        'description' => $task['description'] ?? '',
        'type' => 'general',
        'user_id' => $requester_id,
        'organization_id' => $task['organization_id'] ?? null,
        'assignee_id' => $task['assigned_user_id'] ?? null,
        'priority_id' => $task['priority_id'] ?? null,
        'status_id' => $task['status_id'],
        'ticket_type_id' => $task['ticket_type_id'] ?? null,
        'source' => 'recurring',
        'due_date' => $due_date->format('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Auto-tag generated tickets if tags are configured
    if (!empty($task['tags']) && function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
        $ticket_data['tags'] = function_exists('normalize_ticket_tags')
            ? normalize_ticket_tags($task['tags'])
            : $task['tags'];
    }

    $ticket_id = db_insert('tickets', $ticket_data);

    if ($ticket_id && $task['send_email_notification']) {
        send_recurring_task_notification($ticket_id, $task);
    }

    return $ticket_id;
}

/**
 * Send email notification for generated recurring task
 */
function send_recurring_task_notification($ticket_id, $recurring_task)
{
    $ticket = get_ticket($ticket_id);
    if (!$ticket)
        return false;

    $assigned_user = get_user($ticket['assignee_id'] ?? null);
    if (!$assigned_user || empty($assigned_user['email']))
        return false;

    // Use the recurring task assignment template
    require_once BASE_PATH . '/includes/mailer.php';

    // Get language
    $language = $assigned_user['language'] ?? 'en';

    // Get template
    $template = get_email_template('recurring_task_assignment', $language);

    if (!$template) {
        // Fallback if template missing (though migration should have added it)
        $subject = t('New Recurring Task Assigned') . ': ' . $ticket['title'];
        $message = t('A new recurring task has been assigned to you.') . "\n\n";
        $message .= t('Title') . ': ' . $ticket['title'] . "\n";
        $message .= t('Description') . ': ' . ($ticket['description'] ?: t('None')) . "\n";
        $message .= t('Due Date') . ': ' . format_date($ticket['due_date']) . "\n\n";
        $message .= t('View ticket') . ': ' . APP_URL . '/index.php?page=ticket&id=' . $ticket_id . "\n";
        return send_email($assigned_user['email'], $subject, $message);
    }

    // Replace placeholders
    $placeholders = [
        '{recipient_name}' => $assigned_user['first_name'] . ' ' . $assigned_user['last_name'],
        '{ticket_id}' => $ticket['id'],
        '{ticket_code}' => get_ticket_code($ticket['id']),
        '{ticket_title}' => $ticket['title'],
        '{ticket_description}' => $ticket['description'] ?: t('None'),
        '{due_date}' => format_date($ticket['due_date']),
        '{ticket_url}' => APP_URL . '/index.php?page=ticket&id=' . $ticket_id,
        '{app_name}' => defined('APP_NAME') ? APP_NAME : t('Ticket System')
    ];

    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template['subject']);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $template['body']);

    return send_email($assigned_user['email'], $subject, $body);
}


