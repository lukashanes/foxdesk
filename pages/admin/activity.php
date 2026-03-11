<?php
/**
 * Admin - User Activity
 *
 * Lightweight page view analytics — shows which pages users visit,
 * how active they are, and provides a raw access log.
 */

$page_title = t('Activity');
$page = 'admin';

if (!ensure_page_views_table()) {
    require_once BASE_PATH . '/includes/header.php';
    echo '<div class="p-8 text-center" style="color:var(--text-muted);">' . e(t('Activity tracking table is not available.')) . '</div>';
    require_once BASE_PATH . '/includes/footer.php';
    return;
}

// ── Handle POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    if (isset($_POST['clear_old'])) {
        $days = max(7, (int) ($_POST['days'] ?? 90));
        db_query("DELETE FROM page_views WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        flash(t('Deleted activity data older than {days} days.', ['days' => $days]), 'success');
        redirect('admin', ['section' => 'activity']);
    }
    if (isset($_POST['clear_all'])) {
        db_query("TRUNCATE TABLE page_views");
        flash(t('All activity data cleared.'), 'success');
        redirect('admin', ['section' => 'activity']);
    }
}

// ── Tab / view selection ─────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';
$range = $_GET['range'] ?? '7';
$range_days = in_array($range, ['1', '7', '30', '90']) ? (int) $range : 7;
$range_date = date('Y-m-d H:i:s', strtotime("-{$range_days} days"));

// Human-readable page labels
$page_labels = [
    'dashboard' => t('Dashboard'),
    'tickets' => t('Tickets'),
    'ticket' => t('Ticket Detail'),
    'new-ticket' => t('New Ticket'),
    'notifications' => t('Notifications'),
    'admin' => t('Admin'),
    'profile' => t('Profile'),
    'user-profile' => t('User Profile'),
    'login' => t('Login'),
    'logout' => t('Logout'),
    'forgot-password' => t('Forgot Password'),
];

$section_labels = [
    'statuses' => t('Statuses'),
    'priorities' => t('Priorities'),
    'ticket-types' => t('Ticket Types'),
    'organizations' => t('Organizations'),
    'clients' => t('Clients'),
    'users' => t('Users'),
    'settings' => t('Settings'),
    'reports' => t('Reports'),
    'reports-list' => t('Reports List'),
    'report-builder' => t('Report Builder'),
    'recurring-tasks' => t('Recurring Tasks'),
    'agent-connect' => t('Agent Connect'),
    'activity' => t('Activity'),
];

function pv_page_label($pg, $sec, $page_labels, $section_labels) {
    $label = $page_labels[$pg] ?? ucfirst($pg);
    if ($pg === 'admin' && $sec) {
        $label .= ' → ' . ($section_labels[$sec] ?? ucfirst($sec));
    }
    return $label;
}

function pv_page_icon($pg) {
    $icons = [
        'dashboard' => 'home', 'tickets' => 'list', 'ticket' => 'file-text',
        'new-ticket' => 'plus', 'notifications' => 'bell', 'admin' => 'settings',
        'profile' => 'user', 'user-profile' => 'user',
    ];
    return $icons[$pg] ?? 'file';
}

// ── Fetch data ───────────────────────────────────────────────────────
// Total counts
$total_views = (int) (db_fetch_one("SELECT COUNT(*) as c FROM page_views WHERE created_at >= ?", [$range_date])['c'] ?? 0);
$total_users = (int) (db_fetch_one("SELECT COUNT(DISTINCT user_id) as c FROM page_views WHERE created_at >= ?", [$range_date])['c'] ?? 0);
$today_views = (int) (db_fetch_one("SELECT COUNT(*) as c FROM page_views WHERE created_at >= CURDATE()")['c'] ?? 0);

