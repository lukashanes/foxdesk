<?php
/**
 * Statuses Content (for inclusion in Settings page)
 */

$statuses = get_statuses();

// Handle form submissions for statuses
// CSRF token is already validated in settings.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'workflow') {

    // Add new status
    if (isset($_POST['add_status'])) {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82f6';
        $position = $_POST['position'] ?? 'end';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));

        if (!empty($name)) {
            if ($position === 'start') {
                db_query("UPDATE statuses SET sort_order = sort_order + 1");
                $new_order = 1;
            } elseif (is_numeric($position)) {
                $after_id = (int) $position;
                $after_status = get_status($after_id);
                if ($after_status) {
                    $new_order = $after_status['sort_order'] + 1;
                    db_query("UPDATE statuses SET sort_order = sort_order + 1 WHERE sort_order > ?", [$after_status['sort_order']]);
                } else {
                    $max_order = db_fetch_one("SELECT MAX(sort_order) as max_order FROM statuses")['max_order'] ?? 0;
                    $new_order = $max_order + 1;
                }
            } else {
                $max_order = db_fetch_one("SELECT MAX(sort_order) as max_order FROM statuses")['max_order'] ?? 0;
                $new_order = $max_order + 1;
            }

            db_insert('statuses', [
                'name' => $name,
                'slug' => $slug,
                'color' => $color,
                'sort_order' => $new_order,
                'is_default' => 0,
                'is_closed' => isset($_POST['is_closed']) ? 1 : 0
            ]);

            flash(t('Status added.'), 'success');
            redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
        }
    }

    // Update status
    if (isset($_POST['update_status'])) {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82f6';

        if (!empty($name) && $id > 0) {
            db_update('statuses', [
                'name' => $name,
                'color' => $color,
                'is_closed' => isset($_POST['is_closed']) ? 1 : 0
            ], 'id = ?', [$id]);

            flash(t('Status updated.'), 'success');
            redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
        }
    }

    // Delete status
    if (isset($_POST['delete_status'])) {
        $id = (int) $_POST['id'];
        $count = db_fetch_one("SELECT COUNT(*) as count FROM tickets WHERE status_id = ?", [$id])['count'];

        if ($count > 0) {
            flash(t('Cannot delete a status that is used by tickets.'), 'error');
        } else {
            db_delete('statuses', 'id = ?', [$id]);
            flash(t('Status deleted.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
    }

    // Set default
    if (isset($_POST['set_default'])) {
        $id = (int) $_POST['id'];
        db_query("UPDATE statuses SET is_default = 0");
        db_update('statuses', ['is_default' => 1], 'id = ?', [$id]);
        flash(t('Default status set.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
    }
}

// Refresh statuses after potential changes
$statuses = get_statuses();
$edit_status_id = isset($_GET['edit_status']) ? (int)$_GET['edit_status'] : null;
$edit_status = $edit_status_id ? get_status($edit_status_id) : null;
?>

<div class="flex flex-col h-full">
    <!-- Add New Status Button -->
    <button type="button" class="mb-3 w-full px-3 py-2 rounded-lg text-xs font-medium flex items-center justify-center gap-2 transition-colors"
        style="background: var(--surface-secondary); color: var(--text-primary);"
        onclick="document.getElementById('add-status-form').classList.toggle('hidden')">
        <?php echo get_icon('plus', 'w-3.5 h-3.5'); ?>
        <?php echo e(t('Add Status')); ?>
    </button>

    <!-- Add Form (Glassmorphic) -->
    <form method="post" id="add-status-form" class="hidden mb-3 p-3 rounded-lg glass-form" style="background: rgba(255, 255, 255, 0.7);">
        <?php echo csrf_field(); ?>
        <h4 class="text-xs font-semibold mb-2" style="color: var(--text-primary);">
            <?php echo e(t('Add New Status')); ?>
        </h4>
        <div class="space-y-2">
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Name')); ?> *
                </label>
                <input type="text" name="name" required class="form-input w-full text-xs" placeholder="Open, In Progress, Done...">
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Color')); ?>
                </label>
                <input type="color" name="color" value="#3b82f6" class="w-full h-8 rounded cursor-pointer" style="border: 1px solid var(--border-light);">
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Position')); ?>
                </label>
                <select name="position" class="form-select w-full text-xs">
                    <option value="end"><?php echo e(t('At end')); ?></option>
                    <option value="start"><?php echo e(t('At start')); ?></option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?php echo $s['id']; ?>">
                            <?php echo e(t('After {name}', ['name' => $s['name']])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                <input type="checkbox" name="is_closed" class="rounded w-3.5 h-3.5">
                <?php echo e(t('Mark as closed')); ?>
            </label>
            <div class="flex gap-2 pt-1">
                <button type="submit" name="add_status" class="flex-1 btn btn-primary btn-sm text-xs">
                    <?php echo e(t('Create')); ?>
                </button>
                <button type="button" class="flex-1 px-2 py-1 rounded text-xs transition-colors"
                    style="background: var(--border-light); color: var(--text-secondary);"
                    onclick="document.getElementById('add-status-form').classList.add('hidden')">
                    <?php echo e(t('Cancel')); ?>
                </button>
            </div>
        </div>
    </form>

    <!-- Statuses List -->
    <div id="statuses-list" class="status-list">
        <?php if (empty($statuses)): ?>
            <div class="text-center py-6" style="color: var(--text-muted);">
                <p class="text-xs"><?php echo e(t('No statuses yet.')); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($statuses as $status): ?>
            <div class="status-item accordion-item" data-id="<?php echo $status['id']; ?>"
                data-name="<?php echo e($status['name']); ?>"
                data-color="<?php echo e($status['color']); ?>"
                data-closed="<?php echo $status['is_closed'] ? '1' : '0'; ?>"
                data-default="<?php echo $status['is_default'] ? '1' : '0'; ?>">

                <!-- Item Header (always visible) -->
                <div class="accordion-header">
                    <!-- Drag Handle -->
                    <span class="drag-handle">
                        <?php echo get_icon('grip-vertical', 'w-3.5 h-3.5'); ?>
                    </span>

                    <!-- Color Swatch -->
                    <div class="color-swatch" style="background-color: <?php echo e($status['color']); ?>"></div>

                    <!-- Name and Status -->
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium" style="color: var(--text-primary);">
                            <?php echo e($status['name']); ?>
                        </div>
                        <?php if ($status['is_closed']): ?>
                            <span class="text-xs px-1.5 py-0.5 rounded mt-0.5 inline-block" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                                <?php echo e(t('Closed')); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Default Badge -->
                    <?php if ($status['is_default']): ?>
                        <span class="text-xs px-1.5 py-0.5 rounded flex-shrink-0" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <?php echo e(t('Default')); ?>
                        </span>
                    <?php endif; ?>

                    <!-- Edit Button -->
                    <button type="button" class="px-1.5 py-0.5 rounded transition-colors flex-shrink-0 accordion-toggle"
                        style="color: #3b82f6; background: transparent;"
                        onclick="toggleAccordion(this)"
                        title="<?php echo e(t('Edit')); ?>">
                        <?php echo get_icon('edit-2', 'w-3.5 h-3.5'); ?>
                    </button>
                </div>

                <!-- Expandable Content (initially hidden) -->
                <div class="accordion-content">
                    <form method="post" class="accordion-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo $status['id']; ?>">

                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Name')); ?> *
                                </label>
                                <input type="text" name="name" value="<?php echo e($status['name']); ?>" required class="form-input w-full text-xs"
                                    style="background: var(--surface-secondary);">
                            </div>

                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Color')); ?>
                                </label>
                                <input type="color" name="color" value="<?php echo e($status['color']); ?>" class="w-full h-8 rounded cursor-pointer"
                                    style="border: 1px solid var(--border-light);">
                            </div>

                            <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                                <input type="checkbox" name="is_closed" class="rounded w-3.5 h-3.5" <?php echo $status['is_closed'] ? 'checked' : ''; ?>>
                                <?php echo e(t('Mark as closed')); ?>
                            </label>

                            <div class="pt-2 border-t" style="border-color: var(--border-light);">
                                <div class="flex gap-2 mb-2">
                                    <button type="submit" name="update_status" class="flex-1 btn btn-primary btn-sm text-xs">
                                        <?php echo e(t('Save')); ?>
                                    </button>
                                    <button type="submit" name="set_default" class="flex-1 btn btn-sm text-xs" style="background: var(--border-light); color: var(--text-secondary);">
                                        <?php echo e(t('Set Default')); ?>
                                    </button>
                                </div>
                                <button type="submit" name="delete_status" class="w-full btn btn-sm text-xs" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;"
                                    onclick="return confirm('<?php echo e(t('Are you sure you want to delete this status?')); ?>')">
                                    <?php echo e(t('Delete')); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Toggle accordion expand/collapse
function toggleAccordion(button) {
    const item = button.closest('.accordion-item');
    const isOpen = item.classList.contains('open');

    // Close all other accordions
    document.querySelectorAll('.accordion-item.open').forEach(el => {
        if (el !== item) {
            el.classList.remove('open');
        }
    });

    // Toggle current accordion
    if (isOpen) {
        item.classList.remove('open');
    } else {
        item.classList.add('open');
    }
}

// Handle drag and drop reordering
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('statuses-list');
    const csrfToken = window.csrfToken || '';

    if (list) {
        new Sortable(list, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            handle: '.drag-handle',
            filter: 'input, button, a, select, form, .accordion-content',
            preventOnFilter: false,
            forceFallback: true,
            onEnd: function() {
                const items = list.querySelectorAll('.status-item');
                const order = Array.from(items).map(item => item.dataset.id);

                fetch('index.php?page=api&action=reorder-statuses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ order: order })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.error || '<?php echo e(t('Failed to save order')); ?>');
                        }
                    })
                    .catch(() => alert('<?php echo e(t('Failed to save order')); ?>'));
            }
        });
    }
});
</script>
