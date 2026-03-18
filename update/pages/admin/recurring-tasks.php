<?php
/**
 * Admin - Recurring Tasks Management
 * Features: CRUD, Run Now, Duplicate, Run History, Configurable Due Days
 */

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$current_user = current_user();
$page_title = t('Recurring Tasks');

// Ensure new columns exist
ensure_recurring_task_columns();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $action = $_POST['action'] ?? '';
    $task_id = (int) ($_POST['task_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'ticket_type_id' => !empty($_POST['ticket_type_id']) ? (int) $_POST['ticket_type_id'] : null,
            'organization_id' => !empty($_POST['organization_id']) ? (int) $_POST['organization_id'] : null,
            'assigned_user_id' => (int) $_POST['assigned_user_id'],
            'priority_id' => !empty($_POST['priority_id']) ? (int) $_POST['priority_id'] : null,
            'status_id' => (int) $_POST['status_id'],
            'due_days' => max(1, (int) ($_POST['due_days'] ?? 7)),
            'recurrence_type' => $_POST['recurrence_type'] ?? 'weekly',
            'recurrence_interval' => max(1, (int) ($_POST['recurrence_interval'] ?? 1)),
            'recurrence_day_of_week' => !empty($_POST['recurrence_day_of_week']) ? (int) $_POST['recurrence_day_of_week'] : null,
            'recurrence_day_of_month' => !empty($_POST['recurrence_day_of_month']) ? (int) $_POST['recurrence_day_of_month'] : null,
            'recurrence_month' => !empty($_POST['recurrence_month']) ? (int) $_POST['recurrence_month'] : null,
            'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
            'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
            'send_email_notification' => isset($_POST['send_email_notification']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        // Tags (auto-tag generated tickets)
        $tags_input = trim($_POST['tags'] ?? '');
        if ($tags_input !== '' && function_exists('normalize_ticket_tags')) {
            $data['tags'] = normalize_ticket_tags($tags_input);
        } else {
            $data['tags'] = $tags_input !== '' ? $tags_input : null;
        }

        // Validate end_date > start_date if both provided
        if (!empty($data['end_date']) && !empty($data['start_date']) && $data['end_date'] <= $data['start_date']) {
            flash(t('End date must be after start date.'), 'error');
            redirect('admin', ['section' => 'recurring-tasks']);
        }

        if ($action === 'create') {
            $data['created_by_user_id'] = $current_user['id'];
            if (create_recurring_task($data)) {
                flash(t('Recurring task created successfully.'), 'success');
            } else {
                flash(t('Failed to create recurring task.'), 'error');
            }
        } else {
            if (update_recurring_task($task_id, $data)) {
                flash(t('Recurring task updated successfully.'), 'success');
            } else {
                flash(t('Failed to update recurring task.'), 'error');
            }
        }
    } elseif ($action === 'delete' && $task_id > 0) {
        $existing_task = get_recurring_task($task_id);
        if (!$existing_task) {
            flash(t('Recurring task not found.'), 'error');
            redirect('admin', ['section' => 'recurring-tasks']);
        }
        if (delete_recurring_task($task_id)) {
            flash(t('Recurring task deleted successfully.'), 'success');
        } else {
            flash(t('Failed to delete recurring task.'), 'error');
        }
    } elseif ($action === 'pause' && $task_id > 0) {
        $resume_date = !empty($_POST['resume_date']) ? $_POST['resume_date'] : null;
        if ($resume_date && $resume_date <= date('Y-m-d')) {
            flash(t('Resume date must be in the future.'), 'error');
            redirect('admin', ['section' => 'recurring-tasks']);
        }
        pause_recurring_task($task_id, $resume_date);
        $msg = $resume_date
            ? t('Task paused. Will auto-resume on') . ' ' . date('M j, Y', strtotime($resume_date)) . '.'
            : t('Task paused.');
        flash($msg, 'success');
    } elseif ($action === 'resume' && $task_id > 0) {
        resume_recurring_task($task_id);
        flash(t('Task resumed.'), 'success');
    } elseif ($action === 'run_now' && $task_id > 0) {
        $task = get_recurring_task($task_id);
        if ($task) {
            $ticket_id = generate_ticket_from_recurring_task($task);
            if ($ticket_id) {
                log_recurring_task_run($task_id, (int) $ticket_id, 'success');
                update_recurring_task($task_id, ['last_run_date' => date('Y-m-d H:i:s')]);
                flash(t('Task executed successfully. Ticket created.'), 'success');
            } else {
                log_recurring_task_run($task_id, null, 'failed', 'Manual run failed');
                flash(t('Failed to generate ticket.'), 'error');
            }
        }
    } elseif ($action === 'duplicate' && $task_id > 0) {
        $new_id = duplicate_recurring_task($task_id, $current_user['id']);
        if ($new_id) {
            flash(t('Task duplicated successfully.'), 'success');
        } else {
            flash(t('Failed to duplicate task.'), 'error');
        }
    }

    redirect('admin', ['section' => 'recurring-tasks']);
}

// Get all recurring tasks
$tasks = get_recurring_tasks(false); // Show all, including inactive

// Get run counts for each task
$run_counts = [];
foreach ($tasks as $task) {
    $run_counts[$task['id']] = get_recurring_task_run_count((int) $task['id']);
}

// Get data for form dropdowns
$ticket_types = get_ticket_types();
$organizations = get_organizations(true);
$_ai_excl = (function_exists('ai_agent_column_exists') && ai_agent_column_exists()) ? ' AND is_ai_agent = 0' : '';
$agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1{$_ai_excl} ORDER BY first_name, last_name");
$priorities = get_priorities();
$statuses = get_statuses();

// Load run history if requested via AJAX
if (isset($_GET['ajax_history'])) {
    header('Content-Type: application/json');
    $history_task_id = (int) ($_GET['ajax_history']);
    $runs = get_recurring_task_runs($history_task_id, 20);
    echo json_encode(['success' => true, 'runs' => $runs]);
    exit;
}

include BASE_PATH . '/includes/header.php';
?>

<div class="p-4 lg:p-8">
    <!-- Page Header -->
    <div class="mb-2">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold" style="color: var(--text-primary);">
                    <?php echo get_icon('redo', 'mr-3 inline-block'); ?><?php echo e(t('Recurring Tasks')); ?>
                </h1>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                    <?php echo e(t('Automatically create tickets on a recurring schedule')); ?>
                </p>
            </div>
            <button onclick="openTaskModal()" class="btn btn-primary">
                <?php echo get_icon('plus', 'mr-2 inline-block'); ?><?php echo e(t('Create Recurring Task')); ?>
            </button>
        </div>
    </div>

    <!-- Search / Filter Bar -->
    <?php if (!empty($tasks)): ?>
    <div class="mb-4 flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div class="relative flex-1 w-full sm:max-w-sm">
            <input type="text" id="taskSearch" placeholder="<?php echo e(t('Search tasks...')); ?>"
                class="form-input w-full pl-9 text-sm" oninput="filterTasks()">
            <span class="absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted);"><?php echo get_icon('search', 'w-4 h-4'); ?></span>
        </div>
        <div class="flex items-center gap-1 text-sm">
            <button type="button" class="px-3 py-1.5 rounded-md text-xs font-medium task-filter-btn active"
                data-filter="all" onclick="setTaskFilter('all')"
                style="background: var(--accent-primary); color: #fff;">
                <?php echo e(t('All')); ?> <span class="ml-1 opacity-75"><?php echo count($tasks); ?></span>
            </button>
            <button type="button" class="px-3 py-1.5 rounded-md text-xs font-medium task-filter-btn"
                data-filter="active" onclick="setTaskFilter('active')"
                style="color: var(--text-secondary);">
                <?php echo e(t('Active')); ?> <span class="ml-1 opacity-75"><?php echo count(array_filter($tasks, fn($t) => $t['is_active'] && !($t['is_paused'] ?? false))); ?></span>
            </button>
            <button type="button" class="px-3 py-1.5 rounded-md text-xs font-medium task-filter-btn"
                data-filter="paused" onclick="setTaskFilter('paused')"
                style="color: var(--text-secondary);">
                <?php echo e(t('Paused')); ?> <span class="ml-1 opacity-75"><?php echo count(array_filter($tasks, fn($t) => $t['is_paused'] ?? false)); ?></span>
            </button>
            <button type="button" class="px-3 py-1.5 rounded-md text-xs font-medium task-filter-btn"
                data-filter="inactive" onclick="setTaskFilter('inactive')"
                style="color: var(--text-secondary);">
                <?php echo e(t('Inactive')); ?> <span class="ml-1 opacity-75"><?php echo count(array_filter($tasks, fn($t) => !$t['is_active'])); ?></span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tasks List -->
    <?php if (empty($tasks)): ?>
        <div class="card p-12 text-center">
            <span style="color: var(--text-muted); opacity: 0.5;"><?php echo get_icon('redo', 'text-6xl mb-4 inline-block'); ?></span>
            <h3 class="text-lg font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('No Recurring Tasks')); ?></h3>
            <p class="mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Create your first recurring task to automate ticket creation')); ?></p>
            <button onclick="openTaskModal()" class="btn btn-primary">
                <?php echo get_icon('plus', 'mr-2 inline-block'); ?><?php echo e(t('Create First Task')); ?>
            </button>
        </div>
    <?php else: ?>
        <div class="card overflow-hidden">
            <table class="w-full">
                <thead class="border-b" style="background: var(--surface-secondary); border-color: var(--border-light);">
                    <tr>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Task')); ?></th>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Assigned To')); ?></th>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Schedule')); ?></th>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Next Run')); ?></th>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Last Run')); ?></th>
                        <th class="px-6 py-3 text-left th-label"><?php echo e(t('Status')); ?></th>
                        <th class="px-6 py-3 text-right th-label"><?php echo e(t('Actions')); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--border-light);">
                    <?php foreach ($tasks as $task):
                        $task_status = ($task['is_paused'] ?? false) ? 'paused' : ($task['is_active'] ? 'active' : 'inactive');
                        $task_search_text = strtolower($task['title'] . ' ' . ($task['organization_name'] ?? '') . ' ' . trim($task['first_name'] . ' ' . $task['last_name']));
                    ?>
                        <tr class="tr-hover task-row" data-status="<?php echo $task_status; ?>" data-search="<?php echo e($task_search_text); ?>">
                            <td class="px-6 py-4">
                                <div class="font-medium" style="color: var(--text-primary);"><?php echo e($task['title']); ?></div>
                                <?php if ($task['organization_name']): ?>
                                    <div class="text-xs" style="color: var(--text-muted);"><?php echo e($task['organization_name']); ?></div>
                                <?php endif; ?>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    <?php echo e(t('Due')); ?>: <?php echo e(($task['due_days'] ?? 7) . ' ' . t('days')); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php echo e(trim($task['first_name'] . ' ' . $task['last_name'])); ?>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php
                                $schedule = '';
                                switch ($task['recurrence_type']) {
                                    case 'daily':
                                        $schedule = $task['recurrence_interval'] == 1
                                            ? t('Every day')
                                            : t('Every') . ' ' . $task['recurrence_interval'] . ' ' . t('days');
                                        break;
                                    case 'weekly':
                                        $days = [t('Sunday'), t('Monday'), t('Tuesday'), t('Wednesday'), t('Thursday'), t('Friday'), t('Saturday')];
                                        $day = $days[$task['recurrence_day_of_week'] ?? 1];
                                        $schedule = $task['recurrence_interval'] == 1
                                            ? t('Every') . ' ' . $day
                                            : t('Every') . ' ' . $task['recurrence_interval'] . ' ' . t('weeks on') . ' ' . $day;
                                        break;
                                    case 'monthly':
                                        $schedule = t('Monthly on day') . ' ' . ($task['recurrence_day_of_month'] ?? 1);
                                        break;
                                    case 'yearly':
                                        $schedule = t('Yearly');
                                        break;
                                }
                                echo e($schedule);
                                ?>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php if ($task['is_active']): ?>
                                    <?php echo date('M j, Y', strtotime($task['next_run_date'])); ?>
                                    <?php
                                    $relative = get_next_run_relative($task['next_run_date']);
                                    $next_dt = new DateTime($task['next_run_date']);
                                    $is_overdue = $next_dt < new DateTime();
                                    ?>
                                    <div class="text-xs mt-0.5 <?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?>"
                                         style="<?php echo !$is_overdue ? 'color: var(--text-muted);' : ''; ?>">
                                        <?php echo e($relative); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                    <?php if (!empty($task['resume_date'])): ?>
                                        <div class="text-xs mt-0.5 text-orange-600">
                                            <?php echo get_icon('clock', 'inline-block w-3 h-3 mr-0.5'); ?>
                                            <?php echo e(t('Resumes')); ?> <?php echo date('M j, Y', strtotime($task['resume_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php if ($task['last_run_date']): ?>
                                    <span title="<?php echo e(date('Y-m-d H:i', strtotime($task['last_run_date']))); ?>">
                                        <?php echo date('M j, Y', strtotime($task['last_run_date'])); ?>
                                    </span>
                                    <?php if ($run_counts[$task['id']] > 0): ?>
                                        <button onclick="showRunHistory(<?php echo $task['id']; ?>, <?php echo e(json_encode($task['title'])); ?>)"
                                            class="text-blue-600 hover:text-blue-800 text-xs ml-1">
                                            (<?php echo $run_counts[$task['id']]; ?> <?php echo e(t('runs')); ?>)
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($task['is_active']): ?>
                                    <span class="badge-inline rounded-full bg-green-100 text-green-800">
                                        <?php echo get_icon('check-circle', 'mr-1 inline-block w-3 h-3'); ?> <?php echo e(t('Active')); ?>
                                    </span>
                                <?php elseif (!empty($task['paused_at'])): ?>
                                    <span class="badge-inline rounded-full bg-orange-100 text-orange-800">
                                        <?php echo get_icon('pause-circle', 'mr-1 inline-block w-3 h-3'); ?> <?php echo e(t('Paused')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-inline rounded-full" style="background: var(--surface-secondary); color: var(--text-primary);">
                                        <?php echo get_icon('pause-circle', 'mr-1 inline-block w-3 h-3'); ?> <?php echo e(t('Inactive')); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-1">
                                    <!-- Run Now -->
                                    <form method="POST" class="inline" onsubmit="return confirm('<?php echo e(t('Run this task now? A new ticket will be created immediately.')); ?>');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="run_now">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="text-green-600 hover:text-green-800 text-sm font-medium px-1" title="<?php echo e(t('Run Now')); ?>">
                                            <?php echo get_icon('play', 'inline-block'); ?>
                                        </button>
                                    </form>

                                    <!-- Edit -->
                                    <button onclick='editTask(<?php echo json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="text-blue-600 hover:text-blue-800 text-sm font-medium px-1" title="<?php echo e(t('Edit')); ?>">
                                        <?php echo get_icon('edit', 'inline-block'); ?>
                                    </button>

                                    <!-- Preview -->
                                    <button onclick='openPreviewModal(<?php echo json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="hover:text-blue-800 text-sm font-medium px-1" style="color: var(--text-muted);" title="<?php echo e(t('Preview Ticket')); ?>">
                                        <?php echo get_icon('eye', 'inline-block'); ?>
                                    </button>

                                    <!-- Duplicate -->
                                    <form method="POST" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="duplicate">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="hover:text-blue-800 text-sm font-medium px-1" style="color: var(--text-muted);" title="<?php echo e(t('Duplicate')); ?>">
                                            <?php echo get_icon('copy', 'inline-block'); ?>
                                        </button>
                                    </form>

                                    <!-- Pause / Resume -->
                                    <?php if ($task['is_active']): ?>
                                        <button type="button" onclick="openPauseModal(<?php echo $task['id']; ?>, <?php echo e(json_encode($task['title'])); ?>)"
                                            class="text-orange-600 hover:text-orange-800 text-sm font-medium px-1" title="<?php echo e(t('Pause')); ?>">
                                            <?php echo get_icon('pause-circle', 'inline-block'); ?>
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="resume">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-800 text-sm font-medium px-1" title="<?php echo e(t('Resume')); ?>">
                                                <?php echo get_icon('play', 'inline-block'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Delete -->
                                    <form method="POST" class="inline"
                                        onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this recurring task?')); ?>');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium px-1" title="<?php echo e(t('Delete')); ?>">
                                            <?php echo get_icon('trash', 'inline-block'); ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="noTasksRow" class="hidden">
                        <td colspan="7" class="px-6 py-8 text-center text-sm" style="color: var(--text-muted);">
                            <?php echo e(t('No tasks match your search.')); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Task Modal -->
<div id="taskModal"
    class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="rounded-xl shadow-xl max-w-3xl w-full mx-4 my-8 p-4" style="background: var(--bg-primary);">
        <h3 class="text-lg font-semibold mb-4" id="modalTitle" style="color: var(--text-primary);">
            <?php echo get_icon('redo', 'mr-2 inline-block'); ?><?php echo e(t('Create Recurring Task')); ?>
        </h3>

        <form method="POST" id="taskForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="task_id" id="taskId" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Task Title')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" id="title" required class="form-input w-full"
                        placeholder="<?php echo e(t('e.g., Weekly server backup check')); ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Description')); ?></label>
                    <textarea name="description" id="description" rows="3" class="form-input w-full"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Client')); ?></label>
                    <select name="organization_id" id="organization_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Client --')); ?></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Assign To')); ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="assigned_user_id" id="assigned_user_id" required class="form-select w-full">
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>">
                                <?php echo e(trim($agent['first_name'] . ' ' . $agent['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Type')); ?></label>
                    <select name="ticket_type_id" id="ticket_type_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Type --')); ?></option>
                        <?php foreach ($ticket_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo e($type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Priority')); ?></label>
                    <select name="priority_id" id="priority_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Priority --')); ?></option>
                        <?php foreach ($priorities as $priority): ?>
                            <option value="<?php echo $priority['id']; ?>"><?php echo e($priority['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Initial Status')); ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="status_id" id="status_id" required class="form-select w-full">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status['id']; ?>"><?php echo e($status['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Due in (days)')); ?>
                    </label>
                    <input type="number" name="due_days" id="due_days" value="7" min="1" max="365" class="form-input w-full">
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Days until ticket is due after creation')); ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Tags')); ?>
                        <span class="text-xs font-normal" style="color: var(--text-muted);">(<?php echo e(t('Optional')); ?>)</span>
                    </label>
                    <input type="text" name="tags" id="task_tags" class="form-input w-full"
                        placeholder="<?php echo e(t('e.g., maintenance, monthly, server')); ?>">
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);"><?php echo e(t('Comma-separated tags applied to generated tickets')); ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Frequency')); ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="recurrence_type" id="recurrence_type" required class="form-select w-full"
                        onchange="updateRecurrenceFields()">
                        <option value="daily"><?php echo e(t('Daily')); ?></option>
                        <option value="weekly" selected><?php echo e(t('Weekly')); ?></option>
                        <option value="monthly"><?php echo e(t('Monthly')); ?></option>
                        <option value="yearly"><?php echo e(t('Yearly')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Every')); ?></label>
                    <input type="number" name="recurrence_interval" id="recurrence_interval" value="1" min="1" max="365" class="form-input w-full">
                </div>
                <div id="dayOfWeekField" class="hidden">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Day of Week')); ?></label>
                    <select name="recurrence_day_of_week" id="recurrence_day_of_week" class="form-select w-full">
                        <option value="0"><?php echo e(t('Sunday')); ?></option>
                        <option value="1" selected><?php echo e(t('Monday')); ?></option>
                        <option value="2"><?php echo e(t('Tuesday')); ?></option>
                        <option value="3"><?php echo e(t('Wednesday')); ?></option>
                        <option value="4"><?php echo e(t('Thursday')); ?></option>
                        <option value="5"><?php echo e(t('Friday')); ?></option>
                        <option value="6"><?php echo e(t('Saturday')); ?></option>
                    </select>
                </div>
                <div id="dayOfMonthField" class="hidden">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Day of Month')); ?></label>
                    <input type="number" name="recurrence_day_of_month" id="recurrence_day_of_month" value="1" min="1" max="31" class="form-input w-full">
                </div>
                <div id="monthField" class="hidden">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Month')); ?></label>
                    <select name="recurrence_month" id="recurrence_month" class="form-select w-full">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"><?php echo e(t(date('F', mktime(0, 0, 0, $m, 1)))); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Start Date')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo date('Y-m-d'); ?>" required class="form-input w-full">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('End Date')); ?>
                        <span class="text-xs" style="color: var(--text-muted);">(<?php echo e(t('Optional')); ?>)</span></label>
                    <input type="date" name="end_date" id="end_date" class="form-input w-full">
                </div>
                <div class="md:col-span-2 space-y-2">
                    <label class="flex items-center text-sm">
                        <input type="checkbox" name="send_email_notification" id="send_email_notification" checked class="mr-2">
                        <?php echo e(t('Send email notification to assigned agent')); ?>
                    </label>
                    <label class="flex items-center text-sm">
                        <input type="checkbox" name="is_active" id="is_active" checked class="mr-2">
                        <?php echo e(t('Active')); ?>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-3 pt-6 border-t">
                <button type="button" onclick="closeTaskModal()" class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                <button type="submit" class="btn btn-primary">
                    <?php echo get_icon('save', 'mr-2 inline-block'); ?><?php echo e(t('Save')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Run History Modal -->
<div id="historyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="rounded-xl shadow-xl max-w-2xl w-full mx-4 my-8 p-4" style="background: var(--bg-primary);">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold" id="historyTitle" style="color: var(--text-primary);">
                <?php echo get_icon('clock', 'mr-2 inline-block'); ?><?php echo e(t('Run History')); ?>
            </h3>
            <button onclick="closeHistoryModal()" class="text-xl" style="color: var(--text-muted);">&times;</button>
        </div>
        <div id="historyContent" class="space-y-2">
            <div class="text-center py-8" style="color: var(--text-muted);"><?php echo e(t('Loading...')); ?></div>
        </div>
    </div>
</div>

<!-- Pause Modal -->
<div id="pauseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="rounded-xl shadow-xl max-w-md w-full mx-4 p-4" style="background: var(--bg-primary);">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">
                <?php echo get_icon('pause-circle', 'mr-2 inline-block'); ?><?php echo e(t('Pause Task')); ?>
            </h3>
            <button onclick="closePauseModal()" class="text-xl" style="color: var(--text-muted);">&times;</button>
        </div>
        <p class="text-sm mb-3" style="color: var(--text-secondary);">
            <?php echo e(t('This will stop the task from creating tickets until resumed.')); ?>
        </p>
        <p class="text-sm font-medium mb-1" id="pauseTaskName" style="color: var(--text-primary);"></p>
        <form method="POST" id="pauseForm" class="mt-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="pause">
            <input type="hidden" name="task_id" id="pauseTaskId" value="">

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Auto-resume date')); ?>
                    <span class="text-xs font-normal" style="color: var(--text-muted);">(<?php echo e(t('Optional')); ?>)</span>
                </label>
                <input type="date" name="resume_date" id="pauseResumeDate" class="form-input w-full"
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    <?php echo e(t('Leave empty to pause indefinitely. Task will auto-resume on this date.')); ?>
                </p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t" style="border-color: var(--border-light);">
                <button type="button" onclick="closePauseModal()" class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                <button type="submit" class="btn" style="background: #f97316; color: white;">
                    <?php echo get_icon('pause-circle', 'mr-1 inline-block'); ?><?php echo e(t('Pause Task')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="rounded-xl shadow-xl max-w-2xl w-full mx-4 my-8 p-4" style="background: var(--bg-primary);">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold" id="previewTitle" style="color: var(--text-primary);">
                <?php echo get_icon('eye', 'mr-2 inline-block'); ?><?php echo e(t('Ticket Preview')); ?>
            </h3>
            <button onclick="closePreviewModal()" class="text-xl" style="color: var(--text-muted);">&times;</button>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">
            <?php echo e(t('This is a preview of the ticket that will be generated on the next scheduled run.')); ?>
        </p>
        <div id="previewContent"></div>
        <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t" style="border-color: var(--border-light);">
            <button type="button" onclick="closePreviewModal()" class="btn btn-secondary"><?php echo e(t('Close')); ?></button>
        </div>
    </div>
</div>

<script>
    // Lookup maps for preview
    var _previewLookups = {
        agents: <?php echo json_encode(array_combine(
            array_column($agents, 'id'),
            array_map(fn($a) => trim($a['first_name'] . ' ' . $a['last_name']), $agents)
        ), JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        priorities: <?php echo json_encode(array_combine(
            array_column($priorities, 'id'),
            array_column($priorities, 'name')
        ), JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        statuses: <?php echo json_encode(array_combine(
            array_column($statuses, 'id'),
            array_column($statuses, 'name')
        ), JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        ticketTypes: <?php echo json_encode(array_combine(
            array_column($ticket_types, 'id'),
            array_column($ticket_types, 'name')
        ), JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        organizations: <?php echo json_encode(array_combine(
            array_column($organizations, 'id'),
            array_column($organizations, 'name')
        ), JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        labels: {
            title: <?php echo json_encode(t('Title')); ?>,
            description: <?php echo json_encode(t('Description')); ?>,
            assignedTo: <?php echo json_encode(t('Assigned to')); ?>,
            priority: <?php echo json_encode(t('Priority')); ?>,
            status: <?php echo json_encode(t('Status')); ?>,
            ticketType: <?php echo json_encode(t('Ticket Type')); ?>,
            organization: <?php echo json_encode(t('Organization')); ?>,
            tags: <?php echo json_encode(t('Tags')); ?>,
            dueDate: <?php echo json_encode(t('Due Date')); ?>,
            emailNotification: <?php echo json_encode(t('Email Notification')); ?>,
            yes: <?php echo json_encode(t('Yes')); ?>,
            no: <?php echo json_encode(t('No')); ?>,
            none: <?php echo json_encode(t('None')); ?>,
            noDescription: <?php echo json_encode(t('No description')); ?>,
            daysFromCreation: <?php echo json_encode(t('days from creation')); ?>
        }
    };
</script>

<script>
    function openTaskModal() {
        document.getElementById('taskForm').reset();
        document.getElementById('formAction').value = 'create';
        document.getElementById('taskId').value = '';
        document.getElementById('modalTitle').textContent = <?php echo json_encode(t('Create Recurring Task')); ?>;
        document.getElementById('start_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('due_days').value = '7';
        var modal = document.getElementById('taskModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        updateRecurrenceFields();
    }

    function editTask(task) {
        document.getElementById('formAction').value = 'update';
        document.getElementById('taskId').value = task.id;
        document.getElementById('modalTitle').textContent = <?php echo json_encode(t('Edit Recurring Task')); ?>;
        document.getElementById('title').value = task.title || '';
        document.getElementById('description').value = task.description || '';
        document.getElementById('organization_id').value = task.organization_id || '';
        document.getElementById('assigned_user_id').value = task.assigned_user_id;
        document.getElementById('ticket_type_id').value = task.ticket_type_id || '';
        document.getElementById('priority_id').value = task.priority_id || '';
        document.getElementById('status_id').value = task.status_id;
        document.getElementById('due_days').value = task.due_days || '7';
        document.getElementById('recurrence_type').value = task.recurrence_type;
        document.getElementById('recurrence_interval').value = task.recurrence_interval;
        document.getElementById('recurrence_day_of_week').value = task.recurrence_day_of_week || '1';
        document.getElementById('recurrence_day_of_month').value = task.recurrence_day_of_month || '1';
        document.getElementById('recurrence_month').value = task.recurrence_month || '1';
        document.getElementById('start_date').value = task.start_date;
        document.getElementById('end_date').value = task.end_date || '';
        document.getElementById('task_tags').value = task.tags || '';
        document.getElementById('send_email_notification').checked = task.send_email_notification == 1;
        document.getElementById('is_active').checked = task.is_active == 1;
        var modal = document.getElementById('taskModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        updateRecurrenceFields();
    }

    function closeTaskModal() {
        var modal = document.getElementById('taskModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function updateRecurrenceFields() {
        var type = document.getElementById('recurrence_type').value;
        document.getElementById('dayOfWeekField').classList.toggle('hidden', type !== 'weekly');
        document.getElementById('dayOfMonthField').classList.toggle('hidden', type !== 'monthly' && type !== 'yearly');
        document.getElementById('monthField').classList.toggle('hidden', type !== 'yearly');
    }

    function showRunHistory(taskId, taskTitle) {
        var modal = document.getElementById('historyModal');
        var content = document.getElementById('historyContent');
        document.getElementById('historyTitle').textContent = taskTitle + ' — <?php echo e(t('Run History')); ?>';
        content.textContent = <?php echo json_encode(t('Loading...')); ?>;
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        fetch('index.php?page=admin&section=recurring-tasks&ajax_history=' + taskId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.runs.length) {
                    content.textContent = <?php echo json_encode(t('No runs recorded yet.')); ?>;
                    return;
                }
                // Build run list using DOM methods for safety
                content.textContent = '';
                var container = document.createElement('div');
                container.className = 'divide-y';
                container.style.borderColor = 'var(--border-light)';
                data.runs.forEach(function(run) {
                    var row = document.createElement('div');
                    row.className = 'flex items-center justify-between py-3 px-1';

                    var left = document.createElement('div');
                    left.className = 'flex items-center gap-3';

                    var badge = document.createElement('span');
                    badge.className = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ' +
                        (run.status === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                    badge.textContent = run.status === 'success' ? '✓ ' + <?php echo json_encode(t('Success')); ?> : '✗ ' + <?php echo json_encode(t('Failed')); ?>;

                    var dateSpan = document.createElement('span');
                    dateSpan.className = 'text-sm';
                    dateSpan.style.color = 'var(--text-secondary)';
                    var d = new Date(run.created_at);
                    dateSpan.textContent = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

                    left.appendChild(badge);
                    left.appendChild(dateSpan);

                    var right = document.createElement('div');
                    if (run.ticket_id) {
                        var link = document.createElement('a');
                        link.href = 'index.php?page=ticket&id=' + run.ticket_id;
                        link.className = 'text-blue-600 hover:underline text-sm';
                        link.textContent = run.ticket_title || '#' + run.ticket_id;
                        right.appendChild(link);
                    } else {
                        var dash = document.createElement('span');
                        dash.style.color = 'var(--text-muted)';
                        dash.textContent = '—';
                        right.appendChild(dash);
                    }

                    row.appendChild(left);
                    row.appendChild(right);
                    container.appendChild(row);
                });
                content.appendChild(container);
            })
            .catch(function() {
                content.textContent = <?php echo json_encode(t('Failed to load history.')); ?>;
            });
    }

    function closeHistoryModal() {
        var modal = document.getElementById('historyModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openPauseModal(taskId, taskTitle) {
        document.getElementById('pauseTaskId').value = taskId;
        document.getElementById('pauseTaskName').textContent = taskTitle;
        document.getElementById('pauseResumeDate').value = '';
        var modal = document.getElementById('pauseModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closePauseModal() {
        var modal = document.getElementById('pauseModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openPreviewModal(task) {
        var L = _previewLookups;
        var content = document.getElementById('previewContent');
        content.innerHTML = '';

        // Calculate due date
        var dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + (parseInt(task.due_days) || 7));
        var dueDateStr = dueDate.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

        // Build rows
        var rows = [
            { label: L.labels.title, value: task.title || '—', isBold: true },
            { label: L.labels.description, value: task.description || null, isDesc: true },
            { label: L.labels.assignedTo, value: L.agents[task.assigned_user_id] || '—' },
            { label: L.labels.priority, value: task.priority_id ? (L.priorities[task.priority_id] || '—') : L.labels.none },
            { label: L.labels.status, value: L.statuses[task.status_id] || '—' },
            { label: L.labels.ticketType, value: task.ticket_type_id ? (L.ticketTypes[task.ticket_type_id] || '—') : L.labels.none },
            { label: L.labels.organization, value: task.organization_id ? (L.organizations[task.organization_id] || '—') : L.labels.none },
            { label: L.labels.tags, value: task.tags || null, isTags: true },
            { label: L.labels.dueDate, value: dueDateStr + ' (' + (task.due_days || 7) + ' ' + L.labels.daysFromCreation + ')' },
            { label: L.labels.emailNotification, value: task.send_email_notification == 1 ? L.labels.yes : L.labels.no }
        ];

        var table = document.createElement('div');
        table.className = 'divide-y';
        table.style.borderColor = 'var(--border-light)';

        rows.forEach(function(row) {
            var el = document.createElement('div');
            el.className = 'flex gap-4 py-3 px-1';

            var labelEl = document.createElement('div');
            labelEl.className = 'w-36 flex-shrink-0 text-sm font-medium';
            labelEl.style.color = 'var(--text-muted)';
            labelEl.textContent = row.label;

            var valueEl = document.createElement('div');
            valueEl.className = 'flex-1 text-sm';
            valueEl.style.color = 'var(--text-primary)';

            if (row.isDesc) {
                if (row.value) {
                    valueEl.style.whiteSpace = 'pre-wrap';
                    valueEl.style.wordBreak = 'break-word';
                    valueEl.textContent = row.value;
                } else {
                    valueEl.style.color = 'var(--text-muted)';
                    valueEl.style.fontStyle = 'italic';
                    valueEl.textContent = L.labels.noDescription;
                }
            } else if (row.isTags) {
                if (row.value) {
                    var tagsArr = row.value.split(',');
                    tagsArr.forEach(function(tag) {
                        tag = tag.trim();
                        if (!tag) return;
                        var badge = document.createElement('span');
                        badge.className = 'inline-block px-2 py-0.5 rounded-full text-xs font-medium mr-1 mb-1';
                        badge.style.background = 'var(--surface-secondary)';
                        badge.style.color = 'var(--text-secondary)';
                        badge.textContent = '#' + tag;
                        valueEl.appendChild(badge);
                    });
                } else {
                    valueEl.style.color = 'var(--text-muted)';
                    valueEl.textContent = L.labels.none;
                }
            } else if (row.isBold) {
                valueEl.className += ' font-semibold';
                valueEl.textContent = row.value;
            } else {
                valueEl.textContent = row.value;
            }

            el.appendChild(labelEl);
            el.appendChild(valueEl);
            table.appendChild(el);
        });

        content.appendChild(table);

        var modal = document.getElementById('previewModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closePreviewModal() {
        var modal = document.getElementById('previewModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Close modals on outside click
    ['taskModal', 'historyModal', 'pauseModal', 'previewModal'].forEach(function(id) {
        document.getElementById(id)?.addEventListener('click', function (e) {
            if (e.target === this) {
                this.classList.add('hidden');
                this.classList.remove('flex');
            }
        });
    });

    updateRecurrenceFields();

    // ─── Search & Filter ────────────────────────────────────────────
    var _taskFilter = 'all';

    function setTaskFilter(filter) {
        _taskFilter = filter;
        // Update button styles
        document.querySelectorAll('.task-filter-btn').forEach(function(btn) {
            if (btn.dataset.filter === filter) {
                btn.style.background = 'var(--accent-primary)';
                btn.style.color = '#fff';
                btn.classList.add('active');
            } else {
                btn.style.background = 'transparent';
                btn.style.color = 'var(--text-secondary)';
                btn.classList.remove('active');
            }
        });
        filterTasks();
    }

    function filterTasks() {
        var search = (document.getElementById('taskSearch')?.value || '').toLowerCase().trim();
        var rows = document.querySelectorAll('.task-row');
        var visible = 0;

        rows.forEach(function(row) {
            var status = row.dataset.status;
            var text = row.dataset.search || '';
            var matchFilter = (_taskFilter === 'all') || (status === _taskFilter);
            var matchSearch = !search || text.indexOf(search) !== -1;
            if (matchFilter && matchSearch) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        var noRow = document.getElementById('noTasksRow');
        if (noRow) noRow.style.display = (visible === 0 && rows.length > 0) ? '' : 'none';
        if (noRow) noRow.classList.toggle('hidden', visible > 0 || rows.length === 0);
    }
</script>

<?php include BASE_PATH . '/includes/footer.php';
