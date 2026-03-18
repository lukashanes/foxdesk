<?php
/**
 * Admin - Priorities
 */

$page_title = t('Priorities');
$page = 'admin';

// Handle AJAX reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    require_csrf_token();
    $order = json_decode($_POST['order'], true);
    if (is_array($order)) {
        foreach ($order as $index => $id) {
            db_update('priorities', ['sort_order' => $index], 'id = ?', [$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Create new priority
    if (isset($_POST['create'])) {
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82f6';
        $icon = trim($_POST['icon'] ?? 'fa-flag');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($name)) {
            flash(t('Priority name is required.'), 'error');
        } else {
            // Generate slug
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
            $slug = trim($slug, '_');
            
            // Check if slug exists
            $existing = db_fetch_one("SELECT id FROM priorities WHERE slug = ?", [$slug]);
            if ($existing) {
                $slug .= '_' . time();
            }
            
            // Get max sort_order
            $max = db_fetch_one("SELECT MAX(sort_order) as max_order FROM priorities");
            $sort_order = ($max['max_order'] ?? 0) + 1;
            
            // If this is default, unset others
            if ($is_default) {
                db_query("UPDATE priorities SET is_default = 0");
            }
            
            db_insert('priorities', [
                'name' => $name,
                'slug' => $slug,
                'color' => $color,
                'icon' => $icon,
                'sort_order' => $sort_order,
                'is_default' => $is_default,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            flash(t('Priority created.'), 'success');
        }
        redirect('admin', ['section' => 'priorities']);
    }
    
    // Update priority
    if (isset($_POST['update'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82f6';
        $icon = trim($_POST['icon'] ?? 'fa-flag');
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($name)) {
            flash(t('Priority name is required.'), 'error');
        } else {
            // If this is default, unset others
            if ($is_default) {
                db_query("UPDATE priorities SET is_default = 0");
            }
            
            db_update('priorities', [
                'name' => $name,
                'color' => $color,
                'icon' => $icon,
                'is_default' => $is_default
            ], 'id = ?', [$id]);
            flash(t('Priority updated.'), 'success');
        }
        redirect('admin', ['section' => 'priorities']);
    }
    
    // Delete priority
    if (isset($_POST['delete'])) {
        $id = (int)$_POST['id'];
        
        // Check if priority is used
        $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE priority_id = ?", [$id]);
        if ($tickets_count && $tickets_count['c'] > 0) {
            flash(t('Cannot delete a priority that is used by tickets.'), 'error');
        } else {
            db_query("DELETE FROM priorities WHERE id = ?", [$id]);
            flash(t('Priority deleted.'), 'success');
        }
        redirect('admin', ['section' => 'priorities']);
    }
    
    // Move up/down
    if (isset($_POST['move'])) {
        $id = (int)$_POST['id'];
        $direction = $_POST['direction'];
        
        $priority = get_priority($id);
        if ($priority) {
            $current_order = $priority['sort_order'];
            
            if ($direction === 'up') {
                $swap = db_fetch_one("SELECT id, sort_order FROM priorities WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1", [$current_order]);
            } else {
                $swap = db_fetch_one("SELECT id, sort_order FROM priorities WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1", [$current_order]);
            }
            
            if ($swap) {
                db_update('priorities', ['sort_order' => $swap['sort_order']], 'id = ?', [$id]);
                db_update('priorities', ['sort_order' => $current_order], 'id = ?', [$swap['id']]);
            }
        }
        redirect('admin', ['section' => 'priorities']);
    }
}

// Get all priorities
$priorities = get_priorities();

// Common icons for selection
$common_icons = [
    'fa-flag' => 'Flag',
    'fa-arrow-down' => 'Arrow down',
    'fa-minus' => 'Minus',
    'fa-arrow-up' => 'Arrow up',
    'fa-exclamation' => 'Exclamation',
    'fa-exclamation-triangle' => 'Warning',
    'fa-fire' => 'Fire',
    'fa-bolt' => 'Bolt',
    'fa-star' => 'Star',
    'fa-circle' => 'Circle',
    'fa-dot-circle' => 'Dot',
    'fa-thermometer-empty' => 'Thermometer 0',
    'fa-thermometer-quarter' => 'Thermometer 25',
    'fa-thermometer-half' => 'Thermometer 50',
    'fa-thermometer-three-quarters' => 'Thermometer 75',
    'fa-thermometer-full' => 'Thermometer 100'
];

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage priority labels and ordering.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
    <!-- Priorities List -->
    <div class="lg:col-span-2">
    <div class="card overflow-hidden">
        <div class="px-6 py-3 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800"><?php echo e(t('Priority list')); ?></h3>
            <span class="text-sm text-gray-500"><?php echo e(t('Drag to reorder')); ?></span>
        </div>
        
        <?php if (empty($priorities)): ?>
        <div class="p-8 text-center text-gray-500">
            <?php echo get_icon('flag', 'text-4xl mb-4'); ?>
            <p><?php echo e(t('No priorities yet.')); ?></p>
        </div>
        <?php else: ?>
        <ul id="priorities-list" class="divide-y divide-gray-200">
            <?php foreach ($priorities as $index => $priority): 
                $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE priority_id = ?", [$priority['id']]);
            ?>
            <li data-id="<?php echo $priority['id']; ?>" 
                class="px-6 py-3 flex items-center justify-between bg-white hover:bg-gray-50 cursor-move">
                <div class="flex items-center space-x-4">
                    <span class="text-gray-400 cursor-move drag-handle">
                        <?php echo get_icon('grip-vertical'); ?>
                    </span>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background-color: <?php echo e($priority['color']); ?>20; color: <?php echo e($priority['color']); ?>">
                        <?php echo get_icon($priority['icon'] ?? 'flag'); ?>
                    </div>
                    <span class="font-medium text-gray-800"><?php echo e($priority['name']); ?></span>
                    <?php if ($priority['is_default']): ?>
                    <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-md"><?php echo e(t('Default')); ?></span>
                    <?php endif; ?>
                    <span class="text-sm text-gray-500">(<?php echo e(t('{count} tickets', ['count' => $tickets_count['c']])); ?>)</span>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="openEditPriorityModal(<?php echo htmlspecialchars(json_encode([
                        'id' => $priority['id'],
                        'name' => $priority['name'],
                        'color' => $priority['color'],
                        'icon' => $priority['icon'] ?? 'fa-flag',
                        'is_default' => (bool)$priority['is_default']
                    ]), ENT_QUOTES, 'UTF-8'); ?>)"
                       class="text-blue-500 hover:text-blue-700 p-2" title="<?php echo e(t('Edit')); ?>"
                       aria-label="<?php echo e(t('Edit')); ?>">
                        <?php echo get_icon('edit'); ?>
                    </button>
                    <?php if ($tickets_count['c'] == 0): ?>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this priority?')); ?>')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo $priority['id']; ?>">
                        <button type="submit" name="delete" class="text-red-500 hover:text-red-700 p-2"
                                title="<?php echo e(t('Delete')); ?>" aria-label="<?php echo e(t('Delete')); ?>">
                            <?php echo get_icon('trash'); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    </div>

    <!-- Add Priority Form (Sidebar) -->
    <div class="card card-body h-fit">
        <h3 class="font-semibold text-gray-800 mb-4"><?php echo e(t('Add priority')); ?></h3>

        <form method="post" class="space-y-4">
            <?php echo csrf_field(); ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Name')); ?> *</label>
                <input type="text" name="name" required class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Color')); ?></label>
                <input type="color" name="color" value="#3b82f6"
                       class="w-full h-10 border border-gray-200 rounded-lg cursor-pointer">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Icon')); ?></label>
                <div class="grid grid-cols-4 gap-2 p-3 bg-gray-50 rounded-lg">
                    <?php foreach ($common_icons as $icon => $label): ?>
                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                        <input type="radio" name="icon" value="<?php echo $icon; ?>"
                               <?php echo $icon === 'fa-flag' ? 'checked' : ''; ?>
                               class="sr-only peer">
                        <div class="w-10 h-10 flex items-center justify-center rounded-lg border-2 border-gray-200
                                    peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:bg-blue-900/20 hover:border-gray-300 transition text-gray-600">
                            <?php echo get_icon($icon); ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="is_default" class="rounded text-blue-500 focus:ring-blue-500">
                    <span class="text-sm text-gray-700"><?php echo e(t('Set as default')); ?></span>
                </label>
            </div>

            <button type="submit" name="create" class="btn btn-primary w-full">
                <?php echo e(t('Add priority')); ?>
            </button>
        </form>
    </div>
