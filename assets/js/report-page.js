(function () {
    'use strict';

    var config = window.FoxDeskReportPageConfig || {};
    var labels = config.labels || {};
    var chipSelects = { organizations: null, agents: null, tags: null };

    function csrfToken() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function updateTimeInline(input) {
        var wrap = input.closest('.worklog__time-form');
        if (!wrap) return;

        var start = wrap.querySelector('[name="start_time"]');
        var end = wrap.querySelector('[name="end_time"]');
        if (!start || !end || !start.value || !end.value) return;

        var row = wrap.closest('.worklog__row');
        var duration = row ? row.querySelector('.worklog__cell--duration') : null;
        if (duration) {
            duration.classList.add('is-saving');
            duration.classList.remove('is-saved');
        }

        fetch('index.php?page=api&action=update-time-inline', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken()
            },
            body: JSON.stringify({
                entry_id: wrap.dataset.entryId,
                entry_date: wrap.dataset.entryDate,
                start_time: start.value,
                end_time: end.value
            })
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.error || labels.failedSave || 'Failed to save');
                }
                if (duration) {
                    duration.textContent = data.duration_formatted;
                    duration.classList.remove('is-saving');
                    duration.classList.add('is-saved');
                    window.setTimeout(function () { duration.classList.remove('is-saved'); }, 800);
                }
            })
            .catch(function (error) {
                if (duration) duration.classList.remove('is-saving');
                window.alert(error.message || labels.failedSave || 'Failed to save');
            });
    }

    function toggleRange() {
        var select = document.getElementById('report-time-range');
        var custom = document.getElementById('report-custom-range');
        if (!select) return;
        if (custom) custom.classList.toggle('is-open', select.value === 'custom');
        document.querySelectorAll('[data-report-range]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.reportRange === select.value);
        });
    }

    function dateValue(date) {
        function pad(number) { return String(number).padStart(2, '0'); }
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function rangeBounds(range) {
        var now = new Date();
        var month = now.getMonth();
        var year = now.getFullYear();
        if (range === 'last_month') {
            return { from: dateValue(new Date(year, month - 1, 1)), to: dateValue(new Date(year, month, 0)) };
        }
        if (range === 'last_30_days') {
            var start = new Date(year, month, now.getDate());
            start.setDate(start.getDate() - 29);
            return { from: dateValue(start), to: dateValue(now) };
        }
        if (range === 'this_quarter') {
            return { from: dateValue(new Date(year, Math.floor(month / 3) * 3, 1)), to: dateValue(now) };
        }
        return { from: dateValue(new Date(year, month, 1)), to: dateValue(now) };
    }

    function initCreateLinks() {
        var form = document.querySelector('[data-report-create-form]');
        var links = Array.prototype.slice.call(document.querySelectorAll('[data-report-create-link]'));
        if (!form || !links.length) return;
        var initialUrls = links.map(function (link) { return link.getAttribute('href') || 'index.php?page=admin&section=report-builder'; });
        var client = form.querySelector('[data-report-client-select]');
        var period = form.querySelector('[data-report-period-select]');

        function update() {
            var organizationId = client ? client.value : '';
            var range = period ? period.value : 'this_month';
            var bounds = rangeBounds(range);
            links.forEach(function (link, index) {
                var url = new URL(initialUrls[index] || initialUrls[0], window.location.href);
                url.searchParams.set('page', 'admin');
                url.searchParams.set('section', 'report-builder');
                url.searchParams.set('time_range', range);
                url.searchParams.set('date_from', bounds.from);
                url.searchParams.set('date_to', bounds.to);
                if (organizationId) url.searchParams.set('organization_id', organizationId);
                else url.searchParams.delete('organization_id');
                link.setAttribute('href', url.pathname.split('/').pop() + '?' + url.searchParams.toString());
            });
        }

        if (client) client.addEventListener('change', update);
        if (period) period.addEventListener('change', update);
        update();
    }

    function openEntryModal(entry) {
        var fields = {
            edit_entry_id: entry.id,
            edit_ticket_id: entry.ticket_code || entry.ticket_id,
            edit_ticket_title: entry.ticket_title || '',
            edit_started_at: entry.started_at || '',
            edit_ended_at: entry.ended_at || ''
        };
        Object.keys(fields).forEach(function (id) {
            var field = document.getElementById(id);
            if (field) field.value = fields[id];
        });
        var modal = document.getElementById('entryModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    function closeEntryModal() {
        var modal = document.getElementById('entryModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function toggleReportTicketPreview(detailId, button) {
        var detail = document.getElementById(detailId);
        if (!detail) return;
        var opening = detail.classList.contains('hidden');
        detail.classList.toggle('hidden', !opening);
        if (button) button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }

    function initChipSelects() {
        if (typeof window.ChipSelect !== 'function') return;
        if (document.getElementById('cs-orgs-wrap')) {
            chipSelects.organizations = new window.ChipSelect({
                wrapId: 'cs-orgs-wrap', chipsId: 'cs-orgs-chips', inputId: 'cs-orgs-input',
                dropdownId: 'cs-orgs-dropdown', hiddenId: 'cs-orgs-hidden',
                items: config.organizations || [], selected: config.selectedOrganizations || [],
                name: 'organizations[]', noMatchText: labels.noMatches || 'No matches'
            });
        }
        if (config.isAdmin && document.getElementById('cs-agents-wrap')) {
            chipSelects.agents = new window.ChipSelect({
                wrapId: 'cs-agents-wrap', chipsId: 'cs-agents-chips', inputId: 'cs-agents-input',
                dropdownId: 'cs-agents-dropdown', hiddenId: 'cs-agents-hidden',
                items: config.agents || [], selected: config.selectedAgents || [],
                name: 'agents[]', noMatchText: labels.noMatches || 'No matches'
            });
        }
        if (config.tagsSupported && document.getElementById('cs-tags-wrap')) {
            fetch('index.php?page=api&action=get-tags')
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.success) return;
                    chipSelects.tags = new window.ChipSelect({
                        wrapId: 'cs-tags-wrap', chipsId: 'cs-tags-chips', inputId: 'cs-tags-input',
                        dropdownId: 'cs-tags-dropdown', hiddenId: 'cs-tags-hidden',
                        items: data.tags || [], selected: config.selectedTags || [],
                        name: 'tag_chips[]', allowCreate: true,
                        noMatchText: labels.noMatches || 'No matches'
                    });
                });
        }
    }

    function initColumnPicker() {
        var toggles = document.querySelectorAll('.col-toggle');
        if (!toggles.length) return;
        var storageKey = 'foxdesk_report_cols';
        function apply(column, visible) {
            document.querySelectorAll('[data-col="' + column + '"]').forEach(function (cell) {
                cell.classList.toggle('is-hidden', !visible);
            });
        }
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
            toggles.forEach(function (toggle) {
                if (saved[toggle.dataset.col] === false) {
                    toggle.checked = false;
                    apply(toggle.dataset.col, false);
                }
            });
        } catch (error) {}
        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                apply(toggle.dataset.col, toggle.checked);
                var state = {};
                toggles.forEach(function (item) { state[item.dataset.col] = item.checked; });
                try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (error) {}
            });
        });
    }

    function initBulkSelection() {
        var selectAll = document.getElementById('bulk-select-all');
        if (!selectAll) return;
        var checks = Array.prototype.slice.call(document.querySelectorAll('.bulk-entry-check:not(:disabled)'));
        selectAll.addEventListener('change', function () {
            checks.forEach(function (check) { check.checked = selectAll.checked; });
        });
        checks.forEach(function (check) {
            check.addEventListener('change', function () {
                var count = checks.filter(function (item) { return item.checked; }).length;
                selectAll.checked = count === checks.length && checks.length > 0;
                selectAll.indeterminate = count > 0 && count < checks.length;
            });
        });
    }

    function initFilterConfirmation() {
        var button = document.getElementById('report-apply-btn');
        var confirmation = document.getElementById('report-confirm');
        var body = document.getElementById('report-confirm-body');
        var back = document.getElementById('report-confirm-back');
        var form = button ? button.closest('form') : null;
        if (!button || !confirmation || !body || !back || !form) return;

        button.addEventListener('click', function () {
            var summary = [];
            if (chipSelects.organizations) {
                var organizations = chipSelects.organizations.getSelectedNames();
                if (organizations.length) summary.push(organizations.join(', '));
            }
            if (chipSelects.agents) {
                var agents = chipSelects.agents.getSelectedNames();
                if (agents.length) summary.push(agents.join(', '));
            }
            var range = document.getElementById('report-time-range');
            if (range && range.selectedIndex >= 0) summary.push(range.options[range.selectedIndex].text);
            if (chipSelects.tags) {
                var tags = chipSelects.tags.getSelectedNames();
                var hidden = document.getElementById('rpt-tags-value');
                if (hidden) hidden.value = tags.join(', ');
                if (tags.length) summary.push(tags.map(function (tag) { return '#' + tag; }).join(', '));
            }
            body.textContent = summary.join(' · ');
            confirmation.classList.remove('hidden');
            button.classList.add('hidden');
        });

        back.addEventListener('click', function () {
            confirmation.classList.add('hidden');
            button.classList.remove('hidden');
        });
    }

    function initFilterPersistence() {
        var form = document.querySelector('#report-filters form[method="get"]');
        var range = document.getElementById('report-time-range');
        if (!form || !range) return;
        var storageKey = 'foxdesk_report_filters';
        var urlParams = new URLSearchParams(window.location.search);
        var hasExplicitFilters = urlParams.has('time_range') || urlParams.has('organizations') || urlParams.has('agents');
        if (!hasExplicitFilters) {
            try {
                var saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
                if (saved.time_range && saved.time_range !== 'this_month') {
                    range.value = saved.time_range;
                    var from = form.querySelector('[name="from_date"]');
                    var to = form.querySelector('[name="to_date"]');
                    if (from && saved.from_date) from.value = saved.from_date;
                    if (to && saved.to_date) to.value = saved.to_date;
                    toggleRange();
                }
            } catch (error) {}
        }
        form.addEventListener('submit', function () {
            try {
                localStorage.setItem(storageKey, JSON.stringify({
                    time_range: range.value,
                    from_date: (form.querySelector('[name="from_date"]') || {}).value || '',
                    to_date: (form.querySelector('[name="to_date"]') || {}).value || ''
                }));
            } catch (error) {}
        });
    }

    // Compatibility for existing self-hosted report markup while event handlers
    // are progressively moved to data attributes.
    window.updateTimeInline = updateTimeInline;
    window.openEntryModal = openEntryModal;
    window.closeEntryModal = closeEntryModal;
    window.toggleReportTicketPreview = toggleReportTicketPreview;
    window.toggleWeekAgents = function (id) {
        var row = document.getElementById(id);
        if (row) row.classList.toggle('hidden');
    };
    window.setTimeRange = function (rangeValue) {
        var range = document.getElementById('report-time-range');
        if (!range) return;
        range.value = rangeValue;
        range.dispatchEvent(new Event('change', { bubbles: true }));
    };

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-report-inline-time]')) updateTimeInline(event.target);
        if (event.target.matches('[data-report-auto-submit]') && event.target.form) event.target.form.submit();
        if (event.target.id === 'report-time-range') toggleRange();
    });

    document.addEventListener('click', function (event) {
        var rangeButton = event.target.closest('[data-report-range]');
        if (rangeButton) {
            var range = document.getElementById('report-time-range');
            if (range) {
                range.value = rangeButton.dataset.reportRange;
                range.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        var toggle = event.target.closest('[data-report-toggle-target]');
        if (toggle) {
            var target = document.getElementById(toggle.dataset.reportToggleTarget);
            if (target) target.classList.toggle('hidden');
        }
        var preview = event.target.closest('[data-report-preview-target]');
        if (preview) {
            var detail = document.getElementById(preview.dataset.reportPreviewTarget);
            if (detail) {
                var opening = detail.classList.contains('hidden');
                detail.classList.toggle('hidden', !opening);
                preview.setAttribute('aria-expanded', opening ? 'true' : 'false');
            }
        }
        var edit = event.target.closest('[data-report-edit-entry]');
        if (edit) {
            try { openEntryModal(JSON.parse(edit.dataset.reportEditEntry)); } catch (error) {}
        }
        if (event.target.closest('[data-report-close-entry-modal]')) closeEntryModal();
        if (event.target.closest('[data-report-print]')) window.print();
        var selectable = event.target.closest('[data-report-select-on-click]');
        if (selectable) selectable.select();
        var confirmButton = event.target.closest('[data-report-confirm]');
        if (confirmButton && !window.confirm(confirmButton.dataset.reportConfirm)) event.preventDefault();

        var picker = document.getElementById('col-picker-wrap');
        var dropdown = document.getElementById('col-picker-dropdown');
        if (picker && dropdown && !picker.contains(event.target)) dropdown.classList.add('hidden');
    });

    toggleRange();
    initCreateLinks();
    initChipSelects();
    initColumnPicker();
    initBulkSelection();
    initFilterConfirmation();
    initFilterPersistence();
})();
