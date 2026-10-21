(function (window, document) {
    'use strict';

    var runtime = window.FoxDeskTicketDetailRuntime;
    if (!runtime) throw new Error('FoxDesk ticket detail core was not loaded.');
    var config = runtime.config;
    var icons = config.icons || {};
    var ticketId = runtime.ticketId;
    var csrfToken = runtime.csrfToken;
    var t = runtime.t;
    var escapeHtml = runtime.escapeHtml;
    var showToast = runtime.showToast;
    var showUndoToast = runtime.showUndoToast;
    var fadeRemove = runtime.fadeRemove;
    var fillTemplate = runtime.fillTemplate;
    var getIcon = runtime.getIcon;
    var pad2 = runtime.pad2;
    var formatDateInput = runtime.formatDateInput;
    var formatTimeInput = runtime.formatTimeInput;
    var formatDateTimeLocal = runtime.formatDateTimeLocal;

    function initManualTime() {
        var toggle = document.getElementById('manual-toggle');
        var row = document.getElementById('manual-entry-row');
        var duration = document.getElementById('manual-duration-minutes');
        var dateInput = document.querySelector('input[name="manual_date"]');
        var startInput = document.querySelector('input[name="manual_start_time"]');
        var endInput = document.querySelector('input[name="manual_end_time"]');
        var startAt = document.getElementById('manual-start-at');
        var endAt = document.getElementById('manual-end-at');
        var buttons = document.querySelectorAll('.manual-duration-chip');
        var applying = false;

        function setVisible(show) {
            if (!row || !toggle) return;
            row.classList.toggle('hidden', !show);
            toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        }

        function clearSnapshot(clearDurationValue, clearRangeValues) {
            if (clearDurationValue && duration) duration.value = '';
            if (startAt) startAt.value = '';
            if (endAt) endAt.value = '';
            if (clearRangeValues) {
                if (startInput) startInput.value = '';
                if (endInput) endInput.value = '';
            }
        }

        function applyDuration(minutes) {
            var parsed = parseInt(minutes, 10) || 0;
            if (!parsed || !dateInput || !startInput || !endInput) {
                clearSnapshot(false, true);
                window.updateSubmitLabel();
                return;
            }

            var end = new Date();
            var start = new Date(end.getTime() - (parsed * 60 * 1000));
            applying = true;
            if (duration) duration.value = parsed;
            dateInput.value = formatDateInput(start);
            startInput.value = formatTimeInput(start);
            endInput.value = formatTimeInput(end);
            if (startAt) startAt.value = formatDateTimeLocal(start);
            if (endAt) endAt.value = formatDateTimeLocal(end);
            applying = false;
            setVisible(true);
            window.updateSubmitLabel();
        }

        function switchToRangeMode() {
            if (applying) return;
            clearSnapshot(true);
            window.updateSubmitLabel();
        }

        if (toggle && row) {
            toggle.addEventListener('click', function () {
                setVisible(row.classList.contains('hidden'));
            });
        }
        if ((duration && duration.value) || (startInput && startInput.value) || (endInput && endInput.value)) setVisible(true);
        if (duration) {
            duration.addEventListener('change', function () {
                if (this.value) applyDuration(this.value);
                else {
                    clearSnapshot(false, true);
                    window.updateSubmitLabel();
                }
            });
            duration.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    applyDuration(this.value);
                }
            });
            duration.addEventListener('input', function () { window.updateSubmitLabel(); });
        }
        buttons.forEach(function (button) {
            button.addEventListener('click', function () { applyDuration(this.dataset.minutes); });
        });
        [dateInput, startInput, endInput].forEach(function (input) {
            if (!input) return;
            input.addEventListener('input', switchToRangeMode);
            input.addEventListener('input', function () { window.updateSubmitLabel(); });
        });
    }

    function initCcSearch() {
        var input = document.getElementById('cc-search-input');
        var dropdown = document.getElementById('cc-dropdown');
        var selected = document.getElementById('cc-selected');
        var hidden = document.getElementById('cc-hidden-inputs');
        var selectedUsers = [];
        var timeout = null;
        var isComposing = false;
        if (!input || !dropdown || !selected || !hidden) return;

        function removeUser(userId, event) {
            selectedUsers = selectedUsers.filter(function (user) { return user.id !== userId; });
            var chip = event && event.target ? event.target.closest('span') : null;
            if (chip) chip.remove();
            var userInput = document.getElementById('cc-user-' + userId);
            if (userInput) userInput.remove();
        }

        function addUser(user) {
            selectedUsers.push(user);
            var chip = document.createElement('span');
            chip.className = 'inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm';

            var name = document.createElement('span');
            name.textContent = user.name + ' ';
            chip.appendChild(name);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ml-2 hover:text-blue-900';
            remove.title = t('remove', 'Remove');
            remove.setAttribute('aria-label', t('remove', 'Remove'));
            remove.innerHTML = getIcon('times', 'w-3 h-3');
            remove.addEventListener('click', removeUser.bind(null, user.id));
            chip.appendChild(remove);
            selected.appendChild(chip);

            var userInput = document.createElement('input');
            userInput.type = 'hidden';
            userInput.name = 'cc_users[]';
            userInput.value = user.id;
            userInput.id = 'cc-user-' + user.id;
            hidden.appendChild(userInput);
            input.value = '';
            dropdown.classList.add('hidden');
        }

        function searchUsers(query) {
            fetch('index.php?page=api&action=search_users&q=' + encodeURIComponent(query))
                .then(function (response) { return response.json(); })
                .then(function (users) {
                    dropdown.innerHTML = '';
                    if (!users.length) {
                        dropdown.innerHTML = '<div class="px-3 py-2 text-sm" style="color: var(--text-muted)">' + escapeHtml(t('noUsersFound', 'No users found.')) + '</div>';
                        dropdown.classList.remove('hidden');
                        return;
                    }
                    users.forEach(function (user) {
                        if (selectedUsers.find(function (selectedUser) { return selectedUser.id === user.id; })) return;
                        var item = document.createElement('div');
                        item.className = 'px-3 py-2 cursor-pointer text-sm tr-hover';
                        item.innerHTML = '<strong>' + escapeHtml(user.name) + '</strong><br><span class="text-xs" style="color: var(--text-muted)">' + escapeHtml(user.email) + '</span>';
                        item.addEventListener('click', addUser.bind(null, user));
                        dropdown.appendChild(item);
                    });
                    dropdown.classList.remove('hidden');
                })
                .catch(function (error) {
                    console.error('Error searching users:', error);
                });
        }

        function requestUsers() {
            clearTimeout(timeout);
            if (isComposing) return;
            var query = input.value.trim();
            if (query.length < 2) {
                dropdown.classList.add('hidden');
                return;
            }
            timeout = setTimeout(function () { searchUsers(query); }, 300);
        }

        input.addEventListener('compositionstart', function () {
            isComposing = true;
            clearTimeout(timeout);
        });
        input.addEventListener('compositionend', function () {
            isComposing = false;
            requestUsers();
        });
        input.addEventListener('input', function (event) {
            if (event.isComposing) return;
            requestUsers();
        });
        document.addEventListener('click', function (event) {
            if (!input.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.add('hidden');
            }
        });
    }

    function initSubmitLabel() {
        // On the workflow composer, assets/js/ticket-workflow.js owns the label and registers window.updateSubmitLabel.
        if (document.querySelector('[data-workflow]')) return;
        var submit = document.getElementById('comment-submit-btn');
        window.updateSubmitLabel = function () {
            if (!submit) return;
            var stopToggle = document.getElementById('stop-timer-toggle');
            var hasActiveTimer = submit.dataset.hasActiveTimer === '1';
            var stopRequested = hasActiveTimer && stopToggle && stopToggle.checked;
            var hasManualTime =
                (document.getElementById('manual-duration-minutes') && document.getElementById('manual-duration-minutes').value) ||
                (document.querySelector('input[name="manual_start_time"]') && document.querySelector('input[name="manual_start_time"]').value) ||
                (document.querySelector('input[name="manual_end_time"]') && document.querySelector('input[name="manual_end_time"]').value);

            var label = submit.dataset.defaultText;
            if (stopRequested) label = submit.dataset.stopText;
            else if (hasManualTime) label = submit.dataset.logTimeText;

            var span = submit.querySelector('.btn-text');
            if (span) span.textContent = label;
        };

        window.attachStopTimerToggleListener = function () {
            var toggle = document.getElementById('stop-timer-toggle');
            if (toggle) toggle.addEventListener('change', window.updateSubmitLabel);
        };
        window.attachStopTimerToggleListener();
        window.updateSubmitLabel();
    }

    function initCommentMode() {
        var buttons = document.querySelectorAll('.comment-mode-btn');
        var internalToggle = document.getElementById('is_internal_toggle');
        var internalSection = document.getElementById('internal-comment-section');
        var publicSection = document.getElementById('public-comment-section');
        var commentText = document.getElementById('comment-text');
        var internalText = document.getElementById('internal-text');
        var hint = document.getElementById('comment-mode-hint');

        function setMode(mode) {
            var isInternal = mode === 'internal';
            if (internalToggle) internalToggle.checked = isInternal;
            if (internalSection) internalSection.classList.toggle('hidden', !isInternal);
            if (publicSection) publicSection.classList.toggle('hidden', isInternal);
            if (commentText) {
                if (isInternal) commentText.removeAttribute('required');
                else if (commentText.hasAttribute('data-required')) commentText.setAttribute('required', 'required');
            }
            if (internalText) {
                if (isInternal) internalText.setAttribute('required', 'required');
                else internalText.removeAttribute('required');
            }
            if (hint) hint.textContent = isInternal ? t('visibleAgents', 'Visible to agents only') : t('visibleCustomer', 'Visible to customer');
            buttons.forEach(function (button) {
                var active = button.dataset.mode === mode;
                button.classList.toggle('shadow', active);
                button.classList.toggle('text-blue-600', active);
                button.style.background = active ? 'var(--bg-primary)' : '';
                button.style.color = active ? '' : 'var(--text-muted)';
            });
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                setMode(button.dataset.mode);
            });
        });
        if (buttons.length) setMode('public');
    }

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
            var toolbar = document.getElementById('toolbar-timer-btn');
            if (!toolbar) return;
            var toolbarElapsed = document.getElementById('toolbar-timer-elapsed');

            if (state === 'running' || state === 'paused') {
                toolbar.className = state === 'running' ? 'td-tool-btn td-tool-btn--active-timer' : 'td-tool-btn';
                toolbar.title = state === 'running' ? t('pauseTimerHelp', 'Pause this timer without logging time yet.') : t('resumeTimerHelp', 'Resume the paused timer.');
                toolbar.setAttribute('aria-label', toolbar.title);
                toolbar.textContent = '';
                toolbar.insertAdjacentHTML('afterbegin', state === 'running' ? icons.pauseSm : icons.playSm);
                if (!toolbarElapsed) {
                    toolbarElapsed = document.createElement('span');
                    toolbarElapsed.id = 'toolbar-timer-elapsed';
                    toolbarElapsed.className = 'text-xs tabular-nums';
                    toolbar.parentNode.insertBefore(toolbarElapsed, toolbar.nextSibling);
                }
                toolbarElapsed.style.color = state === 'running' ? 'var(--warning)' : 'var(--success)';
                toolbarElapsed.textContent = timeText || '';
            } else {
                toolbar.className = 'td-tool-btn';
                toolbar.title = t('startTimerHelp', 'Start a timer for this ticket.');
                toolbar.setAttribute('aria-label', toolbar.title);
                toolbar.textContent = '';
                toolbar.insertAdjacentHTML('afterbegin', icons.playSm);
                if (toolbarElapsed) toolbarElapsed.remove();
            }
        }

        function updateCompleteActionTitle(state) {
            var completeButton = document.querySelector('button[name="change_status"]');
            if (!completeButton) return;
            var title = state === 'running' || state === 'paused'
                ? t('completeTimerHelp', 'Mark this ticket as done and stop the active timer.')
                : t('completeHelp', 'Mark this ticket as done.');
            completeButton.title = title;
            completeButton.setAttribute('aria-label', title);
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
                button.className = 'btn btn-warning px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
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
                button.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
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
                button.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
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
    }

    function initAgentCcDropdown() {
        var toggle = document.getElementById('agent-cc-toggle');
        var list = document.getElementById('agent-cc-list');
        var display = document.getElementById('agent-cc-display');
        var checkboxes = document.querySelectorAll('.agent-cc-checkbox');
        if (!toggle || !list || !display) return;

        var noneText = toggle.dataset.noneText || 'Select users...';
        var selectedText = toggle.dataset.selectedText || 'Selected';
        function update() {
            var checked = document.querySelectorAll('.agent-cc-checkbox:checked');
            display.textContent = checked.length === 0 ? noneText : selectedText + ': ' + checked.length;
        }
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            list.classList.toggle('hidden');
        });
        checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', update); });
        document.addEventListener('click', function (event) {
            if (!event.target.closest('#agent-cc-dropdown-container')) list.classList.add('hidden');
        });
        update();
    }

    runtime.initManualTime = initManualTime;
    runtime.initCcSearch = initCcSearch;
    runtime.initSubmitLabel = initSubmitLabel;
    runtime.initCommentMode = initCommentMode;
    runtime.initTimer = initTimer;
    runtime.initAgentCcDropdown = initAgentCcDropdown;
})(window, document);
