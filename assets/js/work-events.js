(function () {
    'use strict';
    const copies = id => Array.from(document.querySelectorAll('[data-notification-id]')).filter(row => row.dataset.notificationId === id);
    function message(row, text) {
        const status = row.querySelector('[data-event-status]');
        status.textContent = text;
        status.hidden = false;
    }
    async function mutate(row, control, action, body, done) {
        control.disabled = true;
        try {
            const result = await window.FoxDeskApi.request(action, {method: 'POST', body});
            done(result.data);
            message(row, row.querySelector('[data-event-status]').dataset.saved);
        } catch (error) {
            message(row, error.message);
            if (control.matches('[data-event-reviewed]')) control.checked = !control.checked;
        } finally { control.disabled = false; }
    }
    document.querySelectorAll('[data-notification-id]').forEach(row => {
        const id = row.dataset.notificationId;
        const read = row.querySelector('[data-event-read]');
        read?.addEventListener('click', () => mutate(row, read, 'app-notification-read-state', {
            notification_id: Number(id), scope: 'notification', is_read: true
        }, data => {
            copies(id).forEach(copy => {
                copy.classList.remove('is-unread');
                const button = copy.querySelector('[data-event-read]');
                if (button) button.hidden = true;
            });
            const count = document.querySelector('[data-work-context-tab="activity"] span');
            if (count) count.textContent = String(data.unread_count);
        }));
        const review = row.querySelector('[data-event-reviewed]');
        review?.addEventListener('change', () => mutate(row, review, 'app-notification-review', {
            notification_id: Number(id), is_resolved: review.checked
        }, data => {
            copies(id).forEach(copy => {
                const checkbox = copy.querySelector('[data-event-reviewed]');
                if (checkbox) checkbox.checked = data.is_resolved;
                copy.classList.toggle('is-reviewed', data.is_resolved);
            });
            document.dispatchEvent(new Event('work-events-updated'));
            if (row.hidden) document.querySelector('[data-work-context-tab="attention"]')?.focus();
        }));
        const toggle = row.querySelector('[data-event-reply-toggle]');
        const form = row.querySelector('[data-event-reply]');
        toggle?.addEventListener('click', () => {
            form.hidden = !form.hidden;
            toggle.setAttribute('aria-expanded', String(!form.hidden));
            if (!form.hidden) form.elements.content.focus();
        });
        form?.addEventListener('submit', event => {
            event.preventDefault();
            if (!form.reportValidity() || !form.elements.content.value.trim()) return;
            const submit = form.querySelector('[type="submit"]');
            if (submit.disabled) return;
            mutate(row, submit, 'app-add-comment', {
                ticket_id: Number(form.dataset.ticketId), content: form.elements.content.value.trim(),
                is_internal: Boolean(form.elements.is_internal?.checked)
            }, () => {
                form.reset(); form.hidden = true;
                toggle.setAttribute('aria-expanded', 'false'); toggle.focus();
                // Replying does not silently mark the event reviewed or read.
            });
        });
    });
})();
