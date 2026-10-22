(function() {
    'use strict';

    var ticketList = window.FoxDeskTicketList || {};
    var config = ticketList.config || {};
    var label = ticketList.label || function(key, fallback) { return fallback || key; };
    var apiCall = ticketList.apiCall;
    var toast = ticketList.toast || function() {};
    var errorToast = ticketList.errorToast || function() {};

    function bindDueDatePopover() {
        if (typeof apiCall !== 'function') return;
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

    document.addEventListener('DOMContentLoaded', bindDueDatePopover);
})();
