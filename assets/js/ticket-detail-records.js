(function (window, document) {
    'use strict';

    var runtime = window.FoxDeskTicketDetailRuntime;
    if (!runtime) throw new Error('FoxDesk ticket detail core was not loaded.');
    var config = runtime.config;
    var ticketId = runtime.ticketId;
    var csrfToken = runtime.csrfToken;
    var t = runtime.t;
    var escapeHtml = runtime.escapeHtml;
    var showToast = runtime.showToast;
    var showUndoToast = runtime.showUndoToast;
    var fadeRemove = runtime.fadeRemove;
    var fillTemplate = runtime.fillTemplate;
    var pad2 = runtime.pad2;
    var formatDateInput = runtime.formatDateInput;
    var formatTimeInput = runtime.formatTimeInput;
    var formatDateTimeLocal = runtime.formatDateTimeLocal;
    var editCommentEditor = null;

    // Quill 2 keeps list markup in data attributes; submit its semantic HTML.
    function richValue(editor) {
        if (window.FoxDeskRichText) return window.FoxDeskRichText.fieldValue(editor);
        var html = editor.root.innerHTML;
        return (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
    }

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
        editCommentEditor.setText('');
        if (window.FoxDeskRichText) {
            window.FoxDeskRichText.loadHtml(editCommentEditor, content);
        } else if (String(content || '').indexOf('<') !== -1 && String(content || '').indexOf('>') !== -1) {
            editCommentEditor.clipboard.dangerouslyPasteHTML(content);
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
            content = richValue(editCommentEditor);
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
                    data.undo_token
                        ? showUndoToast(data.message || t('commentDeleted', 'Comment deleted.'), data)
                        : showToast(data.message || t('commentDeleted', 'Comment deleted.'), 'success');
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
                    data.undo_token
                        ? showUndoToast(data.message || t('timeEntryDeleted', 'Time entry deleted.'), data)
                        : showToast(data.message || t('timeEntryDeleted', 'Time entry deleted.'), 'success');
                } else {
                    window.alert(data.error || t('timeEntryDeleteFailed', 'Failed to delete time entry.'));
                }
            })
            .catch(function () { window.alert(t('genericError', 'An error occurred.')); });
    };

    window.deleteAttachment = function (attachmentId) {
        var formData = new FormData();
        formData.append('attachment_id', attachmentId);
        formData.append('csrf_token', csrfToken);
        fetch('index.php?page=api&action=delete-attachment', { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    document.querySelectorAll('[data-attachment-id="' + attachmentId + '"]').forEach(fadeRemove);
                    data.undo_token
                        ? showUndoToast(data.message || t('attachmentDeleted', 'Attachment deleted.'), data)
                        : showToast(data.message || t('attachmentDeleted', 'Attachment deleted.'), 'success');
                } else {
                    window.alert(data.error || t('attachmentDeleteFailed', 'Failed to delete attachment.'));
                }
            })
            .catch(function () { window.alert(t('genericError', 'An error occurred.')); });
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
                    if (internalText) internalText.value = richValue(window.internalEditor);
                    if (commentText) commentText.value = '';
                } else if (window.commentEditor) {
                    if (commentText) commentText.value = richValue(window.commentEditor);
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
                document.getElementById('edit-description-input').value = richValue(editDescriptionEditor);
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

    runtime.initEditTimeForm = initEditTimeForm;
    runtime.initTags = initTags;
    runtime.initQuillEditors = initQuillEditors;
    runtime.initImagePreview = initImagePreview;
    runtime.initAutosave = initAutosave;
})(window, document);
