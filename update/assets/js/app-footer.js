/**
 * FoxDesk - Footer JS (flash messages, loading helpers, dropzone, timer, a11y)
 * Extracted from includes/footer.php for defer loading
 *
 * Requires window.appConfig to be set before this script loads:
 *   window.appConfig = { apiUrl, deleteConfirmMsg, invalidFileTypeMsg, isStaff }
 */

/* =============================
   Accessibility: Focus Trap
   ============================= */

var _focusTrapHandlers = new WeakMap();

/**
 * Trap keyboard focus inside a modal/dialog element.
 * Tab and Shift+Tab cycle through focusable children; Escape triggers close.
 */
function trapFocus(modal) {
    if (!modal) return;
    var handler = function(e) {
        if (e.key === 'Escape') {
            // Find the close button and click it
            var closeBtn = modal.querySelector('[onclick*="close"], button[aria-label="Close"]');
            if (closeBtn) closeBtn.click();
            return;
        }
        if (e.key !== 'Tab') return;
        var focusable = modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    };
    _focusTrapHandlers.set(modal, handler);
    document.addEventListener('keydown', handler);
}

/**
 * Release focus trap from a modal element.
 */
function releaseFocus(modal) {
    if (!modal) return;
    var handler = _focusTrapHandlers.get(modal);
    if (handler) {
        document.removeEventListener('keydown', handler);
        _focusTrapHandlers.delete(modal);
    }
}

/* =============================
   Flash / Toast Notifications
   ============================= */

function dismissFlashMessage(messageEl) {
    if (!messageEl || messageEl.dataset.dismissing === '1') return;
    messageEl.dataset.dismissing = '1';
    messageEl.classList.add('is-dismissing');
    setTimeout(function() { messageEl.remove(); }, 220);
}

function scheduleFlashAutoDismiss(messageEl, duration) {
    if (!messageEl) return;
    var ttl = Number.isFinite(duration) ? duration : 5000;
    if (ttl <= 0) return;
    setTimeout(function() { dismissFlashMessage(messageEl); }, ttl);
}

function normalizeFlashType(type) {
    var allowed = ['success', 'error', 'warning', 'info'];
    return allowed.includes(type) ? type : 'info';
}

