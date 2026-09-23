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

    function showToast(message, type, options) {
        type = type || 'success';
        if (typeof window.showAppToast === 'function') {
            if (window.showAppToast(message, type, options || {})) return;
        }
        if (window.appNotificationPrefs && window.appNotificationPrefs.inAppEnabled === false) return;

        var toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 px-4 py-2 fd-rounded-card shadow-lg text-sm font-medium z-50 transition-opacity duration-300 ' + (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }
    window.showToastGlobal = showToast;

    function restoreDeletedItem(action, undoToken) {
        if (!action || !undoToken) return;

        var formData = new FormData();
        formData.append('undo_token', undoToken);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=' + encodeURIComponent(action), { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast(data.message || t('restored', 'Restored.'), 'success');
                    window.setTimeout(function () { window.location.reload(); }, 350);
                } else {
                    showToast(data.error || t('undoFailed', 'Undo is no longer available.'), 'error', { force: true });
                }
            })
            .catch(function () {
                showToast(t('genericError', 'An error occurred.'), 'error', { force: true });
            });
    }

    function showUndoToast(message, data) {
        showToast(message, 'success', {
            force: true,
            duration: Math.max(1000, Number(data.undo_seconds || 10) * 1000),
            actionLabel: data.undo_label || t('undo', 'Undo'),
            onAction: function () {
                restoreDeletedItem(data.undo_action, data.undo_token);
            }
        });
    }

    function fadeRemove(node) {
        if (!node) return;
        node.style.opacity = '0';
        node.style.transition = 'opacity 0.2s';
        setTimeout(function () { node.remove(); }, 220);
    }

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

    function quillFieldValue(editor) {
        if (window.FoxDeskRichText && typeof window.FoxDeskRichText.fieldValue === 'function') {
            return window.FoxDeskRichText.fieldValue(editor);
        }
        if (!editor || !editor.root) return '';
        var html = String(editor.root.innerHTML || '').trim();
        return (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
    }

    function loadQuillContent(editor, content) {
        if (window.FoxDeskRichText && typeof window.FoxDeskRichText.loadHtml === 'function') {
            window.FoxDeskRichText.loadHtml(editor, content);
            return;
        }
        if (String(content || '').indexOf('<') !== -1 && String(content || '').indexOf('>') !== -1) {
            editor.clipboard.dangerouslyPasteHTML(content || '');
        } else {
            editor.setText(content || '');
        }
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
                row.className = 'flex items-center justify-between fd-rounded-card px-4 py-2 theme-surface';
                row.innerHTML = '<div class="flex items-center space-x-3 min-w-0">' +
                    getIcon(fileIconName(file.type), 'td-text-muted flex-shrink-0 w-4 h-4') +
                    '<span class="text-sm truncate theme-text-secondary"></span>' +
                    '<span class="text-xs flex-shrink-0 theme-text-muted">' + escapeHtml(formatFileSize(file.size)) + '</span>' +
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
            rowClass: uploadConfig.rowClass || 'flex items-center justify-between fd-rounded-card px-4 py-2',
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

        if (window.FoxDeskAttachmentPasteDrop) {
            window.FoxDeskAttachmentPasteDrop.bind({
                inputId: options.inputId,
                targetSelectors: ['#comment-form', '#comment-upload-zone'],
                namePrefix: 'ticket-attachment',
                onFilesChanged: function () {
                    if (instance && instance.updatePreview) instance.updatePreview();
                }
            });
        }

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
        if (!row) return; // The compact reply-time surface owns its own fields.
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
            chip.className = 'inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 fd-rounded-pill text-sm';

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
                        dropdown.innerHTML = '<div class="px-3 py-2 text-sm theme-text-muted">' + escapeHtml(t('noUsersFound', 'No users found.')) + '</div>';
                        dropdown.classList.remove('hidden');
                        return;
                    }
                    users.forEach(function (user) {
                        if (selectedUsers.find(function (selectedUser) { return selectedUser.id === user.id; })) return;
                        var item = document.createElement('div');
                        item.className = 'px-3 py-2 cursor-pointer text-sm tr-hover';
                        item.innerHTML = '<strong>' + escapeHtml(user.name) + '</strong><br><span class="text-xs theme-text-muted">' + escapeHtml(user.email) + '</span>';
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


    function quillToolbar() {
        return [[{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'image'], ['clean']];
    }

    var featureRuntime = {
        config: config,
        labels: labels,
        icons: icons,
        ticketId: ticketId,
        csrfToken: csrfToken,
        t: t,
        escapeHtml: escapeHtml,
        showToast: showToast,
        showUndoToast: showUndoToast,
        fadeRemove: fadeRemove,
        fillTemplate: fillTemplate,
        quillFieldValue: quillFieldValue,
        loadQuillContent: loadQuillContent,
        formatDateInput: formatDateInput,
        formatTimeInput: formatTimeInput,
        formatDateTimeLocal: formatDateTimeLocal,
        pad2: pad2,
        quillToolbar: quillToolbar
    };
    var featureInstallers = window.FoxDeskTicketDetailFeatures || {};
    var timerFeature = featureInstallers.timer ? featureInstallers.timer(featureRuntime) : {};
    var recordsFeature = featureInstallers.records ? featureInstallers.records(featureRuntime) : {};
    var adminFeature = featureInstallers.admin ? featureInstallers.admin(featureRuntime) : {};
    var initTimer = timerFeature.initTimer || function () {};
    var initAgentCcDropdown = recordsFeature.initAgentCcDropdown || function () {};
    var initEditTimeForm = recordsFeature.initEditTimeForm || function () {};
    var initTags = recordsFeature.initTags || function () {};
    var initPermanentDelete = adminFeature.initPermanentDelete || function () {};

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

    function initTicketEditModalControls() {
        document.querySelectorAll('[data-ticket-edit-open]').forEach(function (button) {
            button.addEventListener('click', window.openEditTicketModal);
        });
        document.querySelectorAll('[data-ticket-edit-close]').forEach(function (button) {
            button.addEventListener('click', window.closeEditTicketModal);
        });
    }

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
                    if (internalText) internalText.value = quillFieldValue(window.internalEditor);
                    if (commentText) commentText.value = '';
                } else if (window.commentEditor) {
                    if (commentText) commentText.value = quillFieldValue(window.commentEditor);
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
                document.getElementById('edit-description-input').value = quillFieldValue(editDescriptionEditor);
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
        if (document.querySelector('[data-workflow]')) return;
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
        initTicketEditModalControls();
        initQuillEditors();
        initImagePreview();
        initAutosave();
        initPermanentDelete();
        if (config.quickStart) {
            var quickModal = document.getElementById('edit-ticket-modal');
            if (quickModal) quickModal.classList.add('is-quick-start');
            window.openEditTicketModal();
            var titleInput = document.querySelector('#edit-ticket-modal input[name="edit_title"]');
            if (titleInput) { titleInput.focus(); titleInput.select(); }
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            window.closeEditCommentModal();
            window.closeEditTimeModal();
            window.closeEditTicketModal();
            var timeline = document.getElementById('timeline-overlay');
            if (timeline && timeline.classList.contains('is-open')) window.closeTimeline();
        });
    });
})(window, document);
