(function (window, document) {
    'use strict';

    var features = window.FoxDeskTicketDetailFeatures = window.FoxDeskTicketDetailFeatures || {};
    features.admin = function (runtime) {
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

    function initPermanentDelete() {
        var modal = document.getElementById('ticket-permanent-delete-modal');
        var openButton = document.querySelector('[data-open-permanent-delete]');
        if (!modal || !openButton || !ticketId) return;

        var summary = modal.querySelector('[data-permanent-delete-summary]');
        var confirmButton = modal.querySelector('[data-confirm-permanent-delete]');
        var error = modal.querySelector('[data-permanent-delete-error]');
        var expectedCode = '';

        function setError(message) {
            if (!error) return;
            error.textContent = message || '';
            error.classList.toggle('hidden', !message);
        }

        function close() {
            modal.classList.add('hidden');
            expectedCode = '';
            if (confirmButton) confirmButton.disabled = true;
            setError('');
        }

        function renderSummary(preflight) {
            if (!summary) return;
            summary.replaceChildren();
            [
                [t('ticket', 'Ticket'), preflight.ticket_code + ' — ' + preflight.title],
                [t('comments', 'Comments'), String(preflight.comment_count)],
                [t('timeEntries', 'Time entries'), String(preflight.time_entry_count)],
                [t('totalTime', 'Total time'), String(preflight.time_minutes) + ' min'],
                [t('attachments', 'Attachments'), String(preflight.attachment_count)]
            ].forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'flex items-start justify-between gap-4';
                var label = document.createElement('span');
                label.className = 'text-theme-muted';
                label.textContent = item[0];
                var value = document.createElement('strong');
                value.className = 'text-right text-theme-primary';
                value.textContent = item[1];
                row.append(label, value);
                summary.appendChild(row);
            });
        }

        openButton.addEventListener('click', function () {
            modal.classList.remove('hidden');
            expectedCode = '';
            if (confirmButton) confirmButton.disabled = true;
            if (summary) summary.textContent = t('loadingDeletionSummary', 'Loading deletion summary...');
            setError('');

            var formData = new FormData();
            formData.append('ticket_id', String(ticketId));
            formData.append('csrf_token', csrfToken);
            fetch('index.php?page=api&action=permanent-delete-ticket-preflight', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success || !data.preflight) throw new Error(data.error || t('genericError', 'An error occurred.'));
                    expectedCode = data.preflight.ticket_code;
                    renderSummary(data.preflight);
                    if (confirmButton) confirmButton.disabled = false;
                })
                .catch(function (requestError) { setError(requestError.message); });
        });

        modal.querySelectorAll('[data-close-permanent-delete]').forEach(function (button) {
            button.addEventListener('click', close);
        });
        if (confirmButton) {
            confirmButton.addEventListener('click', function () {
                if (!expectedCode) return;
                confirmButton.disabled = true;
                setError('');
                var formData = new FormData();
                formData.append('ticket_id', String(ticketId));
                formData.append('confirmation', expectedCode);
                formData.append('csrf_token', csrfToken);
                fetch('index.php?page=api&action=permanent-delete-ticket', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.success || !data.deleted) throw new Error(data.error || t('genericError', 'An error occurred.'));
                        window.location.assign('index.php?page=tickets');
                    })
                    .catch(function (requestError) {
                        confirmButton.disabled = false;
                        setError(requestError.message);
                    });
            });
        }
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
                    html += '<div class="tl-dot" style="--tl-color:' + escapeHtml(event.color) + ';"></div>';
                    html += '<div class="tl-event-row">';
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

        return { initPermanentDelete: initPermanentDelete };
    };
})(window, document);
