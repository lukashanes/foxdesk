<?php
/**
 * Admin - Ticket Types
 */

$page_title = t('Ticket types');
$page = 'admin';

// Handle AJAX reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    require_csrf_token();
    $order = json_decode($_POST['order'], true);
    if (is_array($order)) {
        foreach ($order as $index => $id) {
            db_update('ticket_types', ['sort_order' => $index], 'id = ?', [$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Create new type
    if (isset($_POST['create'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-file-alt');
        $color = $_POST['color'] ?? '#3b82f6';
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($name)) {
            flash(t('Type name is required.'), 'error');
        } else {
            // Generate slug
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
            $slug = trim($slug, '_');
            
            // Check if slug exists
            $existing = db_fetch_one("SELECT id FROM ticket_types WHERE slug = ?", [$slug]);
            if ($existing) {
                $slug .= '_' . time();
            }
            
            // Get max sort_order
            $max = db_fetch_one("SELECT MAX(sort_order) as max_order FROM ticket_types");
            $sort_order = ($max['max_order'] ?? 0) + 1;
            
            // If this is default, unset others
            if ($is_default) {
                db_query("UPDATE ticket_types SET is_default = 0");
            }
            
            db_insert('ticket_types', [
                'name' => $name,
                'slug' => $slug,
                'icon' => $icon,
                'color' => $color,
                'sort_order' => $sort_order,
                'is_default' => $is_default,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            flash(t('Ticket type created.'), 'success');
        }
        redirect('admin', ['section' => 'ticket-types']);
    }
    
    // Update type
    if (isset($_POST['update'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-file-alt');
        $color = $_POST['color'] ?? '#3b82f6';
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        
        if (empty($name)) {
            flash(t('Type name is required.'), 'error');
        } else {
            if ($is_default) {
                db_query("UPDATE ticket_types SET is_default = 0");
            }
            
            db_update('ticket_types', [
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'is_default' => $is_default
            ], 'id = ?', [$id]);
            flash(t('Ticket type updated.'), 'success');
        }
        redirect('admin', ['section' => 'ticket-types']);
    }
    
    // Toggle active
    if (isset($_POST['toggle'])) {
        $id = (int)$_POST['id'];
        $type = db_fetch_one("SELECT * FROM ticket_types WHERE id = ?", [$id]);
        if ($type) {
            $new_status = $type['is_active'] ? 0 : 1;
            db_update('ticket_types', ['is_active' => $new_status], 'id = ?', [$id]);
            flash($new_status ? t('Type activated.') : t('Type deactivated.'), 'success');
        }
        redirect('admin', ['section' => 'ticket-types']);
    }
    
    // Delete type
    if (isset($_POST['delete'])) {
        $id = (int)$_POST['id'];
        
        // Check if type is used
        $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE type = (SELECT slug FROM ticket_types WHERE id = ?)", [$id]);
        if ($tickets_count && $tickets_count['c'] > 0) {
            flash(t('Cannot delete a type that is used by tickets.'), 'error');
        } else {
            db_query("DELETE FROM ticket_types WHERE id = ?", [$id]);
            flash(t('Ticket type deleted.'), 'success');
        }
        redirect('admin', ['section' => 'ticket-types']);
    }
    
    // Move up/down
    if (isset($_POST['move'])) {
        $id = (int)$_POST['id'];
        $direction = $_POST['direction'];
        
        $type = db_fetch_one("SELECT * FROM ticket_types WHERE id = ?", [$id]);
        if ($type) {
            $current_order = $type['sort_order'];
            
            if ($direction === 'up') {
                $swap = db_fetch_one("SELECT id, sort_order FROM ticket_types WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1", [$current_order]);
            } else {
                $swap = db_fetch_one("SELECT id, sort_order FROM ticket_types WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1", [$current_order]);
            }
            
            if ($swap) {
                db_update('ticket_types', ['sort_order' => $swap['sort_order']], 'id = ?', [$id]);
                db_update('ticket_types', ['sort_order' => $current_order], 'id = ?', [$swap['id']]);
            }
        }
        redirect('admin', ['section' => 'ticket-types']);
    }
}

// Get all types
$types = db_fetch_all("SELECT * FROM ticket_types ORDER BY sort_order");

// Common icons for selection
$common_icons = [
    'fa-file-alt' => 'Document',
    'fa-coins' => 'Coins',
    'fa-question-circle' => 'Question',
    'fa-bug' => 'Bug',
    'fa-exclamation-triangle' => 'Warning',
    'fa-cog' => 'Settings',
    'fa-wrench' => 'Tool',
    'fa-lightbulb' => 'Idea',
    'fa-comment' => 'Comment',
    'fa-envelope' => 'Email',
    'fa-phone' => 'Phone',
    'fa-user' => 'User',
    'fa-lock' => 'Lock',
    'fa-shield-alt' => 'Shield',
    'fa-star' => 'Star',
    'fa-heart' => 'Heart',
    'fa-bolt' => 'Bolt',
    'fa-fire' => 'Fire',
    'fa-clock' => 'Clock',
    'fa-calendar' => 'Calendar'
];

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage ticket types and ordering.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
    <!-- Types List -->
    <div class="lg:col-span-2">
    <div class="card overflow-hidden">
        <div class="px-6 py-3 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800"><?php echo e(t('Ticket type list')); ?></h3>
            <span class="text-sm text-gray-500"><?php echo e(t('Drag to reorder')); ?></span>
        </div>
        
        <?php if (empty($types)): ?>
        <div class="p-8 text-center text-gray-500">
            <?php echo get_icon('file-alt', 'text-4xl mb-4 text-gray-300 inline-block'); ?>
            <p><?php echo e(t('No ticket types yet.')); ?></p>
        </div>
        <?php else: ?>
        <ul id="types-list" class="divide-y divide-gray-200">
            <?php foreach ($types as $index => $type): 
                $tickets_count = db_fetch_one("SELECT COUNT(*) as c FROM tickets WHERE type = ?", [$type['slug']]);
            ?>
            <li data-id="<?php echo $type['id']; ?>" 
                class="px-6 py-3 flex items-center justify-between bg-white hover:bg-gray-50 cursor-move <?php echo !$type['is_active'] ? 'opacity-50' : ''; ?>">
                <div class="flex items-center space-x-4">
                    <span class="text-gray-400 cursor-move drag-handle">
                        <?php echo get_icon('grip-vertical'); ?>
                    </span>
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?php echo e($type['color']); ?>20; color: <?php echo e($type['color']); ?>">
                        <?php echo get_icon($type['icon'], 'inline-block'); ?>
                    </div>
                    <div>
                        <span class="font-medium text-gray-800"><?php echo e($type['name']); ?></span>
                        <?php if ($type['is_default']): ?>
                        <span class="ml-2 text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-md"><?php echo e(t('Default')); ?></span>
                        <?php endif; ?>
                        <div class="text-xs text-gray-500"><?php echo e(t('{count} tickets', ['count' => $tickets_count['c']])); ?></div>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="openEditTypeModal(<?php echo htmlspecialchars(json_encode([
                        'id' => $type['id'],
                        'name' => $type['name'],
                        'icon' => $type['icon'] ?? 'fa-file-alt',
                        'color' => $type['color'] ?? '#3b82f6',
                        'is_default' => (bool)$type['is_default']
                    ]), ENT_QUOTES, 'UTF-8'); ?>)"
                       class="text-blue-500 hover:text-blue-700" title="<?php echo e(t('Edit')); ?>"
                       aria-label="<?php echo e(t('Edit')); ?>">
                        <?php echo get_icon('edit'); ?>
                    </button>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Are you sure you want to change the status?')); ?>')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                        <button type="submit" name="toggle"
                                class="<?php echo $type['is_active'] ? 'text-yellow-500 hover:text-yellow-700' : 'text-green-500 hover:text-green-700'; ?>"
                                title="<?php echo e($type['is_active'] ? t('Deactivate') : t('Activate')); ?>"
                                aria-label="<?php echo e($type['is_active'] ? t('Deactivate') : t('Activate')); ?>">
                            <?php echo get_icon($type['is_active'] ? 'pause' : 'play'); ?>
                        </button>
                    </form>
                    <?php if ($tickets_count['c'] == 0): ?>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this type?')); ?>')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                        <button type="submit" name="delete" class="text-red-500 hover:text-red-700"
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

    <!-- Add Type Form (Sidebar) -->
    <div class="card card-body h-fit">
        <h3 class="font-semibold text-gray-800 mb-4"><?php echo e(t('Add ticket type')); ?></h3>

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
                <div class="grid grid-cols-4 gap-2 p-3 bg-gray-50 rounded-lg max-h-48 overflow-y-auto">
                    <?php foreach ($common_icons as $icon => $label): ?>
                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                        <input type="radio" name="icon" value="<?php echo $icon; ?>"
                               <?php echo $icon === 'fa-file-alt' ? 'checked' : ''; ?>
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
                <?php echo e(t('Add type')); ?>
            </button>
        </form>
    </div>
</div>

<!-- Edit Type Modal -->
<div id="editTypeModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 p-4 max-h-[90vh] overflow-y-auto">
        <h3 class="font-semibold text-gray-800 mb-4"><?php echo e(t('Edit ticket type')); ?></h3>

        <form method="post" id="editTypeForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_type_id">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Name')); ?> *</label>
                    <input type="text" name="name" id="edit_type_name" required class="form-input">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Color')); ?></label>
                    <input type="color" name="color" id="edit_type_color"
                           class="w-full h-10 border border-gray-200 rounded-lg cursor-pointer">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(t('Icon')); ?></label>
                <div class="grid grid-cols-8 gap-2 p-3 bg-gray-50 rounded-lg" id="edit_type_icon_grid">
                    <?php foreach ($common_icons as $icon => $label): ?>
                    <label class="relative cursor-pointer" title="<?php echo e($label); ?>">
                        <input type="radio" name="icon" value="<?php echo $icon; ?>" class="sr-only peer edit-type-icon-radio">
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
                    <input type="checkbox" name="is_default" id="edit_type_is_default" class="rounded text-blue-500 focus:ring-blue-500">
                    <span class="text-sm text-gray-700"><?php echo e(t('Set as default type')); ?></span>
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeEditTypeModal()" class="btn btn-secondary">
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
// Edit Type Modal Functions
function openEditTypeModal(type) {
    document.getElementById('edit_type_id').value = type.id;
    document.getElementById('edit_type_name').value = type.name;
    document.getElementById('edit_type_color').value = type.color;
    document.getElementById('edit_type_is_default').checked = type.is_default;

    // Set icon radio
    const iconRadios = document.querySelectorAll('.edit-type-icon-radio');
    iconRadios.forEach(radio => {
        radio.checked = radio.value === type.icon;
    });

    const modal = document.getElementById('editTypeModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditTypeModal() {
    const modal = document.getElementById('editTypeModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Close modal on backdrop click
document.getElementById('editTypeModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditTypeModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditTypeModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('types-list');
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

                fetch('index.php?page=api&action=reorder-ticket-types', {
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