</div>

<!-- Edit Priority Modal -->
<div id="editPriorityModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 p-4 max-h-[90vh] overflow-y-auto">
        <h3 class="font-semibold text-gray-800 mb-4"><?php echo e(t('Edit priority')); ?></h3>

        <form method="post" id="editPriorityForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_priority_id">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Name')); ?> *</label>
                    <input type="text" name="name" id="edit_priority_name" required class="form-input">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Color')); ?></label>
                    <input type="color" name="color" id="edit_priority_color"
                           class="w-full h-10 border border-gray-200 rounded-lg cursor-pointer">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Icon')); ?></label>
                <div class="grid grid-cols-8 gap-2 p-3 bg-gray-50 rounded-lg" id="edit_icon_grid">
                    <?php foreach ($common_icons as $icon => $label): ?>
                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                        <input type="radio" name="icon" value="<?php echo $icon; ?>" class="sr-only peer edit-icon-radio">
                        <div class="w-10 h-10 flex items-center justify-center rounded-lg border-2 border-gray-200
                                    peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:bg-blue-900/20 hover:border-gray-300 transition text-gray-600">
                            <?php echo get_icon($icon); ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="is_default" id="edit_priority_is_default" class="rounded text-blue-500 focus:ring-blue-500">
                    <span class="text-sm text-gray-700"><?php echo e(t('Set as default priority')); ?></span>
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeEditPriorityModal()" class="btn btn-secondary">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" name="update" class="btn btn-primary">
                    <?php echo e(t('Save changes')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Edit Priority Modal Functions
function openEditPriorityModal(priority) {
    document.getElementById('edit_priority_id').value = priority.id;
    document.getElementById('edit_priority_name').value = priority.name;
    document.getElementById('edit_priority_color').value = priority.color;
    document.getElementById('edit_priority_is_default').checked = priority.is_default;

    // Set icon radio
    const iconRadios = document.querySelectorAll('.edit-icon-radio');
    iconRadios.forEach(radio => {
        radio.checked = radio.value === priority.icon;
    });

    const modal = document.getElementById('editPriorityModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditPriorityModal() {
    const modal = document.getElementById('editPriorityModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Close modal on backdrop click
document.getElementById('editPriorityModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditPriorityModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditPriorityModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('priorities-list');
    var csrfToken = window.csrfToken || '';
    if (el) {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            handle: '.drag-handle',
            filter: 'a, button, input, form',
            preventOnFilter: false,
            forceFallback: true,
            onEnd: function() {
                var order = [];
                el.querySelectorAll('li').forEach(function(item) {
                    order.push(item.dataset.id);
                });

                fetch('index.php?page=api&action=reorder-priorities', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ order: order })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (!data.success) {
                        alert(data.error || '<?php echo e(t('Failed to save order')); ?>');
                    }
                }).catch(function(err) {
                    alert('<?php echo e(t('Failed to save order')); ?>');
                });
            }
        });
    }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
