</div>

<!-- Footer -->
<footer class="px-4 lg:px-8 py-3 text-xs" style="color: var(--text-muted); margin-top: auto;">
    <div class="copyright">
        <a href="https://foxdesk.org" target="_blank" rel="noopener" style="color: var(--text-muted);">FoxDesk</a>
    </div>
</footer>
</main>

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
        cancelTicketTooltip: <?php echo json_encode(t('Cancel ticket')); ?>
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
<div id="image-lightbox"
     style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); padding:1rem; cursor:pointer;"
     onclick="if(event.target===this)closeImagePreview();">
    <div style="position:relative; display:flex; flex-direction:column; align-items:center; max-width:90vw; max-height:90vh; cursor:default;">
        <img id="lightbox-img" src="" alt=""
             style="max-width:90vw; max-height:85vh; width:auto; height:auto; object-fit:contain; border-radius:0.5rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">
        <div id="lightbox-name" style="text-align:center; color:#fff; font-size:0.875rem; margin-top:0.5rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%;"></div>
        <button onclick="closeImagePreview();"
                style="position:absolute; top:-0.75rem; right:-0.75rem; width:2rem; height:2rem; border-radius:50%; background:rgba(0,0,0,0.6); color:#fff; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1.25rem; line-height:1; transition:background 0.15s;"
                onmouseover="this.style.background='rgba(0,0,0,0.85)'" onmouseout="this.style.background='rgba(0,0,0,0.6)'">&times;</button>
    </div>
</div>
<script>
function openImagePreview(src, name) {
    var lb = document.getElementById('image-lightbox');
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox-name').textContent = name || '';
    lb.style.display = 'flex';
    document.addEventListener('keydown', _lbEsc);
}
function closeImagePreview() {
    var lb = document.getElementById('image-lightbox');
    lb.style.display = 'none';
    document.getElementById('lightbox-img').src = '';
    document.removeEventListener('keydown', _lbEsc);
}
function _lbEsc(e) { if (e.key === 'Escape') closeImagePreview(); }
</script>
<script defer src="assets/js/app-footer.js?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>"></script>
</body>

</html>

