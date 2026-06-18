(function (window, document) {
    'use strict';

    var config = window.FoxDeskTicketDetailConfig || {};
    var labels = config.labels || {};
    var icons = config.icons || {};
    var ticketId = config.ticketId || null;
    var csrfToken = config.csrfToken || window.csrfToken || '';

    var fileIconPaths = {
        times: '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>',
        file: '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline>',
        'file-image': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
        'file-pdf': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-word': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-excel': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-archive': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    };

    function t(key, fallback) {
        return Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : fallback;
    }

    function getIcon(name, classes) {
        var path = fileIconPaths[name] || fileIconPaths.file;
        return '<svg xmlns="http://www.w3.org/2000/svg" class="' + (classes || '') + '" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
    }
    window.getIcon = window.getIcon || getIcon;

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function showToast(message, type) {
        type = type || 'success';
        if (typeof window.showAppToast === 'function') {
            if (window.showAppToast(message, type)) return;
        }
        if (window.appNotificationPrefs && window.appNotificationPrefs.inAppEnabled === false) return;

        var toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 px-4 py-2 rounded-lg shadow-lg text-sm font-medium z-50 transition-opacity duration-300 ' + (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }
    window.showToastGlobal = showToast;

    window.quickEditField = function (action, data) {
        var body = new FormData();
        body.append('ticket_id', ticketId);
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });

        fetch(window.appConfig.apiUrl + '&action=' + action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            body: body
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result.success) {
                    showToast(result.message || t('saved', 'Saved'), 'success');
                } else {
                    showToast(result.error || t('error', 'Error'), 'error');
                }
            })
            .catch(function () {
                showToast(t('error', 'Error'), 'error');
            });
    };

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function fillTemplate(template, replacements) {
        var output = String(template || '');
        Object.keys(replacements || {}).forEach(function (key) {
            output = output.split('{' + key + '}').join(replacements[key]);
        });
        return output;
    }

    function formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    function fileIconName(mimeType) {
        mimeType = String(mimeType || '');
        if (mimeType.indexOf('image/') === 0) return 'file-image';
        if (mimeType === 'application/pdf') return 'file-pdf';
        if (mimeType.indexOf('word') !== -1) return 'file-word';
        if (mimeType.indexOf('excel') !== -1 || mimeType.indexOf('spreadsheet') !== -1) return 'file-excel';
        if (mimeType.indexOf('zip') !== -1 || mimeType.indexOf('rar') !== -1) return 'file-archive';
        return 'file';
    }

    function fallbackUploadPreview(options) {
        var input = document.getElementById(options.inputId);
        var preview = document.getElementById(options.previewId);
        var limit = options.limit || {};
        var removeLabel = options.removeLabel || t('remove', 'Remove');
        if (!input || !preview) {
            return { enforceLimits: function () { return { changed: false, hadErrors: false }; } };
        }

        function showLimit(message) {
            if (!message) return;
            showToast(message, 'error');
        }

        function enforceLimits() {
            if (typeof DataTransfer === 'undefined') return { changed: false, hadErrors: false };
            var dt = new DataTransfer();
            var originalCount = input.files.length;
            var totalSize = 0;
            var hadErrors = false;
            var totalErrorShown = false;

            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                if (limit.single > 0 && file.size > limit.single) {
                    hadErrors = true;
                    showLimit(fillTemplate(limit.singleTemplate, { name: file.name, size: formatFileSize(limit.single) }));
                    continue;
                }
                if (limit.total > 0 && totalSize + file.size > limit.total) {
                    hadErrors = true;
                    if (!totalErrorShown) {
                        showLimit(fillTemplate(limit.totalTemplate, { size: formatFileSize(limit.total) }));
                        totalErrorShown = true;
                    }
                    continue;
                }
                totalSize += file.size;
                dt.items.add(file);
            }

            if (originalCount !== dt.files.length) {
                input.files = dt.files;
                return { changed: true, hadErrors: hadErrors };
            }
            return { changed: false, hadErrors: hadErrors };
        }

        function removeFile(index) {
            var dt = new DataTransfer();
            for (var i = 0; i < input.files.length; i++) {
                if (i !== index) dt.items.add(input.files[i]);
            }
            input.files = dt.files;
            updatePreview();
        }
        window.removeCommentFile = removeFile;

        function updatePreview() {
            var validation = enforceLimits();
            preview.innerHTML = '';
            if (input.files.length === 0) {
                preview.classList.add('hidden');
                return validation;
            }

            preview.classList.remove('hidden');
            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                var row = document.createElement('div');
                row.className = 'flex items-center justify-between rounded-lg px-4 py-2';
                row.style.background = 'var(--surface-secondary)';
                row.innerHTML = '<div class="flex items-center space-x-3 min-w-0">' +
                    getIcon(fileIconName(file.type), 'td-text-muted flex-shrink-0 w-4 h-4') +
                    '<span class="text-sm truncate" style="color: var(--text-secondary)"></span>' +
                    '<span class="text-xs flex-shrink-0" style="color: var(--text-muted)">' + escapeHtml(formatFileSize(file.size)) + '</span>' +
                    '</div>';
                row.querySelector('.truncate').textContent = file.name;

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'text-red-400 hover:text-red-500 ml-2 flex-shrink-0';
                button.title = removeLabel;
                button.setAttribute('aria-label', removeLabel);
                button.innerHTML = getIcon('times', 'w-4 h-4');
                button.addEventListener('click', removeFile.bind(null, i));
                row.appendChild(button);
                preview.appendChild(row);
            }
            return validation;
        }

        if (window.initFileDropzone && options.zoneId) {
            window.initFileDropzone({ zoneId: options.zoneId, inputId: options.inputId, onFilesChanged: updatePreview });
        } else {
            input.addEventListener('change', updatePreview);
        }
        return { enforceLimits: enforceLimits, updatePreview: updatePreview };
    }

    function initUploadPreview() {
        var uploadConfig = config.upload || {};
        var options = {
            zoneId: 'comment-upload-zone',
            inputId: 'comment-file-input',
            previewId: 'comment-file-preview',
            removeLabel: t('remove', 'Remove'),
            limit: {
                single: uploadConfig.single || 0,
                total: uploadConfig.total || 0,
                singleTemplate: uploadConfig.singleTemplate || '',
                totalTemplate: uploadConfig.totalTemplate || ''
            },
            rowClass: uploadConfig.rowClass || 'flex items-center justify-between rounded-lg px-4 py-2',
            iconClass: uploadConfig.iconClass || 'td-text-muted flex-shrink-0 w-4 h-4',
            metaClass: uploadConfig.metaClass || 'flex items-center gap-3 min-w-0',
            nameClass: uploadConfig.nameClass || 'text-sm truncate',
            sizeClass: uploadConfig.sizeClass || 'text-xs flex-shrink-0',
            removeButtonClass: uploadConfig.removeButtonClass || 'text-red-400 hover:text-red-500 ml-2 flex-shrink-0',
            removeIconClass: uploadConfig.removeIconClass || 'w-4 h-4',
            sizeDecimals: uploadConfig.sizeDecimals || 2
        };

        var instance = window.FoxDeskUploadPreview && window.FoxDeskUploadPreview.init
            ? window.FoxDeskUploadPreview.init(options)
            : fallbackUploadPreview(options);

        window.enforceCommentUploadLimits = function () {
            if (instance && instance.enforceLimits) return instance.enforceLimits();
            return { changed: false, hadErrors: false };
        };
    }

    function initShareCopy() {
        var button = document.getElementById('share-copy-btn');
        var input = document.getElementById('share-link-input');
        if (!button || !input) return;

        button.addEventListener('click', function () {
            var value = input.value;
            var reset = function () {
                setTimeout(function () { button.textContent = t('copy', 'Copy'); }, 1500);
            };
            var copied = function () {
                button.textContent = t('copied', 'Copied');
                reset();
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value).then(copied).catch(function () {
                    button.textContent = t('error', 'Error');
                    reset();
                });
            } else {
                input.select();
                document.execCommand('copy');
                copied();
            }
        });
    }

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatDateInput(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }

    function formatTimeInput(date) {
        return pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    }

    function formatDateTimeLocal(date) {
        return formatDateInput(date) + 'T' + formatTimeInput(date);
    }

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

        input.addEventListener('input', function () {
            var query = this.value.trim();
            if (query.length < 2) {
                dropdown.classList.add('hidden');
                return;
            }
            clearTimeout(timeout);
            timeout = setTimeout(function () { searchUsers(query); }, 300);
        });
        document.addEventListener('click', function (event) {
            if (!input.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.add('hidden');
            }
        });
    }

    function initSubmitLabel() {
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
                if (window.commentEditor) window.commentEditor.setText('');
                if (window.internalEditor) window.internalEditor.setText('');
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
                toolbar.title = state === 'running' ? t('pauseTimer', 'Pause timer') : t('resumeTimer', 'Resume timer');
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
                toolbar.title = t('startTimer', 'Start timer');
                toolbar.textContent = '';
                toolbar.insertAdjacentHTML('afterbegin', icons.playSm);
                if (toolbarElapsed) toolbarElapsed.remove();
            }
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
                button.title = t('pauseTimer', 'Pause timer');
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
            } else if (state === 'paused') {
                var elapsedSec = opts.elapsedSeconds || 0;
                var elapsedMin = Math.floor(elapsedSec / 60);
                button.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
                button.title = t('resumeTimer', 'Resume timer');
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
            } else {
                button.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
                button.title = t('startTimer', 'Start timer');
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

    var editCommentEditor = null;
    window.openEditCommentModal = function (commentId, content) {
        var modal = document.getElementById('edit-comment-modal');
        var input = document.getElementById('edit-comment-id');
        if (!modal || !input || typeof window.Quill === 'undefined') return;
        input.value = commentId;
        modal.classList.remove('hidden');
        if (!editCommentEditor) {
            editCommentEditor = new window.Quill('#edit-comment-editor', {
                theme: 'snow',
                placeholder: t('editCommentPlaceholder', 'Edit your comment...'),
                modules: { toolbar: [[{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']] }
            });
        }
        if (String(content || '').indexOf('<') !== -1 && String(content || '').indexOf('>') !== -1) {
            editCommentEditor.root.innerHTML = content;
        } else {
            editCommentEditor.setText(content || '');
        }
        setTimeout(function () { editCommentEditor.focus(); }, 100);
    };

    window.closeEditCommentModal = function () {
        var modal = document.getElementById('edit-comment-modal');
        if (modal) modal.classList.add('hidden');
        if (editCommentEditor) editCommentEditor.setText('');
    };

    window.submitEditComment = function (event) {
        event.preventDefault();
        var form = event.target;
        var commentId = form.querySelector('#edit-comment-id').value;
        var content = '';
        if (editCommentEditor) {
            var html = editCommentEditor.root.innerHTML;
            content = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
        }
        if (!content) {
            window.alert(t('commentEmpty', 'Comment cannot be empty.'));
            return;
        }

        var formData = new FormData();
        formData.append('comment_id', commentId);
        formData.append('content', content);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=edit-comment', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    var contentNode = document.getElementById('comment-content-' + commentId);
                    if (contentNode) contentNode.innerHTML = data.content_html;
                    if (config.canViewEditHistory) {
                        var comment = document.getElementById('comment-' + commentId);
                        if (comment && !comment.querySelector('.edited-indicator')) {
                            var timestamp = comment.querySelector('.text-sm[style*="--text-muted"]');
                            if (timestamp) {
                                var edited = document.createElement('span');
                                edited.className = 'text-xs italic edited-indicator ml-1';
                                edited.style.color = 'var(--text-muted)';
                                edited.textContent = '(' + t('edited', 'edited') + ')';
                                timestamp.parentNode.insertBefore(edited, timestamp.nextSibling);
                            }
                        }
                    }
                    window.closeEditCommentModal();
                    showToast(data.message || t('commentUpdated', 'Comment updated.'), 'success');
                } else {
                    window.alert(data.error || t('commentUpdateFailed', 'Failed to update comment.'));
                }
            })
            .catch(function () {
                window.alert(t('genericError', 'An error occurred.'));
            });
    };

    window.deleteComment = function (commentId) {
        if (!window.confirm(t('confirmDeleteComment', 'Are you sure you want to delete this comment?'))) return;
        var formData = new FormData();
        formData.append('comment_id', commentId);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=delete-comment', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    var comment = document.getElementById('comment-' + commentId);
                    if (comment) {
                        comment.style.opacity = '0';
                        comment.style.transition = 'opacity 0.3s';
                        setTimeout(function () { comment.remove(); }, 300);
                    }
                    showToast(data.message || t('commentDeleted', 'Comment deleted.'), 'success');
                } else {
                    window.alert(data.error || t('commentDeleteFailed', 'Failed to delete comment.'));
                }
            })
            .catch(function () {
                window.alert(t('genericError', 'An error occurred.'));
            });
    };

    window.openEditTimeEntry = function (entry) {
        var modal = document.getElementById('edit-time-modal');
        if (!modal) return;
        document.getElementById('edit-time-id').value = entry.id;
        document.getElementById('edit-time-summary').value = entry.summary || '';
        document.getElementById('edit-time-billable').checked = entry.is_billable == 1;
        if (entry.started_at) {
            var start = new Date(entry.started_at.replace(' ', 'T'));
            document.getElementById('edit-time-date-picker').value = start.getFullYear() + '-' + pad2(start.getMonth() + 1) + '-' + pad2(start.getDate());
            document.getElementById('edit-time-start-time').value = pad2(start.getHours()) + ':' + pad2(start.getMinutes());
        }
        if (entry.ended_at) {
            var end = new Date(entry.ended_at.replace(' ', 'T'));
            document.getElementById('edit-time-end-time').value = pad2(end.getHours()) + ':' + pad2(end.getMinutes());
        }
        syncEditTimeHiddenFields();
        window.updateTimeDuration();
        modal.classList.remove('hidden');
    };

    window.closeEditTimeModal = function () {
        var modal = document.getElementById('edit-time-modal');
        if (modal) modal.classList.add('hidden');
    };

    function syncEditTimeHiddenFields() {
        var date = document.getElementById('edit-time-date-picker') ? document.getElementById('edit-time-date-picker').value : '';
        var start = document.getElementById('edit-time-start-time') ? document.getElementById('edit-time-start-time').value : '';
        var end = document.getElementById('edit-time-end-time') ? document.getElementById('edit-time-end-time').value : '';
        if (date && start) document.getElementById('edit-time-start').value = date + 'T' + start;
        if (date && end) document.getElementById('edit-time-end').value = date + 'T' + end;
    }

    window.updateTimeDuration = function () {
        syncEditTimeHiddenFields();
        var startInput = document.getElementById('edit-time-start');
        var endInput = document.getElementById('edit-time-end');
        var duration = document.getElementById('edit-time-duration');
        if (!startInput || !endInput || !duration) return;
        var start = new Date(startInput.value);
        var end = new Date(endInput.value);
        if (start && end && end > start) {
            var diffMins = Math.floor((end - start) / 60000);
            var hours = Math.floor(diffMins / 60);
            var mins = diffMins % 60;
            duration.textContent = hours > 0 ? hours + 'h ' + mins + 'min' : mins + ' min';
            duration.classList.remove('text-red-600');
            duration.classList.add('text-blue-600');
        } else {
            duration.textContent = t('invalidRange', 'Invalid range');
            duration.classList.remove('text-blue-600');
            duration.classList.add('text-red-600');
        }
    };

    function initEditTimeForm() {
        ['edit-time-date-picker', 'edit-time-start-time', 'edit-time-end-time'].forEach(function (id) {
            var input = document.getElementById(id);
            if (input) input.addEventListener('change', window.updateTimeDuration);
        });
        var form = document.getElementById('edit-time-form');
        if (form) form.addEventListener('submit', syncEditTimeHiddenFields);
    }

    function initTags() {
        var tagConfig = config.tags || {};
        if (!tagConfig.enabled || typeof window.ChipSelect === 'undefined') return;
        var editButton = document.getElementById('sidebar-tags-edit-btn');
        var display = document.getElementById('sidebar-tags-display');
        var editor = document.getElementById('sidebar-tags-editor');
        var saveButton = document.getElementById('sidebar-tags-save');
        var cancelButton = document.getElementById('sidebar-tags-cancel');
        if (!editButton || !editor || !display || !saveButton || !cancelButton) return;

        var chipSelect = null;
        var itemsLoaded = false;
        var currentTags = (tagConfig.current || []).slice();
        var filterUrlBase = tagConfig.filterUrlBase || 'index.php?page=tickets';

        function initChipSelect(allTags) {
            chipSelect = new window.ChipSelect({
                wrapId: 'cs-tags-detail-wrap',
                chipsId: 'cs-tags-detail-chips',
                inputId: 'cs-tags-detail-input',
                dropdownId: 'cs-tags-detail-dropdown',
                hiddenId: 'cs-tags-detail-hidden',
                items: allTags,
                selected: currentTags.slice(),
                name: 'tags[]',
                allowCreate: true,
                noMatchText: t('noMatches', 'No matches')
            });
        }

        function rebuild(allTags) {
            document.getElementById('cs-tags-detail-chips').innerHTML = '';
            document.getElementById('cs-tags-detail-hidden').innerHTML = '';
            initChipSelect(allTags);
        }

        function showEditor() {
            display.classList.add('hidden');
            editButton.classList.add('hidden');
            editor.classList.remove('hidden');
        }

        function hideEditor() {
            editor.classList.add('hidden');
            display.classList.remove('hidden');
            editButton.classList.remove('hidden');
        }

        editButton.addEventListener('click', function () {
            if (!itemsLoaded) {
                fetch('index.php?page=api&action=get-tags')
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        itemsLoaded = true;
                        initChipSelect(data && data.tags ? data.tags : []);
                        showEditor();
                    });
            } else {
                rebuild(chipSelect ? chipSelect.items : []);
                showEditor();
            }
        });
        cancelButton.addEventListener('click', hideEditor);
        saveButton.addEventListener('click', function () {
            if (!chipSelect) return;
            var tags = chipSelect.getSelectedValues().join(', ');
            saveButton.disabled = true;
            var formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('tags', tags);
            formData.append('csrf_token', csrfToken);
            fetch('index.php?page=api&action=update-tags', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    saveButton.disabled = false;
                    if (!data || !data.success) return;
                    currentTags = data.tags || [];
                    var html = '';
                    currentTags.forEach(function (tag) {
                        html += '<a href="' + filterUrlBase + '&tags=' + encodeURIComponent(tag) + '" class="ticket-tag-pill" title="' + escapeHtml(t('filterByTag', 'Filter by this tag')) + '">#' + escapeHtml(tag) + '</a>';
                    });
                    display.innerHTML = html || '<span class="text-xs" style="color: var(--text-muted);">-</span>';
                    hideEditor();
                })
                .catch(function () { saveButton.disabled = false; });
        });
    }

    function quillToolbar() {
        return [[{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'image'], ['clean']];
    }

    var editTicketReturnFocus = null;
    var editDescriptionEditor = null;
    window.openEditTicketModal = function () {
        editTicketReturnFocus = document.activeElement;
        var modal = document.getElementById('edit-ticket-modal');
        if (!modal) return;
        modal.classList.remove('hidden');
        if (!editDescriptionEditor && typeof window.Quill !== 'undefined') {
            var editor = document.getElementById('edit-description-editor');
            if (editor) {
                editDescriptionEditor = new window.Quill('#edit-description-editor', {
                    theme: 'snow',
                    placeholder: t('descriptionPlaceholder', 'Description...'),
                    modules: { toolbar: quillToolbar() }
                });
                if (window.initQuillImageUpload) window.initQuillImageUpload(editDescriptionEditor, config.quillUpload || {});
                var existing = document.getElementById('edit-description-input').value;
                if (existing) editDescriptionEditor.clipboard.dangerouslyPasteHTML(existing);
            }
        }
        var first = modal.querySelector('input:not([type="hidden"]), select, textarea');
        if (first) first.focus();
        if (typeof window.trapFocus === 'function') window.trapFocus(modal);
    };

    window.closeEditTicketModal = function () {
        var modal = document.getElementById('edit-ticket-modal');
        if (modal) {
            if (typeof window.releaseFocus === 'function') window.releaseFocus(modal);
            modal.classList.add('hidden');
        }
        if (editTicketReturnFocus) {
            editTicketReturnFocus.focus();
            editTicketReturnFocus = null;
        }
    };

    function initQuillEditors() {
        if (typeof window.Quill === 'undefined') return;
        var upload = config.quillUpload || {};
        var commentEl = document.getElementById('comment-editor');
        if (commentEl) {
            window.commentEditor = new window.Quill('#comment-editor', {
                theme: 'snow',
                placeholder: t('replyPlaceholder', 'Write a reply...'),
                modules: { toolbar: quillToolbar() }
            });
            if (window.initQuillImageUpload) window.initQuillImageUpload(window.commentEditor, upload);
        }
        var internalEl = document.getElementById('internal-editor');
        if (internalEl) {
            window.internalEditor = new window.Quill('#internal-editor', {
                theme: 'snow',
                placeholder: t('internalPlaceholder', 'Internal note for agents...'),
                modules: { toolbar: quillToolbar() }
            });
            if (window.initQuillImageUpload) window.initQuillImageUpload(window.internalEditor, upload);
        }

        var form = document.getElementById('comment-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                var uploadValidation = window.enforceCommentUploadLimits ? window.enforceCommentUploadLimits() : { hadErrors: false };
                var fileInput = document.getElementById('comment-file-input');
                if (uploadValidation.hadErrors && fileInput && fileInput.files.length === 0) {
                    event.preventDefault();
                    return;
                }
                var isInternal = document.getElementById('is_internal_toggle') && document.getElementById('is_internal_toggle').checked;
                var commentText = document.getElementById('comment-text');
                var internalText = document.getElementById('internal-text');
                if (isInternal && window.internalEditor) {
                    var internalHtml = window.internalEditor.root.innerHTML;
                    if (internalText) internalText.value = (internalHtml === '<p><br></p>' || internalHtml === '<p></p>') ? '' : internalHtml;
                    if (commentText) commentText.value = '';
                } else if (window.commentEditor) {
                    var publicHtml = window.commentEditor.root.innerHTML;
                    if (commentText) commentText.value = (publicHtml === '<p><br></p>' || publicHtml === '<p></p>') ? '' : publicHtml;
                    if (internalText) internalText.value = '';
                }
                var stop = document.getElementById('stop-timer-toggle');
                if (stop && stop.checked && !stop.disabled) {
                    var manualStart = document.querySelector('input[name="manual_start_time"]');
                    var manualEnd = document.querySelector('input[name="manual_end_time"]');
                    if (manualStart) manualStart.value = '';
                    if (manualEnd) manualEnd.value = '';
                }
            });
        }

        var editForm = document.getElementById('edit-ticket-form');
        if (editForm) {
            editForm.addEventListener('submit', function () {
                if (!editDescriptionEditor) return;
                var html = editDescriptionEditor.root.innerHTML;
                document.getElementById('edit-description-input').value = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
            });
        }
    }

    function initImagePreview() {
        function getName(img) {
            var alt = (img.getAttribute('alt') || '').trim();
            if (alt) return alt;
            var src = img.currentSrc || img.getAttribute('src') || '';
            if (!src) return '';
            try {
                var url = new URL(src, window.location.origin);
                var fileParam = url.searchParams.get('f');
                if (fileParam) return decodeURIComponent(fileParam.split('/').pop() || fileParam);
                return decodeURIComponent(url.pathname.split('/').pop() || '');
            } catch (error) {
                var fallback = src.split('/').pop() || '';
                return decodeURIComponent((fallback.split('?')[0] || fallback));
            }
        }

        document.addEventListener('click', function (event) {
            var img = event.target.closest('.rich-content img.rich-inline-image');
            if (!img || img.closest('.link-preview-card')) return;
            event.preventDefault();
            event.stopPropagation();
            if (typeof window.openImagePreview === 'function') {
                window.openImagePreview(img.currentSrc || img.src, getName(img));
            }
        });
    }

    function initAutosave() {
        if (!window.FoxDeskAutosave || !window.commentEditor || !ticketId) return;
        var draft = window.FoxDeskAutosave.create({
            key: 'foxdesk_draft_comment_' + ticketId,
            formSelector: '#comment-form',
            quillEditors: { comment: window.commentEditor },
            fields: [{ name: 'comment', type: 'quill', editorKey: 'comment', selector: '#comment-text' }],
            onRestore: function (relativeTime) {
                showToast(t('draftRestored', 'Draft restored') + ' (' + relativeTime + ')', 'info');
            }
        });
        draft.init();
    }

    window.openTicketTimeline = function (id) {
        var overlay = document.getElementById('timeline-overlay');
        var content = document.getElementById('timeline-content');
        if (!overlay || !content) return;
        overlay.style.display = '';
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        content.innerHTML = '<div class="ticket-timeline-empty">' + escapeHtml(t('loading', 'Loading...')) + '</div>';
        document.body.style.overflow = 'hidden';
        document.body.classList.add('ticket-timeline-open');

        fetch('index.php?page=api&action=get-timeline&ticket_id=' + encodeURIComponent(id), {
            headers: { 'X-CSRF-TOKEN': csrfToken }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success || !data.events || !data.events.length) {
                    content.innerHTML = '<div class="ticket-timeline-empty">' + escapeHtml(t('noActivity', 'No activity found')) + '</div>';
                    return;
                }
                var html = '';
                data.events.forEach(function (event) {
                    html += '<div class="tl-event">';
                    html += '<div class="tl-dot" style="border-color:' + escapeHtml(event.color) + ';"></div>';
                    html += '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;">';
                    html += '<div><span class="tl-user">' + escapeHtml(event.user_name) + '</span> <span class="tl-label">' + escapeHtml(event.label) + '</span></div>';
                    html += '<span class="tl-time">' + formatTimelineDate(event.timestamp) + '</span></div>';
                    if (event.type === 'change' && (event.old_value || event.new_value)) {
                        html += '<div class="tl-change">';
                        if (event.old_value) html += '<span class="tl-old">' + event.old_value + '</span>';
                        html += '<span class="tl-arrow">&rarr;</span>';
                        if (event.new_value) html += '<span class="tl-new">' + event.new_value + '</span>';
                        html += '</div>';
                    }
                    if (event.detail) html += '<div class="tl-detail">' + escapeHtml(event.detail) + '</div>';
                    html += '</div>';
                });
                content.innerHTML = html;
            })
            .catch(function () {
                content.innerHTML = '<div class="ticket-timeline-empty">' + escapeHtml(t('timelineError', 'Error loading timeline')) + '</div>';
            });
    };

    window.closeTimeline = function () {
        var overlay = document.getElementById('timeline-overlay');
        if (overlay) {
            overlay.classList.remove('is-open');
            overlay.style.display = 'none';
            overlay.setAttribute('aria-hidden', 'true');
        }
        document.body.style.overflow = '';
        document.body.classList.remove('ticket-timeline-open');
    };

    function formatTimelineDate(timestamp) {
        if (!timestamp) return '';
        var date = new Date(timestamp.replace(' ', 'T'));
        var now = new Date();
        var time = pad2(date.getHours()) + ':' + pad2(date.getMinutes());
        if (date.toDateString() === now.toDateString()) return time;
        return pad2(date.getDate()) + '.' + pad2(date.getMonth() + 1) + '. ' + time;
    }

    ready(function () {
        initUploadPreview();
        initShareCopy();
        initSubmitLabel();
        initManualTime();
        initCcSearch();
        initCommentMode();
        initTimer();
        initAgentCcDropdown();
        initEditTimeForm();
        initTags();
        initQuillEditors();
        initImagePreview();
        initAutosave();

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            window.closeEditCommentModal();
            window.closeEditTimeModal();
            var timeline = document.getElementById('timeline-overlay');
            if (timeline && timeline.classList.contains('is-open')) window.closeTimeline();
        });
    });
})(window, document);
