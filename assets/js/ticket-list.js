(function() {
    'use strict';

    var config = window.FoxDeskTicketListConfig || {};
    var labels = config.labels || {};
    var bulkMode = false;

    function label(key, fallback) {
        return Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : fallback;
    }

    function csrfToken() {
        return window.csrfToken || config.csrfToken || '';
    }

    function apiUrl(action) {
        var base = window.appConfig && window.appConfig.apiUrl
            ? window.appConfig.apiUrl
            : 'index.php?page=api';
        return base + '&action=' + action;
    }

    function toast(message, kind) {
        if (window.showAppToast) {
            window.showAppToast(message, kind || 'success');
        }
    }

    function errorToast(message) {
        toast(message || label('error', 'Error'), 'error');
    }

    function apiCall(action, ticketId, payload) {
        var body = new FormData();
        if (ticketId) {
            body.append('ticket_id', ticketId);
        }
        Object.keys(payload || {}).forEach(function(key) {
            body.append(key, payload[key]);
        });
        return fetch(apiUrl(action), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            body: body
        }).then(function(response) {
            return response.json();
        });
    }

    function replaceModifierClass(element, prefix, nextClass) {
        if (!element) return;
        Array.from(element.classList).forEach(function(className) {
            if (className.indexOf(prefix + '--') === 0) {
                element.classList.remove(className);
            }
        });
        if (nextClass) {
            element.classList.add(nextClass);
        }
    }

    function syncTicketViewPreference() {
        var currentView = config.currentView || '';
        if (!currentView) return;
        try {
            localStorage.setItem(config.ticketViewStorageKey || 'foxdesk_ticket_view', currentView);
        } catch (err) {}
    }

    window.applyHeaderSort = function(value) {
        var form = document.getElementById('filter-form');
        if (form) {
            var hidden = form.querySelector('input[name="sort"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'sort';
                form.appendChild(hidden);
            }
            hidden.value = value;
            form.submit();
            return;
        }

        var url = new URL(window.location);
        url.searchParams.set('sort', value);
        window.location = url.toString();
    };

    function syncBulkHighlights() {
        document.querySelectorAll('.bulk-checkbox').forEach(function(checkbox) {
            var tableRow = checkbox.closest('tr');
            var mobileCard = checkbox.closest('.ticket-list-item');
            var isSelected = bulkMode && checkbox.checked;

            [tableRow, mobileCard].forEach(function(element) {
                if (!element) return;
                element.classList.toggle('is-bulk-selected', isSelected);
                element.style.background = isSelected ? 'var(--surface-secondary)' : '';
            });
        });
    }

    function bulkToggleMarkup(isActive) {
        if (isActive) {
            return '<svg xmlns="http://www.w3.org/2000/svg" class="mr-1" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' + label('cancel', 'Cancel');
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" class="mr-1" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>' + label('bulkSelect', 'Bulk select');
    }

    window.toggleBulkMode = function() {
        bulkMode = !bulkMode;
        var checkboxes = document.querySelectorAll('.bulk-checkbox');
        var selectAll = document.getElementById('select-all');
        var toggleBtn = document.getElementById('bulk-toggle');
        var bulkActions = document.getElementById('bulk-actions');

        checkboxes.forEach(function(checkbox) {
            checkbox.classList.toggle('hidden', !bulkMode);
            checkbox.checked = false;
        });

        if (selectAll) {
            selectAll.classList.toggle('hidden', !bulkMode);
            selectAll.checked = false;
        }

        if (toggleBtn) {
            toggleBtn.innerHTML = bulkToggleMarkup(bulkMode);
            toggleBtn.classList.toggle('btn-ghost', !bulkMode);
            toggleBtn.classList.toggle('btn-secondary', bulkMode);
            toggleBtn.setAttribute('aria-pressed', bulkMode ? 'true' : 'false');
            if (!bulkMode && bulkActions) {
                bulkActions.classList.add('hidden');
            }
        }

        syncBulkHighlights();
        window.updateSelectedCount();
    };

    window.toggleAll = function(source) {
        document.querySelectorAll('.bulk-checkbox').forEach(function(checkbox) {
            checkbox.checked = source.checked;
        });
        syncBulkHighlights();
        window.updateSelectedCount();
    };

    window.updateSelectedCount = function() {
        var checked = document.querySelectorAll('.bulk-checkbox:checked').length;
        var countSpan = document.getElementById('selected-count');
        var bulkActions = document.getElementById('bulk-actions');

        if (countSpan) {
            countSpan.textContent = checked;
        }
        if (bulkActions) {
            bulkActions.classList.toggle('hidden', checked <= 0);
        }
        syncBulkHighlights();
    };

    function bindBulkCheckboxes() {
        document.querySelectorAll('.bulk-checkbox').forEach(function(checkbox) {
            checkbox.addEventListener('change', window.updateSelectedCount);
        });
    }

    function bindSearchSuggestions() {
        var searchInput = document.getElementById('ticket-search-input');
        var suggestBox = document.getElementById('ticket-search-suggestions');
        if (!searchInput || !suggestBox) return;

        var debounceTimer = null;
        var activeIdx = -1;
        var items = [];

        function closeSuggestions() {
            suggestBox.classList.remove('active');
            while (suggestBox.firstChild) {
                suggestBox.removeChild(suggestBox.firstChild);
            }
            activeIdx = -1;
            items = [];
        }

        function highlightItem(idx) {
            items.forEach(function(element, index) {
                element.classList.toggle('active', index === idx);
            });
            activeIdx = idx;
        }

        function createSuggestionItem(ticket) {
            var anchor = document.createElement('a');
            anchor.className = 'ticket-suggest-item';
            anchor.href = ticket.url;
            anchor.setAttribute('data-url', ticket.url);

            var code = document.createElement('span');
            code.className = 'suggest-code';
            code.textContent = ticket.ticket_code;

            var title = document.createElement('span');
            title.className = 'suggest-title';
            title.textContent = ticket.title;

            var status = document.createElement('span');
            status.className = 'suggest-status';
            if (ticket.status_color) {
                status.style.background = ticket.status_color + '20';
                status.style.color = ticket.status_color;
            }
            status.textContent = ticket.status_name;

            anchor.appendChild(code);
            anchor.appendChild(title);
            anchor.appendChild(status);
            return anchor;
        }

        function renderSuggestions(tickets) {
            while (suggestBox.firstChild) {
                suggestBox.removeChild(suggestBox.firstChild);
            }
            if (!tickets.length) {
                var emptyHint = document.createElement('div');
                emptyHint.className = 'ticket-suggest-hint';
                emptyHint.textContent = label('noTicketsFound', 'No tickets found') + ' - ' + label('enterToFilterList', 'Enter to filter list');
                suggestBox.appendChild(emptyHint);
                suggestBox.classList.add('active');
                items = [];
                activeIdx = -1;
                return;
            }
            tickets.forEach(function(ticket) {
                suggestBox.appendChild(createSuggestionItem(ticket));
            });
            var hint = document.createElement('div');
            hint.className = 'ticket-suggest-hint';
            hint.textContent = label('enterToFilterList', 'Enter to filter list');
            suggestBox.appendChild(hint);
            suggestBox.classList.add('active');
            items = Array.from(suggestBox.querySelectorAll('.ticket-suggest-item'));
            activeIdx = -1;
        }

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var value = this.value.trim();
            if (value.length < 2) {
                closeSuggestions();
                return;
            }
            debounceTimer = setTimeout(function() {
                fetch('index.php?page=api&action=search-tickets&q=' + encodeURIComponent(value))
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success && data.tickets) {
                            renderSuggestions(data.tickets);
                        } else {
                            closeSuggestions();
                        }
                    })
                    .catch(closeSuggestions);
            }, 300);
        });

        searchInput.addEventListener('keydown', function(event) {
            if (!suggestBox.classList.contains('active') || !items.length) {
                if (event.key === 'Escape') closeSuggestions();
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlightItem(Math.min(activeIdx + 1, items.length - 1));
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlightItem(Math.max(activeIdx - 1, 0));
            } else if (event.key === 'Enter') {
                if (activeIdx >= 0 && items[activeIdx]) {
                    event.preventDefault();
                    window.location.href = items[activeIdx].getAttribute('data-url');
                }
                closeSuggestions();
            } else if (event.key === 'Escape') {
                closeSuggestions();
                searchInput.blur();
            }
        });

        document.addEventListener('click', function(event) {
            if (!searchInput.contains(event.target) && !suggestBox.contains(event.target)) {
                closeSuggestions();
            }
        });

        searchInput.addEventListener('blur', function() {
            setTimeout(closeSuggestions, 200);
        });
    }

    function bindInlineDropdowns() {
        var openDropdown = null;
        var openTrigger = null;

        function positionDropdown(dropdown, trigger) {
            var rect = trigger.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.left = 'auto';
            dropdown.style.top = 'auto';
            var previousVisibility = dropdown.style.visibility;
            dropdown.style.visibility = 'hidden';
            dropdown.classList.remove('hidden');

            var dropdownWidth = dropdown.offsetWidth;
            var dropdownHeight = dropdown.offsetHeight;
            var viewportWidth = document.documentElement.clientWidth;
            var viewportHeight = document.documentElement.clientHeight;
            var left = rect.left;
            if (left + dropdownWidth > viewportWidth - 8) {
                left = Math.max(8, viewportWidth - dropdownWidth - 8);
            }
            var top = rect.bottom + 4;
            if (top + dropdownHeight > viewportHeight - 8) {
                top = Math.max(8, rect.top - dropdownHeight - 4);
            }
            dropdown.style.left = left + 'px';
            dropdown.style.top = top + 'px';
            dropdown.style.visibility = previousVisibility || '';
        }

        function onReposition() {
            if (openDropdown && openTrigger) {
                positionDropdown(openDropdown, openTrigger);
            }
        }

        function closeAll() {
            if (openDropdown) {
                openDropdown.classList.add('hidden');
                openDropdown.style.position = '';
                openDropdown.style.left = '';
                openDropdown.style.top = '';
                openDropdown = null;
            }
            openTrigger = null;
            window.removeEventListener('scroll', onReposition, true);
            window.removeEventListener('resize', onReposition);
        }

        window.__foxdeskCloseInlineDropdowns = closeAll;

        document.addEventListener('click', function(event) {
            var trigger = event.target.closest('.tl-edit-trigger');
            if (trigger) {
                event.preventDefault();
                event.stopImmediatePropagation();
                var ticketId = trigger.dataset.ticket;
                var field = trigger.dataset.field;
                var dropdown = document.querySelector('[data-dropdown="' + field + '-' + ticketId + '"]');
                if (!dropdown) return;
                if (openDropdown === dropdown) {
                    closeAll();
                    return;
                }
                closeAll();
                positionDropdown(dropdown, trigger);
                openDropdown = dropdown;
                openTrigger = trigger;
                window.addEventListener('scroll', onReposition, true);
                window.addEventListener('resize', onReposition);
                return;
            }
            if (!event.target.closest('.tl-dropdown')) {
                closeAll();
            }
        });

        window.inlineUpdate = function(ticketId, field, valueId, button) {
            closeAll();
            var action = field === 'status' ? 'agent-update-status' : 'quick-priority';
            var options = { method: 'POST' };

            if (field === 'status') {
                options.headers = { 'Content-Type': 'application/json' };
                options.body = JSON.stringify({ ticket_id: ticketId, status_id: valueId });
            } else {
                var body = new FormData();
                body.append('ticket_id', ticketId);
                body.append('priority_id', valueId);
                options.headers = { 'X-CSRF-TOKEN': csrfToken() };
                options.body = body;
            }

            fetch(apiUrl(action), options)
                .then(function(response) {
                    return response.json();
                })
                .then(function(result) {
                    if (!result.success) {
                        errorToast(result.error);
                        return;
                    }

                    var row = button.closest('tr');
                    if (!row) {
                        location.reload();
                        return;
                    }
                    var name = button.textContent.trim();
                    var trigger = row.querySelector('.tl-edit-trigger[data-field="' + field + '"]');
                    if (trigger) {
                        trigger.textContent = name;
                        var toneClass = button.dataset.toneClass || '';
                        if (toneClass) {
                            replaceModifierClass(trigger, field === 'status' ? 'ticket-status-inline' : 'ticket-priority-inline', toneClass);
                        } else {
                            var color = button.querySelector('.rounded-full') ? button.querySelector('.rounded-full').style.background : '';
                            if (color) {
                                trigger.style.backgroundColor = color + '20';
                                trigger.style.color = color;
                            }
                        }
                    }

                    if (field === 'status') {
                        var accentClass = button.dataset.rowAccentClass || '';
                        if (accentClass) {
                            replaceModifierClass(row, 'ticket-status-accent', accentClass);
                        } else {
                            var statusColor = button.querySelector('.rounded-full') ? button.querySelector('.rounded-full').style.background : '';
                            if (statusColor) row.style.borderLeftColor = statusColor;
                        }
                    }
                    toast(result.message || label('saved', 'Saved'), 'success');
                })
                .catch(function() {
                    errorToast();
                });
        };
    }

    function closeInlineDropdowns() {
        if (typeof window.__foxdeskCloseInlineDropdowns === 'function') {
            window.__foxdeskCloseInlineDropdowns();
        } else {
            document.querySelectorAll('.tl-dropdown').forEach(function(dropdown) {
                dropdown.classList.add('hidden');
            });
        }
    }

    window.inlineUpdateType = function(ticketId, slug, text, button) {
        closeInlineDropdowns();
        apiCall('quick-type', ticketId, { type: slug })
            .then(function(result) {
                if (!result.success) {
                    errorToast(result.error);
                    return;
                }
                var row = button.closest('tr');
                var trigger = row && row.querySelector('.tl-type-trigger[data-ticket="' + ticketId + '"]');
                if (trigger) trigger.textContent = text;
                toast(result.message || label('saved', 'Saved'), 'success');
            })
            .catch(function() {
                errorToast();
            });
    };

    window.inlineUpdateCompany = function(ticketId, organizationId, text, button) {
        closeInlineDropdowns();
        apiCall('quick-company', ticketId, { organization_id: organizationId })
            .then(function(result) {
                if (!result.success) {
                    errorToast(result.error);
                    return;
                }
                var row = button.closest('tr');
                var trigger = row && row.querySelector('.tl-company-trigger[data-ticket="' + ticketId + '"]');
                if (trigger) {
                    trigger.innerHTML = organizationId === '' || !text
                        ? '<span class="ticket-empty-value">&mdash;</span>'
                        : '';
                    if (organizationId !== '' && text) {
                        trigger.textContent = text;
                    }
                }
                toast(result.message || label('saved', 'Saved'), 'success');
            })
            .catch(function() {
                errorToast();
            });
    };

    window.inlineUpdateAssign = function(ticketId, assigneeId, text, button) {
        closeInlineDropdowns();
        apiCall('quick-assign', ticketId, { assignee_id: assigneeId })
            .then(function(result) {
                if (!result.success) {
                    errorToast(result.error);
                    return;
                }
                var row = button.closest('tr');
                var trigger = row && row.querySelector('.tl-assign-trigger[data-ticket="' + ticketId + '"]');
                if (trigger) {
                    trigger.innerHTML = !assigneeId
                        ? '<span class="ticket-empty-value">' + label('unassigned', 'Unassigned') + '</span>'
                        : '';
                    if (assigneeId) {
                        trigger.textContent = text;
                    }
                }
                toast(result.message || label('saved', 'Saved'), 'success');
            })
            .catch(function() {
                errorToast();
            });
    };

    function bindSubjectInlineEditor() {
        document.addEventListener('click', function(event) {
            var subject = event.target.closest('.tl-inline-text[data-field="subject"]');
            if (!subject || subject.dataset.editing === '1') return;

            event.preventDefault();
            event.stopPropagation();
            var ticketId = subject.dataset.ticket;
            var current = subject.dataset.value || subject.textContent.trim();
            var input = document.createElement('input');
            input.type = 'text';
            input.value = current;
            input.className = 'tl-inline-input';
            input.maxLength = 500;
            subject.dataset.editing = '1';
            subject.textContent = '';
            subject.appendChild(input);
            input.focus();
            input.select();

            var committed = false;
            function commit(save) {
                if (committed) return;
                committed = true;
                var nextValue = input.value.trim();
                subject.dataset.editing = '';
                if (!save || nextValue === '' || nextValue === current) {
                    subject.textContent = current;
                    return;
                }

                subject.textContent = nextValue;
                subject.dataset.value = nextValue;
                apiCall('quick-subject', ticketId, { title: nextValue })
                    .then(function(result) {
                        if (result.success) {
                            toast(result.message || label('saved', 'Saved'), 'success');
                            return;
                        }
                        subject.textContent = current;
                        subject.dataset.value = current;
                        errorToast(result.error);
                    })
                    .catch(function() {
                        subject.textContent = current;
                        subject.dataset.value = current;
                        errorToast();
                    });
            }

            input.addEventListener('keydown', function(innerEvent) {
                if (innerEvent.key === 'Enter') {
                    innerEvent.preventDefault();
                    commit(true);
                    input.blur();
                } else if (innerEvent.key === 'Escape') {
                    commit(false);
                    input.blur();
                }
            });
            input.addEventListener('blur', function() {
                commit(true);
            });
            input.addEventListener('click', function(innerEvent) {
                innerEvent.stopPropagation();
            });
        });
    }

    function bindDueDatePopover() {
        var activePopover = null;
        var activeTrigger = null;
        var overdueIconHtml = config.overdueIconHtml || '';

        function closeDuePopover() {
            if (activePopover) {
                activePopover.remove();
                activePopover = null;
            }
            activeTrigger = null;
            document.removeEventListener('click', onOutsideClick, true);
            document.removeEventListener('keydown', onEscape);
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        }

        function onOutsideClick(event) {
            if (event.target.closest('.tl-due-popover') || event.target.closest('.tl-due-trigger')) return;
            closeDuePopover();
        }

        function onEscape(event) {
            if (event.key === 'Escape') closeDuePopover();
        }

        function reposition() {
            if (!activePopover || !activeTrigger) return;
            var rect = activeTrigger.getBoundingClientRect();
            var viewportWidth = document.documentElement.clientWidth;
            var viewportHeight = document.documentElement.clientHeight;
            var popoverWidth = activePopover.offsetWidth || 220;
            var popoverHeight = activePopover.offsetHeight || 90;
            var left = Math.min(rect.left, viewportWidth - popoverWidth - 8);
            if (left < 8) left = 8;
            var top = rect.bottom + 6;
            if (top + popoverHeight > viewportHeight - 8) {
                top = Math.max(8, rect.top - popoverHeight - 6);
            }
            activePopover.style.left = left + 'px';
            activePopover.style.top = top + 'px';
        }

        function renderTrigger(trigger, dueValue) {
            trigger.dataset.due = dueValue || '';
            trigger.classList.remove('text-red-600', 'font-medium');
            var row = trigger.closest('.ticket-row, .ticket-list-item');
            var isClosed = trigger.dataset.isClosed === '1';
            if (!dueValue) {
                trigger.innerHTML = '<span class="ticket-empty-value">&mdash;</span>';
                if (row) row.classList.remove('ticket-overdue');
                return;
            }

            var parts = dueValue.split('-');
            var dueLabel = (parts[2] || '') + '.' + (parts[1] || '');
            var dueEnd = new Date(dueValue + 'T23:59:59');
            var isOverdue = !isClosed && !isNaN(dueEnd.getTime()) && dueEnd.getTime() < Date.now();
            trigger.innerHTML = dueLabel + (isOverdue ? overdueIconHtml : '');
            trigger.classList.toggle('text-red-600', isOverdue);
            trigger.classList.toggle('font-medium', isOverdue);
            if (row) row.classList.toggle('ticket-overdue', isOverdue);
        }

        function syncDraft(input) {
            if (!input) return '';
            input.dataset.pendingValue = input.value || '';
            return input.dataset.pendingValue;
        }

        function readDraft(input) {
            if (!input) return '';
            return typeof input.dataset.pendingValue === 'string'
                ? input.dataset.pendingValue
                : input.value || '';
        }

        function saveValue(trigger, input) {
            var ticketId = trigger.dataset.ticket;
            var nextValue = readDraft(input);
            input.disabled = true;
            apiCall('quick-due-date', ticketId, { due_date: nextValue })
                .then(function(result) {
                    if (result.success) {
                        renderTrigger(trigger, typeof result.due_date_iso === 'string' ? result.due_date_iso : nextValue);
                        closeDuePopover();
                        toast(result.message || label('saved', 'Saved'), 'success');
                        return;
                    }
                    input.disabled = false;
                    errorToast(result.error);
                })
                .catch(function() {
                    input.disabled = false;
                    errorToast();
                });
        }

        document.addEventListener('click', function(event) {
            var trigger = event.target.closest('.tl-due-trigger');
            if (!trigger) return;
            event.preventDefault();
            event.stopPropagation();

            if (activeTrigger === trigger) {
                closeDuePopover();
                return;
            }

            closeDuePopover();
            var template = document.getElementById('tl-due-popover-tpl');
            if (!template) return;
            var fragment = template.content.cloneNode(true);
            var popover = fragment.querySelector('.tl-due-popover');
            var input = fragment.querySelector('.tl-due-popover__input');
            var saveButton = fragment.querySelector('.tl-due-popover__save');
            var clearButton = fragment.querySelector('.tl-due-popover__clear');

            input.value = trigger.dataset.due || '';
            input.dataset.pendingValue = trigger.dataset.due || '';
            document.body.appendChild(popover);
            activePopover = popover;
            activeTrigger = trigger;
            reposition();

            input.addEventListener('input', function() {
                syncDraft(input);
            });
            input.addEventListener('change', function() {
                syncDraft(input);
            });
            saveButton.addEventListener('click', function(innerEvent) {
                innerEvent.preventDefault();
                innerEvent.stopPropagation();
                syncDraft(input);
                window.setTimeout(function() {
                    saveValue(trigger, input);
                }, 0);
            });
            clearButton.addEventListener('click', function(innerEvent) {
                innerEvent.preventDefault();
                innerEvent.stopPropagation();
                input.value = '';
                input.dataset.pendingValue = '';
                window.setTimeout(function() {
                    saveValue(trigger, input);
                }, 0);
            });
            input.addEventListener('keydown', function(innerEvent) {
                if (innerEvent.key === 'Enter') {
                    innerEvent.preventDefault();
                    syncDraft(input);
                    window.setTimeout(function() {
                        saveValue(trigger, input);
                    }, 0);
                } else if (innerEvent.key === 'Escape') {
                    innerEvent.preventDefault();
                    closeDuePopover();
                }
            });

            setTimeout(function() {
                input.focus();
                if (typeof input.showPicker === 'function') {
                    try {
                        input.showPicker();
                    } catch (err) {}
                }
            }, 30);

            setTimeout(function() {
                document.addEventListener('click', onOutsideClick, true);
                document.addEventListener('keydown', onEscape);
                window.addEventListener('resize', reposition);
                window.addEventListener('scroll', reposition, true);
            }, 0);
        });
    }

    function bindNewTicketRow() {
        var row = document.getElementById('new-ticket-row');
        var button = document.getElementById('new-ticket-submit-btn');
        var subject = document.getElementById('new-ticket-subject');
        if (!row || !button || !subject) return;

        function value(id) {
            var element = document.getElementById(id);
            return element ? element.value : '';
        }

        function isHidden() {
            return row.classList.contains('hidden') || row.style.display === 'none';
        }

        window.toggleNewTicketRow = function(force) {
            var show = typeof force === 'boolean' ? force : isHidden();
            row.classList.toggle('hidden', !show);
            row.style.display = show ? '' : 'none';
            var toggleButton = document.getElementById('quick-add-toggle-btn');
            if (toggleButton) {
                toggleButton.classList.toggle('is-active', show);
            }
            if (show) {
                setTimeout(function() {
                    subject.focus();
                }, 50);
            }
        };

        var submitting = false;
        function submit() {
            if (submitting) return;
            var title = subject.value.trim();
            if (!title) {
                subject.focus();
                return;
            }

            var minutes = parseInt(value('new-ticket-minutes'), 10);
            if (!isNaN(minutes) && (minutes < 0 || minutes > 1440)) {
                errorToast(label('durationBounds', 'Duration must be between 1 and 1440 minutes.'));
                return;
            }

            submitting = true;
            button.disabled = true;
            apiCall('quick-create-ticket', null, {
                title: title,
                status_id: value('new-ticket-status'),
                priority_id: value('new-ticket-priority'),
                due_date: value('new-ticket-due'),
                organization_id: value('new-ticket-company'),
                assignee_id: value('new-ticket-assignee'),
                type: value('new-ticket-type')
            }).then(function(result) {
                if (!result.success) {
                    submitting = false;
                    button.disabled = false;
                    errorToast(result.error);
                    return;
                }

                var createdMessage = result.message || label('ticketCreated', 'Ticket created.');
                if (!isNaN(minutes) && minutes > 0 && result.ticket_id) {
                    apiCall('quick-log-time', result.ticket_id, { duration_minutes: minutes })
                        .then(function() {
                            toast(createdMessage, 'success');
                            location.reload();
                        })
                        .catch(function() {
                            toast(createdMessage, 'success');
                            location.reload();
                        });
                    return;
                }

                toast(createdMessage, 'success');
                location.reload();
            }).catch(function() {
                submitting = false;
                button.disabled = false;
                errorToast();
            });
        }

        button.addEventListener('click', submit);
        [subject, document.getElementById('new-ticket-minutes')].forEach(function(element) {
            if (!element) return;
            element.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submit();
                } else if (event.key === 'Escape') {
                    window.toggleNewTicketRow(false);
                }
            });
        });
    }

    function bindInlineLogTime() {
        var activeChips = null;
        var activeButton = null;
        var customPopover = null;

        function closeAll() {
            if (activeChips) {
                if (activeChips._reposition) {
                    window.removeEventListener('resize', activeChips._reposition);
                    window.removeEventListener('scroll', activeChips._reposition, true);
                }
                activeChips.remove();
                activeChips = null;
            }
            if (customPopover) {
                customPopover.remove();
                customPopover = null;
            }
            activeButton = null;
            document.removeEventListener('click', onOutsideClick, true);
            document.removeEventListener('keydown', onEscape);
        }

        function positionChips(chips, button) {
            var rect = button.getBoundingClientRect();
            var viewportWidth = document.documentElement.clientWidth;
            var viewportHeight = document.documentElement.clientHeight;
            var chipsWidth = chips.offsetWidth || 240;
            var chipsHeight = chips.offsetHeight || 28;
            var left = rect.left - chipsWidth - 8;
            if (left < 8) left = 8;
            if (left + chipsWidth > viewportWidth - 8) left = viewportWidth - chipsWidth - 8;
            var top = rect.top + (rect.height - chipsHeight) / 2;
            if (top < 8) top = 8;
            if (top + chipsHeight > viewportHeight - 8) top = viewportHeight - chipsHeight - 8;
            chips.style.left = left + 'px';
            chips.style.top = top + 'px';
        }

        function onOutsideClick(event) {
            if (event.target.closest('.js-inline-log-time') ||
                event.target.closest('.ilt-chips') ||
                event.target.closest('.ilt-custom')) {
                return;
            }
            closeAll();
        }

        function onEscape(event) {
            if (event.key === 'Escape') closeAll();
        }

        function saveDuration(ticketId, minutes, note) {
            var body = new URLSearchParams();
            body.append('ticket_id', ticketId);
            body.append('duration_minutes', String(minutes));
            if (note) body.append('note', note);

            return fetch('index.php?page=api&action=quick-log-time', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            }).then(function(response) {
                return response.json();
            });
        }

        function openCustom(button) {
            if (activeChips) {
                activeChips.remove();
            }
            activeChips = null;

            var template = document.getElementById('inline-log-time-custom-tpl');
            if (!template) return;
            var fragment = template.content.cloneNode(true);
            var popover = fragment.querySelector('.ilt-custom');
            document.body.appendChild(popover);
            customPopover = popover;

            var rect = button.getBoundingClientRect();
            var viewportWidth = document.documentElement.clientWidth;
            var viewportHeight = document.documentElement.clientHeight;
            var popoverWidth = 240;
            var popoverHeight = 140;
            var left = Math.min(rect.right - popoverWidth, viewportWidth - popoverWidth - 8);
            if (left < 8) left = 8;
            var top = rect.bottom + 6;
            if (top + popoverHeight > viewportHeight - 8) top = rect.top - popoverHeight - 6;
            if (top < 8) top = 8;
            popover.style.left = left + 'px';
            popover.style.top = top + 'px';

            var duration = popover.querySelector('.ilt-duration');
            var note = popover.querySelector('.ilt-note');
            var save = popover.querySelector('.ilt-save');

            setTimeout(function() {
                duration.focus();
            }, 30);

            popover.querySelector('.ilt-cancel').addEventListener('click', function(event) {
                event.stopPropagation();
                closeAll();
            });

            save.addEventListener('click', function(event) {
                event.stopPropagation();
                var minutes = parseInt(duration.value, 10) || 0;
                if (minutes <= 0) {
                    duration.focus();
                    return;
                }
                save.disabled = true;
                save.textContent = '...';
                saveDuration(button.dataset.ticketId, minutes, note.value.trim())
                    .then(function(result) {
                        if (result.success) {
                            toast(result.message || label('saved', 'Saved'), 'success');
                            setTimeout(function() {
                                window.location.reload();
                            }, 300);
                            return;
                        }
                        save.disabled = false;
                        save.textContent = label('save', 'Save');
                        errorToast(result.error);
                    })
                    .catch(function() {
                        save.disabled = false;
                        save.textContent = label('save', 'Save');
                        errorToast();
                    });
            });

            duration.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    save.click();
                }
            });
        }

        function openChips(button) {
            closeAll();
            var template = document.getElementById('inline-log-time-chips-tpl');
            if (!template) return;
            var fragment = template.content.cloneNode(true);
            var chips = fragment.querySelector('.ilt-chips');
            chips.classList.add('ilt-chips--floating');
            document.body.appendChild(chips);
            positionChips(chips, button);
            activeChips = chips;
            activeButton = button;

            var onResize = function() {
                if (activeChips) {
                    positionChips(activeChips, button);
                }
            };
            window.addEventListener('resize', onResize);
            window.addEventListener('scroll', onResize, true);
            chips._reposition = onResize;

            chips.querySelectorAll('.ilt-chip[data-mins]').forEach(function(chip) {
                chip.addEventListener('click', function(event) {
                    event.stopPropagation();
                    var minutes = parseInt(chip.dataset.mins, 10) || 0;
                    if (!minutes) return;
                    chip.disabled = true;
                    chip.textContent = '...';
                    saveDuration(button.dataset.ticketId, minutes, '')
                        .then(function(result) {
                            if (result.success) {
                                toast(result.message || label('saved', 'Saved'), 'success');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 300);
                                return;
                            }
                            chip.disabled = false;
                            chip.textContent = '+' + minutes;
                            errorToast(result.error);
                        })
                        .catch(function() {
                            chip.disabled = false;
                            chip.textContent = '+' + minutes;
                            errorToast();
                        });
                });
            });

            var customButton = chips.querySelector('.ilt-chip--custom');
            if (customButton) {
                customButton.addEventListener('click', function(event) {
                    event.stopPropagation();
                    openCustom(button);
                });
            }

            setTimeout(function() {
                document.addEventListener('click', onOutsideClick, true);
                document.addEventListener('keydown', onEscape);
            }, 0);
        }

        document.addEventListener('click', function(event) {
            var button = event.target.closest('.js-inline-log-time');
            if (!button) return;
            event.preventDefault();
            event.stopPropagation();
            if (activeButton === button) {
                closeAll();
                return;
            }
            openChips(button);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        syncTicketViewPreference();
        bindBulkCheckboxes();
        bindSearchSuggestions();
        bindInlineDropdowns();
        bindSubjectInlineEditor();
        bindDueDatePopover();
        bindNewTicketRow();
        bindInlineLogTime();
    });
})();
