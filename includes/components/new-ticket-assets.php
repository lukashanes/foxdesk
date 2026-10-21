<script src="assets/js/attachment-paste-drop.js?v=<?php echo APP_VERSION; ?>"></script>
<script>
    const ICONS = {
        'times': '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>',
        'file': '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline>',
        'file-image': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
        'file-pdf': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-word': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-excel': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-archive': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    };

    function getIcon(name, classes = '') {
        const path = ICONS[name] || ICONS['file'];
        return `<svg xmlns="http://www.w3.org/2000/svg" class="${classes}" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
    }

    // Selection handler for option pills
    function selectOption(element, inputId) {
        const group = element.dataset.group;
        const value = element.dataset.value;

        document.querySelectorAll(`[data-group="${group}"]`).forEach(el => {
            el.classList.remove('selected');
        });
        element.classList.add('selected');
        document.getElementById(inputId).value = value;
    }

    document.querySelectorAll('#new-ticket-form .option-pill[data-pill-color]').forEach(function (pill) {
        pill.style.setProperty('--pill-color', pill.dataset.pillColor || '#6b7280');
        pill.addEventListener('click', function () {
            selectOption(pill, pill.dataset.inputId);
        });
    });

    // File upload handling
    const fileInput = document.getElementById('file-input');
    const filePreview = document.getElementById('file-preview');
    const fileUploadErrors = document.getElementById('file-upload-errors');
    const removeFileLabel = '<?php echo e(t('Remove')); ?>';
    const uploadLimitConfig = {
        single: <?php echo json_encode((int) get_max_upload_size()); ?>,
        total: <?php echo json_encode((int) get_request_upload_limit()); ?>,
        singleTemplate: <?php echo json_encode(t('File "{name}" exceeds the maximum allowed size of {size}.')); ?>,
        totalTemplate: <?php echo json_encode(t('Selected attachments exceed the server request limit of {size}.')); ?>
    };

    function showUploadLimitMessage(message) {
        if (!message) return;
        if (window.showAppToast) {
            window.showAppToast(message, 'error');
        } else {
            alert(message);
        }
    }

    function renderUploadErrors(messages) {
        if (!fileUploadErrors) return;

        const uniqueMessages = Array.from(new Set((messages || []).filter(Boolean)));
        if (uniqueMessages.length === 0) {
            fileUploadErrors.innerHTML = '';
            fileUploadErrors.classList.add('hidden');
            return;
        }

        fileUploadErrors.innerHTML = uniqueMessages.map(function (message) {
            return '<div>' + message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        }).join('');
        fileUploadErrors.classList.remove('hidden');
    }

    function fillUploadTemplate(template, replacements) {
        let output = String(template || '');
        Object.keys(replacements || {}).forEach(function (key) {
            output = output.split('{' + key + '}').join(replacements[key]);
        });
        return output;
    }

    function enforceUploadLimits() {
        if (!fileInput || typeof DataTransfer === 'undefined') return { changed: false, hadErrors: false };

        const originalCount = fileInput.files.length;
        const dt = new DataTransfer();
        let totalSize = 0;
        let hadErrors = false;
        let totalErrorShown = false;
        const messages = [];

        for (let i = 0; i < fileInput.files.length; i++) {
            const file = fileInput.files[i];

            if (uploadLimitConfig.single > 0 && file.size > uploadLimitConfig.single) {
                hadErrors = true;
                const message = fillUploadTemplate(uploadLimitConfig.singleTemplate, {
                    name: file.name,
                    size: formatFileSize(uploadLimitConfig.single)
                });
                messages.push(message);
                showUploadLimitMessage(message);
                continue;
            }

            if (uploadLimitConfig.total > 0 && totalSize + file.size > uploadLimitConfig.total) {
                hadErrors = true;
                if (!totalErrorShown) {
                    const message = fillUploadTemplate(uploadLimitConfig.totalTemplate, {
                        size: formatFileSize(uploadLimitConfig.total)
                    });
                    messages.push(message);
                    showUploadLimitMessage(message);
                    totalErrorShown = true;
                }
                continue;
            }

            totalSize += file.size;
            dt.items.add(file);
        }

        if (originalCount !== dt.files.length) {
            fileInput.files = dt.files;
            return { changed: true, hadErrors: hadErrors, messages: messages };
        }

        return { changed: false, hadErrors: hadErrors, messages: messages };
    }

    function updatePreview() {
        const validation = enforceUploadLimits();
        renderUploadErrors(validation.messages);
        filePreview.innerHTML = '';
        if (fileInput.files.length === 0) {
            filePreview.classList.add('hidden');
            return;
        }
        filePreview.classList.remove('hidden');
        for (let i = 0; i < fileInput.files.length; i++) {
            const file = fileInput.files[i];
            const div = document.createElement('div');
            div.className = 'flex items-center justify-between rounded px-2 py-1.5 text-xs';
            div.style.background = 'var(--surface-secondary)';
            var row = document.createElement('div');
            row.className = 'flex items-center gap-2 min-w-0';
            row.innerHTML = getIcon(getFileIcon(file.type), 'flex-shrink-0 w-3.5 h-3.5');
            var nameSpan = document.createElement('span');
            nameSpan.className = 'truncate';
            nameSpan.style.color = 'var(--text-secondary)';
            nameSpan.textContent = file.name;
            row.appendChild(nameSpan);
            var sizeSpan = document.createElement('span');
            sizeSpan.className = 'flex-shrink-0';
            sizeSpan.style.color = 'var(--text-muted)';
            sizeSpan.textContent = formatFileSize(file.size);
            row.appendChild(sizeSpan);
            div.appendChild(row);
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'text-red-400 hover:text-red-500 ml-2';
            removeBtn.title = removeFileLabel;
            removeBtn.innerHTML = getIcon('times', 'w-3.5 h-3.5');
            (function(idx) { removeBtn.onclick = function() { removeFile(idx); }; })(i);
            div.appendChild(removeBtn);
            filePreview.appendChild(div);
        }
    }

    function removeFile(index) {
        const dt = new DataTransfer();
        for (let i = 0; i < fileInput.files.length; i++) {
            if (i !== index) dt.items.add(fileInput.files[i]);
        }
        fileInput.files = dt.files;
        updatePreview();
    }

    function formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    }

    function getFileIcon(mimeType) {
        if (mimeType.startsWith('image/')) return 'file-image';
        if (mimeType === 'application/pdf') return 'file-pdf';
        if (mimeType.includes('word')) return 'file-word';
        if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'file-excel';
        if (mimeType.includes('zip') || mimeType.includes('rar')) return 'file-archive';
        return 'file';
    }

    const initTicketUploadZones = function () {
        if (window.initFileDropzone) {
            window.initFileDropzone({
                zoneId: 'upload-zone',
                inputId: 'file-input',
                onFilesChanged: updatePreview
            });
        } else if (fileInput) {
            fileInput.addEventListener('change', updatePreview);
        }
        if (window.FoxDeskAttachmentPasteDrop) {
            window.FoxDeskAttachmentPasteDrop.bind({
                inputId: 'file-input',
                targetSelectors: ['#new-ticket-form', '#upload-zone'],
                namePrefix: 'ticket-attachment',
                onFilesChanged: updatePreview
            });
        }

    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTicketUploadZones);
    } else {
        initTicketUploadZones();
    }

    const newTicketForm = document.getElementById('new-ticket-form');
    if (newTicketForm) {
        const resetFreshTicketSelects = function() {
            if (newTicketForm.dataset.freshTicket !== '1') return;
            newTicketForm.querySelectorAll('[data-reset-on-fresh-ticket="1"]').forEach(function(select) {
                select.value = '';
                select.querySelectorAll('option').forEach(function(option) {
                    option.selected = option.value === '';
                });
            });
        };
        resetFreshTicketSelects();
        window.addEventListener('pageshow', resetFreshTicketSelects);

        newTicketForm.addEventListener('submit', function(event) {
            const hadRenderedUploadErrors = fileUploadErrors && !fileUploadErrors.classList.contains('hidden');
            const validation = enforceUploadLimits();
            const shouldKeepRenderedUploadErrors = hadRenderedUploadErrors && fileInput && fileInput.files.length === 0 && validation.messages.length === 0;
            if (!shouldKeepRenderedUploadErrors) {
                renderUploadErrors(validation.messages);
            }
            const hasBlockingUploadError = hadRenderedUploadErrors || (fileUploadErrors && !fileUploadErrors.classList.contains('hidden'));
            if ((validation.hadErrors && fileInput && fileInput.files.length === 0) || (hasBlockingUploadError && fileInput && fileInput.files.length === 0)) {
                const submitBtn = this.querySelector('[type=submit]');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '';
                }
                if (fileUploadErrors) {
                    fileUploadErrors.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                event.preventDefault();
                return;
            }
            const submitBtn = this.querySelector('[type=submit]');
            if (submitBtn) {
                submitBtn.disabled = true;
                this.classList.add('is-submitting');
            }
            localStorage.removeItem('foxdesk_draft_timer');
        });
    }
</script>

<?php if (is_agent() && function_exists('ticket_time_table_exists') && ticket_time_table_exists()): ?>
<script>
(function() {
    var STORAGE_KEY = 'foxdesk_draft_timer';
    var wrapper = document.getElementById('new-ticket-timer');
    var btn = document.getElementById('nt-timer-btn');
    var btnIcon = btn ? btn.querySelector('.nt-timer-icon') : null;
    var btnText = btn ? btn.querySelector('.nt-timer-text') : null;
    var discardBtn = document.getElementById('nt-timer-discard');
    var hiddenInput = document.getElementById('timer_elapsed_seconds');
    if (!wrapper || !btn || !btnIcon || !btnText || !discardBtn || !hiddenInput) return;

    // Server-rendered icon SVGs (safe — PHP-escaped, not user input) and translated strings
    var iconPlay = '<?php echo get_icon('play', 'w-4 h-4'); ?>';
    var iconPause = '<?php echo get_icon('pause', 'w-4 h-4'); ?>';
    var STR_START = '<?php echo e(t('Start timer')); ?>';
    var STR_PAUSE = '<?php echo e(t('Pause timer')); ?>';
    var STR_RESUME = '<?php echo e(t('Resume timer')); ?>';
    var STR_PAUSED = '<?php echo e(t('Paused')); ?>';
    var STR_DISCARD_CONFIRM = '<?php echo e(t('Discard this timer? The tracked time will be lost.')); ?>';

    var timerStart = null;
    var timerInterval = null;
    var pausedTotal = 0;
    var pausedAt = null;
    var state = 'stopped';

    // --- localStorage persistence ---
    function saveTimer() {
        if (state === 'stopped') {
            localStorage.removeItem(STORAGE_KEY);
            return;
        }
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            startedAt: timerStart,
            pausedTotal: pausedTotal,
            pausedAt: pausedAt,
            state: state
        }));
    }

    function restoreTimer() {
        var raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return false;
        try { var d = JSON.parse(raw); } catch(e) { return false; }
        if (!d.startedAt || !d.state) return false;

        timerStart = d.startedAt;
        if (d.state === 'paused' && d.pausedAt) {
            // Hibernation time counts as paused
            pausedTotal = d.pausedTotal + (Date.now() - d.pausedAt);
            pausedAt = Date.now();
        } else {
            pausedTotal = d.pausedTotal || 0;
            pausedAt = null;
        }
        setState(d.state);
        return true;
    }

    // --- Core timer logic ---
    function formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        if (h > 0) return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        return m + ':' + String(s).padStart(2, '0');
    }

    function getElapsed() {
        if (!timerStart) return 0;
        return Math.floor((Date.now() - timerStart - pausedTotal) / 1000);
    }

    function tick() {
        if (state !== 'running') return;
        var elapsed = getElapsed();
        btnText.textContent = formatTime(elapsed);
        hiddenInput.value = elapsed;
    }

    // setIcon: safely swap SVG icon content (source is PHP get_icon(), not user input)
    function setIcon(el, svgHtml) { el.innerHTML = svgHtml; } // eslint-disable-line no-param-reassign

    function setState(newState) {
        state = newState;
        btn.dataset.state = newState;

        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }

        if (newState === 'running') {
            btn.className = 'btn btn-warning px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
            btn.title = STR_PAUSE;
            setIcon(btnIcon, iconPause);
            btnText.textContent = formatTime(getElapsed());
            discardBtn.classList.remove('hidden');
            timerInterval = setInterval(tick, 1000);

        } else if (newState === 'paused') {
            var elapsed = getElapsed();
            btn.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
            btn.title = STR_RESUME;
            setIcon(btnIcon, iconPlay);
            btnText.textContent = formatTime(elapsed);
            var pausedLabel = document.createElement('span');
            pausedLabel.className = 'text-xs uppercase ml-1';
            pausedLabel.textContent = STR_PAUSED;
            btnText.appendChild(pausedLabel);
            discardBtn.classList.remove('hidden');
            hiddenInput.value = elapsed;

        } else { // stopped
            btn.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
            btn.title = STR_START;
            setIcon(btnIcon, iconPlay);
            btnText.textContent = STR_START;
            discardBtn.classList.add('hidden');
            timerStart = null;
            pausedTotal = 0;
            pausedAt = null;
            hiddenInput.value = '0';
        }
        saveTimer();
    }

    btn.addEventListener('click', function() {
        if (state === 'stopped') {
            timerStart = Date.now();
            pausedTotal = 0;
            pausedAt = null;
            setState('running');
        } else if (state === 'running') {
            pausedAt = Date.now();
            setState('paused');
        } else if (state === 'paused') {
            pausedTotal += Date.now() - pausedAt;
            pausedAt = null;
            setState('running');
        }
    });

    discardBtn.addEventListener('click', function() {
        if (!confirm(STR_DISCARD_CONFIRM)) return;
        setState('stopped');
    });

    // Restore from localStorage, or auto-start if ?auto_timer=1
    if (!restoreTimer() && wrapper.dataset.autoStart === '1') {
        timerStart = Date.now();
        pausedTotal = 0;
        pausedAt = null;
        setState('running');
    }
})();

// Manual entry toggle
(function() {
    var toggle = document.getElementById('nt-manual-toggle');
    var row = document.getElementById('nt-manual-entry-row');
    var durationInput = document.getElementById('nt-manual-duration-minutes');
    var durationButtons = document.querySelectorAll('.nt-manual-duration-chip');
    var dateInput = document.querySelector('input[name="manual_date"]');
    var startInput = document.querySelector('input[name="manual_start_time"]');
    var endInput = document.querySelector('input[name="manual_end_time"]');
    if (!toggle || !row) return;

    function setManualVisible(visible) {
        row.classList.toggle('hidden', !visible);
        toggle.style.color = visible ? 'var(--accent-primary)' : 'var(--text-muted)';
    }

    function activateQuickMinutes(minutes) {
        if (!durationInput) return;
        durationInput.value = minutes;
        if (startInput) startInput.value = '';
        if (endInput) endInput.value = '';
        setManualVisible(true);
        durationInput.focus();
    }

    if (
        (durationInput && durationInput.value !== '') ||
        (startInput && startInput.value !== '') ||
        (endInput && endInput.value !== '')
    ) {
        setManualVisible(true);
    }

    toggle.addEventListener('click', function() {
        setManualVisible(row.classList.contains('hidden'));
    });

    if (durationInput) {
        durationInput.addEventListener('input', function() {
            if (this.value !== '') {
                if (startInput) startInput.value = '';
                if (endInput) endInput.value = '';
                if (dateInput && dateInput.value === '') {
                    dateInput.value = '<?php echo e(date('Y-m-d')); ?>';
                }
                setManualVisible(true);
            }
        });
    }

    durationButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            activateQuickMinutes(this.dataset.minutes);
        });
    });

    [startInput, endInput].forEach(function(input) {
        if (!input) return;
        input.addEventListener('input', function() {
            if (durationInput && this.value !== '') {
                durationInput.value = '';
            }
            setManualVisible(true);
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($tags_supported): ?>
<script src="assets/js/chip-select.js"></script>
<script>
(function () {
    var hiddenInput = document.getElementById('nt-tags-value');
    if (!hiddenInput) return;

    // Fetch existing tag suggestions
    fetch('index.php?page=api&action=get-tags')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) return;
            initTagChips(data.tags || []);
        })
        .catch(function () { initTagChips([]); });

    function initTagChips(tagItems) {
        // Pre-selected tags from POST (on validation failure)
        var preSelected = [];
        var existing = hiddenInput.value.trim();
        if (existing) {
            preSelected = existing.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
        }

        var csTags = new ChipSelect({
            wrapId:     'cs-tags-wrap',
            chipsId:    'cs-tags-chips',
            inputId:    'cs-tags-input',
            dropdownId: 'cs-tags-dropdown',
            hiddenId:   'cs-tags-hidden',
            items:      tagItems,
            selected:   preSelected,
            name:       'tag_chips[]',
            allowCreate: true,
            noMatchText: <?php echo json_encode(t('No matches')); ?>
        });

        // Sync chip values to hidden input before form submit
        var form = document.getElementById('new-ticket-form');
        if (form) {
            form.addEventListener('submit', function () {
                hiddenInput.value = csTags.getSelectedValues().join(', ');
            });
        }
    }
})();
</script>
<?php endif; ?>

<!-- Quill Editor (self-hosted) -->
<link href="assets/vendor/quill/2.0.2/quill.snow.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">
<script src="assets/vendor/quill/2.0.2/quill.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/quill-image-upload.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/rich-text-editor.js?v=<?php echo APP_VERSION; ?>"></script>
<script>
    // Initialize Quill Editor
    (function() {
        try {
            const editorEl = document.getElementById('description-editor');
            if (!editorEl) {
                console.error('Quill: editor element #description-editor not found');
                return;
            }

            if (typeof Quill === 'undefined') {
                console.error('Quill: library not loaded - check that assets/vendor/quill is deployed');
                return;
            }

            window.descriptionEditor = new Quill('#description-editor', {
                theme: 'snow',
                placeholder: '<?php echo e(t('Describe your request...')); ?>',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });

            // Enable image paste/drop upload
            if (window.initQuillImageUpload) {
                initQuillImageUpload(window.descriptionEditor, {
                    uploadUrl: 'index.php?page=api&action=upload',
                    csrfToken: window.csrfToken || ''
                });
            }

            // Load existing content if any
            const existingContent = document.getElementById('description-input').value;
            if (existingContent) {
                window.descriptionEditor.clipboard.dangerouslyPasteHTML(existingContent);
            }

            const descriptionInput = document.getElementById('description-input');
            const ticketForm = document.getElementById('new-ticket-form');
            if (!descriptionInput) {
                console.error('Quill: hidden input #description-input not found');
                return;
            }
            const syncDescriptionInput = function() {
                if (window.FoxDeskRichText) {
                    descriptionInput.value = window.FoxDeskRichText.fieldValue(window.descriptionEditor);
                    return;
                }
                const html = window.descriptionEditor.root.innerHTML;
                descriptionInput.value = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
            };

            // Keep hidden input in sync continuously and also right before submit.
            window.descriptionEditor.on('text-change', syncDescriptionInput);
            if (ticketForm) {
                ticketForm.addEventListener('submit', syncDescriptionInput);
            }
            syncDescriptionInput();

        } catch (e) {
            console.error('Quill initialization error:', e);
        }
    })();
</script>

<!-- Autosave for new ticket form -->
<script src="assets/js/autosave.js"></script>
<script>
(function() {
    if (typeof FoxDeskAutosave === 'undefined') return;

    var draft = FoxDeskAutosave.create({
        key: 'foxdesk_draft_new_ticket',
        formSelector: '#new-ticket-form',
        quillEditors: {description: window.descriptionEditor},
        fields: [
            {name: 'title', selector: '#ticket-title-input', type: 'input'},
            {name: 'description', type: 'quill', editorKey: 'description', selector: '#description-input'},
            {name: 'priority_id', selector: '#priority_id', type: 'hidden'},
            {name: 'type', selector: '#type', type: 'hidden'}
        ],
        pillRestore: function(fieldName, value) {
            // Re-select pill UI for priority and type
            if (fieldName === 'priority_id') {
                var group = 'priority';
                document.querySelectorAll('[data-group="' + group + '"]').forEach(function(el) {
                    el.classList.remove('selected');
                    if (el.dataset.value === value) el.classList.add('selected');
                });
            } else if (fieldName === 'type') {
                var group = 'type';
                document.querySelectorAll('[data-group="' + group + '"]').forEach(function(el) {
                    el.classList.remove('selected');
                    if (el.dataset.value === value) el.classList.add('selected');
                });
            }
        },
        onRestore: function(relTime) {
            if (window.showAppToast) window.showAppToast('<?php echo e(t('Draft restored')); ?> (' + relTime + ')', 'info');
        }
    });
    draft.init();

    // Suppress beforeunload on cancel link
    var cancelLink = document.querySelector('a[href*="dashboard"]');
    if (cancelLink) {
        cancelLink.addEventListener('click', function() {
            draft.suppressBeforeUnload();
        });
    }
})();
</script>
