(function () {
    'use strict';
    const dialog = document.querySelector('[data-workspace-time-dialog]');
    if (!dialog) return;
    const form = dialog.querySelector('form');
    const field = name => form.elements.namedItem(name);
    const error = dialog.querySelector('[data-workspace-time-error]');
    const entries = JSON.parse(dialog.querySelector('[data-workspace-time-entries]').textContent);
    let opener, precision = 'exact', composing = false;
    function open(button, entry, commentId) {
        opener = button; form.reset(); error.hidden = true;
        dialog.querySelector('h2').textContent = entry ? dialog.dataset.titleEdit : dialog.dataset.titleAdd;
        dialog.querySelector('[data-workspace-time-delete]').hidden = !entry;
        precision = entry?.time_precision || 'exact';
        field('time_entry_id').value = entry?.id || '';
        field('comment_id').value = entry?.comment_id || commentId || 0;
        field('comment_id').dispatchEvent(new Event('change', {bubbles:true}));
        if (entry) {
            field('worked_on').value = entry.worked_on || entry.started_at.slice(0, 10);
            field('start_time').value = precision === 'exact' ? entry.started_at.slice(11, 16) : '';
            field('duration_minutes').value = entry.duration_minutes;
            field('summary').value = entry.summary || '';
            field('is_billable').checked = Number(entry.is_billable) === 1;
        }
        dialog.showModal(); field('summary').focus();
    }
    document.querySelectorAll('[data-workspace-time-add]').forEach(button => button.addEventListener('click', () => open(button, null, Number(button.dataset.workspaceTimeAdd))));
    document.querySelectorAll('[data-workspace-time-edit]').forEach(button => button.addEventListener('click', () => {
        const entry = entries.find(item => Number(item.id) === Number(button.dataset.workspaceTimeEdit));
        if (entry) open(button, entry);
    }));
    dialog.querySelector('[data-workspace-time-close]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-workspace-time-delete]').addEventListener('click', () => {
        const id = Number(field('time_entry_id').value);
        if (id && window.deleteTimeEntry) { dialog.close(); window.deleteTimeEntry(id); }
    });
    dialog.addEventListener('close', () => opener?.focus());
    form.addEventListener('compositionstart', () => { composing = true; });
    form.addEventListener('compositionend', () => { composing = false; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (composing || event.isComposing || !form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]'); submit.disabled = true; error.hidden = true;
        const body = {ticket_id:Number(field('ticket_id').value), time_entry_id:Number(field('time_entry_id').value),
            comment_id:Number(field('comment_id').value), duration_minutes:Number(field('duration_minutes').value),
            summary:field('summary').value, is_billable:field('is_billable').checked, worked_on:field('worked_on').value,
            time_precision:field('start_time').value ? 'exact' : (precision === 'exact' ? 'duration_only' : precision),
            started_at:field('start_time').value ? field('worked_on').value+'T'+field('start_time').value : null, ended_at:null};
        try {
            const result = await window.FoxDeskApi.request(body.time_entry_id ? 'app-update-time-entry' : 'app-log-time', {method:'POST',body});
            const id = body.time_entry_id || result.data?.time_entry_id || result.time_entry_id;
            // Reload even when an existing fragment is unchanged; then reveal its group.
            if (id) history.replaceState(null, '', '#time-entry-'+id);
            location.reload();
        } catch (failure) { error.textContent = failure.message; error.hidden = false; submit.disabled = false; }
    });
    if (location.hash.startsWith('#time-entry-')) {
        const row = document.getElementById(location.hash.slice(1));
        const details = row?.closest('details');
        if (details) { details.open = true; row.scrollIntoView({block:'center'}); }
    }
}());
