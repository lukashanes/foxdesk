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

