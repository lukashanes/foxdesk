<?php
/**
 * Recurring Task Management Functions
 */

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

    $tasks = db_fetch_all("
        SELECT * FROM recurring_tasks
        WHERE is_active = 1
        AND next_run_date <= ?
        AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY next_run_date ASC
    ", [$now]);

    $processed = 0;

    foreach ($tasks as $task) {
        if (generate_ticket_from_recurring_task($task)) {
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
        }
    }

    return $processed;
}

/**
 * Generate a ticket from a recurring task
 */
function generate_ticket_from_recurring_task($task)
{
    // Calculate due date (task generated today, due in X days based on priority or default)
    $due_date = new DateTime();
    $default_days = 7; // Default 7 days to complete
    $due_date->modify("+{$default_days} days");

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
        '{app_name}' => defined('APP_NAME') ? APP_NAME : 'Ticket System'
    ];

    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template['subject']);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $template['body']);

    return send_email($assigned_user['email'], $subject, $body);
}


