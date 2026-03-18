<?php
$user = current_user();
$flash = get_flash();
$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$app_version = defined('APP_VERSION') ? APP_VERSION : '';
$in_app_notifications_enabled = user_in_app_notifications_enabled($user);
$in_app_sound_enabled = user_in_app_sound_enabled($user);

// Throttled update check for admins (every 12 hours)
$_foxdesk_update_info = false;
if (is_admin() && file_exists(__DIR__ . '/update-check-functions.php')) {
    require_once __DIR__ . '/update-check-functions.php';
    if (is_update_check_enabled()) {
        $last_check = get_setting('update_check_last_run', '');
        if (!$last_check || (time() - strtotime($last_check)) > UPDATE_CHECK_INTERVAL) {
            @check_for_updates(); // silent, stores result in DB
        }
        $_foxdesk_update_info = get_cached_update_info();
    }
}

// Pseudo-cron: trigger background tasks on page load (WordPress-style)
if (file_exists(__DIR__ . '/pseudo-cron.php')) {
    require_once __DIR__ . '/pseudo-cron.php';
    pseudo_cron_check();
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <script>
        // Apply theme immediately to prevent flash
        (function() {
            const saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title id="page-title"><?php echo e($page_title ?? t('Dashboard')); ?> - <?php echo e($app_name); ?></title>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <script>
        window.csrfToken = <?php echo json_encode(csrf_token()); ?>;
        window.appName = <?php echo json_encode($app_name); ?>;
        window.originalPageTitle = <?php echo json_encode(($page_title ?? t('Dashboard')) . ' - ' . $app_name); ?>;
        window.appNotificationPrefs = {
            inAppEnabled: <?php echo $in_app_notifications_enabled ? 'true' : 'false'; ?>,
            soundEnabled: <?php echo $in_app_sound_enabled ? 'true' : 'false'; ?>
        };
        function sidebarToggleTimer(ticketId, isPaused) {
            var action = isPaused ? 'resume-timer' : 'pause-timer';
            var errLabel = <?php echo json_encode(t('Error')); ?>;
            fetch('index.php?page=api&action=' + action, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': window.csrfToken, 'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'ticket_id=' + ticketId
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.dispatchEvent(new Event('timerStateChanged'));
                } else {
                    if (typeof showAppToast === 'function') showAppToast(data.error || errLabel, 'error');
                    else alert(data.error || errLabel);
                }
            })
            .catch(function() {
                if (typeof showAppToast === 'function') showAppToast(errLabel, 'error');
                else alert(errLabel);
            });
        }
        function sidebarStopTimer(ticketId) {
            var errLabel = <?php echo json_encode(t('Error')); ?>;
            fetch('index.php?page=api&action=stop-timer', {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': window.csrfToken, 'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'ticket_id=' + ticketId
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (typeof showAppToast === 'function') showAppToast(data.message || <?php echo json_encode(t('Timer stopped.')); ?>, 'success');
                    document.dispatchEvent(new Event('timerStateChanged'));
                } else {
                    if (typeof showAppToast === 'function') showAppToast(data.error || errLabel, 'error');
                    else alert(data.error || errLabel);
                }
            })
            .catch(function() {
                if (typeof showAppToast === 'function') showAppToast(errLabel, 'error');
                else alert(errLabel);
            });
        }
    </script>

    <!-- Favicon -->
    <?php
    $custom_favicon = $settings['favicon'] ?? '';
    if ($custom_favicon) {
        $favicon_href = upload_url($custom_favicon);
        $favicon_type = 'image/png';
    } else {
        $favicon_href = 'assets/img/logo.png';
        $favicon_type = 'image/png';
    }
    ?>
    <link rel="icon" id="favicon" type="<?php echo $favicon_type; ?>" href="<?php echo $favicon_href; ?>">
    <!-- PWA -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="<?php echo e($settings['primary_color'] ?? '#3b82f6'); ?>">
    <link rel="apple-touch-icon" href="pwa-icon.php?s=180">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <!-- Active timer favicon (preloaded for JS swap) -->
    <link rel="preload" id="favicon-timer" as="image" type="image/svg+xml" href="data:image/svg+xml,<?php echo rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 32 32\'><rect width=\'32\' height=\'32\' rx=\'6\' fill=\'#22c55e\'/><circle cx=\'16\' cy=\'16\' r=\'10\' fill=\'none\' stroke=\'white\' stroke-width=\'2\'/><path d=\'M16 10 L16 16 L20 16\' fill=\'none\' stroke=\'white\' stroke-width=\'2\' stroke-linecap=\'round\'/></svg>'); ?>">

    <link href="tailwind.min.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">

    <link href="theme.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script defer src="assets/js/app-header.js?v=<?php echo APP_VERSION; ?>"></script>
    <script defer src="assets/js/shortcuts.js?v=<?php echo APP_VERSION; ?>"></script>

    <!-- Flatpickr — lazy-loaded only when date inputs exist on page -->
    <script>
        (function() {
            var fpLoaded = false;
            function loadFlatpickr() {
                if (fpLoaded) return;
                fpLoaded = true;
                var theme = document.documentElement.getAttribute('data-theme');
                var cssHref = theme === 'dark'
                    ? 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/themes/dark.css'
                    : 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css';
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.id = 'flatpickr-theme-css';
                link.href = cssHref;
                document.head.appendChild(link);
                var script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js';
                script.onload = function() { initDatePickers(document); };
                document.head.appendChild(script);
            }
            function initDatePickers(root) {
                if (typeof flatpickr === 'undefined') return;
                var dates = root.querySelectorAll('input[type="date"], .date-picker');
                var datetimes = root.querySelectorAll('input[type="datetime-local"], .datetime-picker');
                if (dates.length) flatpickr(dates, { dateFormat: "Y-m-d", disableMobile: "true" });
                if (datetimes.length) flatpickr(datetimes, { enableTime: true, dateFormat: "Y-m-d\\TH:i", time_24hr: true, disableMobile: "true" });
            }
            window._initDatePickers = initDatePickers;
            document.addEventListener('DOMContentLoaded', function() {
                var hasDateInputs = document.querySelector('input[type="date"], input[type="datetime-local"], .date-picker, .datetime-picker');
                if (hasDateInputs) loadFlatpickr();
                var observer = new MutationObserver(function(mutations) {
                    for (var i = 0; i < mutations.length; i++) {
                        var added = mutations[i].addedNodes;
                        for (var j = 0; j < added.length; j++) {
                            var node = added[j];
                            if (node.nodeType !== 1) continue;
                            var isDateInput = node.matches && (node.matches('input[type="date"]') || node.matches('input[type="datetime-local"]'));
                            var hasDateChild = node.querySelector && node.querySelector('input[type="date"], input[type="datetime-local"]');
                            if (isDateInput || hasDateChild) {
                                if (!fpLoaded) { loadFlatpickr(); return; }
                                initDatePickers(isDateInput ? node.parentNode : node);
                            }
                        }
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });
            });
        })();
    </script>
</head>

<body class="antialiased font-sans" style="background-color: var(--bg-primary); color: var(--text-primary);">
    <!-- Impersonation Warning Banner -->
    <?php if (function_exists('is_impersonating') && is_impersonating()): ?>
        <div class="bg-red-600 text-white px-4 py-2 flex items-center justify-end gap-4 shadow-md relative z-50">
            <div class="flex items-center gap-2">
                <?php echo get_icon('user-secret', 'w-5 h-5 flex-shrink-0'); ?>
                <span class="font-medium whitespace-nowrap">
                    <?php echo t('Viewing as'); ?>
                    <strong class="ml-1"><?php echo e($_SESSION['user_name']); ?></strong>
                </span>
            </div>
            <a href="<?php echo url('impersonate', ['stop' => 'true']); ?>"
                class="text-red-600 px-3 py-1 rounded text-sm font-bold transition shadow-sm flex-shrink-0 whitespace-nowrap" style="background: var(--bg-primary);">
                <?php echo t('Stop'); ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- Skip to content link (a11y) -->
    <a href="#main-content" class="skip-to-content"><?php echo e(t('Skip to content')); ?></a>

    <!-- Mobile Overlay -->
    <div id="sidebar-overlay" class="sidebar-overlay fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
        onclick="toggleSidebar()" role="presentation"></div>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed top-0 left-0 h-full z-50 flex flex-col" role="complementary" aria-label="<?php echo e(t('Sidebar navigation')); ?>">
        <!-- Logo - Fixed at top -->
        <div class="p-3 flex-shrink-0 flex items-center justify-between">
            <a href="<?php echo url('dashboard'); ?>" class="flex items-center space-x-3 group">
                <?php $app_logo = get_setting('app_logo', ''); ?>
                <?php if ($app_logo): ?>
                    <img src="<?php echo e(upload_url($app_logo)); ?>" alt="<?php echo e($app_name); ?>"
                         class="w-10 h-10 rounded-xl object-cover shadow-lg transition-transform group-hover:scale-105">
                <?php else: ?>
                    <img src="assets/img/logo.png" alt="<?php echo e($app_name); ?>"
                         class="w-10 h-10 rounded-xl object-cover shadow-lg transition-transform group-hover:scale-105">
                <?php endif; ?>
                <span class="text-xl font-bold text-gradient"><?php echo e($app_name); ?></span>
            </a>
            <!-- Close button for mobile -->
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl transition-all" style="color: var(--text-muted);"
                aria-label="<?php echo e(t('Close menu')); ?>" aria-controls="sidebar">
                <?php echo get_icon('times', 'text-xl'); ?>
            </button>
        </div>

        <!-- Scrollable Navigation -->
        <div class="sidebar-nav flex-1 px-2.5 pb-1">
            <!-- Main Navigation -->
            <nav class="space-y-1.5" aria-label="<?php echo e(t('Main navigation')); ?>">
                <?php $is_dashboard = ($page ?? '') === 'dashboard'; ?>
                <a href="<?php echo url('dashboard'); ?>"
                    class="nav-item <?php echo $is_dashboard ? 'active' : ''; ?>"
                    <?php echo $is_dashboard ? 'aria-current="page"' : ''; ?>>
                    <?php echo get_icon('home', 'nav-item__icon'); ?>
                    <span><?php echo e(t('Dashboard')); ?></span>
                </a>

                <?php $is_notifications_page = ($page ?? '') === 'notifications'; ?>
                <a href="<?php echo url('notifications'); ?>"
                    class="nav-item <?php echo $is_notifications_page ? 'active' : ''; ?>"
                    <?php echo $is_notifications_page ? 'aria-current="page"' : ''; ?>>
                    <?php echo get_icon('bell', 'nav-item__icon'); ?>
                    <span><?php echo e(t('Notifications')); ?></span>
                    <span id="sidebar-notif-badge" class="notif-sidebar-badge hidden">0</span>
                </a>

                <?php $is_tickets = ($page ?? '') === 'tickets' && ($_GET['archived'] ?? '') !== '1'; ?>
                <a href="<?php echo url('tickets'); ?>"
                    class="nav-item <?php echo $is_tickets ? 'active' : ''; ?>"
                    <?php echo $is_tickets ? 'aria-current="page"' : ''; ?>>
                    <?php echo get_icon('ticket-alt', 'nav-item__icon'); ?>
                    <span><?php echo e(t('All tickets')); ?></span>
                </a>

                <?php $is_new_ticket = ($page ?? '') === 'new-ticket' && !isset($_GET['auto_timer']); ?>
                <?php $has_quick_start = (is_admin() || is_agent()) && function_exists('ticket_time_table_exists') && ticket_time_table_exists(); ?>
                <?php if ($has_quick_start): ?><div class="nav-item-group"><?php endif; ?>
                <a href="<?php echo url('new-ticket'); ?>"
                    class="nav-item nav-item--cta <?php echo $is_new_ticket ? 'active' : ''; ?>"
                    <?php echo $is_new_ticket ? 'aria-current="page"' : ''; ?>>
                    <?php echo get_icon('plus', 'nav-item__icon'); ?>
                    <span><?php echo e(t('New ticket')); ?></span>
                </a>
                <?php if ($has_quick_start): ?>
                <div class="nav-item-flyout">
                    <a href="<?php echo url('new-ticket', ['auto_timer' => '1']); ?>" class="nav-item-flyout__item">
                        <?php echo get_icon('play', 'nav-item__icon'); ?>
                        <span><?php echo e(t('New ticket + timer')); ?></span>
                    </a>
                </div>
                </div>
                <?php endif; ?>
            </nav>

            <!-- Active timers (staff only) -->
            <?php if (is_admin() || is_agent()): ?>
                <?php
                $sidebar_timers = [];
                if (function_exists('ticket_time_table_exists') && ticket_time_table_exists() && function_exists('get_user_all_active_timers')) {
                    $sidebar_timers = get_user_all_active_timers($user['id']);
                }
                ?>
                <div id="sidebar-timers" class="mt-3 pt-3 border-t" style="border-color: var(--border-light);<?php echo empty($sidebar_timers) ? ' display:none;' : ''; ?>">
                    <p class="px-3 mb-1.5 text-[10px] font-semibold uppercase tracking-wider flex items-center gap-1.5" style="color: var(--text-muted);">
                        <span class="sidebar-timer-dot"></span>
                        <?php echo e(t('Active Timers')); ?>
                        <span class="sidebar-timer-count"><?php echo count($sidebar_timers); ?></span>
                    </p>
                    <div id="sidebar-timers-list" class="space-y-0.5">
                        <?php foreach ($sidebar_timers as $stimer):
                            $st_paused = is_timer_paused($stimer);
                            $st_elapsed = calculate_timer_elapsed($stimer);
                            $st_minutes = max(0, (int)floor($st_elapsed / 60));
                            $st_url = !empty($stimer['ticket_hash'])
                                ? url('ticket', ['t' => $stimer['ticket_hash']])
                                : url('ticket', ['id' => $stimer['ticket_id']]);
                        ?>
                        <div class="flex items-center group">
                            <a href="<?php echo $st_url; ?>"
                               class="sidebar-timer-item flex-1 flex items-center gap-2 px-3 py-1.5 rounded-lg transition-all sidebar-hover min-w-0"
                               title="<?php echo e($stimer['ticket_title']); ?>">
                                <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full <?php echo $st_paused ? 'bg-yellow-400' : 'sidebar-timer-pulse'; ?>"></span>
                                <span class="flex-1 min-w-0 text-xs truncate" style="color: var(--text-secondary);">
                                    <?php echo e($stimer['ticket_title']); ?>
                                </span>
                                <span class="flex-shrink-0 text-[10px] font-mono font-medium <?php echo $st_paused ? '' : 'timer-display'; ?>"
                                      style="color: <?php echo $st_paused ? 'var(--corp-warning, #f59e0b)' : 'var(--corp-success, #10b981)'; ?>;"
                                      <?php if (!$st_paused): ?>
                                      data-started="<?php echo strtotime($stimer['started_at']); ?>"
                                      data-paused-seconds="<?php echo (int)($stimer['paused_seconds'] ?? 0); ?>"
                                      <?php endif; ?>
                                ><?php echo $st_paused ? e(t('Paused')) : e(format_duration_minutes($st_minutes)); ?></span>
                            </a>
                            <button onclick="event.stopPropagation(); sidebarToggleTimer(<?php echo (int)$stimer['ticket_id']; ?>, <?php echo $st_paused ? 'true' : 'false'; ?>)"
                                    class="flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity"
                                    style="color: var(--text-muted);"
                                    title="<?php echo $st_paused ? e(t('Resume')) : e(t('Pause')); ?>">
                                <?php echo $st_paused ? get_icon('play', 'w-3 h-3') : get_icon('pause', 'w-3 h-3'); ?>
                            </button>
                            <button onclick="event.stopPropagation(); sidebarStopTimer(<?php echo (int)$stimer['ticket_id']; ?>)"
                                    class="flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity"
                                    style="color: var(--text-muted);"
                                    title="<?php echo e(t('Stop timer')); ?>">
                                <?php echo get_icon('stop', 'w-3 h-3'); ?>
                            </button>
                            <button onclick="event.stopPropagation(); if(typeof cancelTicket==='function') cancelTicket(<?php echo (int)$stimer['ticket_id']; ?>); else if(confirm('<?php echo e(t('Cancel ticket? The ticket will be deleted.')); ?>')) fetch('index.php?page=api&action=cancel-ticket',{method:'POST',headers:{'X-CSRF-TOKEN':window.csrfToken,'Content-Type':'application/x-www-form-urlencoded'},body:'ticket_id=<?php echo (int)$stimer['ticket_id']; ?>'}).then(function(r){return r.json()}).then(function(d){if(d.success)location.reload();else alert(d.error||'Error')});"
                                    class="flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity hover:text-red-500"
                                    style="color: var(--text-muted);"
                                    title="<?php echo e(t('Cancel ticket')); ?>">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Help Button -->
        <div class="px-2.5 pb-0.5">
            <button onclick="toggleHelpPanel()" id="help-panel-btn"
                class="w-full flex items-center gap-3 p-2 rounded-xl transition-all sidebar-hover"
                style="color: var(--text-muted);"
                title="<?php echo e(t('Help')); ?> (?)">
                <?php echo get_icon('question-circle', 'nav-item__icon'); ?>
                <span class="text-sm"><?php echo e(t('Help')); ?></span>
            </button>
        </div>

        <!-- Sidebar Footer with User Menu -->
        <div class="px-2.5 pt-1 pb-0.5 mt-auto relative"
            style="padding-bottom: max(0.125rem, env(safe-area-inset-bottom));">
            <!-- User Profile Button (clickable for dropdown) -->
            <button onclick="toggleSidebarUserMenu()" id="sidebar-user-btn"
                class="w-full flex items-center gap-3 p-1.5 rounded-xl transition-all cursor-pointer sidebar-hover"
                aria-expanded="false" aria-controls="sidebar-user-menu" aria-haspopup="true">
                <?php if (!empty($user['avatar']) && is_safe_avatar_url($user['avatar'])): ?>
                    <img src="<?php echo e(upload_url($user['avatar'])); ?>" alt="Avatar"
                        class="w-10 h-10 rounded-full object-cover ring-2 ring-blue-500/20 flex-shrink-0"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="avatar avatar-md flex-shrink-0" style="border-radius: 12px; display: none;">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                    </div>
                <?php else: ?>
                    <div class="avatar avatar-md flex-shrink-0" style="border-radius: 12px;">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0 text-left">
                    <p class="font-medium text-sm truncate" style="color: var(--text-primary);">
                        <?php echo e($user['first_name'] . ' ' . $user['last_name']); ?>
                    </p>
                    <p class="text-xs truncate" style="color: var(--text-muted);"><?php echo e($user['email']); ?></p>
                </div>
                <span class="sidebar-user-chevron" style="color: var(--text-muted);"><?php echo get_icon('chevron-up', 'w-4 h-4 flex-shrink-0 transition-transform'); ?></span>
            </button>

            <!-- User Dropdown Menu (appears above) -->
            <div id="sidebar-user-menu" class="hidden absolute bottom-full left-4 right-4 mb-2 py-2 rounded-xl shadow-lg z-50"
                role="menu" aria-label="<?php echo e(t('User menu')); ?>"
                style="background: var(--surface-primary); border: 1px solid var(--border-light);">

                <!-- Profile -->
                <a href="<?php echo url('profile'); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('user', 'w-4 h-4'); ?>
                    <span><?php echo e(t('My profile')); ?></span>
                </a>

                <!-- Dark Mode Toggle -->
                <button onclick="toggleTheme(); event.stopPropagation();" role="menuitem"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <span class="theme-icon-light"><?php echo get_icon('sun', 'w-4 h-4'); ?></span>
                    <span class="theme-icon-dark hidden"><?php echo get_icon('moon', 'w-4 h-4'); ?></span>
                    <span class="theme-text-light"><?php echo e(t('Dark mode')); ?></span>
                    <span class="theme-text-dark hidden"><?php echo e(t('Light mode')); ?></span>
                </button>

                <?php if (is_admin() || is_agent()): ?>
                <div class="border-t my-2" role="separator" style="border-color: var(--border-light);"></div>

                <a href="<?php echo url('admin', ['section' => 'reports']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('chart-bar', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Time report')); ?></span>
                </a>

                <?php if (is_admin()): ?>
                <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('file-invoice', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Client Reports')); ?></span>
                </a>
                <?php endif; ?>

                <?php if (is_admin()): ?>
                <a href="<?php echo url('admin', ['section' => 'users']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('users', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Users')); ?></span>
                </a>
                <a href="<?php echo url('admin', ['section' => 'organizations']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('building', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Organizations')); ?></span>
                </a>
                <a href="<?php echo url('admin', ['section' => 'settings']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('cog', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Settings')); ?></span>
                </a>
                <a href="<?php echo url('admin', ['section' => 'recurring-tasks']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('sync-alt', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Recurring tasks')); ?></span>
                </a>
                <a href="<?php echo url('admin', ['section' => 'activity']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('chart-line', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Activity')); ?></span>
                </a>
                <a href="<?php echo url('tickets', ['archived' => '1']); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors sidebar-hover"
                    style="color: var(--text-secondary);">
                    <?php echo get_icon('archive', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Archive')); ?></span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <div class="border-t my-2" role="separator" style="border-color: var(--border-light);"></div>

                <!-- Logout -->
                <a href="<?php echo url('logout'); ?>" role="menuitem"
                    class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 transition-colors hover:bg-red-50">
                    <?php echo get_icon('sign-out-alt', 'w-4 h-4'); ?>
                    <span><?php echo e(t('Sign out')); ?></span>
                </a>
            </div>
        </div>
    </aside>

    <?php include __DIR__ . '/help-panel.php'; ?>

    <!-- Main Content -->
    <main id="main-content" class="main-content min-h-screen">
        <!-- Mobile Header -->
        <header class="mobile-header bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-4 py-3 flex items-center justify-between sticky top-0 z-30">
            <button onclick="toggleSidebar()" id="mobile-menu-btn" class="p-2 rounded-xl transition-all sidebar-hover" style="color: var(--text-secondary);" aria-label="<?php echo e(t('Open menu')); ?>" aria-expanded="false" aria-controls="sidebar">
                <?php echo get_icon('bars', 'text-xl'); ?>
            </button>
            <h1 class="text-lg font-semibold truncate flex-1 mx-4" style="color: var(--text-primary);">
                <?php echo e($page_title ?? t('Dashboard')); ?>
            </h1>
            <div class="flex items-center space-x-2">
                <!-- Notification Bell (Mobile) -->
                <div class="relative">
                    <button onclick="toggleNotificationPanel()" id="mobile-notif-btn"
                        class="p-2 rounded-xl transition-all sidebar-hover relative" style="color: var(--text-secondary);"
                        aria-label="<?php echo e(t('Notifications')); ?>" aria-expanded="false">
                        <?php echo get_icon('bell', 'w-5 h-5'); ?>
                        <span id="notif-badge-mobile" class="notif-badge hidden">0</span>
                    </button>
                </div>

                <a href="<?php echo url('tickets'); ?>" class="p-2 rounded-xl transition-all sidebar-hover" style="color: var(--text-secondary);"
                    aria-label="<?php echo e(t('Search tickets')); ?>">
                    <?php echo get_icon('search'); ?>
                </a>

                <!-- Mobile User Dropdown -->
                <div class="relative">
                    <button onclick="toggleUserDropdownMobile()" id="mobile-user-btn"
                        class="p-1 rounded-xl transition-all sidebar-hover" style="color: var(--text-secondary);"
                        aria-label="<?php echo e(t('User menu')); ?>" aria-expanded="false" aria-controls="user-dropdown-mobile" aria-haspopup="true">
                        <?php if (!empty($user['avatar']) && is_safe_avatar_url($user['avatar'])): ?>
                            <img src="<?php echo e(upload_url($user['avatar'])); ?>" alt="Avatar"
                                class="w-8 h-8 rounded-full object-cover ring-2 ring-blue-500/20"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="avatar avatar-sm" style="border-radius: 10px; display: none;">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                            </div>
                        <?php else: ?>
                            <div class="avatar avatar-sm" style="border-radius: 10px;">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </button>

                    <!-- Mobile Dropdown Menu -->
                    <div id="user-dropdown-mobile" role="menu" aria-label="<?php echo e(t('User menu')); ?>"
                        class="hidden absolute right-0 mt-2 w-52 glass py-2 z-50 animate-scale-in rounded-xl">
                        <div class="px-4 py-3 border-b" style="border-color: var(--border-light);">
                            <p class="font-semibold truncate" style="color: var(--text-primary);">
                                <?php echo e($user['first_name'] . ' ' . $user['last_name']); ?>
                            </p>
                            <p class="text-xs truncate" style="color: var(--text-muted);"><?php echo e($user['email']); ?></p>
                        </div>
                        <a href="<?php echo url('profile'); ?>" role="menuitem"
                            class="flex items-center space-x-3 px-4 py-2.5 text-sm transition-colors sidebar-hover" style="color: var(--text-secondary);">
                            <?php echo get_icon('user', 'w-4 opacity-70'); ?>
                            <span><?php echo e(t('My profile')); ?></span>
                        </a>
                        <a href="<?php echo url('logout'); ?>" role="menuitem"
                            class="flex items-center space-x-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
                            <?php echo get_icon('sign-out-alt', 'w-4 opacity-70'); ?>
                            <span><?php echo e(t('Sign out')); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="desktop-header bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-6 py-3 flex items-center justify-between sticky top-0 z-30 w-full">
            <h1 class="text-lg font-semibold" style="color: var(--text-primary);"><?php echo e($page_title ?? t('Dashboard')); ?></h1>
            <div class="flex items-center space-x-4">
                <form action="<?php echo url('tickets'); ?>" method="get" class="relative">
                    <input type="hidden" name="page" value="tickets">
                    <input type="text" name="search" id="header-search" placeholder="<?php echo e(t('Search...')); ?>"
                        class="form-input pr-4 header-search-input" style="width: clamp(200px, 25vw, 320px); border-radius: 10px; padding-left: 2.25rem;">
                    <span class="absolute top-1/2 transform -translate-y-1/2" style="left: 1rem; color: var(--text-muted); pointer-events: none;">
                        <?php echo get_icon('search', 'w-4 h-4'); ?>
                    </span>
                </form>

                <!-- Notification Bell (Desktop) -->
                <div class="relative">
                    <button onclick="toggleNotificationPanel()" id="desktop-notif-btn"
                        class="p-2 rounded-xl transition-all sidebar-hover relative" style="color: var(--text-secondary);"
                        aria-label="<?php echo e(t('Notifications')); ?>" aria-expanded="false">
                        <?php echo get_icon('bell', 'w-5 h-5'); ?>
                        <span id="notif-badge-desktop" class="notif-badge hidden">0</span>
                    </button>
                </div>

                <style>
                    [data-theme="dark"] .header-search-input,
                    [data-theme="dark"] #header-search {
                        background: #334155 !important;
                        color: #f1f5f9 !important;
                        border-color: #475569 !important;
                    }
                    [data-theme="dark"] .header-search-input::placeholder,
                    [data-theme="dark"] #header-search::placeholder {
                        color: #cbd5e1 !important;
                        opacity: 1 !important;
                    }
                    [data-theme="dark"] .header-search-input::-webkit-input-placeholder,
                    [data-theme="dark"] #header-search::-webkit-input-placeholder {
                        color: #cbd5e1 !important;
                        opacity: 1 !important;
                    }
                </style>

            </div>
        </header>

        <!-- Notification Panel (shared between mobile & desktop) -->
        <div id="notification-panel" class="hidden fixed z-50 glass rounded-xl shadow-2xl animate-scale-in"
            style="width: min(396px, calc(100vw - 2rem)); max-height: 480px; right: 1rem; top: 3.5rem;">
            <!-- Header -->
            <div class="flex items-center justify-between px-4 py-3 border-b" style="border-color: var(--border-light);">
                <h3 class="font-semibold text-sm" style="color: var(--text-primary);"><?php echo e(t('Notifications')); ?></h3>
                <div class="flex items-center gap-3">
                    <button onclick="togglePushNotifications()" id="push-toggle-btn"
                        class="p-1 rounded transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                        title="<?php echo e(t('Push notifications')); ?>" style="color: var(--text-muted); display: none;">
                        <span class="push-icon-on" style="display:none"><?php echo get_icon('bell', 'w-4 h-4'); ?></span>
                        <span class="push-icon-off"><?php echo get_icon('bell-slash', 'w-4 h-4'); ?></span>
                    </button>
                    <button onclick="toggleNotifSound()" id="notif-sound-toggle"
                        class="p-1 rounded transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
                        title="<?php echo e(t('Toggle sound')); ?>" style="color: var(--text-muted);">
                        <?php echo get_icon('volume-up', 'w-4 h-4 notif-sound-on'); ?>
                        <span class="notif-sound-off hidden" style="display:none"><?php echo get_icon('volume-mute', 'w-4 h-4'); ?></span>
                    </button>
                    <button onclick="markAllNotificationsRead()" id="notif-mark-all-btn"
                        class="text-xs font-medium hover:underline" style="color: var(--accent-primary);">
                        <?php echo e(t('Mark all as read')); ?>
                    </button>
                </div>
            </div>
            <!-- Content -->
            <div id="notification-list" class="overflow-y-auto" style="max-height: 420px;">
                <div id="notif-loading" class="flex items-center justify-center py-8">
                    <div class="w-5 h-5 border-2 rounded-full animate-spin" style="border-color: var(--border-light); border-top-color: var(--accent-primary);"></div>
                </div>
                <div id="notif-empty" class="hidden text-center py-8 px-4">
                    <div style="color: var(--text-muted);" class="mb-3"><?php echo get_icon('bell', 'w-10 h-10 mx-auto opacity-20'); ?></div>
                    <p class="text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('All caught up!')); ?></p>
                    <p class="text-xs" style="color: var(--text-muted);"><?php echo e(t('No new notifications. Check back later.')); ?></p>
                </div>
                <div id="notif-items"></div>
            </div>
        </div>

        <!-- Flash / Toast Notifications -->
        <div id="app-toast-stack" class="toast-stack" aria-live="polite" aria-atomic="true">
            <?php if ($flash && $in_app_notifications_enabled): ?>
                <?php include BASE_PATH . '/includes/components/flash.php'; ?>
            <?php endif; ?>
        </div>
        <?php if ($flash && !$in_app_notifications_enabled): ?>
            <div class="mx-4 lg:mx-8 mt-4 flash-inline-wrapper">
                <?php include BASE_PATH . '/includes/components/flash.php'; ?>
            </div>
        <?php endif; ?>

        <?php if ($_foxdesk_update_info && !is_update_dismissed($_foxdesk_update_info['version'])): ?>
        <!-- Update Available Banner -->
        <div class="foxdesk-update-bar" id="foxdeskUpdateBar">
            <div class="foxdesk-update-bar-inner">
                <div class="foxdesk-update-bar-content">
                    <span class="foxdesk-update-bar-icon"><?php echo get_icon('arrow-circle-up'); ?></span>
                    <span class="foxdesk-update-bar-text">
                        <?php echo e(t('FoxDesk {version} is available!', ['version' => $_foxdesk_update_info['version']])); ?>
                    </span>
                    <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'system']); ?>#updates" class="foxdesk-update-bar-btn">
                        <?php echo e(t('Update now')); ?>
                    </a>
                </div>
                <button type="button" class="foxdesk-update-bar-dismiss" onclick="dismissFoxDeskUpdate('<?php echo e($_foxdesk_update_info['version']); ?>')" title="<?php echo e(t('Dismiss')); ?>">&times;</button>
            </div>
        </div>
        <script>
        function dismissFoxDeskUpdate(version) {
            var bar = document.getElementById('foxdeskUpdateBar');
            if (bar) bar.style.display = 'none';
            fetch('api.php?action=dismiss-update-notice', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken},
                body: JSON.stringify({version: version})
            }).catch(function() {});
        }
        </script>
        <?php endif; ?>

        <!-- Notification Center CSS -->
        <style>
            .notif-badge {
                position: absolute;
                top: 2px; right: 2px;
                min-width: 18px; height: 18px;
                padding: 0 5px;
                font-size: 11px; font-weight: 600; line-height: 18px;
                text-align: center;
                color: #fff;
                background: #ef4444;
                border-radius: 9px;
                pointer-events: none;
            }
            .notif-badge.pulse {
                animation: notif-pulse 2s ease-in-out infinite;
                box-shadow: 0 0 0 2px var(--bg-primary, #fff);
            }
            .notif-sidebar-badge {
                margin-left: auto;
                min-width: 18px; height: 18px;
                padding: 0 5px;
                font-size: 11px; font-weight: 600; line-height: 18px;
                text-align: center;
                color: #fff;
                background: #ef4444;
                border-radius: 9px;
            }
            @keyframes notif-pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.15); }
            }
            .notif-item {
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
                padding: 0.75rem 1rem;
                cursor: pointer;
                transition: background 0.15s;
                border-left: 3px solid transparent;
            }
            .notif-item:hover {
                background: var(--surface-hover, rgba(0,0,0,0.03));
            }
            [data-theme="dark"] .notif-item:hover {
                background: rgba(255,255,255,0.05);
            }
            .notif-item.unread {
                border-left-color: var(--accent-primary, #3b82f6);
                background: rgba(59, 130, 246, 0.04);
            }
            [data-theme="dark"] .notif-item.unread {
                background: rgba(59, 130, 246, 0.08);
            }
            .notif-item .notif-avatar {
                width: 32px; height: 32px;
                border-radius: 8px;
                flex-shrink: 0;
                display: flex; align-items: center; justify-content: center;
                font-size: 13px; font-weight: 600;
                color: #fff;
                background: var(--text-muted, #9ca3af);
                overflow: hidden;
            }
            .notif-item .notif-avatar img {
                width: 100%; height: 100%; object-fit: cover;
            }
            .notif-item .notif-body {
                flex: 1; min-width: 0;
            }
            .notif-item .notif-text {
                font-size: 13px; line-height: 1.4;
                color: var(--text-secondary);
            }
            .notif-item.unread .notif-text {
                color: var(--text-primary);
                font-weight: 500;
            }
            .notif-item .notif-time {
                font-size: 11px;
                color: var(--text-muted);
                margin-top: 2px;
            }
            .notif-item .notif-mark-read {
                flex-shrink: 0;
                width: 24px; height: 24px;
                border-radius: 4px;
                display: none;
                align-items: center; justify-content: center;
                cursor: pointer;
                color: var(--text-muted);
                background: transparent;
                border: none;
                padding: 0;
                transition: color 0.15s, background 0.15s;
                margin-top: 4px;
            }
            .notif-item .notif-mark-read:hover {
                color: var(--accent-primary, #3b82f6);
                background: rgba(59, 130, 246, 0.1);
            }
            .notif-item.unread .notif-mark-read { display: flex; }
            .notif-item.unread:hover .notif-mark-read { display: flex; }
            @media (max-width: 768px) {
                .notif-item.unread .notif-mark-read { display: flex; }
            }
            .notif-item.unread .notif-time::before {
                content: '';
                display: inline-block;
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--accent-primary, #3b82f6);
                margin-right: 4px;
                vertical-align: middle;
            }
            .notif-group-header {
                padding: 0.5rem 1rem 0.25rem;
                font-size: 11px; font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--text-muted);
            }
            #notification-list::-webkit-scrollbar { width: 4px; }
            #notification-list::-webkit-scrollbar-track { background: transparent; }
            #notification-list::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 2px; }
        </style>

        <!-- Notification Center JS -->
        <script>
        (function() {
            var _notifPanel = null;
            var _notifLoaded = false;
            var _notifOpen = false;
            var _pollInterval = null;
            var _lastCount = 0;
            var _firstPoll = true;

            function esc(s) {
                var d = document.createElement('div');
                d.textContent = s || '';
                return d.innerHTML;
            }

            function avatarColor(name, hue) {
                if (typeof hue === 'number') return 'hsl(' + hue + ', 55%, 60%)';
                var h = 0;
                for (var i = 0; i < (name||'').length; i++) h = (name.charCodeAt(i) + ((h << 5) - h)) | 0;
                return 'hsl(' + (Math.abs(h) % 360) + ', 55%, 60%)';
            }

            function avatarUrl(path) {
                if (!path) return '';
                if (path.indexOf('data:') === 0 || path.indexOf('http') === 0) return path;
                // Use image.php proxy — extract filename from e.g. "uploads/avatar_emily.png"
                var filename = path.split('/').pop().split('?')[0];
                return 'image.php?f=' + encodeURIComponent(filename);
            }

            function avatarHtml(n) {
                var hue = typeof n.avatar_hue === 'number' ? n.avatar_hue : undefined;
                if (n.actor_avatar) {
                    var src = avatarUrl(n.actor_avatar);
                    var fallbackInit = esc((n.actor_name||'?').charAt(0).toUpperCase());
                    return '<div class="notif-avatar" style="background:' + avatarColor(n.actor_name, hue) + '"><img src="' + esc(src) + '" onerror="this.style.display=\'none\';this.parentElement.textContent=\'' + fallbackInit + '\'"></div>';
                }
                var init = (n.actor_name || '?').charAt(0).toUpperCase();
                return '<div class="notif-avatar" style="background:' + avatarColor(n.actor_name, hue) + '">' + esc(init) + '</div>';
            }

            function renderItem(n) {
                var cls = 'notif-item' + (n.is_read ? '' : ' unread');
                var ticketUrl = n.ticket_id ? ('index.php?page=ticket&id=' + n.ticket_id) : '#';
                var markBtn = n.is_read ? '' : '<button class="notif-mark-read" onclick="markOneRead(event,' + n.id + ')" title="' + esc(<?php echo json_encode(t('Mark as read')); ?>) + '">&#10003;</button>';
                return '<div class="' + cls + '" data-id="' + n.id + '" onclick="notifItemClick(event,' + n.id + ',' + (n.ticket_id||0) + ')">'
                    + avatarHtml(n)
                    + '<div class="notif-body">'
                    + '<div class="notif-text">' + esc(n.text) + '</div>'
                    + '<div class="notif-time">' + esc(n.time_ago) + '</div>'
                    + '</div>'
                    + markBtn
                    + '</div>';
            }

            function renderGroup(label, items) {
                if (!items || items.length === 0) return '';
                var html = '<div class="notif-group-header">' + esc(label) + '</div>';
                for (var i = 0; i < items.length; i++) {
                    var tg = items[i];
                    if (tg.primary) {
                        // Show "mark group read" if 2+ unread items for same ticket
                        var unreadCount = (tg.primary && !tg.primary.is_read ? 1 : 0);
                        if (tg.others) {
                            for (var k = 0; k < tg.others.length; k++) {
                                if (!tg.others[k].is_read) unreadCount++;
                            }
                        }
                        if (unreadCount >= 2 && tg.ticket_id) {
                            html += '<div class="flex items-center justify-end px-3 py-1" style="margin-top: -2px;">'
                                + '<button class="text-xs font-medium hover:underline" style="color: var(--accent-primary);" onclick="markTicketRead(event,' + tg.ticket_id + ')">'
                                + esc(<?php echo json_encode(t('Mark all for this ticket as read')); ?>)
                                + '</button></div>';
                        }
                        html += renderItem(tg.primary);
                        if (tg.others) {
                            for (var j = 0; j < tg.others.length; j++) html += renderItem(tg.others[j]);
                        }
                    } else {
                        html += renderItem(tg);
                    }
                }
                return html;
            }

            window.toggleNotificationPanel = function() {
                _notifPanel = document.getElementById('notification-panel');
                if (!_notifPanel) return;

                _notifOpen = !_notifOpen;
                if (_notifOpen) {
                    _notifPanel.classList.remove('hidden');
                    // Position for mobile vs desktop
                    if (window.innerWidth < 1024) {
                        _notifPanel.style.top = '3.5rem';
                        _notifPanel.style.right = '0.5rem';
                    } else {
                        _notifPanel.style.top = '3.5rem';
                        _notifPanel.style.right = '1rem';
                    }
                    if (!_notifLoaded) loadNotifications();
                } else {
                    _notifPanel.classList.add('hidden');
                }
            };

            window.notifItemClick = function(event, id, ticketId) {
                // Don't navigate if clicking the mark-read button
                if (event.target.closest('.notif-mark-read')) return;
                // Mark as read
                var el = event.currentTarget;
                if (el && el.classList.contains('unread')) {
                    el.classList.remove('unread');
                    fetch('index.php?page=api&action=mark-notification-read', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken},
                        body: 'notification_id=' + id + '&csrf_token=' + encodeURIComponent(window.csrfToken)
                    }).catch(function(){});
                    // Sync dashboard widget
                    var dbEl = document.getElementById('dbnotif-' + id);
                    if (dbEl) dbEl.classList.remove('unread');
                }
                // Navigate to ticket (nid for server-side mark-read)
                if (ticketId) {
                    window.location.href = 'index.php?page=ticket&id=' + ticketId + '&nid=' + id;
                }
            };

            window.markOneRead = function(event, id) {
                event.stopPropagation();
                var item = event.target.closest('.notif-item');
                if (item) {
                    item.classList.remove('unread');
                    // Hide the button itself
                    var btn = item.querySelector('.notif-mark-read');
                    if (btn) btn.style.display = 'none';
                }
                fetch('index.php?page=api&action=mark-notification-read', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken},
                    body: 'notification_id=' + id + '&csrf_token=' + encodeURIComponent(window.csrfToken)
                }).then(function() {
                    // Decrement badge
                    if (_lastCount > 0) updateBadge(_lastCount - 1);
                }).catch(function(){});
                // Sync dashboard widget
                var dbEl = document.getElementById('dbnotif-' + id);
                if (dbEl) dbEl.classList.remove('unread');
            };

            window.markTicketRead = function(event, ticketId) {
                event.stopPropagation();
                // Find all unread items in the panel and mark them read visually
                var items = document.querySelectorAll('#notif-items .notif-item.unread');
                var count = 0;
                items.forEach(function(el) {
                    var onclick = el.getAttribute('onclick') || '';
                    // Match items that belong to this ticket
                    if (onclick.indexOf(',' + ticketId + ')') !== -1) {
                        el.classList.remove('unread');
                        var btn = el.querySelector('.notif-mark-read');
                        if (btn) btn.style.display = 'none';
                        count++;
                    }
                });
                // Hide the "mark all for this ticket" button
                var btn = event.target.closest('div');
                if (btn) btn.style.display = 'none';
                // API call
                fetch('index.php?page=api&action=mark-ticket-notifications-read', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken},
                    body: 'ticket_id=' + ticketId + '&csrf_token=' + encodeURIComponent(window.csrfToken)
                }).then(function() {
                    if (_lastCount > 0) updateBadge(Math.max(0, _lastCount - count));
                }).catch(function(){});
            };

            window.markAllNotificationsRead = function() {
                fetch('index.php?page=api&action=mark-all-notifications-read', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken},
                    body: 'csrf_token=' + encodeURIComponent(window.csrfToken)
                }).then(function() {
                    // Visual update — header dropdown
                    document.querySelectorAll('.notif-item.unread').forEach(function(el) { el.classList.remove('unread'); });
                    updateBadge(0);
                    // Sync dashboard notifications widget (if on dashboard page)
                    document.querySelectorAll('.dbnotif-card.unread').forEach(function(el) { el.classList.remove('unread'); });
                    document.querySelectorAll('.dbnotif-child-card.unread').forEach(function(el) { el.classList.remove('unread'); });
                    var dbBadge = document.getElementById('dbnotif-badge');
                    if (dbBadge) dbBadge.style.display = 'none';
                    // Sync notifications page (if on notifications page)
                    document.querySelectorAll('.notif-card.unread').forEach(function(el) { el.classList.remove('unread'); });
                    document.querySelectorAll('.notif-child-card.unread').forEach(function(el) { el.classList.remove('unread'); });
                    var pageBadge = document.querySelector('.notif-page-badge');
                    if (pageBadge) pageBadge.remove();
                }).catch(function(){});
            };

            function loadNotifications() {
                var loading = document.getElementById('notif-loading');
                var empty = document.getElementById('notif-empty');
                var items = document.getElementById('notif-items');
                if (loading) loading.classList.remove('hidden');
                if (empty) empty.classList.add('hidden');
                if (items) items.innerHTML = '';

                fetch('index.php?page=api&action=get-notifications&limit=20')
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (loading) loading.classList.add('hidden');
                        if (!res.success || !res.groups) { if (empty) empty.classList.remove('hidden'); return; }

                        var g = res.groups || {};
                        var groupHtml = '';
                        groupHtml += renderGroup(<?php echo json_encode(t('Today')); ?>, g.today);
                        groupHtml += renderGroup(<?php echo json_encode(t('Yesterday')); ?>, g.yesterday);
                        groupHtml += renderGroup(<?php echo json_encode(t('Earlier')); ?>, g.earlier);

                        if (!groupHtml) {
                            if (empty) empty.classList.remove('hidden');
                        } else {
                            if (items) {
                                items.textContent = '';
                                // Insert notification groups
                                var wrapper = document.createElement('div');
                                wrapper.innerHTML = groupHtml;
                                while (wrapper.firstChild) items.appendChild(wrapper.firstChild);
                                // "View all" link
                                var footer = document.createElement('div');
                                footer.className = 'text-center py-3 border-t';
                                footer.style.borderColor = 'var(--border-light)';
                                var link = document.createElement('a');
                                link.href = 'index.php?page=notifications';
                                link.className = 'text-xs font-medium hover:underline';
                                link.style.color = 'var(--accent-primary)';
                                link.textContent = <?php echo json_encode(t('View all notifications')); ?>;
                                footer.appendChild(link);
                                items.appendChild(footer);
                            }
                        }

                        updateBadge(res.unread_count || 0);
                        _notifLoaded = true;
                    })
                    .catch(function() {
                        if (loading) loading.classList.add('hidden');
                        if (empty) empty.classList.remove('hidden');
                    });
            }

            function updateBadge(count) {
                _lastCount = count;
                ['notif-badge-mobile', 'notif-badge-desktop', 'sidebar-notif-badge'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    if (count > 0) {
                        el.textContent = count > 99 ? '99+' : count;
                        el.classList.remove('hidden');
                        if (id !== 'sidebar-notif-badge') el.classList.add('pulse');
                    } else {
                        el.classList.add('hidden');
                        if (id !== 'sidebar-notif-badge') el.classList.remove('pulse');
                    }
                });
            }

            function pollCount() {
                fetch('index.php?page=api&action=get-notification-count')
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            var newCount = res.unread_count || 0;
                            if (newCount > _lastCount && newCount > 0 && !_firstPoll) {
                                // New notifications arrived (skip first poll to avoid sound on page load)
                                if (window.appNotificationPrefs && window.appNotificationPrefs.soundEnabled) {
                                    playNotifSound();
                                }
                                _notifLoaded = false; // Force reload on next open
                            }
                            _firstPoll = false;
                            updateBadge(newCount);
                        }
                    })
                    .catch(function(){});
            }

            function playNotifSound() {
                try {
                    var ctx = new (window.AudioContext || window.webkitAudioContext)();
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    osc.type = 'sine';
                    gain.gain.value = 0.1;
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                    osc.start();
                    osc.stop(ctx.currentTime + 0.3);
                } catch(e) {}
            }

            // Sound toggle
            window.toggleNotifSound = function() {
                var prefs = window.appNotificationPrefs || {};
                prefs.soundEnabled = !prefs.soundEnabled;
                window.appNotificationPrefs = prefs;
                try { localStorage.setItem('foxdesk_notif_sound', prefs.soundEnabled ? '1' : '0'); } catch(e) {}
                updateSoundIcon(prefs.soundEnabled);
            };

            function updateSoundIcon(enabled) {
                var btn = document.getElementById('notif-sound-toggle');
                if (!btn) return;
                var onIcon = btn.querySelector('.notif-sound-on');
                var offIcon = btn.querySelector('.notif-sound-off');
                if (onIcon) onIcon.style.display = enabled ? '' : 'none';
                if (offIcon) offIcon.style.display = enabled ? 'none' : '';
                btn.style.color = enabled ? 'var(--accent-primary, #3b82f6)' : 'var(--text-muted)';
            }

            // Restore sound preference from localStorage
            (function() {
                var stored = '0';
                try { stored = localStorage.getItem('foxdesk_notif_sound'); } catch(e) {}
                var enabled = stored === '1';
                window.appNotificationPrefs = window.appNotificationPrefs || {};
                window.appNotificationPrefs.soundEnabled = enabled;
                // Defer icon update until DOM is ready
                if (document.readyState !== 'loading') {
                    updateSoundIcon(enabled);
                } else {
                    document.addEventListener('DOMContentLoaded', function() { updateSoundIcon(enabled); });
                }
            })();

            // Close panel on outside click
            document.addEventListener('click', function(e) {
                if (!_notifOpen) return;
                var panel = document.getElementById('notification-panel');
                var mBtn = document.getElementById('mobile-notif-btn');
                var dBtn = document.getElementById('desktop-notif-btn');
                if (panel && !panel.contains(e.target) && (!mBtn || !mBtn.contains(e.target)) && (!dBtn || !dBtn.contains(e.target))) {
                    _notifOpen = false;
                    panel.classList.add('hidden');
                }
            });

            // Init on page load
            document.addEventListener('DOMContentLoaded', function() {
                // Initial count check
                pollCount();
                // Poll every 60 seconds
                _pollInterval = setInterval(pollCount, 60000);
            });
        })();
        </script>

        <!-- Page Content -->
        <div class="p-2 lg:p-3 xl:p-4">

            <!-- JS moved to assets/js/app-header.js (defer) -->


