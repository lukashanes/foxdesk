<?php
/**
 * Admin - Recurring Tasks Management
 */

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$current_user = current_user();
$page_title = t('Recurring Tasks');

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
    } elseif ($action === 'toggle_active' && $task_id > 0) {
        $task = get_recurring_task($task_id);
        if ($task) {
            update_recurring_task($task_id, ['is_active' => $task['is_active'] ? 0 : 1]);
            flash(t('Status updated successfully.'), 'success');
        }
    }

    redirect('admin', ['section' => 'recurring-tasks']);
}

// Get all recurring tasks
$tasks = get_recurring_tasks(false); // Show all, including inactive

// Get data for form dropdowns
$ticket_types = get_ticket_types();
$organizations = get_organizations(true);
$_ai_excl = (function_exists('ai_agent_column_exists') && ai_agent_column_exists()) ? ' AND is_ai_agent = 0' : '';
$agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1{$_ai_excl} ORDER BY first_name, last_name");
$priorities = get_priorities();
$statuses = get_statuses();

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

    <!-- Tasks List -->
    <?php if (empty($tasks)): ?>
        <div class="card p-12 text-center">
            <span style="color: var(--text-muted); opacity: 0.5;"><?php echo get_icon('redo', 'text-6xl mb-4 inline-block'); ?></span>
            <h3 class="text-lg font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('No Recurring Tasks')); ?></h3>
            <p class="mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Create your first recurring task to automate ticket creation')); ?></p>
            <button onclick="openTaskModal()" class="btn btn-primary">
                <?php echo get_icon('plus', 'mr-2 inline-block'); ?>    <?php echo e(t('Create First Task')); ?>
            </button>
        </div>
    <?php else: ?>
        <div class="card overflow-hidden">
            <table class="w-full">
                <thead class="border-b" style="background: var(--surface-secondary); border-color: var(--border-light);">
                    <tr>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Task')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Assigned To')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Schedule')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Next Run')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Status')); ?>
                        </th>
                        <th class="px-6 py-3 text-right th-label">
                            <?php echo e(t('Actions')); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--border-light);">
                    <?php foreach ($tasks as $task): ?>
                        <tr class="tr-hover">
                            <td class="px-6 py-4">
                                <div class="font-medium" style="color: var(--text-primary);"><?php echo e($task['title']); ?></div>
                                <?php if ($task['organization_name']): ?>
                                    <div class="text-xs" style="color: var(--text-muted);"><?php echo e($task['organization_name']); ?></div>
                                <?php endif; ?>
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
                                <?php echo date('M j, Y', strtotime($task['next_run_date'])); ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($task['is_active']): ?>
                                    <span class="badge-inline rounded-full bg-green-100 text-green-800">
                                        <?php echo get_icon('check-circle', 'mr-1 inline-block w-3 h-3'); ?> <?php echo e(t('Active')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-inline rounded-full" style="background: var(--surface-secondary); color: var(--text-primary);">
                                        <?php echo get_icon('pause-circle', 'mr-1 inline-block w-3 h-3'); ?> <?php echo e(t('Inactive')); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <button onclick='editTask(<?php echo json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                        class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        <?php echo get_icon('edit', 'mr-1 inline-block'); ?>        <?php echo e(t('Edit')); ?>
                                    </button>

                                    <form method="POST" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="text-orange-600 hover:text-orange-800 text-sm font-medium">
                                            <?php echo get_icon('power-off', 'mr-1 inline-block'); ?>        <?php echo $task['is_active'] ? e(t('Deactivate')) : e(t('Activate')); ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="inline"
                                        onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this recurring task?')); ?>');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                            <?php echo get_icon('trash', 'mr-1 inline-block'); ?>        <?php echo e(t('Delete')); ?>
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
                <!-- Title -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Task Title')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" id="title" required class="form-input w-full"
                        placeholder="<?php echo e(t('e.g., Weekly server backup check')); ?>">
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                    <label
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Description')); ?></label>
                    <textarea name="description" id="description" rows="3" class="form-input w-full"></textarea>
                </div>

                <!-- Organization -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Client')); ?></label>
                    <select name="organization_id" id="organization_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Client --')); ?></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Assigned User -->
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

                <!-- Ticket Type -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Type')); ?></label>
                    <select name="ticket_type_id" id="ticket_type_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Type --')); ?></option>
                        <?php foreach ($ticket_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo e($type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Priority -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Priority')); ?></label>
                    <select name="priority_id" id="priority_id" class="form-select w-full">
                        <option value=""><?php echo e(t('-- No Priority --')); ?></option>
                        <?php foreach ($priorities as $priority): ?>
                            <option value="<?php echo $priority['id']; ?>"><?php echo e($priority['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Initial Status -->
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

                <!-- Recurrence Type -->
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

                <!-- Recurrence Interval -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Every')); ?>
                    </label>
                    <input type="number" name="recurrence_interval" id="recurrence_interval" value="1" min="1" max="365"
                        class="form-input w-full">
                </div>

                <!-- Day of Week (for weekly) -->
                <div id="dayOfWeekField" class="hidden">
                    <label
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Day of Week')); ?></label>
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

                <!-- Day of Month (for monthly/yearly) -->
                <div id="dayOfMonthField" class="hidden">
                    <label
                        class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Day of Month')); ?></label>
                    <input type="number" name="recurrence_day_of_month" id="recurrence_day_of_month" value="1" min="1"
                        max="31" class="form-input w-full">
                </div>

                <!-- Month (for yearly) -->
                <div id="monthField" class="hidden">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Month')); ?></label>
                    <select name="recurrence_month" id="recurrence_month" class="form-select w-full">
                        <option value="1"><?php echo e(t('January')); ?></option>
                        <option value="2"><?php echo e(t('February')); ?></option>
                        <option value="3"><?php echo e(t('March')); ?></option>
                        <option value="4"><?php echo e(t('April')); ?></option>
                        <option value="5"><?php echo e(t('May')); ?></option>
                        <option value="6"><?php echo e(t('June')); ?></option>
                        <option value="7"><?php echo e(t('July')); ?></option>
                        <option value="8"><?php echo e(t('August')); ?></option>
                        <option value="9"><?php echo e(t('September')); ?></option>
                        <option value="10"><?php echo e(t('October')); ?></option>
                        <option value="11"><?php echo e(t('November')); ?></option>
                        <option value="12"><?php echo e(t('December')); ?></option>
                    </select>
                </div>

                <!-- Start Date -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Start Date')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo date('Y-m-d'); ?>" required
                        class="form-input w-full">
                </div>

                <!-- End Date -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('End Date')); ?> <span
                            class="text-xs" style="color: var(--text-muted);">(<?php echo e(t('Optional')); ?>)</span></label>
                    <input type="date" name="end_date" id="end_date" class="form-input w-full">
                </div>

                <!-- Options -->
                <div class="md:col-span-2 space-y-2">
                    <label class="flex items-center text-sm">
                        <input type="checkbox" name="send_email_notification" id="send_email_notification" checked
                            class="mr-2">
                        <?php echo e(t('Send email notification to assigned agent')); ?>
                    </label>
                    <label class="flex items-center text-sm">
                        <input type="checkbox" name="is_active" id="is_active" checked class="mr-2">
                        <?php echo e(t('Active')); ?>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-3 pt-6 border-t">
                <button type="button" onclick="closeTaskModal()" class="btn btn-secondary">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" class="btn btn-primary">
                    <?php echo get_icon('save', 'mr-2 inline-block'); ?><?php echo e(t('Save')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openTaskModal() {
        document.getElementById('taskForm').reset();
        document.getElementById('formAction').value = 'create';
        document.getElementById('taskId').value = '';
        document.getElementById('modalTitle').innerHTML = '<?php echo get_icon('redo', 'mr-2 inline-block'); ?><?php echo e(t('Create Recurring Task')); ?>';
        document.getElementById('start_date').value = '<?php echo date('Y-m-d'); ?>';

        const modal = document.getElementById('taskModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        updateRecurrenceFields();
    }

    function editTask(task) {
        document.getElementById('formAction').value = 'update';
        document.getElementById('taskId').value = task.id;
        document.getElementById('modalTitle').innerHTML = '<?php echo get_icon('redo', 'mr-2 inline-block'); ?><?php echo e(t('Edit Recurring Task')); ?>';

        document.getElementById('title').value = task.title || '';
        document.getElementById('description').value = task.description || '';
        document.getElementById('organization_id').value = task.organization_id || '';
        document.getElementById('assigned_user_id').value = task.assigned_user_id;
        document.getElementById('ticket_type_id').value = task.ticket_type_id || '';
        document.getElementById('priority_id').value = task.priority_id || '';
        document.getElementById('status_id').value = task.status_id;
        document.getElementById('recurrence_type').value = task.recurrence_type;
        document.getElementById('recurrence_interval').value = task.recurrence_interval;
        document.getElementById('recurrence_day_of_week').value = task.recurrence_day_of_week || '1';
        document.getElementById('recurrence_day_of_month').value = task.recurrence_day_of_month || '1';
        document.getElementById('recurrence_month').value = task.recurrence_month || '1';
        document.getElementById('start_date').value = task.start_date;
        document.getElementById('end_date').value = task.end_date || '';
        document.getElementById('send_email_notification').checked = task.send_email_notification == 1;
        document.getElementById('is_active').checked = task.is_active == 1;

        const modal = document.getElementById('taskModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        updateRecurrenceFields();
    }

    function closeTaskModal() {
        const modal = document.getElementById('taskModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function updateRecurrenceFields() {
        const type = document.getElementById('recurrence_type').value;

        const dayOfWeekField = document.getElementById('dayOfWeekField');
        const dayOfMonthField = document.getElementById('dayOfMonthField');
        const monthField = document.getElementById('monthField');

        dayOfWeekField.classList.add('hidden');
        dayOfMonthField.classList.add('hidden');
        monthField.classList.add('hidden');

        if (type === 'weekly') {
            dayOfWeekField.classList.remove('hidden');
        } else if (type === 'monthly') {
            dayOfMonthField.classList.remove('hidden');
        } else if (type === 'yearly') {
            dayOfMonthField.classList.remove('hidden');
            monthField.classList.remove('hidden');
        }
    }

    // Close modal on outside click
    document.getElementById('taskModal')?.addEventListener('click', function (e) {
        if (e.target === this) {
            closeTaskModal();
        }
    });

    // Initialize
    updateRecurrenceFields();
</script>

<?php include BASE_PATH . '/includes/footer.php'; 
