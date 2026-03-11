<?php
/**
 * Admin - Organizations (Firms)
 */

$page_title = t('Organizations');
$page = 'admin';

// Auto-migrate: add logo column if missing
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM organizations LIKE 'logo'");
    if (empty($cols)) {
        db_query("ALTER TABLE organizations ADD COLUMN logo TEXT DEFAULT NULL AFTER notes");
    }
} catch (Exception $e) {}

$time_range = $_GET['time_range'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$range_data = get_time_range_bounds($time_range, $from_date, $to_date);
$time_range = $range_data['range'];
$range_start = $range_data['start'];
$range_end = $range_data['end'];
$time_tracking_available = ticket_time_table_exists();
$org_time_totals = [];
$org_user_counts = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    // Create new organization
    if (isset($_POST['create'])) {
        $name = trim($_POST['name'] ?? '');
        $ico = trim($_POST['ico'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $billable_rate_input = trim($_POST['billable_rate'] ?? '');
        $billable_rate = $billable_rate_input !== '' ? (float) str_replace(',', '.', $billable_rate_input) : 0;

        if (empty($name)) {
            flash(t('Organization name is required.'), 'error');
        } else {
            $org_data = [
                'name' => $name,
                'ico' => $ico ?: null,
                'address' => $address ?: null,
                'contact_email' => $contact_email ?: null,
                'contact_phone' => $contact_phone ?: null,
                'notes' => $notes ?: null,
                'billable_rate' => $billable_rate,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Handle logo upload on create
            if (!empty($_FILES['org_logo_create']['name']) && $_FILES['org_logo_create']['error'] === UPLOAD_ERR_OK) {
                try {
                    $allowed_logo = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $logo_result = upload_file($_FILES['org_logo_create'], $allowed_logo, 2 * 1024 * 1024);
                    $org_data['logo'] = UPLOAD_DIR . $logo_result['filename'];
                } catch (Exception $e) {
                    // Logo upload failed, continue without logo
                }
            }

            db_insert('organizations', $org_data);
            flash(t('Organization created.'), 'success');
        }
        redirect('admin', ['section' => 'organizations']);
    }

    // Update organization
    if (isset($_POST['update'])) {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $ico = trim($_POST['ico'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $billable_rate_input = trim($_POST['billable_rate'] ?? '');
        $billable_rate = $billable_rate_input !== '' ? (float) str_replace(',', '.', $billable_rate_input) : 0;

        if (empty($name)) {
            flash(t('Organization name is required.'), 'error');
        } else {
            db_update('organizations', [
                'name' => $name,
                'ico' => $ico ?: null,
                'address' => $address ?: null,
                'contact_email' => $contact_email ?: null,
                'contact_phone' => $contact_phone ?: null,
                'notes' => $notes ?: null,
                'billable_rate' => $billable_rate
            ], 'id = ?', [$id]);
            flash(t('Organization updated.'), 'success');
        }
        redirect('admin', ['section' => 'organizations']);
    }

    // Toggle active status
    if (isset($_POST['toggle'])) {
        $id = (int) $_POST['id'];
        $org = get_organization($id);
        if ($org) {
            $new_status = $org['is_active'] ? 0 : 1;
            db_update('organizations', ['is_active' => $new_status], 'id = ?', [$id]);
            flash($new_status ? t('Organization activated.') : t('Organization deactivated.'), 'success');
        }
        redirect('admin', ['section' => 'organizations']);
    }

    // Delete organization
    if (isset($_POST['delete'])) {
        $id = (int) $_POST['id'];
        $org_to_delete = get_organization($id);
        if (!$org_to_delete) {
            flash(t('Organization not found.'), 'error');
            redirect('admin', ['section' => 'organizations']);
        }
        $users = db_fetch_all("SELECT id FROM users");
        $removed_memberships = 0;
        foreach ($users as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $org_ids = get_user_organization_ids($uid);
            if (in_array($id, $org_ids, true)) {
                if (remove_user_organization_membership($uid, $id)) {
                    $removed_memberships++;
                }
            }
        }

        db_query("DELETE FROM organizations WHERE id = ?", [$id]);
        if ($removed_memberships > 0) {
            flash(t('Organization deleted. Users were unassigned.'), 'success');
        } else {
            flash(t('Organization deleted.'), 'success');
        }
        redirect('admin', ['section' => 'organizations']);
    }

    // Upload organization logo
    if (isset($_POST['upload_org_logo']) && isset($_FILES['org_logo'])) {
        $org_id = (int) $_POST['org_id'];
        $org = get_organization($org_id);
        if ($org && $_FILES['org_logo']['error'] === UPLOAD_ERR_OK) {
            try {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $result = upload_file($_FILES['org_logo'], $allowed, 2 * 1024 * 1024);
                // Delete old logo file if exists
                if (!empty($org['logo']) && file_exists(BASE_PATH . '/' . $org['logo'])) {
                    @unlink(BASE_PATH . '/' . $org['logo']);
                }
                db_update('organizations', ['logo' => UPLOAD_DIR . $result['filename']], 'id = ?', [$org_id]);
                flash(t('Logo uploaded.'), 'success');
            } catch (Exception $e) {
                flash($e->getMessage(), 'error');
            }
        }
        redirect('admin', ['section' => 'organizations']);
    }

    // Remove organization logo
    if (isset($_POST['remove_org_logo'])) {
        $org_id = (int) $_POST['org_id'];
        $org = get_organization($org_id);
        if ($org && !empty($org['logo']) && file_exists(BASE_PATH . '/' . $org['logo'])) {
            @unlink(BASE_PATH . '/' . $org['logo']);
        }
        if ($org) {
            db_update('organizations', ['logo' => null], 'id = ?', [$org_id]);
        }
        flash(t('Logo removed.'), 'success');
        redirect('admin', ['section' => 'organizations']);
    }

    // Remove user from organization
    if (isset($_POST['remove_user'])) {
        $user_id = (int) $_POST['user_id'];
        $org_id = (int) ($_POST['org_id'] ?? 0);
        if ($user_id > 0 && $org_id > 0) {
            remove_user_organization_membership($user_id, $org_id);
        }
        flash(t('User removed from organization.'), 'success');
        redirect('admin', ['section' => 'organizations']);
    }

    // Add user to organization
    if (isset($_POST['add_user'])) {
        $org_id = (int) $_POST['org_id'];
        $user_id = (int) $_POST['user_id'];
        if ($user_id > 0 && $org_id > 0) {
            add_user_organization_membership($user_id, $org_id);
            flash(t('User added to organization.'), 'success');
        }
        redirect('admin', ['section' => 'organizations']);
    }
}

// Get all organizations
$organizations = get_organizations(true);

// Organization user counts and users list (supports multi-organization memberships)
$org_users = [];
$available_users_by_org = [];
foreach ($organizations as $org) {
    $org_id = (int) ($org['id'] ?? 0);
    $org_users[$org_id] = [];
    $available_users_by_org[$org_id] = [];
}

$users_sql = "SELECT id, first_name, last_name, email, role, is_active, organization_id, permissions, avatar
              FROM users
              WHERE email NOT LIKE 'deleted-user-%@invalid.local'";
if (function_exists('users_deleted_at_column_exists') && users_deleted_at_column_exists()) {
    $users_sql .= " AND deleted_at IS NULL";
}
$users_sql .= " ORDER BY last_name, first_name";
$all_users = db_fetch_all($users_sql);

foreach ($all_users as $u) {
    $uid = (int) ($u['id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }

    $permissions = [];
    if (!empty($u['permissions'])) {
        $decoded = json_decode((string) $u['permissions'], true);
        if (is_array($decoded)) {
            $permissions = $decoded;
        }
    }

    $org_ids = get_permissions_organization_ids($permissions);
    if (!empty($u['organization_id'])) {
        $org_ids[] = (int) $u['organization_id'];
    }
    $org_ids = normalize_organization_ids($org_ids);

    foreach ($org_ids as $org_id) {
        if (isset($org_users[$org_id])) {
            $org_users[$org_id][$uid] = $u;
        }
    }
}

foreach ($organizations as $org) {
    $org_id = (int) ($org['id'] ?? 0);
    $members = array_values($org_users[$org_id] ?? []);
    usort($members, function ($a, $b) {
        $a_name = strtolower(trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')));
        $b_name = strtolower(trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? '')));
        return strcmp($a_name, $b_name);
    });
    $org_users[$org_id] = $members;
    $org_user_counts[$org_id] = count($members);

    $member_lookup = [];
    foreach ($members as $member) {
        $member_lookup[(int) $member['id']] = true;
    }
    foreach ($all_users as $u) {
        if ((int) ($u['is_active'] ?? 0) !== 1) {
            continue;
        }
        $uid = (int) ($u['id'] ?? 0);
        if ($uid <= 0 || isset($member_lookup[$uid])) {
            continue;
        }
        $available_users_by_org[$org_id][] = [
            'id' => $uid,
            'first_name' => $u['first_name'] ?? '',
            'last_name' => $u['last_name'] ?? '',
            'email' => $u['email'] ?? ''
        ];
    }
}

// Organization time totals
if ($time_tracking_available) {
    $sql = "SELECT t.organization_id,
                   SUM(CASE WHEN tte.ended_at IS NULL THEN TIMESTAMPDIFF(MINUTE, tte.started_at, NOW()) ELSE tte.duration_minutes END) as total_minutes
            FROM ticket_time_entries tte
            JOIN tickets t ON tte.ticket_id = t.id
            WHERE t.organization_id IS NOT NULL";
    $params = [];
    if ($range_start && $range_end) {
        $sql .= " AND tte.started_at >= ? AND tte.started_at <= ?";
        $params[] = $range_start;
        $params[] = $range_end;
    }
    $sql .= " GROUP BY t.organization_id";
    $rows = db_fetch_all($sql, $params);
    foreach ($rows as $row) {
        $org_time_totals[(int) $row['organization_id']] = (int) $row['total_minutes'];
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Manage organizations and visibility.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
    <!-- Organizations List -->
    <div class="lg:col-span-2">
    <div class="card overflow-hidden">
        <div class="px-6 py-3 border-b flex items-center justify-between">
            <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Organization list')); ?></h3>
            <span class="text-sm" style="color: var(--text-muted);"><?php echo e(t('Click row to expand members')); ?></span>
        </div>

        <div class="px-6 py-3 border-b" style="background: var(--surface-secondary);">
            <form method="get" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="organizations">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-muted);"><?php echo e(t('Time range')); ?></label>
                    <select name="time_range" id="orgs-time-range" class="form-select">
                        <option value="all" <?php echo $time_range === 'all' ? 'selected' : ''; ?>>
                            <?php echo e(t('All time')); ?>
                        </option>
                        <option value="this_week" <?php echo $time_range === 'this_week' ? 'selected' : ''; ?>>
                            <?php echo e(t('This week')); ?>
                        </option>
                        <option value="this_month" <?php echo $time_range === 'this_month' ? 'selected' : ''; ?>>
                            <?php echo e(t('This month')); ?>
                        </option>
                        <option value="last_month" <?php echo $time_range === 'last_month' ? 'selected' : ''; ?>>
                            <?php echo e(t('Last month')); ?>
                        </option>
                        <option value="custom" <?php echo $time_range === 'custom' ? 'selected' : ''; ?>>
                            <?php echo e(t('Custom range')); ?>
                        </option>
                    </select>
                </div>
                <div id="orgs-custom-range"
                    class="flex items-end gap-3 <?php echo $time_range === 'custom' ? '' : 'hidden'; ?>">
                    <div>
                        <label class="block text-xs mb-1" style="color: var(--text-muted);"><?php echo e(t('From date')); ?></label>
                        <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color: var(--text-muted);"><?php echo e(t('To date')); ?></label>
                        <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
            </form>
        </div>

        <?php if (empty($organizations)): ?>
            <div class="p-8 text-center" style="color: var(--text-muted);">
                <?php echo get_icon('building', 'text-4xl mb-4'); ?>
                <p><?php echo e(t('No organizations yet.')); ?></p>
            </div>
        <?php else: ?>
            <div class="divide-y" style="border-color: var(--border-light);">
                <?php foreach ($organizations as $org):
                    $users_count = $org_user_counts[$org['id']] ?? 0;
                    $total_minutes = $org_time_totals[$org['id']] ?? 0;
                    $currency = get_currency_label();
                    $members = $org_users[$org['id']] ?? [];
                    ?>
                    <!-- Organization Row -->
                    <div class="org-item <?php echo !$org['is_active'] ? 'opacity-60' : ''; ?>">
                        <div class="org-header px-6 py-3 flex items-center justify-between cursor-pointer transition-colors tr-hover"
                             onclick="toggleOrgMembers(<?php echo $org['id']; ?>)">
                            <div class="flex items-center gap-4 flex-1 min-w-0">
                                <!-- Expand/Collapse Icon -->
                                <span class="org-toggle transition-transform" id="toggle-icon-<?php echo $org['id']; ?>" style="color: var(--text-muted);">
                                    <?php echo get_icon('chevron-right', 'w-5 h-5'); ?>
                                </span>

                                <!-- Logo -->
                                <?php if (!empty($org['logo'])): ?>
                                    <img src="<?php echo e(upload_url($org['logo'])); ?>" alt="" class="w-8 h-8 rounded-lg object-cover flex-shrink-0">
                                <?php else: ?>
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                        <span class="text-xs font-bold"><?php echo strtoupper(substr($org['name'], 0, 1)); ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Organization Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3">
                                        <span class="font-medium" style="color: var(--text-primary);"><?php echo e($org['name']); ?></span>
                                        <?php if ($org['is_active']): ?>
                                            <span class="badge bg-green-100 text-green-800 text-xs"><?php echo e(t('Active')); ?></span>
                                        <?php else: ?>
                                            <span class="badge text-xs" style="background: var(--surface-secondary); color: var(--text-primary);"><?php echo e(t('Inactive')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($org['contact_email'])): ?>
                                        <div class="text-sm" style="color: var(--text-muted);"><?php echo e($org['contact_email']); ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Stats -->
                                <div class="hidden md:flex items-center gap-3 text-sm" style="color: var(--text-secondary);">
                                    <div class="text-center">
                                        <div class="font-medium"><?php echo $users_count; ?></div>
                                        <div class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Users')); ?></div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-medium"><?php echo $total_minutes > 0 ? e(format_duration_minutes($total_minutes)) : '-'; ?></div>
                                        <div class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Time')); ?></div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-medium"><?php echo $org['billable_rate'] ? e(number_format((float) $org['billable_rate'], 0) . ' ' . $currency) : '-'; ?></div>
                                        <div class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Rate')); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 ml-4" onclick="event.stopPropagation()">
                                <button type="button" onclick="openEditOrgModal(<?php echo htmlspecialchars(json_encode([
                                    'id' => $org['id'],
                                    'name' => $org['name'],
                                    'ico' => $org['ico'] ?? '',
                                    'address' => $org['address'] ?? '',
                                    'contact_email' => $org['contact_email'] ?? '',
                                    'contact_phone' => $org['contact_phone'] ?? '',
                                    'notes' => $org['notes'] ?? '',
                                    'billable_rate' => $org['billable_rate'] ?? ''
                                ]), ENT_QUOTES, 'UTF-8'); ?>)"
                                    class="text-blue-500 hover:text-blue-700 p-2" title="<?php echo e(t('Edit')); ?>">
                                    <?php echo get_icon('edit', 'w-4 h-4'); ?>
                                </button>
                                <form method="post" class="inline"
                                    onsubmit="return confirm('<?php echo e(t('Are you sure you want to change the status?')); ?>')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $org['id']; ?>">
                                    <button type="submit" name="toggle"
                                        class="<?php echo $org['is_active'] ? 'text-yellow-500 hover:text-yellow-700' : 'text-green-500 hover:text-green-700'; ?> p-2"
                                        title="<?php echo e($org['is_active'] ? t('Deactivate') : t('Activate')); ?>">
                                        <?php echo get_icon($org['is_active'] ? 'pause' : 'play', 'w-4 h-4'); ?>
                                    </button>
                                </form>
                                <form method="post" class="inline"
                                    onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this organization?')); ?>')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $org['id']; ?>">
                                    <button type="submit" name="delete" class="text-red-500 hover:text-red-700 p-2"
                                        title="<?php echo e(t('Delete')); ?>">
                                        <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Collapsible Members Section -->
                        <div class="org-members hidden border-t" id="members-<?php echo $org['id']; ?>" style="background: var(--surface-secondary); border-color: var(--border-light);">
                            <div class="px-6 py-3 pl-14">
                                <div class="members-list space-y-2 mb-4" id="members-list-<?php echo $org['id']; ?>">
                                    <?php if (empty($members)): ?>
                                        <div class="text-sm py-2 empty-message" style="color: var(--text-muted);"><?php echo e(t('No users in this organization.')); ?></div>
                                    <?php else: ?>
                                        <?php foreach ($members as $u): ?>
                                            <div class="member-row flex items-center justify-between rounded-lg px-4 py-2 shadow-sm" data-user-id="<?php echo $u['id']; ?>" style="background: var(--surface-primary);">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($u['avatar'])): ?>
                                                        <img src="<?php echo e(upload_url($u['avatar'])); ?>" alt="" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                                                    <?php else: ?>
                                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                                            <?php echo strtoupper(substr($u['first_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="font-medium text-sm" style="color: var(--text-primary);"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></div>
                                                        <div class="text-xs" style="color: var(--text-muted);"><?php echo e($u['email']); ?></div>
                                                    </div>
                                                    <span class="badge text-xs <?php echo $u['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : ($u['role'] === 'agent' ? 'bg-blue-100 text-blue-800' : ''); ?>"<?php if ($u['role'] !== 'admin' && $u['role'] !== 'agent'): ?> style="background: var(--surface-secondary); color: var(--text-primary);"<?php endif; ?>>
                                                        <?php echo e(ucfirst($u['role'])); ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <a href="<?php echo url('admin', ['section' => 'users', 'edit' => $u['id']]); ?>"
                                                        class="text-blue-500 hover:text-blue-700 p-1" title="<?php echo e(t('Edit user')); ?>">
                                                        <?php echo get_icon('edit', 'w-4 h-4'); ?>
                                                    </a>
                                                    <button type="button" onclick="removeUserFromOrg(<?php echo $u['id']; ?>, <?php echo $org['id']; ?>)"
                                                        class="text-red-500 hover:text-red-700 p-1" title="<?php echo e(t('Remove from organization')); ?>">
                                                        <?php echo get_icon('user-minus', 'w-4 h-4'); ?>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Add User Form -->
                                <div class="flex items-center gap-2 mt-3 add-user-form" id="add-user-form-<?php echo $org['id']; ?>">
                                    <select class="form-select text-sm flex-1 add-user-select" id="add-user-select-<?php echo $org['id']; ?>">
                                        <option value=""><?php echo e(t('Select user to add...')); ?></option>
                                        <?php foreach (($available_users_by_org[$org['id']] ?? []) as $uu): ?>
                                            <option value="<?php echo $uu['id']; ?>" data-name="<?php echo e($uu['first_name'] . ' ' . $uu['last_name']); ?>" data-email="<?php echo e($uu['email']); ?>">
                                                <?php echo e($uu['first_name'] . ' ' . $uu['last_name'] . ' (' . $uu['email'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" onclick="addUserToOrg(<?php echo $org['id']; ?>)" class="btn btn-primary btn-sm">
                                        <?php echo get_icon('user-plus', 'w-4 h-4 mr-1'); ?>
                                        <?php echo e(t('Add')); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <!-- Add Organization Form (Sidebar) -->
    <div class="card card-body h-fit">
        <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Add organization')); ?></h3>

        <form method="post" enctype="multipart/form-data" class="space-y-4">
            <?php echo csrf_field(); ?>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Organization name')); ?> *</label>
                <input type="text" name="name" required class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Logo')); ?></label>
                <div id="org-logo-create-zone" class="rounded-lg p-2.5 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors" style="border-color: var(--border-light);">
                    <input type="file" name="org_logo_create" id="org-logo-create-input" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                    <div class="flex items-center gap-2 text-xs">
                        <span style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-base flex-shrink-0'); ?></span>
                        <span><span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span> <?php echo e(t('or drag file')); ?></span>
                    </div>
                </div>
                <p id="org-logo-create-filename" class="text-xs mt-1 hidden" style="color: var(--text-secondary);"></p>
                <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Square image recommended. Max 2 MB.')); ?></p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Company ID')); ?></label>
                <input type="text" name="ico" class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Address')); ?></label>
                <textarea name="address" rows="2" class="form-textarea"></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Contact email')); ?></label>
                <input type="email" name="contact_email" class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Phone')); ?></label>
                <input type="text" name="contact_phone" class="form-input">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Notes')); ?></label>
                <textarea name="notes" rows="2" class="form-textarea"></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Billable rate (per hour)')); ?></label>
                <input type="number" name="billable_rate" step="0.01" min="0" class="form-input">
            </div>

            <button type="submit" name="create" class="btn btn-primary w-full">
                <?php echo e(t('Add organization')); ?>
            </button>
        </form>
    </div>
</div>

<!-- Edit Organization Modal -->
<div id="editOrgModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" role="dialog" aria-modal="true" aria-labelledby="edit-org-title">
    <div class="rounded-xl shadow-xl max-w-lg w-full mx-4 p-4 max-h-[90vh] overflow-y-auto" style="background: var(--surface-primary);">
        <h3 id="edit-org-title" class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Edit organization')); ?></h3>

        <!-- Logo upload (separate form) -->
        <div class="flex items-center gap-4 pb-4 mb-4 border-b" style="border-color: var(--border-light);">
            <div id="edit_org_logo_preview">
                <div class="w-14 h-14 rounded-xl flex items-center justify-center" style="background: var(--surface-tertiary); color: var(--text-secondary);">
                    <span class="text-lg font-bold" id="edit_org_logo_initial"></span>
                </div>
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium mb-1.5" style="color: var(--text-secondary);"><?php echo e(t('Logo')); ?></label>
                <form method="post" enctype="multipart/form-data" class="mb-1">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="org_id" id="edit_org_logo_org_id">
                    <input type="hidden" name="upload_org_logo" value="1">
                    <div id="org-logo-edit-zone" class="rounded-lg p-2 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors" style="border-color: var(--border-light);">
                        <input type="file" name="org_logo" id="org-logo-edit-input" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden"
                               onchange="this.form.submit()">
                        <div class="flex items-center gap-2 text-xs">
                            <span style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-base flex-shrink-0'); ?></span>
                            <span><span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span> <?php echo e(t('or drag file')); ?></span>
                        </div>
                    </div>
                    <p id="org-logo-edit-filename" class="text-xs mt-1 hidden" style="color: var(--text-secondary);"></p>
                </form>
                <div class="flex items-center gap-2">
                    <form method="post" id="remove_org_logo_form" class="inline" style="display:none;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="org_id" id="remove_org_logo_org_id">
                        <button type="submit" name="remove_org_logo" class="btn btn-secondary btn-sm text-red-500 hover:text-red-700">
                            <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?> <?php echo e(t('Remove')); ?>
                        </button>
                    </form>
                </div>
                <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Square image recommended. Max 2 MB.')); ?></p>
            </div>
        </div>

        <form method="post" id="editOrgForm" class="space-y-4">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_org_id">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_org_name" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Organization name')); ?> *</label>
                    <input type="text" name="name" id="edit_org_name" required aria-required="true" class="form-input">
                </div>
                <div>
                    <label for="edit_org_ico" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Company ID')); ?></label>
                    <input type="text" name="ico" id="edit_org_ico" class="form-input">
                </div>
            </div>

            <div>
                <label for="edit_org_address" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Address')); ?></label>
                <textarea name="address" id="edit_org_address" rows="2" class="form-textarea"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="edit_org_contact_email" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Contact email')); ?></label>
                    <input type="email" name="contact_email" id="edit_org_contact_email" class="form-input">
                </div>
                <div>
                    <label for="edit_org_contact_phone" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Phone')); ?></label>
                    <input type="text" name="contact_phone" id="edit_org_contact_phone" class="form-input">
                </div>
            </div>

            <div>
                <label for="edit_org_notes" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Notes')); ?></label>
                <textarea name="notes" id="edit_org_notes" rows="2" class="form-textarea"></textarea>
            </div>

            <div>
                <label for="edit_org_billable_rate" class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Billable rate (per hour)')); ?></label>
                <input type="number" name="billable_rate" id="edit_org_billable_rate" step="0.01" min="0" class="form-input">
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeEditOrgModal()" class="btn btn-secondary">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" name="update" class="btn btn-primary">
                    <?php echo e(t('Save changes')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.org-toggle {
    transition: transform 0.2s ease;
}
.org-toggle.rotated {
    transform: rotate(90deg);
}
.org-members {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.org-members.expanded {
    max-height: 1000px;
}
</style>

<script>
    const csrfToken = window.csrfToken || '';

    // HTML escape helper to prevent XSS in dynamic content
    function escHtml(str) {
        if (str == null) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // Edit Organization Modal Functions
    var _editOrgReturnFocus = null;

    function openEditOrgModal(org) {
        _editOrgReturnFocus = document.activeElement;
        document.getElementById('edit_org_id').value = org.id;
        document.getElementById('edit_org_name').value = org.name;
        document.getElementById('edit_org_ico').value = org.ico || '';
        document.getElementById('edit_org_address').value = org.address || '';
        document.getElementById('edit_org_contact_email').value = org.contact_email || '';
        document.getElementById('edit_org_contact_phone').value = org.contact_phone || '';
        document.getElementById('edit_org_notes').value = org.notes || '';
        document.getElementById('edit_org_billable_rate').value = org.billable_rate || '';

        // Logo preview
        document.getElementById('edit_org_logo_org_id').value = org.id;
        document.getElementById('remove_org_logo_org_id').value = org.id;
        const logoPreview = document.getElementById('edit_org_logo_preview');
        const removeLogoForm = document.getElementById('remove_org_logo_form');
        // Clear existing content safely
        while (logoPreview.firstChild) logoPreview.removeChild(logoPreview.firstChild);
        if (org.logo) {
            const img = document.createElement('img');
            img.src = org.logo;
            img.alt = '';
            img.className = 'w-14 h-14 rounded-xl object-cover';
            logoPreview.appendChild(img);
            removeLogoForm.style.display = 'inline';
        } else {
            const wrapper = document.createElement('div');
            wrapper.className = 'w-14 h-14 rounded-xl flex items-center justify-center';
            wrapper.style.cssText = 'background: var(--surface-tertiary); color: var(--text-secondary);';
            const span = document.createElement('span');
            span.className = 'text-lg font-bold';
            span.textContent = (org.name || '?').charAt(0).toUpperCase();
            wrapper.appendChild(span);
            logoPreview.appendChild(wrapper);
            removeLogoForm.style.display = 'none';
        }

        const modal = document.getElementById('editOrgModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.getElementById('edit_org_name').focus();
        if (typeof trapFocus === 'function') trapFocus(modal);
    }

    function closeEditOrgModal() {
        const modal = document.getElementById('editOrgModal');
        if (typeof releaseFocus === 'function') releaseFocus(modal);
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        if (_editOrgReturnFocus) { _editOrgReturnFocus.focus(); _editOrgReturnFocus = null; }
    }

    // Close modal on backdrop click
    document.getElementById('editOrgModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditOrgModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditOrgModal();
        }
    });

    function toggleOrgMembers(orgId) {
        const membersDiv = document.getElementById('members-' + orgId);
        const toggleIcon = document.getElementById('toggle-icon-' + orgId);

        if (membersDiv.classList.contains('hidden')) {
            membersDiv.classList.remove('hidden');
            setTimeout(() => membersDiv.classList.add('expanded'), 10);
            toggleIcon.classList.add('rotated');
        } else {
            membersDiv.classList.remove('expanded');
            setTimeout(() => membersDiv.classList.add('hidden'), 300);
            toggleIcon.classList.remove('rotated');
        }
    }

    async function addUserToOrg(orgId) {
        const select = document.getElementById('add-user-select-' + orgId);
        const userId = select.value;
        if (!userId) return;

        const selectedOption = select.options[select.selectedIndex];
        const userName = selectedOption.dataset.name;
        const userEmail = selectedOption.dataset.email;

        try {
            const response = await fetch('index.php?page=api&action=org-add-user', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ org_id: orgId, user_id: parseInt(userId) })
            });

            const data = await response.json();
            if (data.success) {
                // Add user to members list
                const membersList = document.getElementById('members-list-' + orgId);
                const emptyMsg = membersList.querySelector('.empty-message');
                if (emptyMsg) emptyMsg.remove();

                const user = data.user;
                const roleClass = user.role === 'admin' ? 'bg-purple-100 text-purple-800' :
                                  (user.role === 'agent' ? 'bg-blue-100 text-blue-800' : '');
                const roleStyle = (user.role !== 'admin' && user.role !== 'agent') ? 'background: var(--surface-secondary); color: var(--text-primary);' : '';
                const initial = user.first_name.charAt(0).toUpperCase();

                const safeFirstName = escHtml(user.first_name);
                const safeLastName = escHtml(user.last_name);
                const safeEmail = escHtml(user.email);
                const safeRole = escHtml(user.role.charAt(0).toUpperCase() + user.role.slice(1));
                const safeId = parseInt(user.id, 10);
                const safeOrgId = parseInt(orgId, 10);

                const memberHtml = `
                    <div class="member-row flex items-center justify-between rounded-lg px-4 py-2 shadow-sm" data-user-id="${safeId}" style="background: var(--surface-primary);">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style="background: var(--surface-tertiary); color: var(--text-secondary);">${escHtml(initial)}</div>
                            <div>
                                <div class="font-medium text-sm" style="color: var(--text-primary);">${safeFirstName} ${safeLastName}</div>
                                <div class="text-xs" style="color: var(--text-muted);">${safeEmail}</div>
                            </div>
                            <span class="badge text-xs ${roleClass}" style="${roleStyle}">${safeRole}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="index.php?page=admin&section=users&edit=${safeId}" class="text-blue-500 hover:text-blue-700 p-1" title="<?php echo e(t('Edit user')); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                            <button type="button" onclick="removeUserFromOrg(${safeId}, ${safeOrgId})" class="text-red-500 hover:text-red-700 p-1" title="<?php echo e(t('Remove from organization')); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                            </button>
                        </div>
                    </div>
                `;
                membersList.insertAdjacentHTML('beforeend', memberHtml);

                // Remove user from current organization dropdown only
                const currentOption = select.querySelector('option[value="' + userId + '"]');
                if (currentOption) {
                    currentOption.remove();
                }

                // Reset select
                select.value = '';

                // Update user count in header
                updateOrgUserCount(orgId, 1);
            } else {
                alert(data.error || '<?php echo e(t('Error adding user')); ?>');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('<?php echo e(t('Error adding user')); ?>');
        }
    }

    async function removeUserFromOrg(userId, orgId) {
        if (!confirm('<?php echo e(t('Remove user from organization?')); ?>')) return;

        try {
            const response = await fetch('index.php?page=api&action=org-remove-user', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ user_id: userId, org_id: orgId })
            });

            const data = await response.json();
            if (data.success) {
                // Get user info before removing
                const membersList = document.getElementById('members-list-' + orgId);
                const memberRow = membersList ? membersList.querySelector(`.member-row[data-user-id="${userId}"]`) : null;
                if (memberRow) {
                    const userName = memberRow.querySelector('.font-medium').textContent;
                    const userEmail = memberRow.querySelector('.text-xs').textContent;

                    // Remove from list with animation
                    memberRow.style.transition = 'opacity 0.2s, transform 0.2s';
                    memberRow.style.opacity = '0';
                    memberRow.style.transform = 'translateX(-10px)';
                    setTimeout(() => {
                        memberRow.remove();

                        // Check if list is empty
                        if (!membersList.querySelector('.member-row')) {
                            membersList.innerHTML = '<div class="text-sm py-2 empty-message" style="color: var(--text-muted);"><?php echo e(t('No users in this organization.')); ?></div>';
                        }
                    }, 200);

                    // Add user back to this organization dropdown
                    const select = document.getElementById('add-user-select-' + orgId);
                    if (select && !select.querySelector('option[value="' + userId + '"]')) {
                        const option = document.createElement('option');
                        option.value = userId;
                        option.dataset.name = userName;
                        option.dataset.email = userEmail;
                        option.textContent = userName + ' (' + userEmail + ')';
                        select.appendChild(option);
                    }

                    // Update user count
                    updateOrgUserCount(orgId, -1);
                }
            } else {
                alert(data.error || '<?php echo e(t('Error removing user')); ?>');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('<?php echo e(t('Error removing user')); ?>');
        }
    }

    function updateOrgUserCount(orgId, delta) {
        const orgHeader = document.querySelector(`[onclick="toggleOrgMembers(${orgId})"]`);
        if (orgHeader) {
            const countEl = orgHeader.querySelector('.text-center .font-medium');
            if (countEl) {
                const current = parseInt(countEl.textContent) || 0;
                countEl.textContent = Math.max(0, current + delta);
            }
        }
    }

    const orgTimeRangeSelect = document.getElementById('orgs-time-range');
    const orgCustomRange = document.getElementById('orgs-custom-range');
    if (orgTimeRangeSelect && orgCustomRange) {
        const toggleCustomRange = () => {
            orgCustomRange.classList.toggle('hidden', orgTimeRangeSelect.value !== 'custom');
        };
        orgTimeRangeSelect.addEventListener('change', toggleCustomRange);
        toggleCustomRange();
    }

    // Org logo drag & drop (must wait for DOMContentLoaded so app-footer.js defer script is loaded)
    function initOrgLogoDropzones() {
        if (!window.initFileDropzone) return;

        // Create form
        window.initFileDropzone({
            zoneId: 'org-logo-create-zone',
            inputId: 'org-logo-create-input',
            acceptedExtensions: ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
            invalidTypeMessage: '<?php echo e(t('Invalid file type.')); ?>',
            onFilesChanged: function(files) {
                var label = document.getElementById('org-logo-create-filename');
                if (label && files.length > 0) {
                    label.textContent = files[0].name;
                    label.classList.remove('hidden');
                } else if (label) {
                    label.classList.add('hidden');
                }
            }
        });

        // Edit modal
        window.initFileDropzone({
            zoneId: 'org-logo-edit-zone',
            inputId: 'org-logo-edit-input',
            acceptedExtensions: ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
            invalidTypeMessage: '<?php echo e(t('Invalid file type.')); ?>',
            onFilesChanged: function(files) {
                var label = document.getElementById('org-logo-edit-filename');
                if (label && files.length > 0) {
                    label.textContent = files[0].name;
                    label.classList.remove('hidden');
                    var form = document.getElementById('org-logo-edit-input')?.closest('form');
                    if (form) form.submit();
                }
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOrgLogoDropzones);
    } else {
        initOrgLogoDropzones();
    }
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
