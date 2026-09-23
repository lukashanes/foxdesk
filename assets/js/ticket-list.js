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

    window.FoxDeskTicketList = {
        config: config,
        label: label,
        csrfToken: csrfToken,
        apiCall: apiCall,
        toast: toast,
        errorToast: errorToast
    };

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
        var isComposing = false;

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

        function requestSuggestions() {
            clearTimeout(debounceTimer);
            if (isComposing) return;
            var value = searchInput.value.trim();
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
        }

        searchInput.addEventListener('compositionstart', function() {
            isComposing = true;
            clearTimeout(debounceTimer);
        });
        searchInput.addEventListener('compositionend', function() {
            isComposing = false;
            requestSuggestions();
        });
        searchInput.addEventListener('input', function(event) {
            if (event.isComposing) return;
            requestSuggestions();
        });

        searchInput.addEventListener('keydown', function(event) {
            if (isComposing || event.isComposing || event.keyCode === 229) return;
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
        var openOriginalParent = null;
        var openOriginalNextSibling = null;

        function restoreOpenDropdown() {
            if (!openDropdown || !openOriginalParent) return;
            if (openOriginalNextSibling && openOriginalNextSibling.parentNode === openOriginalParent) {
                openOriginalParent.insertBefore(openDropdown, openOriginalNextSibling);
            } else {
                openOriginalParent.appendChild(openDropdown);
            }
        }

        function clearDropdownPosition(dropdown) {
            dropdown.style.position = '';
            dropdown.style.left = '';
            dropdown.style.top = '';
            dropdown.style.right = '';
            dropdown.style.bottom = '';
            dropdown.style.minWidth = '';
            dropdown.style.zIndex = '';
            dropdown.style.visibility = '';
        }

        function positionDropdown(dropdown, trigger) {
            var rect = trigger.getBoundingClientRect();
            if (dropdown.parentNode !== document.body) {
                openOriginalParent = dropdown.parentNode;
                openOriginalNextSibling = dropdown.nextSibling;
                document.body.appendChild(dropdown);
            }

            // The dropdown lives under <body> while open, so viewport coordinates
            // must be paired with fixed positioning. Mixing getBoundingClientRect()
            // with document scroll offsets made the menu drift away from its row.
            dropdown.style.position = 'fixed';
            dropdown.style.left = '0px';
            dropdown.style.top = '0px';
            dropdown.style.right = 'auto';
            dropdown.style.bottom = 'auto';
            dropdown.style.minWidth = Math.max(trigger.offsetWidth, 176) + 'px';
            dropdown.style.zIndex = '1200';
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
                restoreOpenDropdown();
                clearDropdownPosition(openDropdown);
                openDropdown = null;
            }
            openTrigger = null;
            openOriginalParent = null;
            openOriginalNextSibling = null;
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
                        }
                    }

                    if (field === 'status') {
                        var accentClass = button.dataset.rowAccentClass || '';
                        if (accentClass) {
                            replaceModifierClass(row, 'ticket-status-accent', accentClass);
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
            var editButton = event.target.closest('.ticket-subject-edit-button[data-field="subject"]');
            if (!editButton || editButton.dataset.editing === '1') return;

            event.preventDefault();
            event.stopPropagation();
            var ticketId = editButton.dataset.ticket;
            var container = editButton.parentNode;
            var subjectLink = container && container.querySelector('.ticket-subject-link[data-ticket-field="title"]');
            if (!subjectLink) return;
            var subjectText = subjectLink.querySelector('bdi') || subjectLink;
            var current = editButton.dataset.value || subjectText.textContent.trim();
            var input = document.createElement('input');
            input.type = 'text';
            input.value = current;
            input.className = 'tl-inline-input ticket-subject-inline-input';
            input.maxLength = 500;
            input.setAttribute('aria-label', editButton.getAttribute('aria-label') || label('subject', 'Subject'));
            editButton.dataset.editing = '1';
            subjectLink.classList.add('hidden');
            editButton.classList.add('hidden');
            container.insertBefore(input, editButton);
            input.focus();
            input.select();

            var committed = false;
            function render(value) {
                subjectText.textContent = value;
                subjectLink.title = value;
                editButton.dataset.value = value;
            }
            function restoreControls() {
                input.remove();
                subjectLink.classList.remove('hidden');
                editButton.classList.remove('hidden');
                editButton.dataset.editing = '';
            }
            function commit(save) {
                if (committed) return;
                committed = true;
                var nextValue = input.value.trim();
                if (!save || nextValue === '' || nextValue === current) {
                    render(current);
                    restoreControls();
                    return;
                }

                render(nextValue);
                restoreControls();
                apiCall('quick-subject', ticketId, { title: nextValue })
                    .then(function(result) {
                        if (result.success) {
                            toast(result.message || label('saved', 'Saved'), 'success');
                            return;
                        }
                        render(current);
                        errorToast(result.error);
                    })
                    .catch(function() {
                        render(current);
                        errorToast();
                    });
            }

            input.addEventListener('keydown', function(innerEvent) {
                if (innerEvent.key === 'Enter') {
                    innerEvent.preventDefault();
                    commit(true);
                } else if (innerEvent.key === 'Escape') {
                    innerEvent.preventDefault();
                    commit(false);
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

    document.addEventListener('DOMContentLoaded', function() {
        syncTicketViewPreference();
        bindBulkCheckboxes();
        bindSearchSuggestions();
        bindInlineDropdowns();
        bindSubjectInlineEditor();
        bindNewTicketRow();
    });
})();
