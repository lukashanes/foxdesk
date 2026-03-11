<?php
/**
 * User Profile - Ticket History
 */

// Check if user is admin or agent
if (!is_agent() && !is_admin()) {
    flash(t('You do not have permission to view this profile.'), 'error');
    redirect('dashboard');
}

$user_id = (int) ($_GET['id'] ?? 0);

if (!$user_id) {
    flash(t('Invalid user.'), 'error');
    redirect('admin', ['section' => 'users']);
}

// Get user details
$user = get_user($user_id);

if (!$user) {
    flash(t('User not found.'), 'error');
    redirect('admin', ['section' => 'users']);
}

$page_title = t('User profile: {name}', ['name' => $user['first_name'] . ' ' . $user['last_name']]);

// Get current user for permission checking
$current_user = current_user();

// Get user's tickets - filter based on current user's permissions
$all_user_tickets = db_fetch_all("
    SELECT t.*,
           s.name as status_name, s.color as status_color, s.is_closed as status_is_closed,
           p.name as priority_name, p.color as priority_color,
           tt.name as type_name, tt.icon as type_icon,
           o.name as organization_name
    FROM tickets t
    LEFT JOIN statuses s ON t.status_id = s.id
    LEFT JOIN priorities p ON t.priority_id = p.id
    LEFT JOIN ticket_types tt ON t.type = tt.slug
    LEFT JOIN organizations o ON t.organization_id = o.id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
", [$user_id]);

// Filter tickets based on current user's permissions (agents see only tickets they have access to)
$tickets = [];
foreach ($all_user_tickets as $ticket) {
    if (can_see_ticket($ticket, $current_user)) {
        $tickets[] = $ticket;
    }
}

// Calculate statistics
$total_tickets = count($tickets);
$open_tickets = 0;
$closed_tickets = 0;
$ticket_stats = [];

foreach ($tickets as $ticket) {
    if (!isset($ticket_stats[$ticket['status_id']])) {
        $ticket_stats[$ticket['status_id']] = [
            'name' => $ticket['status_name'],
            'color' => $ticket['status_color'],
            'count' => 0
        ];
    }
    $ticket_stats[$ticket['status_id']]['count']++;

    // Use status flag instead of name to determine closed state
    if (!empty($ticket['status_is_closed'])) {
        $closed_tickets++;
    } else {
        $open_tickets++;
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('User activity and ticket history.');
$page_header_actions = '<a href="' . url('admin', ['section' => 'users']) . '" class="btn btn-secondary btn-sm">' . get_icon('arrow-left', 'mr-1 inline') . e(t('Back to users')) . '</a>';
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="space-y-3">
    <!-- User Info Card -->
    <div class="card card-body">
        <div class="flex items-start space-x-6">
            <div class="flex-shrink-0">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?php echo e(upload_url($user['avatar'])); ?>" alt="Avatar" class="w-20 h-20 rounded-full object-cover">
                <?php else: ?>
                    <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center">
                        <span
                            class="text-blue-600 text-2xl font-medium"><?php echo strtoupper(substr($user['first_name'], 0, 1)); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex-1">
                <h2 class="text-2xl font-bold text-gray-800">
                    <?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                <p class="text-gray-600 mt-1"><?php echo e($user['email']); ?></p>

                <div class="mt-4 flex flex-wrap gap-4">
                    <div>
                        <span class="text-xs text-gray-500"><?php echo e(t('Role')); ?>:</span>
                        <?php
                        $role_labels = ['user' => t('User'), 'agent' => t('Agent'), 'admin' => t('Admin')];
                        $role_colors = ['user' => 'gray', 'agent' => 'blue', 'admin' => 'purple'];
                        ?>
                        <span
                            class="badge bg-<?php echo $role_colors[$user['role']]; ?>-100 text-<?php echo $role_colors[$user['role']]; ?>-600 ml-2">
                            <?php echo $role_labels[$user['role']]; ?>
                        </span>
                    </div>

                    <?php if (!empty($user['organization_id'])): ?>
                        <div>
                            <span class="text-xs text-gray-500"><?php echo e(t('Company')); ?>:</span>
                            <?php
                            $org = get_organization($user['organization_id']);
                            if ($org):
                                ?>
                                <span class="text-sm text-gray-700 ml-2"><?php echo e($org['name']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <span class="text-xs text-gray-500"><?php echo e(t('Registered')); ?>:</span>
                        <span
                            class="text-sm text-gray-700 ml-2"><?php echo format_date($user['created_at'], 'd.m.Y'); ?></span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="card card-body">
            <div class="text-sm text-gray-500"><?php echo e(t('Total tickets')); ?></div>
            <div class="text-3xl font-bold text-gray-800 mt-1"><?php echo $total_tickets; ?></div>
        </div>
        <div class="card card-body">
            <div class="text-sm text-gray-500"><?php echo e(t('Open')); ?></div>
            <div class="text-3xl font-bold text-orange-600 mt-1"><?php echo $open_tickets; ?></div>
        </div>
        <div class="card card-body">
            <div class="text-sm text-gray-500"><?php echo e(t('Closed')); ?></div>
            <div class="text-3xl font-bold text-green-600 mt-1"><?php echo $closed_tickets; ?></div>
        </div>
        <div class="card card-body">
            <div class="text-sm text-gray-500"><?php echo e(t('By status')); ?></div>
            <div class="mt-2 space-y-1">
                <?php foreach ($ticket_stats as $stat): ?>
                    <div class="flex items-center justify-between text-xs">
                        <span class="flex items-center">
                            <span class="w-2 h-2 rounded-full mr-1"
                                style="background-color: <?php echo e($stat['color']); ?>"></span>
                            <?php echo e($stat['name']); ?>
                        </span>
                        <span class="font-medium"><?php echo $stat['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tickets List -->
    <div class="card overflow-hidden">
        <div class="px-6 py-3 border-b">
            <h3 class="font-semibold text-gray-800"><?php echo e(t('Ticket history')); ?></h3>
        </div>

        <?php if (empty($tickets)): ?>
            <div class="p-8 text-center text-gray-500">
                <?php echo get_icon('inbox', 'text-4xl mb-4 text-gray-400'); ?>
                <p><?php echo e(t('This user has not created any tickets yet.')); ?></p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left th-label">ID</th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Subject')); ?></th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Type')); ?></th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Priority')); ?></th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Status')); ?></th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Created')); ?></th>
                            <th class="px-6 py-3 text-right th-label">
                                <?php echo e(t('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($tickets as $ticket): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-mono text-gray-600">
                                    <?php echo get_ticket_code($ticket['id']); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-800"><?php echo e($ticket['title']); ?></div>
                                    <?php if (!empty($ticket['organization_name'])): ?>
                                        <div class="text-xs text-gray-500"><?php echo e($ticket['organization_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($ticket['type_name'])): ?>
                                        <span class="inline-flex items-center text-xs">
                                            <?php echo get_icon($ticket['type_icon'], 'mr-1'); ?>
                                            <?php echo e($ticket['type_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="badge"
                                        style="background-color: <?php echo e($ticket['priority_color']); ?>20; color: <?php echo e($ticket['priority_color']); ?>">
                                        <?php echo e($ticket['priority_name']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="badge"
                                        style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>">
                                        <?php echo e($ticket['status_name']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?php echo format_date($ticket['created_at'], 'd.m.Y'); ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="table-actions inline-flex items-center gap-2">
                                        <a href="<?php echo ticket_url($ticket); ?>"
                                            class="text-blue-500 hover:text-blue-600"
                                            aria-label="<?php echo e(t('View ticket')); ?>">
                                            <?php echo get_icon('eye'); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; 
