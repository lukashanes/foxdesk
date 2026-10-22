(function (window, document) {
    'use strict';

    var features = window.FoxDeskTicketDetailFeatures = window.FoxDeskTicketDetailFeatures || {};
    features.timer = function (runtime) {
    var config = runtime.config;
    var labels = runtime.labels;
    var icons = runtime.icons;
    var ticketId = runtime.ticketId;
    var csrfToken = runtime.csrfToken;
    var t = runtime.t;
    var escapeHtml = runtime.escapeHtml;
    var showToast = runtime.showToast;
    var showUndoToast = runtime.showUndoToast;
    var fadeRemove = runtime.fadeRemove;
    var fillTemplate = runtime.fillTemplate;
    var quillFieldValue = runtime.quillFieldValue;
    var loadQuillContent = runtime.loadQuillContent;
    var formatDateInput = runtime.formatDateInput;
    var formatTimeInput = runtime.formatTimeInput;
    var formatDateTimeLocal = runtime.formatDateTimeLocal;
    var pad2 = runtime.pad2;

    function initTimer() {
        var controls = document.getElementById('timer-controls');
        if (!controls) return;

        var localTicketId = controls.dataset.ticketId;
        var button = document.getElementById('btn-timer-action');
        var buttonIcon = button ? button.querySelector('.btn-timer-icon') : null;
        var buttonText = button ? button.querySelector('.btn-timer-text') : null;
        var logToggle = document.getElementById('timer-log-toggle');
        var discardButton = document.getElementById('btn-discard-timer');
        var currentState = config.timerState || 'stopped';
        var timerInterval = null;
        var timerStartTime = null;
        var pausedSeconds = 0;
        var busy = false;
        var selfDispatch = false;

        var elapsed = document.getElementById('timer-elapsed');
        if (elapsed && elapsed.dataset.started) {
            timerStartTime = parseInt(elapsed.dataset.started, 10);
            pausedSeconds = parseInt(elapsed.dataset.pausedSeconds || '0', 10);
        }

        function formatTime(totalSec) {
            if (totalSec < 0) totalSec = 0;
            var hours = Math.floor(totalSec / 3600);
            var minutes = Math.floor((totalSec % 3600) / 60);
            var seconds = totalSec % 60;
            if (hours > 0) return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            return minutes + ':' + String(seconds).padStart(2, '0');
        }

        function resetPageTitle() {
            document.title = window.originalPageTitle || config.pageTitle || document.title;
            var favicon = document.getElementById('favicon');
            var customFavicon = config.favicon || '';
            if (favicon && customFavicon) {
                favicon.href = customFavicon;
            } else if (favicon) {
                var appName = window.appName || config.appName || 'A';
                favicon.href = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#3b82f6"/><text x="16" y="22" font-family="Arial,sans-serif" font-size="18" font-weight="bold" fill="white" text-anchor="middle">' + appName.charAt(0).toUpperCase() + '</text></svg>');
            }
        }

        function updateToolbarTimer(state, timeText) {
            var stopControl = document.getElementById('workspace-stop-timer');
            if (stopControl) stopControl.hidden = state === 'stopped';
            var toolbar = document.getElementById('toolbar-timer-btn');
            if (!toolbar) return;
            var toolbarElapsed = document.getElementById('toolbar-timer-elapsed');

            if (state === 'running' || state === 'paused') {
                toolbar.className = 'ticket-primary-action ticket-primary-action--timer';
                toolbar.title = state === 'running' ? t('pauseTimerHelp', 'Pause this timer without logging time yet.') : t('resumeTimerHelp', 'Resume the paused timer.');
                toolbar.setAttribute('aria-label', toolbar.title);
                toolbar.textContent = '';
                toolbar.insertAdjacentHTML('afterbegin', state === 'running' ? icons.pauseSm : icons.playSm);
                var label = document.createElement('span');
                label.textContent = state === 'running' ? t('pauseTimer', 'Pause') : t('resumeTimer', 'Resume');
                toolbar.append(label);
                toolbarElapsed = document.createElement('span');
                toolbarElapsed.id = 'toolbar-timer-elapsed';
                toolbarElapsed.className = 'tabular-nums';
                toolbar.append(toolbarElapsed);
                toolbarElapsed.textContent = timeText || '';
            } else {
                toolbar.className = 'ticket-primary-action ticket-primary-action--timer';
                toolbar.title = t('startTimerHelp', 'Start a timer for this ticket.');
                toolbar.setAttribute('aria-label', toolbar.title);
                toolbar.textContent = '';
                toolbar.insertAdjacentHTML('afterbegin', icons.playSm);
                var startLabel = document.createElement('span');
                startLabel.textContent = t('startTimer', 'Start timer');
                toolbar.append(startLabel);
                if (toolbarElapsed) toolbarElapsed.remove();
            }
        }

        function updateCompleteActionTitle(state) {
            var completeButton = document.querySelector('[data-action-label="complete"]')?.closest('button');
            if (!completeButton) return;
            var hasActiveTimer = state === 'running' || state === 'paused';
            var title = state === 'running' || state === 'paused'
                ? t('completeTimerHelp', 'Mark this ticket as done and stop the active timer.')
                : t('completeHelp', 'Mark this ticket as done.');
            var label = hasActiveTimer
                ? t('completeTimerLabel', 'Complete & stop timer')
                : t('completeLabel', 'Complete');
            var labelNode = completeButton.querySelector('[data-action-label="complete"]') || completeButton.querySelector('span');
            var stopIntent = completeButton.form ? completeButton.form.querySelector('input[name="stop_timer_on_complete"]') : null;

            completeButton.title = title;
            completeButton.setAttribute('aria-label', title);
            if (labelNode) labelNode.textContent = label;
            if (stopIntent) stopIntent.value = hasActiveTimer ? '1' : '0';
        }

        function tick() {
            if (currentState !== 'running' || !timerStartTime) return;
            var elapsedSeconds = Math.floor(Date.now() / 1000) - timerStartTime - pausedSeconds;
            var timeText = formatTime(elapsedSeconds);
            var elapsedNode = document.getElementById('timer-elapsed');
            if (elapsedNode) elapsedNode.textContent = timeText;
            var toolbarElapsed = document.getElementById('toolbar-timer-elapsed');
            if (toolbarElapsed) toolbarElapsed.textContent = timeText;
            var favicon = document.getElementById('favicon');
            var faviconTimer = document.getElementById('favicon-timer');
            if (favicon && faviconTimer) favicon.href = faviconTimer.href;
            document.title = '\u23F1\uFE0F ' + timeText + ' - ' + (window.originalPageTitle || document.title.replace(/^\u23F1\uFE0F.*? - /, ''));
        }

        function setTimerState(state, opts) {
            opts = opts || {};
            currentState = state;
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }

            if (state === 'running') {
                button.className = 'btn btn-warning ticket-composer-timer-button';
                button.title = t('pauseTimerHelp', 'Pause this timer without logging time yet.');
                button.dataset.state = 'running';
                buttonIcon.innerHTML = icons.pause;
                var runningElapsed = Math.floor(Date.now() / 1000) - timerStartTime - pausedSeconds;
                buttonText.innerHTML = '<span id="timer-elapsed" class="tabular-nums" data-started="' + timerStartTime + '" data-paused-seconds="' + pausedSeconds + '">' + formatTime(runningElapsed) + '</span>';
                if (logToggle) logToggle.classList.remove('hidden');
                if (discardButton) discardButton.classList.remove('hidden');
                var stopRunning = document.getElementById('stop-timer-toggle');
                if (stopRunning) {
                    stopRunning.disabled = false;
                    stopRunning.checked = true;
                }
                timerInterval = setInterval(tick, 1000);
                var submitRunning = document.getElementById('comment-submit-btn');
                if (submitRunning) submitRunning.dataset.hasActiveTimer = '1';
                updateToolbarTimer('running', formatTime(runningElapsed));
                updateCompleteActionTitle('running');
            } else if (state === 'paused') {
                var elapsedSec = opts.elapsedSeconds || 0;
                var elapsedMin = Math.floor(elapsedSec / 60);
                button.className = 'btn btn-primary ticket-composer-timer-button';
                button.title = t('resumeTimerHelp', 'Resume the paused timer.');
                button.dataset.state = 'paused';
                buttonIcon.innerHTML = icons.play;
                buttonText.innerHTML = '<span id="timer-elapsed" class="tabular-nums" data-started="' + timerStartTime + '" data-paused-seconds="' + pausedSeconds + '">' + elapsedMin + ' min</span> <span class="text-xs uppercase ml-1">' + t('paused', 'Paused') + '</span>';
                if (logToggle) logToggle.classList.remove('hidden');
                if (discardButton) discardButton.classList.remove('hidden');
                var stopPaused = document.getElementById('stop-timer-toggle');
                if (stopPaused) {
                    stopPaused.disabled = false;
                    stopPaused.checked = true;
                }
                resetPageTitle();
                updateToolbarTimer('paused', elapsedMin + ' min');
                updateCompleteActionTitle('paused');
            } else {
                button.className = 'btn btn-primary ticket-composer-timer-button';
                button.title = t('startTimerHelp', 'Start a timer for this ticket.');
                button.dataset.state = 'stopped';
                buttonIcon.innerHTML = icons.play;
                buttonText.textContent = t('startTimer', 'Start timer');
                if (logToggle) logToggle.classList.add('hidden');
                if (discardButton) discardButton.classList.add('hidden');
                var stopStopped = document.getElementById('stop-timer-toggle');
                if (stopStopped) {
                    stopStopped.disabled = true;
                    stopStopped.checked = false;
                }
                timerStartTime = null;
                pausedSeconds = 0;
                resetPageTitle();
                var submitStopped = document.getElementById('comment-submit-btn');
                if (submitStopped) submitStopped.dataset.hasActiveTimer = '0';
                updateToolbarTimer('stopped');
                updateCompleteActionTitle('stopped');
            }

            if (window.attachStopTimerToggleListener) window.attachStopTimerToggleListener();
            if (window.updateSubmitLabel) window.updateSubmitLabel();
            if (button) button.disabled = false;
            if (discardButton) discardButton.disabled = false;
        }

        function timerAction(action) {
            var formData = new FormData();
            formData.append('ticket_id', localTicketId);
            formData.append('csrf_token', csrfToken);
            return fetch('index.php?page=api&action=' + action, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); });
        }

        function dispatchTimerChanged() {
            selfDispatch = true;
            document.dispatchEvent(new CustomEvent('timerStateChanged'));
            selfDispatch = false;
        }

        function onActionClick() {
            if (busy) return;
            busy = true;
            button.disabled = true;

            if (currentState === 'stopped') {
                buttonIcon.innerHTML = icons.spinner;
                buttonText.textContent = t('startingTimer', 'Starting...');
                timerAction('start-timer')
                    .then(function (data) {
                        if (data.success) {
                            timerStartTime = Math.floor(Date.now() / 1000);
                            pausedSeconds = 0;
                            setTimerState('running');
                            dispatchTimerChanged();
                            showToast(data.message || t('timerStarted', 'Timer started.'), 'success');
                        } else {
                            showToast(data.error || t('failStartTimer', 'Failed to start timer.'), 'error');
                            setTimerState('stopped');
                        }
                    })
                    .catch(function () {
                        showToast(t('genericError', 'An error occurred.'), 'error');
                        setTimerState('stopped');
                    })
                    .finally(function () { busy = false; });
                return;
            }

            if (currentState === 'running') {
                timerAction('pause-timer')
                    .then(function (data) {
                        if (data.success) {
                            setTimerState('paused', { elapsedSeconds: data.elapsed_seconds || 0 });
                            dispatchTimerChanged();
                            showToast(data.message || t('timerPaused', 'Timer paused.'), 'success');
                        } else {
                            showToast(data.error || t('failPauseTimer', 'Failed to pause timer.'), 'error');
                            button.disabled = false;
                        }
                    })
                    .catch(function () {
                        showToast(t('genericError', 'An error occurred.'), 'error');
                        button.disabled = false;
                    })
                    .finally(function () { busy = false; });
                return;
            }

            timerAction('resume-timer')
                .then(function (data) {
                    if (data.success) {
                        pausedSeconds = data.paused_seconds || pausedSeconds;
                        setTimerState('running');
                        dispatchTimerChanged();
                        showToast(data.message || t('timerResumed', 'Timer resumed.'), 'success');
                    } else {
                        showToast(data.error || t('failResumeTimer', 'Failed to resume timer.'), 'error');
                        button.disabled = false;
                    }
                })
                .catch(function () {
                    showToast(t('genericError', 'An error occurred.'), 'error');
                    button.disabled = false;
                })
                .finally(function () { busy = false; });
        }

        function onDiscardClick() {
            if (busy || !window.confirm(t('confirmDiscardTimer', 'Discard this timer? The tracked time will be lost.'))) return;
            busy = true;
            if (discardButton) discardButton.disabled = true;
            timerAction('discard-timer')
                .then(function (data) {
                    if (data.success) {
                        setTimerState('stopped');
                        dispatchTimerChanged();
                        showToast(data.message || t('timerDiscarded', 'Timer discarded.'), 'success');
                    } else {
                        showToast(data.error || t('failDiscardTimer', 'Failed to discard timer.'), 'error');
                        if (discardButton) discardButton.disabled = false;
                    }
                })
                .catch(function () {
                    showToast(t('genericError', 'An error occurred.'), 'error');
                    if (discardButton) discardButton.disabled = false;
                })
                .finally(function () { busy = false; });
        }

        if (button) button.addEventListener('click', onActionClick);
        if (discardButton) discardButton.addEventListener('click', onDiscardClick);
        var toolbarButton = document.getElementById('toolbar-timer-btn');
        if (toolbarButton) toolbarButton.addEventListener('click', onActionClick);
        var stopButton = document.getElementById('workspace-stop-timer');
        if (stopButton) stopButton.addEventListener('click', function () {
            if (busy || currentState === 'stopped') return;
            busy = true; stopButton.disabled = true;
            timerAction('stop-timer').then(function (data) {
                if (!data.success) throw new Error(data.error || t('genericError', 'An error occurred.'));
                setTimerState('stopped'); dispatchTimerChanged();
                if (data.entry_id) history.replaceState(null, '', '#time-entry-' + data.entry_id);
                location.reload();
            }).catch(function (error) { showToast(error.message, 'error'); })
                .finally(function () { busy = false; stopButton.disabled = false; });
        });

        document.addEventListener('timerStateChanged', function () {
            if (selfDispatch) return;
            fetch('index.php?page=api&action=get_active_timers')
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) return;
                    var mine = (data.timers || []).find(function (timer) { return timer.ticket_id == localTicketId; });
                    if (mine) {
                        timerStartTime = mine.started_at;
                        pausedSeconds = mine.paused_seconds || 0;
                        setTimerState(mine.is_paused ? 'paused' : 'running', { elapsedSeconds: (mine.elapsed_minutes || 0) * 60 });
                    } else if (currentState !== 'stopped') {
                        setTimerState('stopped');
                    }
                })
                .catch(function () {});
        });

        if (currentState === 'running') {
            timerInterval = setInterval(tick, 1000);
        }
        updateCompleteActionTitle(currentState);
        updateToolbarTimer(currentState, elapsed ? elapsed.textContent : '');
    }

        return { initTimer: initTimer };
    };
})(window, document);