// Page popularity
$popular_pages = db_fetch_all("
    SELECT page, section, COUNT(*) as views, COUNT(DISTINCT user_id) as users
    FROM page_views WHERE created_at >= ?
    GROUP BY page, section ORDER BY views DESC LIMIT 15
", [$range_date]);

// User activity
$user_activity = db_fetch_all("
    SELECT pv.user_id, u.first_name, u.last_name, u.email, u.role, u.avatar,
           COUNT(*) as views, MAX(pv.created_at) as last_active,
           COUNT(DISTINCT pv.page) as pages_used
    FROM page_views pv
    JOIN users u ON pv.user_id = u.id
    WHERE pv.created_at >= ?
    GROUP BY pv.user_id ORDER BY views DESC
", [$range_date]);

// Daily views for sparkline (last N days)
$daily_views = db_fetch_all("
    SELECT DATE(created_at) as day, COUNT(*) as views
    FROM page_views WHERE created_at >= ?
    GROUP BY DATE(created_at) ORDER BY day ASC
", [$range_date]);

require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .act-card { padding: 16px 20px; border-radius: 12px; background: var(--surface-primary); border: 1px solid var(--border-light); }
    .act-stat-value { font-size: 1.75rem; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
    .act-stat-label { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }
    .act-table { width: 100%; border-collapse: collapse; }
    .act-table th { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
        color: var(--text-muted); padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
    .act-table td { font-size: 0.8125rem; padding: 8px 12px; color: var(--text-primary); border-bottom: 1px solid var(--border-light); }
    .act-table tbody tr:hover { background: var(--primary-soft, rgba(59,130,246,0.03)); }
    .act-page-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px;
        font-size: 0.75rem; font-weight: 500; background: var(--surface-secondary); color: var(--text-secondary); }
    .act-role-badge { font-size: 0.625rem; font-weight: 600; text-transform: uppercase; padding: 1px 5px;
        border-radius: 4px; letter-spacing: 0.03em; }
    .act-role-admin { background: #fef3c7; color: #92400e; }
    .act-role-agent { background: #dbeafe; color: #1e40af; }
    .act-role-user { background: #f1f5f9; color: #64748b; }
    [data-theme="dark"] .act-role-admin { background: rgba(146,64,14,0.2); color: #fbbf24; }
    [data-theme="dark"] .act-role-agent { background: rgba(30,64,175,0.2); color: #60a5fa; }
    [data-theme="dark"] .act-role-user { background: rgba(100,116,139,0.2); color: #94a3b8; }
    .act-bar { height: 6px; border-radius: 3px; background: var(--primary, #3b82f6); transition: width 0.3s; }
    .act-bar-bg { height: 6px; border-radius: 3px; background: var(--surface-secondary); width: 100%; overflow: hidden; }
    .act-avatar { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center;
        font-size: 11px; font-weight: 600; color: #fff; flex-shrink: 0; overflow: hidden; }
    .act-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .act-tabs { display: flex; gap: 3px; padding: 3px; border-radius: 10px; background: var(--surface-secondary, #f1f5f9); margin-bottom: 16px; }
    .act-tab { padding: 6px 14px; font-size: 0.8125rem; font-weight: 500; border-radius: 8px; color: var(--text-secondary);
        text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .act-tab:hover { background: var(--surface-primary, #fff); color: var(--text-primary); }
    .act-tab.active { background: var(--surface-primary, #fff); color: var(--primary, #3b82f6); font-weight: 600;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .act-range { display: flex; gap: 2px; }
    .act-range a { padding: 4px 10px; font-size: 0.6875rem; font-weight: 500; border-radius: 6px;
        color: var(--text-secondary); text-decoration: none; transition: all 0.12s; }
    .act-range a:hover { background: var(--surface-secondary); }
    .act-range a.active { background: var(--primary, #3b82f6); color: #fff; }
    .act-spark { display: flex; align-items: flex-end; gap: 2px; height: 32px; }
    .act-spark-bar { min-width: 4px; border-radius: 2px; background: var(--primary, #3b82f6); opacity: 0.7; transition: opacity 0.12s; }
    .act-spark-bar:hover { opacity: 1; }
</style>

<div style="max-width: 1100px; margin: 0 auto; padding: 0 16px;">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <h2 class="text-lg font-bold" style="color: var(--text-primary);">
            <?php echo get_icon('bar-chart', 'w-5 h-5 mr-1 inline-block opacity-60'); ?>
            <?php echo e(t('User Activity')); ?>
        </h2>
        <div class="flex items-center gap-3">
            <!-- Range selector -->
            <div class="act-range">
                <?php foreach ([1 => t('Today'), 7 => t('7 days'), 30 => t('30 days'), 90 => t('90 days')] as $d => $label): ?>
                    <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => $tab, 'range' => $d]); ?>"
                       class="<?php echo $range_days === $d ? 'active' : ''; ?>"><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="act-tabs">
        <a href="<?php echo url('admin', ['section' => 'activity', 'range' => $range]); ?>"
           class="act-tab <?php echo $tab === 'overview' ? 'active' : ''; ?>"><?php echo e(t('Overview')); ?></a>
        <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => 'log', 'range' => $range]); ?>"
           class="act-tab <?php echo $tab === 'log' ? 'active' : ''; ?>"><?php echo e(t('Access Log')); ?></a>
        <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => 'manage']); ?>"
           class="act-tab <?php echo $tab === 'manage' ? 'active' : ''; ?>"><?php echo e(t('Manage')); ?></a>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- ═══════════════ OVERVIEW ═══════════════ -->

    <!-- Stats row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-5">
        <div class="act-card">
            <div class="act-stat-value"><?php echo number_format($today_views); ?></div>
            <div class="act-stat-label"><?php echo e(t('Page views today')); ?></div>
        </div>
        <div class="act-card">
            <div class="act-stat-value"><?php echo number_format($total_views); ?></div>
            <div class="act-stat-label"><?php echo e(t('Page views ({days}d)', ['days' => $range_days])); ?></div>
        </div>
        <div class="act-card">
            <div class="act-stat-value"><?php echo $total_users; ?></div>
            <div class="act-stat-label"><?php echo e(t('Active users ({days}d)', ['days' => $range_days])); ?></div>
        </div>
        <div class="act-card">
            <div class="act-stat-label" style="margin-bottom: 4px;"><?php echo e(t('Daily trend')); ?></div>
            <?php if (!empty($daily_views)):
                $max_day = max(array_column($daily_views, 'views'));
            ?>
                <div class="act-spark" title="<?php echo e(t('Daily page views')); ?>">
                    <?php foreach ($daily_views as $dv):
                        $h = $max_day > 0 ? max(3, round(28 * $dv['views'] / $max_day)) : 3;
                    ?>
                        <div class="act-spark-bar" style="height: <?php echo $h; ?>px; flex: 1;"
                             title="<?php echo e($dv['day'] . ': ' . $dv['views']); ?>"></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-xs" style="color: var(--text-muted);">—</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Popular pages -->
        <div class="act-card">
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">
                <?php echo e(t('Most Visited Pages')); ?>
            </h3>
            <?php if (empty($popular_pages)): ?>
                <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('No data yet.')); ?></div>
            <?php else:
                $max_views = $popular_pages[0]['views'] ?? 1;
            ?>
                <div class="space-y-2.5">
                    <?php foreach ($popular_pages as $pp): ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="act-page-pill">
                                    <?php echo get_icon(pv_page_icon($pp['page']), 'w-3 h-3'); ?>
                                    <?php echo e(pv_page_label($pp['page'], $pp['section'], $page_labels, $section_labels)); ?>
                                </span>
                                <span class="text-xs tabular-nums" style="color: var(--text-muted);">
                                    <?php echo number_format($pp['views']); ?>
                                    <span class="ml-1">(<?php echo $pp['users']; ?> <?php echo e(t('users')); ?>)</span>
                                </span>
                            </div>
                            <div class="act-bar-bg">
                                <div class="act-bar" style="width: <?php echo round(100 * $pp['views'] / $max_views); ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- User activity -->
        <div class="act-card" style="overflow: hidden; padding: 0;">
            <div class="px-5 pt-4 pb-2">
                <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                    <?php echo e(t('User Activity')); ?>
                </h3>
            </div>
            <?php if (empty($user_activity)): ?>
                <div class="px-5 pb-4 text-sm" style="color: var(--text-muted);"><?php echo e(t('No data yet.')); ?></div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="act-table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('User')); ?></th>
                                <th><?php echo e(t('Role')); ?></th>
                                <th style="text-align:right;"><?php echo e(t('Views')); ?></th>
                                <th style="text-align:right;"><?php echo e(t('Pages')); ?></th>
                                <th><?php echo e(t('Last Active')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_activity as $ua):
                                $ua_name = trim(($ua['first_name'] ?? '') . ' ' . ($ua['last_name'] ?? ''));
                                $ua_initials = mb_strtoupper(mb_substr($ua['first_name'] ?? '?', 0, 1));
                                $ua_bg = 'hsl(' . abs(crc32($ua_name)) % 360 . ', 55%, 60%)';
                                $role_class = 'act-role-' . ($ua['role'] ?? 'user');
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => 'user', 'uid' => $ua['user_id'], 'range' => $range]); ?>"
                                           class="flex items-center gap-2" style="text-decoration: none; color: var(--text-primary);">
                                            <div class="act-avatar" style="background: <?php echo $ua_bg; ?>;">
                                                <?php if (!empty($ua['avatar']) && !str_starts_with($ua['avatar'], 'data:')): ?>
                                                    <img src="<?php echo e(upload_url($ua['avatar'])); ?>" alt="">
                                                <?php else: ?>
                                                    <?php echo e($ua_initials); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="font-medium text-sm"><?php echo e($ua_name); ?></div>
                                                <div class="text-xs" style="color: var(--text-muted);"><?php echo e($ua['email']); ?></div>
                                            </div>
                                        </a>
                                    </td>
                                    <td><span class="act-role-badge <?php echo $role_class; ?>"><?php echo e($ua['role']); ?></span></td>
                                    <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo number_format($ua['views']); ?></td>
                                    <td style="text-align:right; font-variant-numeric: tabular-nums;"><?php echo $ua['pages_used']; ?></td>
                                    <td class="whitespace-nowrap" style="color: var(--text-muted); font-size: 0.75rem;">
                                        <?php echo e(notification_time_ago($ua['last_active'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($tab === 'user' && isset($_GET['uid'])): ?>
    <!-- ═══════════════ USER DETAIL ═══════════════ -->
    <?php
    $uid = (int) $_GET['uid'];
    $detail_user = db_fetch_one("SELECT id, first_name, last_name, email, role, avatar FROM users WHERE id = ?", [$uid]);

    if (!$detail_user):
        echo '<div class="act-card text-center py-8" style="color:var(--text-muted);">' . e(t('User not found.')) . '</div>';
    else:
        $du_name = trim(($detail_user['first_name'] ?? '') . ' ' . ($detail_user['last_name'] ?? ''));
        $du_initials = mb_strtoupper(mb_substr($detail_user['first_name'] ?? '?', 0, 1));
        $du_bg = 'hsl(' . abs(crc32($du_name)) % 360 . ', 55%, 60%)';

        $du_pages = db_fetch_all("
            SELECT page, section, COUNT(*) as views
            FROM page_views WHERE user_id = ? AND created_at >= ?
            GROUP BY page, section ORDER BY views DESC
        ", [$uid, $range_date]);

        $du_recent = db_fetch_all("
            SELECT page, section, created_at
            FROM page_views WHERE user_id = ?
            ORDER BY created_at DESC LIMIT 30
        ", [$uid]);

        $du_total = (int) (db_fetch_one("SELECT COUNT(*) as c FROM page_views WHERE user_id = ? AND created_at >= ?", [$uid, $range_date])['c'] ?? 0);
    ?>
    <a href="<?php echo url('admin', ['section' => 'activity', 'range' => $range]); ?>"
       class="inline-flex items-center gap-1 text-sm mb-3" style="color: var(--primary); text-decoration: none;">
        <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?> <?php echo e(t('Back to overview')); ?>
    </a>

    <div class="act-card mb-4">
        <div class="flex items-center gap-3">
            <div class="act-avatar" style="width: 40px; height: 40px; border-radius: 10px; font-size: 16px; background: <?php echo $du_bg; ?>;">
                <?php if (!empty($detail_user['avatar']) && !str_starts_with($detail_user['avatar'], 'data:')): ?>
                    <img src="<?php echo e(upload_url($detail_user['avatar'])); ?>" alt="">
                <?php else: ?>
                    <?php echo e($du_initials); ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="font-bold text-base" style="color: var(--text-primary);"><?php echo e($du_name); ?></div>
                <div class="text-xs" style="color: var(--text-muted);">
                    <?php echo e($detail_user['email']); ?> ·
                    <span class="act-role-badge act-role-<?php echo e($detail_user['role']); ?>"><?php echo e($detail_user['role']); ?></span> ·
                    <?php echo number_format($du_total); ?> <?php echo e(t('views in {days}d', ['days' => $range_days])); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Pages used -->
        <div class="act-card">
            <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">
                <?php echo e(t('Pages Used')); ?>
            </h3>
            <?php if (empty($du_pages)): ?>
                <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('No data.')); ?></div>
            <?php else:
                $du_max = $du_pages[0]['views'] ?? 1;
            ?>
                <div class="space-y-2">
                    <?php foreach ($du_pages as $dp): ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="act-page-pill">
                                    <?php echo get_icon(pv_page_icon($dp['page']), 'w-3 h-3'); ?>
                                    <?php echo e(pv_page_label($dp['page'], $dp['section'], $page_labels, $section_labels)); ?>
                                </span>
                                <span class="text-xs tabular-nums" style="color: var(--text-muted);"><?php echo number_format($dp['views']); ?></span>
                            </div>
                            <div class="act-bar-bg">
                                <div class="act-bar" style="width: <?php echo round(100 * $dp['views'] / $du_max); ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent activity -->
        <div class="act-card" style="overflow: hidden; padding: 0;">
            <div class="px-5 pt-4 pb-2">
                <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                    <?php echo e(t('Recent Activity')); ?>
                </h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="act-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('Time')); ?></th>
                            <th><?php echo e(t('Page')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($du_recent as $dr): ?>
                            <tr>
                                <td class="whitespace-nowrap" style="color: var(--text-muted); font-size: 0.75rem;">
                                    <?php echo date('d.m. H:i', strtotime($dr['created_at'])); ?>
                                </td>
                                <td>
                                    <span class="act-page-pill">
                                        <?php echo get_icon(pv_page_icon($dr['page']), 'w-3 h-3'); ?>
                                        <?php echo e(pv_page_label($dr['page'], $dr['section'], $page_labels, $section_labels)); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'log'): ?>
    <!-- ═══════════════ ACCESS LOG ═══════════════ -->
    <?php
    $log_user = isset($_GET['uid']) ? (int) $_GET['uid'] : null;
    $log_page_filter = $_GET['fp'] ?? '';
    $page_num = max(1, (int) ($_GET['p'] ?? 1));
    $per_page = 50;
    $log_offset = ($page_num - 1) * $per_page;

    $where = ["pv.created_at >= ?"];
    $params = [$range_date];
    if ($log_user) {
        $where[] = "pv.user_id = ?";
        $params[] = $log_user;
    }
    if ($log_page_filter !== '') {
        $where[] = "pv.page = ?";
        $params[] = $log_page_filter;
    }
    $where_sql = implode(' AND ', $where);

    $log_total = (int) (db_fetch_one("SELECT COUNT(*) as c FROM page_views pv WHERE $where_sql", $params)['c'] ?? 0);
    $log_pages = (int) ceil(max(1, $log_total) / $per_page);

    $log_entries = db_fetch_all("
        SELECT pv.*, u.first_name, u.last_name, u.email, u.role
        FROM page_views pv
        LEFT JOIN users u ON pv.user_id = u.id
        WHERE $where_sql
        ORDER BY pv.created_at DESC
        LIMIT ? OFFSET ?
    ", array_merge($params, [(int) $per_page, (int) $log_offset]));

    // Available users and pages for filter dropdowns
    $filter_users = db_fetch_all("
        SELECT DISTINCT pv.user_id, u.first_name, u.last_name
        FROM page_views pv JOIN users u ON pv.user_id = u.id
        WHERE pv.created_at >= ? ORDER BY u.first_name
    ", [$range_date]);
    $filter_pages_list = db_fetch_all("
        SELECT DISTINCT page FROM page_views WHERE created_at >= ? ORDER BY page
    ", [$range_date]);
    ?>

    <!-- Filters -->
    <div class="act-card mb-3">
        <form method="get" class="flex items-center gap-3 flex-wrap">
            <input type="hidden" name="page" value="admin">
            <input type="hidden" name="section" value="activity">
            <input type="hidden" name="tab" value="log">
            <input type="hidden" name="range" value="<?php echo $range_days; ?>">

            <select name="uid" class="text-sm rounded-lg border px-3 py-1.5"
                    style="background: var(--surface-primary); border-color: var(--border-light); color: var(--text-primary);">
                <option value=""><?php echo e(t('All users')); ?></option>
                <?php foreach ($filter_users as $fu): ?>
                    <option value="<?php echo $fu['user_id']; ?>" <?php echo $log_user == $fu['user_id'] ? 'selected' : ''; ?>>
                        <?php echo e(trim($fu['first_name'] . ' ' . $fu['last_name'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="fp" class="text-sm rounded-lg border px-3 py-1.5"
                    style="background: var(--surface-primary); border-color: var(--border-light); color: var(--text-primary);">
                <option value=""><?php echo e(t('All pages')); ?></option>
                <?php foreach ($filter_pages_list as $fp): ?>
                    <option value="<?php echo e($fp['page']); ?>" <?php echo $log_page_filter === $fp['page'] ? 'selected' : ''; ?>>
                        <?php echo e($page_labels[$fp['page']] ?? ucfirst($fp['page'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="text-sm font-medium px-3 py-1.5 rounded-lg"
                    style="background: var(--primary); color: #fff;"><?php echo e(t('Filter')); ?></button>
            <?php if ($log_user || $log_page_filter !== ''): ?>
                <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => 'log', 'range' => $range]); ?>"
                   class="text-sm" style="color: var(--text-muted);"><?php echo e(t('Clear')); ?></a>
            <?php endif; ?>

            <span class="text-xs ml-auto" style="color: var(--text-muted);">
                <?php echo number_format($log_total); ?> <?php echo e(t('entries')); ?>
            </span>
        </form>
    </div>

    <!-- Log table -->
    <div class="act-card" style="overflow: hidden; padding: 0;">
        <div style="overflow-x: auto;">
            <table class="act-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('Time')); ?></th>
                        <th><?php echo e(t('User')); ?></th>
                        <th><?php echo e(t('Role')); ?></th>
                        <th><?php echo e(t('Page')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($log_entries)): ?>
                        <tr><td colspan="4" class="text-center py-6" style="color: var(--text-muted);"><?php echo e(t('No entries found.')); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($log_entries as $le): ?>
                            <tr>
                                <td class="whitespace-nowrap" style="color: var(--text-muted); font-size: 0.75rem;">
                                    <?php echo date('d.m. H:i:s', strtotime($le['created_at'])); ?>
                                </td>
                                <td>
                                    <a href="<?php echo url('admin', ['section' => 'activity', 'tab' => 'user', 'uid' => $le['user_id'], 'range' => $range]); ?>"
                                       style="color: var(--text-primary); text-decoration: none; font-size: 0.8125rem;">
                                        <?php echo e(trim(($le['first_name'] ?? '') . ' ' . ($le['last_name'] ?? ''))); ?>
                                    </a>
                                </td>
                                <td><span class="act-role-badge act-role-<?php echo e($le['role'] ?? 'user'); ?>"><?php echo e($le['role'] ?? ''); ?></span></td>
                                <td>
                                    <span class="act-page-pill">
                                        <?php echo get_icon(pv_page_icon($le['page']), 'w-3 h-3'); ?>
                                        <?php echo e(pv_page_label($le['page'], $le['section'], $page_labels, $section_labels)); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($log_pages > 1): ?>
            <div class="flex items-center justify-between px-4 py-3 border-t" style="border-color: var(--border-light);">
                <div class="text-xs" style="color: var(--text-muted);">
                    <?php echo e(t('Page {current} of {total}', ['current' => $page_num, 'total' => $log_pages])); ?>
                </div>
                <div class="flex gap-1">
                    <?php if ($page_num > 1): ?>
                        <a href="<?php echo url('admin', array_merge(['section' => 'activity', 'tab' => 'log', 'range' => $range, 'p' => $page_num - 1],
                            $log_user ? ['uid' => $log_user] : [], $log_page_filter !== '' ? ['fp' => $log_page_filter] : [])); ?>"
                           class="px-3 py-1 text-xs rounded border" style="border-color: var(--border-light); color: var(--text-secondary); text-decoration: none;">
                            ← <?php echo e(t('Previous')); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($page_num < $log_pages): ?>
                        <a href="<?php echo url('admin', array_merge(['section' => 'activity', 'tab' => 'log', 'range' => $range, 'p' => $page_num + 1],
                            $log_user ? ['uid' => $log_user] : [], $log_page_filter !== '' ? ['fp' => $log_page_filter] : [])); ?>"
                           class="px-3 py-1 text-xs rounded border" style="border-color: var(--border-light); color: var(--text-secondary); text-decoration: none;">
                            <?php echo e(t('Next')); ?> →
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php elseif ($tab === 'manage'): ?>
    <!-- ═══════════════ MANAGE ═══════════════ -->
    <?php
    $total_all = (int) (db_fetch_one("SELECT COUNT(*) as c FROM page_views")['c'] ?? 0);
    $oldest = db_fetch_one("SELECT MIN(created_at) as oldest FROM page_views");
    $oldest_date = $oldest['oldest'] ?? null;
    ?>
    <div class="act-card" style="max-width: 500px;">
        <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">
            <?php echo e(t('Data Management')); ?>
        </h3>
        <p class="text-xs mb-4" style="color: var(--text-muted);">
            <?php echo e(t('Total records: {count}', ['count' => number_format($total_all)])); ?>
            <?php if ($oldest_date): ?>
                · <?php echo e(t('Since: {date}', ['date' => date('d.m.Y', strtotime($oldest_date))])); ?>
            <?php endif; ?>
        </p>

        <form method="post" class="space-y-3" onsubmit="return confirm('<?php echo e(t('Are you sure?')); ?>');">
            <?php echo csrf_field(); ?>
            <div class="flex items-center gap-2">
                <button type="submit" name="clear_old" class="text-sm font-medium px-3 py-1.5 rounded-lg border"
                        style="border-color: var(--border-light); color: var(--text-secondary);">
                    <?php echo e(t('Delete older than')); ?>
                </button>
                <select name="days" class="text-sm rounded-lg border px-2 py-1.5"
                        style="background: var(--surface-primary); border-color: var(--border-light); color: var(--text-primary);">
                    <option value="7">7 <?php echo e(t('days')); ?></option>
                    <option value="30">30 <?php echo e(t('days')); ?></option>
                    <option value="90" selected>90 <?php echo e(t('days')); ?></option>
                </select>
            </div>
            <div>
                <button type="submit" name="clear_all" class="text-sm text-red-600 hover:text-red-800">
                    <?php echo get_icon('trash', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                    <?php echo e(t('Clear all activity data')); ?>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