function showAppToastFallback(message, type) {
    var toast = document.createElement('div');
    toast.className = 'fixed bottom-4 right-4 px-4 py-2 rounded-lg shadow-lg text-sm font-medium z-50 transition-opacity duration-300 '
        + (type === 'success' ? 'bg-green-600 text-white' : (type === 'error' ? 'bg-red-600 text-white' : 'bg-blue-600 text-white'));
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

window.showAppToast = function(message, type, options) {
    type = type || 'info';
    options = options || {};
    if (!message) return false;

    var prefs = window.appNotificationPrefs || {};
    if (prefs.inAppEnabled === false && !options.force) return false;

    var stack = document.getElementById('app-toast-stack');
    var flashType = normalizeFlashType(type);
    if (!stack) {
        showAppToastFallback(String(message), flashType);
        return true;
    }

    var wrapper = document.createElement('div');
    wrapper.className = 'flash-message flash-' + flashType;
    wrapper.setAttribute('role', 'status');
    wrapper.setAttribute('aria-live', 'polite');
    wrapper.setAttribute('data-flash-type', flashType);

    var row = document.createElement('div');
    row.className = 'flex items-start justify-between gap-4';
    var text = document.createElement('div');
    text.className = 'text-sm';
    text.textContent = String(message);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'flash-close';
    close.setAttribute('aria-label', 'Close');
    close.innerHTML = '&times;';

    row.appendChild(text);
    row.appendChild(close);
    wrapper.appendChild(row);
    stack.appendChild(wrapper);

    scheduleFlashAutoDismiss(wrapper, options.duration != null ? options.duration : 5000);
    return true;
};

/* =============================
   File Dropzone
   ============================= */

window.initFileDropzone = function(config) {
    var zone = document.getElementById(config && config.zoneId || '');
    var input = document.getElementById(config && config.inputId || '');
    var onFilesChanged = typeof (config && config.onFilesChanged) === 'function' ? config.onFilesChanged : null;
    var acceptedExtensions = Array.isArray(config && config.acceptedExtensions)
        ? config.acceptedExtensions.map(function(ext) { return String(ext).toLowerCase(); })
        : null;

    if (!zone || !input) return null;

    var invalidMsg = (config && config.invalidTypeMessage) || (window.appConfig && window.appConfig.invalidFileTypeMsg) || 'Invalid file type.';

    var mergeFiles = function(existing, incoming) {
        var dt = new DataTransfer();
        for (var i = 0; i < existing.length; i++) dt.items.add(existing[i]);
        for (var j = 0; j < incoming.length; j++) {
            var extension = '.' + String(incoming[j].name || '').split('.').pop().toLowerCase();
            if (acceptedExtensions && !acceptedExtensions.includes(extension)) {
                window.showAppToast(invalidMsg, 'error');
                continue;
            }
            dt.items.add(incoming[j]);
        }
        input.files = dt.files;
        if (onFilesChanged) onFilesChanged(input.files);
    };

    zone.addEventListener('click', function(event) {
        if (event.target.closest('button')) return;
        input.click();
    });
    zone.addEventListener('dragover', function(event) {
        event.preventDefault();
        zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', function() { zone.classList.remove('dragover'); });
    zone.addEventListener('drop', function(event) {
        event.preventDefault();
        zone.classList.remove('dragover');
        mergeFiles(input.files, event.dataTransfer.files);
    });
    input.addEventListener('change', function() {
        if (onFilesChanged) onFilesChanged(input.files);
    });

    return {
        setFiles: function(files) { mergeFiles([], files); }
    };
};

/* =============================
   DOMContentLoaded - Flash init
   ============================= */

document.addEventListener('DOMContentLoaded', function() {
    var flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(function(msg) {
        scheduleFlashAutoDismiss(msg, 5000);
    });

});

// Manual flash close (delegated)
document.addEventListener('click', function(event) {
    var closeBtn = event.target.closest('.flash-close');
    if (!closeBtn) return;
    var flash = closeBtn.closest('.flash-message');
    dismissFlashMessage(flash);
});

/* =============================
   Clickable Table Rows
   ============================= */

document.addEventListener('click', function(event) {
    // Skip if user clicked a link, button, input, or checkbox inside the row
    if (event.target.closest('a, button, input, select, textarea, .bulk-checkbox, .tl-inline-edit')) return;
    var row = event.target.closest('tr[data-href]');
    if (!row) return;
    var href = row.getAttribute('data-href');
    if (!href) return;
    // Cmd/Ctrl+click = new tab
    if (event.metaKey || event.ctrlKey) {
        window.open(href, '_blank');
    } else {
        window.location.href = href;
    }
});

/* =============================
   Active Timer Browser Tab Indicator
   ============================= */

(function() {
    var cfg = window.appConfig || {};
    if (!cfg.isStaff) return; // Only for agents/admins

    var faviconDefault = document.getElementById('favicon');
    var faviconTimer = document.getElementById('favicon-timer');
    var originalTitle = window.originalPageTitle || document.title;
    var timerActive = false;
    var timerTicketId = null;
    var timerStartedAt = null;
    var timerPausedSeconds = 0;
    var timerIsPaused = false;
    var localTickInterval = null;

    var defaultFaviconUrl = faviconDefault ? faviconDefault.href : '';
    var timerFaviconUrl = faviconTimer ? faviconTimer.href : '';

    function formatDuration(totalSeconds) {
        var hours = Math.floor(totalSeconds / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;
        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }
        return minutes + ':' + String(seconds).padStart(2, '0');
    }

    function updateTitleWithTime() {
        if (!timerActive || timerIsPaused || !timerStartedAt) return;
        var now = Math.floor(Date.now() / 1000);
        var elapsed = now - timerStartedAt - timerPausedSeconds;
        var ticketCode = '#' + timerTicketId;
        document.title = '\u23F1 ' + formatDuration(elapsed) + ' \u2022 ' + ticketCode + ' | ' + (window.appName || 'FoxDesk');
    }

    function startLocalTick() {
        if (localTickInterval) return;
        localTickInterval = setInterval(updateTitleWithTime, 1000);
    }

    function stopLocalTick() {
        if (localTickInterval) {
            clearInterval(localTickInterval);
            localTickInterval = null;
        }
    }

    function updateBrowserTab(data) {
        if (data.active && !data.is_paused) {
            timerActive = true;
            timerTicketId = data.ticket_id;
            timerStartedAt = data.started_at;
            timerPausedSeconds = data.paused_seconds || 0;
            timerIsPaused = false;
            if (faviconDefault && timerFaviconUrl) faviconDefault.href = timerFaviconUrl;
            updateTitleWithTime();
            startLocalTick();
        } else if (data.active && data.is_paused) {
            timerActive = true;
            timerIsPaused = true;
            stopLocalTick();
            var ticketCode = '#' + data.ticket_id;
            document.title = '\u23F8 ' + data.elapsed_str + ' \u2022 ' + ticketCode + ' | ' + (window.appName || 'FoxDesk');
            if (faviconDefault && timerFaviconUrl) faviconDefault.href = timerFaviconUrl;
        } else {
            if (timerActive) {
                document.title = originalTitle;
                if (faviconDefault && defaultFaviconUrl) faviconDefault.href = defaultFaviconUrl;
                stopLocalTick();
                timerActive = false;
                timerTicketId = null;
                timerStartedAt = null;
            }
        }
    }

    function checkActiveTimer() {
        var apiUrl = (cfg.apiUrl || '/index.php?page=api') + '&action=get_active_timer';
        fetch(apiUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) updateBrowserTab(data);
            })
            .catch(function(err) { console.warn('Timer check failed:', err.message || err); });
    }

    // Initial check + poll every 30s
    checkActiveTimer();
    setInterval(checkActiveTimer, 30000);

    // Instant refresh when timer state changes on current page
    document.addEventListener('timerStateChanged', function() {
        checkActiveTimer();
    });
})();

/* =============================
   Global Timer Display Updater
   (ticks all .timer-display spans every 1s — sidebar + dashboard)
   ============================= */

(function() {
    function updateTimerDisplays() {
        document.querySelectorAll('.timer-display').forEach(function(span) {
            var started = parseInt(span.dataset.started || '0', 10);
            var paused = parseInt(span.dataset.pausedSeconds || '0', 10);
            if (!started) return;
            var elapsed = Math.floor(Date.now() / 1000) - started - paused;
            if (elapsed < 0) elapsed = 0;
            var h = Math.floor(elapsed / 3600);
            var m = Math.floor((elapsed % 3600) / 60);
            span.textContent = h > 0 ? (h + 'h ' + m + 'min') : (m + ' min');
        });
    }
    if (document.querySelectorAll('.timer-display').length > 0) {
        updateTimerDisplays();
        setInterval(updateTimerDisplays, 1000);
    }
})();

/* =============================
   Sidebar Timer Widget Updater
   (polls API every 30s to sync sidebar timers)
   Note: All user-provided text is escaped via textContent (escHtml helper)
   to prevent XSS. The buildTimerRow function uses DOM methods for safe rendering.
   ============================= */

(function() {
    var cfg = window.appConfig || {};
    if (!cfg.isStaff) return;

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function buildTimerUrl(t) {
        if (t.ticket_hash) return 'index.php?page=ticket&t=' + encodeURIComponent(t.ticket_hash);
        return 'index.php?page=ticket&id=' + t.ticket_id;
    }

    function cancelTicket(ticketId) {
        if (!confirm(cfg.cancelTicketConfirm || 'Cancel ticket? The ticket will be deleted.')) return;
        var apiUrl = (cfg.apiUrl || 'index.php?page=api') + '&action=cancel-ticket';
        var body = new FormData();
        body.append('ticket_id', ticketId);
        fetch(apiUrl, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': window.csrfToken},
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.dispatchEvent(new Event('timerStateChanged'));
                // If currently viewing this ticket, redirect to dashboard
                if (window.location.href.indexOf('id=' + ticketId) !== -1 ||
                    window.location.href.indexOf('ticket_id=' + ticketId) !== -1) {
                    window.location.href = 'index.php?page=dashboard';
                }
            } else {
                alert(data.error || 'Error');
            }
        })
        .catch(function() { alert('Error'); });
    }

    function stopTimer(ticketId) {
        var apiUrl = (cfg.apiUrl || 'index.php?page=api') + '&action=stop-timer';
        fetch(apiUrl, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': window.csrfToken, 'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ticket_id=' + ticketId
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof showAppToast === 'function') showAppToast(data.message || 'Timer stopped.', 'success');
                document.dispatchEvent(new Event('timerStateChanged'));
            } else {
                if (typeof showAppToast === 'function') showAppToast(data.error || 'Error', 'error');
                else alert(data.error || 'Error');
            }
        })
        .catch(function() { alert('Error'); });
    }

    // Expose for PHP-rendered sidebar buttons
    window.sidebarStopTimer = stopTimer;

    function buildTimerRow(t, pausedLabel) {
        var isPaused = t.is_paused;
        var wrapper = document.createElement('div');
        wrapper.className = 'flex items-center group';

        var a = document.createElement('a');
        a.href = buildTimerUrl(t);
        a.className = 'sidebar-timer-item flex-1 flex items-center gap-2 px-3 py-1.5 rounded-lg transition-all sidebar-hover min-w-0';
        a.title = t.ticket_title || '';

        var dot = document.createElement('span');
        dot.className = 'flex-shrink-0 w-1.5 h-1.5 rounded-full ' + (isPaused ? 'bg-yellow-400' : 'sidebar-timer-pulse');
        a.appendChild(dot);

        var title = document.createElement('span');
        title.className = 'flex-1 min-w-0 text-xs truncate';
        title.style.color = 'var(--text-secondary)';
        title.textContent = t.ticket_title || '';
        a.appendChild(title);

        var time = document.createElement('span');
        time.className = 'flex-shrink-0 text-[10px] font-mono font-medium' + (isPaused ? '' : ' timer-display');
        time.style.color = isPaused ? 'var(--corp-warning, #f59e0b)' : 'var(--corp-success, #10b981)';
        if (!isPaused) {
            time.dataset.started = t.started_at;
            time.dataset.pausedSeconds = t.paused_seconds || 0;
        }
        time.textContent = isPaused ? pausedLabel : (t.elapsed_str || '0 min');
        a.appendChild(time);

        wrapper.appendChild(a);

        // Pause/Resume toggle button
        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity';
        toggleBtn.style.color = 'var(--text-muted)';
        toggleBtn.title = isPaused ? (cfg.resumeLabel || 'Resume') : (cfg.pauseLabel || 'Pause');
        var toggleSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        toggleSvg.setAttribute('class', 'w-3 h-3');
        toggleSvg.setAttribute('viewBox', '0 0 20 20');
        toggleSvg.setAttribute('fill', 'currentColor');
        var togglePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        if (isPaused) {
            togglePath.setAttribute('d', 'M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.344-5.891a1.5 1.5 0 000-2.538L6.3 2.84z');
        } else {
            togglePath.setAttribute('d', 'M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zm7 0a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z');
        }
        toggleSvg.appendChild(togglePath);
        toggleBtn.appendChild(toggleSvg);
        toggleBtn.addEventListener('mouseenter', function() {
            toggleBtn.style.color = isPaused ? 'var(--corp-success, #10b981)' : 'var(--corp-warning, #f59e0b)';
        });
        toggleBtn.addEventListener('mouseleave', function() {
            toggleBtn.style.color = 'var(--text-muted)';
        });
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var action = isPaused ? 'resume-timer' : 'pause-timer';
            fetch((cfg.apiUrl || 'index.php?page=api') + '&action=' + action, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': window.csrfToken, 'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'ticket_id=' + t.ticket_id
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.dispatchEvent(new Event('timerStateChanged'));
                } else {
                    if (typeof showAppToast === 'function') showAppToast(data.error || 'Error', 'error');
                }
            });
        });
        wrapper.appendChild(toggleBtn);

        // Stop button (■) - saves the timer
        var stopBtn = document.createElement('button');
        stopBtn.className = 'flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity';
        stopBtn.style.color = 'var(--text-muted)';
        stopBtn.title = cfg.stopTimerLabel || 'Stop timer';
        var stopSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        stopSvg.setAttribute('class', 'w-3 h-3');
        stopSvg.setAttribute('viewBox', '0 0 24 24');
        stopSvg.setAttribute('fill', 'none');
        stopSvg.setAttribute('stroke', 'currentColor');
        stopSvg.setAttribute('stroke-width', '2');
        var stopRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        stopRect.setAttribute('x', '6');
        stopRect.setAttribute('y', '6');
        stopRect.setAttribute('width', '12');
        stopRect.setAttribute('height', '12');
        stopSvg.appendChild(stopRect);
        stopBtn.appendChild(stopSvg);
        stopBtn.addEventListener('mouseenter', function() {
            stopBtn.style.color = 'var(--corp-danger, #ef4444)';
        });
        stopBtn.addEventListener('mouseleave', function() {
            stopBtn.style.color = 'var(--text-muted)';
        });
        stopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            stopTimer(t.ticket_id);
        });
        wrapper.appendChild(stopBtn);

        // Cancel button (✕)
        var btn = document.createElement('button');
        btn.className = 'flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-[10px] opacity-0 group-hover:opacity-100 transition-opacity';
        btn.style.color = 'var(--text-muted)';
        btn.title = cfg.cancelTicketTooltip || 'Cancel ticket';
        btn.textContent = '\u00D7';
        btn.addEventListener('mouseenter', function() { btn.style.color = 'var(--corp-danger, #ef4444)'; });
        btn.addEventListener('mouseleave', function() { btn.style.color = 'var(--text-muted)'; });
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            cancelTicket(t.ticket_id);
        });
        wrapper.appendChild(btn);

        return wrapper;
    }

    function renderSidebarTimers(timers) {
        var section = document.getElementById('sidebar-timers');
        var list = document.getElementById('sidebar-timers-list');

        if (timers.length === 0) {
            if (section) section.style.display = 'none';
            return;
        }

        // Create section dynamically if timer started after page load
        if (!section) {
            var staffSections = document.querySelectorAll('#sidebar .mt-4.pt-3');
            var insertAfter = staffSections.length > 0 ? staffSections[0] : null;
            if (!insertAfter) return;

            section = document.createElement('div');
            section.id = 'sidebar-timers';
            section.className = 'mt-3 pt-3 border-t';
            section.style.borderColor = 'var(--border-light)';

            var header = document.createElement('p');
            header.className = 'px-3 mb-1.5 text-[10px] font-semibold uppercase tracking-wider flex items-center gap-1.5';
            header.style.color = 'var(--text-muted)';

            var dotSpan = document.createElement('span');
            dotSpan.className = 'sidebar-timer-dot';
            header.appendChild(dotSpan);
            header.appendChild(document.createTextNode(' ' + (cfg.activeTimersLabel || 'Active Timers') + ' '));

            var countSpan = document.createElement('span');
            countSpan.className = 'sidebar-timer-count';
            countSpan.textContent = timers.length;
            header.appendChild(countSpan);
            section.appendChild(header);

            list = document.createElement('div');
            list.id = 'sidebar-timers-list';
            list.className = 'space-y-0.5';
            section.appendChild(list);

            insertAfter.parentNode.insertBefore(section, insertAfter.nextSibling);
        }

        section.style.display = '';

        // Update count
        var countEl = section.querySelector('.sidebar-timer-count');
        if (countEl) countEl.textContent = timers.length;

        // Rebuild timer list using safe DOM methods
        var pausedLabel = cfg.pausedLabel || 'Paused';
        if (list) {
            while (list.firstChild) list.removeChild(list.firstChild);
            timers.forEach(function(t) {
                list.appendChild(buildTimerRow(t, pausedLabel));
            });
        }
    }

    function pollSidebarTimers() {
        var apiUrl = (cfg.apiUrl || 'index.php?page=api') + '&action=get_active_timers';
        fetch(apiUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) renderSidebarTimers(data.timers || []);
            })
            .catch(function(err) { console.warn('Sidebar timers poll failed:', err.message || err); });
    }

    // Poll every 30s (don't poll immediately — PHP already rendered initial state)
    setInterval(pollSidebarTimers, 30000);

    // Instant refresh when timer state changes on current page
    document.addEventListener('timerStateChanged', function() {
        pollSidebarTimers();
    });
})();
