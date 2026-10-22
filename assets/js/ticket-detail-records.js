(function (window, document) {
    'use strict';

    var features = window.FoxDeskTicketDetailFeatures = window.FoxDeskTicketDetailFeatures || {};
    features.records = function (runtime) {
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
    var quillToolbar = runtime.quillToolbar;

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
                modules: { toolbar: quillToolbar() }
            });
            if (window.initQuillImageUpload) window.initQuillImageUpload(editCommentEditor, config.quillUpload || {});
        }
        loadQuillContent(editCommentEditor, content);
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
            content = quillFieldValue(editCommentEditor);
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
        var formData = new FormData();
        formData.append('comment_id', commentId);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=delete-comment', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    var comment = document.getElementById('comment-' + commentId);
                    fadeRemove(comment);
                    if (data.undo_token) {
                        showUndoToast(data.message || t('commentDeleted', 'Comment deleted.'), data);
                    } else {
                        showToast(data.message || t('commentDeleted', 'Comment deleted.'), 'success');
                    }
                } else {
                    window.alert(data.error || t('commentDeleteFailed', 'Failed to delete comment.'));
                }
            })
            .catch(function () {
                window.alert(t('genericError', 'An error occurred.'));
            });
    };

    window.deleteTimeEntry = function (entryId) {
        var formData = new FormData();
        formData.append('entry_id', entryId);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=delete-time-entry', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    document.querySelectorAll('[data-time-entry-id="' + entryId + '"]').forEach(fadeRemove);
                    if (data.undo_token) {
                        showUndoToast(data.message || t('timeEntryDeleted', 'Time entry deleted.'), data);
                    } else {
                        showToast(data.message || t('timeEntryDeleted', 'Time entry deleted.'), 'success');
                    }
                } else {
                    window.alert(data.error || t('timeEntryDeleteFailed', 'Failed to delete time entry.'));
                }
            })
            .catch(function () {
                window.alert(t('genericError', 'An error occurred.'));
            });
    };

    window.deleteAttachment = function (attachmentId) {
        var formData = new FormData();
        formData.append('attachment_id', attachmentId);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=delete-attachment', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    document.querySelectorAll('[data-attachment-id="' + attachmentId + '"]').forEach(function (item) {
                        item.style.opacity = '0';
                        item.style.transition = 'opacity 0.2s';
                        setTimeout(function () { item.remove(); }, 220);
                    });
                    if (data.undo_token) {
                        showUndoToast(data.message || t('attachmentDeleted', 'Attachment deleted.'), data);
                    } else {
                        showToast(data.message || t('attachmentDeleted', 'Attachment deleted.'), 'success');
                    }
                } else {
                    window.alert(data.error || t('attachmentDeleteFailed', 'Failed to delete attachment.'));
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
                noMatchText: t('noMatches', 'No matches'),
                removeLabel: t('remove', 'Remove')
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
                    display.innerHTML = html || '<span class="text-xs theme-text-muted">-</span>';
                    hideEditor();
                })
                .catch(function () { saveButton.disabled = false; });
        });
    }

        return { initAgentCcDropdown: initAgentCcDropdown, initEditTimeForm: initEditTimeForm, initTags: initTags };
    };
})(window, document);
