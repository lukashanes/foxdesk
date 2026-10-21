(function () {
    'use strict';
    const root = document.querySelector('[data-time-agenda]');
    if (!root) return;
    const dialog = root.querySelector('[data-time-dialog]');
    const form = dialog.querySelector('form');
    const rows = JSON.parse(root.querySelector('[data-time-rows]').textContent);
    const field = name => form.elements.namedItem(name);
    const error = root.querySelector('[data-time-error]');
    let opener = null, searchVersion = 0, searchTimeout = null, precision = 'exact';
    function show(row, button) {
        opener = button; form.reset(); error.hidden = true;
        const title = root.querySelector('[data-time-title]');
        title.textContent = row ? title.dataset.edit : title.dataset.add;
        field('entry_id').value = row?.id || '';
        field('ticket_id').value = row?.ticket_id || '';
        root.querySelector('[data-time-ticket-picker]').hidden = !!row;
        root.querySelector('[data-time-ticket-caption]').textContent = row?.ticket_title || '';
        precision = row?.time_precision || 'exact';
        if (row) {
            field('worked_on').value = row.worked_on || row.started_at.slice(0,10);
            field('start_time').value = precision === 'exact' ? row.started_at.slice(11,16) : '';
            field('duration_minutes').value = row.duration_minutes;
            field('summary').value = row.summary || '';
            field('is_billable').checked = Number(row.is_billable) === 1;
        }
        dialog.showModal();
        if (!row) findTickets('');
    }
    function close() { dialog.close(); opener?.focus(); }
    root.querySelector('[data-time-add]').addEventListener('click', event => show(null,event.currentTarget));
    root.querySelectorAll('[data-time-edit]').forEach(button => button.addEventListener('click',()=>show(rows.find(row=>Number(row.id)===Number(button.dataset.timeEdit)),button)));
    root.querySelector('[data-time-close]').addEventListener('click', close);
    async function findTickets(search) {
        const version = ++searchVersion;
        const results = root.querySelector('[data-time-ticket-results]');
        results.setAttribute('aria-busy','true');
        try {
            const response = await window.FoxDeskApi.getTicketList({view:'all',search,limit:20,sort:'last_updated'});
            if (version !== searchVersion) return;
            results.replaceChildren();
            (response.data?.tickets || []).forEach(ticket=>{
                const button = document.createElement('button'); button.type='button'; button.className='btn btn-secondary btn-sm';
                button.textContent = ticket.code + ' · ' + ticket.title;
                button.addEventListener('click',()=>{
                    field('ticket_id').value=ticket.id;
                    root.querySelector('[data-time-ticket-caption]').textContent=button.textContent;
                    results.replaceChildren(); field('worked_on').focus();
                }); results.append(button);
            });
        } catch (failure) { error.textContent=failure.message; error.hidden=false; }
        finally { if(version===searchVersion)results.removeAttribute('aria-busy'); }
    }
    const search = root.querySelector('[data-time-ticket-search]');
    function schedule() { clearTimeout(searchTimeout); searchTimeout=setTimeout(()=>findTickets(search.value),200); }
    search.addEventListener('input',event=>{if(!event.isComposing)schedule();}); search.addEventListener('compositionend',schedule);
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        if(!form.reportValidity())return;
        const submit=form.querySelector('[type="submit"]'); submit.disabled=true; error.hidden=true;
        const body={ticket_id:Number(field('ticket_id').value),time_entry_id:Number(field('entry_id').value),
            duration_minutes:Number(field('duration_minutes').value),summary:field('summary').value,is_billable:field('is_billable').checked,
            worked_on:field('worked_on').value,time_precision:field('start_time').value?'exact':(precision==='exact'?'duration_only':precision),
            started_at:field('start_time').value?field('worked_on').value+'T'+field('start_time').value:null,ended_at:null};
        try {
            await window.FoxDeskApi.request(body.time_entry_id?'app-update-time-entry':'app-log-time',{method:'POST',body});
            location.reload();
        } catch(failure) {error.textContent=failure.message;error.hidden=false;submit.disabled=false;}
    });
}());
