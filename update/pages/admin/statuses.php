<?php
/**
 * Admin - Statuses Management
 */

$page_title = t('Statuses');
$page = 'admin';
$statuses = get_statuses();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Add new status
    if (isset($_POST['add_status'])) {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82f6';
        $position = $_POST['position'] ?? 'end';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));

        if (!empty($name)) {
            // Determine sort order based on position
            if ($position === 'start') {
                // Move all statuses down
                db_query("UPDATE statuses SET sort_order = sort_order + 1");
                $new_order = 1;
            } elseif (is_numeric($position)) {
                // Insert at specific position
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
                // Add at end
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
            redirect('admin', ['section' => 'statuses']);
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
            redirect('admin', ['section' => 'statuses']);
        }
    }

    // Delete status
    if (isset($_POST['delete_status'])) {
        $id = (int) $_POST['id'];

        // Check if status is used
        $count = db_fetch_one("SELECT COUNT(*) as count FROM tickets WHERE status_id = ?", [$id])['count'];

        if ($count > 0) {
            flash(t('Cannot delete a status that is used by tickets.'), 'error');
        } else {
            db_delete('statuses', 'id = ?', [$id]);
            flash(t('Status deleted.'), 'success');
        }
        redirect('admin', ['section' => 'statuses']);
    }

    // Set default
    if (isset($_POST['set_default'])) {
        $id = (int) $_POST['id'];

        db_query("UPDATE statuses SET is_default = 0");
        db_update('statuses', ['is_default' => 1], 'id = ?', [$id]);

        flash(t('Default status set.'), 'success');
        redirect('admin', ['section' => 'statuses']);
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage ticket statuses and ordering.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
    <!-- Statuses List -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="px-6 py-3 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800"><?php echo e(t('Ticket statuses')); ?></h3>
                <span class="text-sm text-gray-500 flex items-center">
                    <?php echo get_icon('grip-vertical', 'mr-1 w-4 h-4'); ?><?php echo e(t('Drag to reorder')); ?>
                </span>
            </div>

            <div id="statuses-list" class="divide-y">
                <?php foreach ($statuses as $index => $status):
                    $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE status_id = ?", [$status['id']]);
                ?>
                    <div class="px-6 py-3 hover:bg-gray-50 status-item flex items-center justify-between" data-id="<?php echo $status['id']; ?>">
                        <div class="flex items-center space-x-4">
                            <!-- Drag handle -->
                            <div class="drag-handle cursor-move text-gray-400 hover:text-gray-600 flex-shrink-0">
                                <?php echo get_icon('grip-vertical'); ?>
                            </div>

                            <!-- Color indicator -->
                            <div class="w-8 h-8 rounded-lg flex-shrink-0" style="background-color: <?php echo e($status['color']); ?>"></div>

                            <!-- Status info -->
                            <div>
                                <span class="font-medium text-gray-800"><?php echo e($status['name']); ?></span>
                                <?php if ($status['is_default']): ?>
                                    <span class="ml-2 text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-md"><?php echo e(t('Default')); ?></span>
                                <?php endif; ?>
                                <?php if ($status['is_closed']): ?>
                                    <span class="ml-2 text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-md"><?php echo e(t('Closed')); ?></span>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500"><?php echo e(t('{count} tickets', ['count' => $tickets_count['c']])); ?></div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center space-x-2">
                            <?php if (!$status['is_default']): ?>
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $status['id']; ?>">
                                    <button type="submit" name="set_default" class="text-xs text-gray-500 hover:text-blue-500 px-2 py-1">
                                        <?php echo e(t('Set default')); ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <button type="button" onclick="openEditStatusModal(<?php echo htmlspecialchars(json_encode([
                                'id' => $status['id'],
                                'name' => $status['name'],
                                'color' => $status['color'],
                                'is_closed' => (bool)$status['is_closed']
                            ]), ENT_QUOTES, 'UTF-8'); ?>)"
                                class="text-blue-500 hover:text-blue-700 p-2" title="<?php echo e(t('Edit')); ?>">
                                <?php echo get_icon('edit'); ?>
                            </button>

                            <?php if (!$status['is_default'] && $tickets_count['c'] == 0): ?>
                                <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this status?')); ?>')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $status['id']; ?>">
                                    <button type="submit" name="delete_status" class="text-red-500 hover:text-red-700 p-2"
                                        title="<?php echo e(t('Delete')); ?>">
                                        <?php echo get_icon('trash'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add New Status -->
    <div>
        <div class="card card-body">
            <h3 class="font-semibold text-gray-800 text-sm mb-4 uppercase tracking-wide">
                <?php echo e(t('Add new status')); ?>
            </h3>

            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Name')); ?></label>
                    <input type="text" name="name" required class="form-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Color')); ?></label>
                    <input type="color" name="color" value="#3b82f6"
                        class="w-full h-10 rounded-lg cursor-pointer border border-gray-200">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Position')); ?></label>
                    <select name="position" class="form-select">
                        <option value="end"><?php echo e(t('At end')); ?></option>
                        <option value="start"><?php echo e(t('At start')); ?></option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo e(t('After {name}', ['name' => $s['name']])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="flex items-center text-sm text-gray-600">
                        <input type="checkbox" name="is_closed" class="mr-2 rounded">
                        <?php echo e(t('Mark as closed status')); ?>
                    </label>
                </div>

                <button type="submit" name="add_status" class="btn btn-primary w-full">
                    <?php echo e(t('Add status')); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Status Modal -->
<div id="editStatusModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-4">
        <h3 class="font-semibold text-gray-800 mb-4"><?php echo e(t('Edit status')); ?></h3>

        <form method="post" id="editStatusForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_status_id">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Name')); ?> *</label>
                <input type="text" name="name" id="edit_status_name" required class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Color')); ?></label>
                <input type="color" name="color" id="edit_status_color"
                       class="w-full h-10 rounded-lg cursor-pointer border border-gray-200">
            </div>

            <div>
                <label class="flex items-center text-sm text-gray-600">
                    <input type="checkbox" name="is_closed" id="edit_status_is_closed" class="mr-2 rounded">
                    <?php echo e(t('Mark as closed status')); ?>
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeEditStatusModal()" class="btn btn-secondary">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" name="update_status" class="btn btn-primary">
                    <?php echo e(t('Save changes')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- SortableJS for drag & drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
    // Edit Status Modal Functions
    function openEditStatusModal(status) {
        document.getElementById('edit_status_id').value = status.id;
        document.getElementById('edit_status_name').value = status.name;
        document.getElementById('edit_status_color').value = status.color;
        document.getElementById('edit_status_is_closed').checked = status.is_closed;

        const modal = document.getElementById('editStatusModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeEditStatusModal() {
        const modal = document.getElementById('editStatusModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Close modal on backdrop click
    document.getElementById('editStatusModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditStatusModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditStatusModal();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const list = document.getElementById('statuses-list');
        const csrfToken = window.csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).content;

        if (list) {
            new Sortable(list, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                handle: '.drag-handle',
                draggable: '.status-item',
                forceFallback: true,
                fallbackClass: 'sortable-fallback',
                fallbackOnBody: true,
                swapThreshold: 0.65,
                onEnd: function (evt) {
                    const items = list.querySelectorAll('.status-item');
                    const order = Array.from(items).map(item => item.dataset.id);

                    fetch('index.php?page=api&action=reorder-statuses', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken || ''
                        },
                        body: JSON.stringify({ order: order })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.error || '<?php echo e(t('Failed to save order')); ?>');
                        }
                    })
                    .catch(error => {
                        alert('<?php echo e(t('Failed to save order')); ?>');
                    });
                }
            });
        }
    });
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
