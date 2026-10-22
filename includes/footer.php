</div>

<!-- Footer -->
<footer class="px-4 lg:px-8 py-3 text-xs" style="color: var(--text-muted); margin-top: auto;">
    <div class="copyright">
        <a href="https://foxdesk.net" target="_blank" rel="noopener" style="color: var(--text-muted);">FoxDesk</a>
    </div>
</footer>
</main>

<?php
// Flight deck UI: bottom navigation on narrow screens (the rail is desktop-only).
$mobile_nav_page = (string) ($page ?? '');
$mobile_nav_section = (string) ($_GET['section'] ?? '');
$mobile_nav_items = [
    ['href' => url('work'), 'icon' => 'home', 'label' => t('Dashboard'), 'active' => $mobile_nav_page === 'work'],
    ['href' => url('tickets'), 'icon' => 'ticket-alt', 'label' => t('Tickets'), 'active' => $mobile_nav_page === 'tickets' && ($_GET['archived'] ?? '') !== '1'],
];
if (is_admin() || is_agent()) {
    $mobile_nav_items[] = ['href' => url('time'), 'icon' => 'clock', 'label' => t('Time'), 'active' => $mobile_nav_page === 'time'];
    $mobile_nav_items[] = ['href' => url('admin', is_admin() ? ['section' => 'reports-list'] : ['section' => 'reports', 'tab' => 'time']), 'icon' => 'chart-bar', 'label' => t('Reports'), 'active' => $mobile_nav_page === 'admin' && in_array($mobile_nav_section, ['reports', 'reports-list', 'report-builder'], true)];
    if (is_admin()) {
        $mobile_nav_items[] = ['href' => url('admin', ['section' => 'organizations']), 'icon' => 'building', 'label' => t('Clients'), 'active' => $mobile_nav_page === 'admin' && in_array($mobile_nav_section, ['organizations', 'clients'], true)];
    }
} else {
    $mobile_nav_items[] = ['href' => url('new-ticket'), 'icon' => 'plus', 'label' => t('New ticket'), 'active' => $mobile_nav_page === 'new-ticket'];
}
?>
<nav class="app-mobile-nav" aria-label="<?php echo e(t('Main navigation')); ?>">
    <?php foreach ($mobile_nav_items as $mobile_nav_item): ?>
        <a href="<?php echo e($mobile_nav_item['href']); ?>" class="app-mobile-nav__item <?php echo $mobile_nav_item['active'] ? 'is-active' : ''; ?>" <?php echo $mobile_nav_item['active'] ? 'aria-current="page"' : ''; ?>>
            <?php echo get_icon($mobile_nav_item['icon'], 'app-mobile-nav__icon'); ?>
            <span><?php echo e($mobile_nav_item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<script>
    // App config for external JS (bridge PHP → JS)
    window.appConfig = {
        apiUrl: <?php echo json_encode(url('api')); ?>,
        deleteConfirmMsg: <?php echo json_encode(t('Are you sure you want to delete this item?')); ?>,
        invalidFileTypeMsg: <?php echo json_encode(t('Invalid file type.')); ?>,
        isStaff: <?php echo (is_agent() || is_admin()) ? 'true' : 'false'; ?>,
        isAdmin: <?php echo is_admin() ? 'true' : 'false'; ?>,
        pausedLabel: <?php echo json_encode(t('Paused')); ?>,
        activeTimersLabel: <?php echo json_encode(t('Active Timers')); ?>,
        cancelTicketConfirm: <?php echo json_encode(t('Cancel ticket? The ticket will be deleted.')); ?>,
        cancelTicketTooltip: <?php echo json_encode(t('Cancel ticket')); ?>,
        stopTimerLabel: <?php echo json_encode(t('Stop timer')); ?>,
        timerStoppedLabel: <?php echo json_encode(t('Timer stopped.')); ?>,
        errorLabel: <?php echo json_encode(t('Error')); ?>,
        savedLabel: <?php echo json_encode(t('Saved')); ?>,
        pauseLabel: <?php echo json_encode(t('Pause')); ?>,
        resumeLabel: <?php echo json_encode(t('Resume')); ?>,
        startingWorkLabel: <?php echo json_encode(t('Starting...')); ?>,
        startWorkFailedLabel: <?php echo json_encode(t('Could not start work.')); ?>,
        shortcutLabels: <?php echo json_encode([
            'search' => t('Search tickets, clients, history...'),
            'dashboard' => t('Work'),
            'dashboardDesc' => t('Open your main workspace dashboard'),
            'analytics' => t('Analytics'),
            'analyticsDesc' => t('View analytics dashboard'),
            'tickets' => t('Tickets'),
            'ticketsDesc' => t('View all tickets'),
            'newTicket' => t('New ticket'),
            'newTicketDesc' => t('Create a new ticket'),
            'profile' => t('My profile'),
            'profileDesc' => t('Edit your profile'),
            'timeReports' => t('Time Reports'),
            'timeReportsDesc' => t('View time reports'),
            'settings' => t('Settings'),
            'settingsDesc' => t('System settings'),
            'users' => t('Users'),
            'usersDesc' => t('Manage users'),
            'organizations' => t('Organizations'),
            'organizationsDesc' => t('Manage companies'),
            'toggleDark' => t('Toggle Dark Mode'),
            'toggleDarkDesc' => t('Switch light/dark theme'),
            'ticket' => t('ticket'),
            'client' => t('client'),
            'contact' => t('contact'),
            'report' => t('report'),
            'action' => t('action'),
            'goTo' => t('go to'),
            'noMatches' => t('No matches'),
            'openTickets' => t('Open tickets'),
            'doneTickets' => t('Done tickets'),
            'archivedTickets' => t('Archived tickets'),
            'clients' => t('Clients'),
            'contacts' => t('Contacts'),
            'reports' => t('Reports'),
        ], JSON_UNESCAPED_UNICODE); ?>
    };
</script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function() {});
}
</script>
<script>
// ── Push Notification Subscription Management ────────────────────────────
(function() {
    var pushBtn, pushOn, pushOff;

    function initPushUI() {
        pushBtn = document.getElementById('push-toggle-btn');
        if (!pushBtn) return;
        pushOn = pushBtn.querySelector('.push-icon-on');
        pushOff = pushBtn.querySelector('.push-icon-off');

        // Only show if browser supports push
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        pushBtn.style.display = '';

        // Check current state
        navigator.serviceWorker.ready.then(function(reg) {
            reg.pushManager.getSubscription().then(function(sub) {
                updatePushUI(!!sub);
            });
        });
    }

    function updatePushUI(subscribed) {
        if (!pushOn || !pushOff || !pushBtn) return;
        if (subscribed) {
            pushOn.style.display = '';
            pushOff.style.display = 'none';
            pushBtn.style.color = 'var(--accent-primary, #3b82f6)';
            pushBtn.title = <?php echo json_encode(t('Push notifications enabled')); ?>;
        } else {
            pushOn.style.display = 'none';
            pushOff.style.display = '';
            pushBtn.style.color = 'var(--text-muted)';
            pushBtn.title = <?php echo json_encode(t('Enable push notifications')); ?>;
        }
    }

    window.togglePushNotifications = function() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        navigator.serviceWorker.ready.then(function(reg) {
            reg.pushManager.getSubscription().then(function(sub) {
                if (sub) {
                    // Unsubscribe
                    sub.unsubscribe().then(function() {
                        // Tell server
                        fetch('index.php?page=api&action=push-unsubscribe', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken},
                            body: JSON.stringify({endpoint: sub.endpoint, csrf_token: window.csrfToken})
                        }).catch(function(){});
                        updatePushUI(false);
                    });
                } else {
                    // Subscribe
                    Notification.requestPermission().then(function(perm) {
                        if (perm !== 'granted') return;

                        // Get VAPID public key
                        fetch('index.php?page=api&action=push-vapid-key')
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.publicKey) return;

                                // Convert base64url to Uint8Array
                                var raw = atob(data.publicKey.replace(/-/g, '+').replace(/_/g, '/'));
                                var arr = new Uint8Array(raw.length);
                                for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);

                                return reg.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: arr
                                });
                            })
                            .then(function(newSub) {
                                if (!newSub) return;

                                var key = newSub.getKey('p256dh');
                                var auth = newSub.getKey('auth');

                                var body = {
                                    endpoint: newSub.endpoint,
                                    p256dh: key ? btoa(String.fromCharCode.apply(null, new Uint8Array(key))) : '',
                                    auth: auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : '',
                                    csrf_token: window.csrfToken
                                };

                                fetch('index.php?page=api&action=push-subscribe', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken},
                                    body: JSON.stringify(body)
                                }).catch(function(){});

                                updatePushUI(true);
                            })
                            .catch(function(err) {
                                console.warn('Push subscription failed:', err);
                            });
                    });
                }
            });
        });
    };

    if (document.readyState !== 'loading') {
        initPushUI();
    } else {
        document.addEventListener('DOMContentLoaded', initPushUI);
    }
})();
</script>
<!-- Image Preview Lightbox -->
<div id="image-lightbox" class="image-lightbox" hidden aria-hidden="true">
    <div class="image-lightbox__dialog" role="dialog" aria-modal="true" aria-labelledby="lightbox-name">
        <img id="lightbox-img" class="image-lightbox__image" alt="">
        <div id="lightbox-name" class="image-lightbox__name"></div>
        <button type="button" class="image-lightbox__close" data-image-preview-close aria-label="<?php echo e(t('Close')); ?>">&times;</button>
    </div>
