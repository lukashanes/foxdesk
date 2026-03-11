<?php
/**
 * Priorities Content (for inclusion in Settings page)
 */

// Handle form submissions for priorities
// CSRF token is already validated in settings.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'workflow') {

    // Create new priority
    if (isset($_POST['create_priority'])) {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#f59e0b';
        $icon = trim($_POST['icon'] ?? 'fa-flag');
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if (empty($name)) {
            flash(t('Priority name is required.'), 'error');
        } else {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
            $slug = trim($slug, '_');

            $existing = db_fetch_one("SELECT id FROM priorities WHERE slug = ?", [$slug]);
            if ($existing) $slug .= '_' . time();

            $max = db_fetch_one("SELECT MAX(sort_order) as max_order FROM priorities");
            $sort_order = ($max['max_order'] ?? 0) + 1;

            if ($is_default) {
                db_query("UPDATE priorities SET is_default = 0");
            }

            db_insert('priorities', [
                'name' => $name, 'slug' => $slug, 'color' => $color, 'icon' => $icon,
                'sort_order' => $sort_order, 'is_default' => $is_default, 'created_at' => date('Y-m-d H:i:s')
            ]);
            flash(t('Priority created.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
    }

    // Update priority
    if (isset($_POST['update_priority'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#f59e0b';
        $icon = trim($_POST['icon'] ?? 'fa-flag');
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if (empty($name)) {
            flash(t('Priority name is required.'), 'error');
        } else {
            if ($is_default) {
                db_query("UPDATE priorities SET is_default = 0");
            }
            db_update('priorities', ['name' => $name, 'color' => $color, 'icon' => $icon, 'is_default' => $is_default], 'id = ?', [$id]);
            flash(t('Priority updated.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
    }

    // Delete priority
    if (isset($_POST['delete_priority'])) {
        $id = (int)$_POST['id'];
        $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE priority_id = ?", [$id]);
        if ($tickets_count && $tickets_count['c'] > 0) {
            flash(t('Cannot delete a priority that is used by tickets.'), 'error');
        } else {
            db_query("DELETE FROM priorities WHERE id = ?", [$id]);
            flash(t('Priority deleted.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'workflow']);
    }
}

$priorities = get_priorities();

$priority_icons = [
    'fa-flag' => 'Flag', 'fa-arrow-down' => 'Low', 'fa-minus' => 'Normal',
    'fa-arrow-up' => 'High', 'fa-exclamation' => 'Urgent', 'fa-fire' => 'Critical',
    'fa-bolt' => 'Bolt', 'fa-star' => 'Star', 'fa-circle' => 'Dot'
];
?>

<div class="flex flex-col h-full">
    <!-- Add New Priority Button -->
    <button type="button" class="mb-3 w-full px-3 py-2 rounded-lg text-xs font-medium flex items-center justify-center gap-2 transition-colors"
        style="background: var(--surface-secondary); color: var(--text-primary);"
        onclick="document.getElementById('add-priority-form').classList.toggle('hidden')">
        <?php echo get_icon('plus', 'w-3.5 h-3.5'); ?>
        <?php echo e(t('Add Priority')); ?>
    </button>

    <!-- Add Form (Glassmorphic) -->
    <form method="post" id="add-priority-form" class="hidden mb-3 p-3 rounded-lg glass-form" style="background: rgba(255, 255, 255, 0.7);">
        <?php echo csrf_field(); ?>
        <h4 class="text-xs font-semibold mb-2" style="color: var(--text-primary);">
            <?php echo e(t('Add Priority')); ?>
        </h4>
        <div class="space-y-2">
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Name')); ?> *
                </label>
                <input type="text" name="name" required class="form-input w-full text-xs" placeholder="High, Medium, Low...">
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Color')); ?>
                </label>
                <input type="color" name="color" value="#f59e0b" class="w-full h-8 rounded cursor-pointer" style="border: 1px solid var(--border-light);">
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                    <?php echo e(t('Icon')); ?>
                </label>
                <div class="grid grid-cols-4 gap-1">
                    <?php foreach ($priority_icons as $icon => $label): ?>
                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                        <input type="radio" name="icon" value="<?php echo $icon; ?>" class="sr-only peer">
                        <div class="w-7 h-7 flex items-center justify-center rounded border text-xs peer-checked:border-amber-500 peer-checked:bg-amber-50"
                            style="border-color: var(--border-light); color: var(--text-secondary);">
                            <?php echo get_icon($icon, 'w-3.5 h-3.5'); ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                <input type="checkbox" name="is_default" class="rounded w-3.5 h-3.5">
                <?php echo e(t('Set as default')); ?>
            </label>
            <div class="flex gap-2 pt-1">
                <button type="submit" name="create_priority" class="flex-1 btn btn-primary btn-sm text-xs">
                    <?php echo e(t('Create')); ?>
                </button>
                <button type="button" class="flex-1 px-2 py-1 rounded text-xs transition-colors"
                    style="background: var(--border-light); color: var(--text-secondary);"
                    onclick="document.getElementById('add-priority-form').classList.add('hidden')">
                    <?php echo e(t('Cancel')); ?>
                </button>
            </div>
        </div>
    </form>

    <!-- Priorities List -->
    <div id="priorities-list" class="priority-list">
        <?php if (empty($priorities)): ?>
            <div class="text-center py-6" style="color: var(--text-muted);">
                <p class="text-xs"><?php echo e(t('No priorities yet.')); ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($priorities as $priority): ?>
            <div class="priority-item accordion-item" data-id="<?php echo $priority['id']; ?>"
                data-name="<?php echo e($priority['name']); ?>"
                data-color="<?php echo e($priority['color']); ?>"
                data-icon="<?php echo e($priority['icon'] ?? 'fa-flag'); ?>"
                data-default="<?php echo ($priority['is_default'] ?? 0) ? '1' : '0'; ?>">

                <!-- Item Header (always visible) -->
                <div class="accordion-header">
                    <!-- Drag Handle -->
                    <span class="drag-handle">
                        <?php echo get_icon('grip-vertical', 'w-3.5 h-3.5'); ?>
                    </span>

                    <!-- Icon with Color Background -->
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 text-xs"
                        style="background-color: <?php echo e($priority['color']); ?>20; color: <?php echo e($priority['color']); ?>">
                        <?php echo get_icon($priority['icon'] ?? 'fa-flag', 'w-3.5 h-3.5'); ?>
                    </div>

                    <!-- Name and Default Badge -->
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium" style="color: var(--text-primary);">
                            <?php echo e($priority['name']); ?>
                        </div>
                    </div>

                    <!-- Default Badge -->
                    <?php if ($priority['is_default'] ?? 0): ?>
                        <span class="text-xs px-1.5 py-0.5 rounded flex-shrink-0" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <?php echo e(t('Default')); ?>
                        </span>
                    <?php endif; ?>

                    <!-- Edit Button -->
                    <button type="button" class="px-1.5 py-0.5 rounded transition-colors flex-shrink-0 accordion-toggle"
                        style="color: #f59e0b; background: transparent;"
                        onclick="toggleAccordion(this)"
                        title="<?php echo e(t('Edit')); ?>">
                        <?php echo get_icon('edit-2', 'w-3.5 h-3.5'); ?>
                    </button>
                </div>

                <!-- Expandable Content (initially hidden) -->
                <div class="accordion-content">
                    <form method="post" class="accordion-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo $priority['id']; ?>">

                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Name')); ?> *
                                </label>
                                <input type="text" name="name" value="<?php echo e($priority['name']); ?>" required class="form-input w-full text-xs"
                                    style="background: var(--surface-secondary);">
                            </div>

                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Color')); ?>
                                </label>
                                <input type="color" name="color" value="<?php echo e($priority['color']); ?>" class="w-full h-8 rounded cursor-pointer"
                                    style="border: 1px solid var(--border-light);">
                            </div>

                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Icon')); ?>
                                </label>
                                <div class="grid grid-cols-4 gap-1">
                                    <?php foreach ($priority_icons as $icon => $label): ?>
                                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                                        <input type="radio" name="icon" value="<?php echo $icon; ?>" class="sr-only peer" <?php echo ($priority['icon'] ?? 'fa-flag') === $icon ? 'checked' : ''; ?>>
                                        <div class="w-7 h-7 flex items-center justify-center rounded border text-xs peer-checked:border-amber-500 peer-checked:bg-amber-50"
                                            style="border-color: var(--border-light); color: var(--text-secondary);">
                                            <?php echo get_icon($icon, 'w-3.5 h-3.5'); ?>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="pt-2 border-t" style="border-color: var(--border-light);">
                                <div class="flex gap-2 mb-2">
                                    <button type="submit" name="update_priority" class="flex-1 btn btn-primary btn-sm text-xs">
                                        <?php echo e(t('Save')); ?>
                                    </button>
                                    <button type="submit" name="set_default" class="flex-1 btn btn-sm text-xs" style="background: var(--border-light); color: var(--text-secondary);">
                                        <?php echo e(t('Set Default')); ?>
                                    </button>
                                </div>
                                <button type="submit" name="delete_priority" class="w-full btn btn-sm text-xs" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;"
                                    onclick="return confirm('<?php echo e(t('Are you sure you want to delete this priority?')); ?>')">
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
    const list = document.getElementById('priorities-list');
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
                const items = list.querySelectorAll('.priority-item');
                const order = Array.from(items).map(item => item.dataset.id);

                fetch('index.php?page=api&action=reorder-priorities', {
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