</div>
<script defer src="assets/js/image-preview.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<!-- Document preview: PDFs are drawn as pixels with the bundled PDF.js; text files as plain text. -->
<dialog class="document-dialog" data-document-dialog aria-labelledby="document-preview-title" data-loading="<?php echo e(t('Loading...')); ?>" data-unavailable="<?php echo e(t('media.preview_unavailable')); ?>">
    <header><h2 id="document-preview-title" data-document-title dir="auto"></h2><a class="btn btn-secondary btn-sm" data-document-download download data-no-page-transition><?php echo e(t('Download')); ?></a><button class="btn btn-secondary btn-sm" type="button" data-document-close><?php echo e(t('Close')); ?></button></header>
    <p role="status" data-document-status></p><div class="document-dialog__body" data-document-body></div>
    <footer><button class="btn btn-secondary btn-sm" type="button" data-document-prev><?php echo e(t('Previous')); ?></button><span data-document-pager></span><button class="btn btn-secondary btn-sm" type="button" data-document-next><?php echo e(t('Next')); ?></button></footer>
</dialog>
<script type="module" src="assets/js/document-preview.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<script defer src="assets/js/page-transitions.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<script defer src="assets/js/app-api-client.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<script defer src="assets/js/app-footer.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<span hidden data-soft-select-config data-search="<?php echo e(t('Search')); ?>" data-empty="<?php echo e(t('No entries')); ?>" data-scope="<?php echo (int) (current_user()['id'] ?? 0); ?>"></span>
<script defer src="assets/js/soft-select.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<script defer src="assets/js/row-action-menu.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
<?php if (in_array($page ?? '', ['ticket', 'tickets', 'work'], true)): ?>
<script id="ticket-workflow-script" data-scope="<?php echo (int) (current_user()['tenant_id'] ?? 0) . '-' . (int) (current_user()['id'] ?? 0); ?>" src="assets/js/ticket-workflow.js?v=<?php echo (int) filemtime(BASE_PATH . '/assets/js/ticket-workflow.js'); ?>" defer></script>
<?php endif; ?>

</body>

</html>
