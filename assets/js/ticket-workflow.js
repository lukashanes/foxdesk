/* Ticket workflow enhancements. Server permissions remain authoritative. */
(function () {
    'use strict';
    function ready(run) { if (document.readyState !== 'complete') document.addEventListener('DOMContentLoaded', run, {once:true}); else run(); }
    ready(function () {
        var script = document.getElementById('ticket-workflow-script');
        var scope = script ? script.dataset.scope : '';
        var queueKey = 'foxdesk_queue_v3_' + scope;
        function load(store, key) { try { return JSON.parse(store.getItem(key) || 'null'); } catch (_) { return null; } }
        function save(store, key, value) { try { store.setItem(key, JSON.stringify(value)); return true; } catch (_) { return false; } }
        function remove(store, key) { try { store.removeItem(key); } catch (_) {} }
        function safeUrl(value) { try { var u = new URL(value, location.href); return u.origin === location.origin && u.pathname === location.pathname ? u : null; } catch (_) { return null; } }
        function ticketKey(value) { var u = safeUrl(value); return u ? (u.searchParams.get('t') || u.searchParams.get('id')) : null; }
        var surface = document.querySelector('[data-workflow]');
        if (!surface) {
            if (!['tickets','work'].includes(new URL(location.href).searchParams.get('page'))) return;
            var activeView = document.querySelector('.ticket-view-tab.is-active');
            if (activeView && activeView.parentElement.scrollWidth > activeView.parentElement.clientWidth) {
                var tabStrip = activeView.parentElement;
                tabStrip.scrollBy({left: activeView.getBoundingClientRect().left - tabStrip.getBoundingClientRect().left - (tabStrip.clientWidth - activeView.offsetWidth) / 2, behavior:'instant'});
            }
            var previous = load(sessionStorage, queueKey);
            if (previous && previous.url === location.href && previous.restore) {
                window.scrollTo(0, previous.scroll || 0); previous.restore = false; save(sessionStorage, queueKey, previous);
            }
            document.addEventListener('click', function (event) {
                var link = event.target.closest('a[href]');
                var url = link && safeUrl(link.href);
                if (!url || url.searchParams.get('page') !== 'ticket') return;
                var queueRoot = link.closest('[data-work-ticket-list], .work-activity-list, table') || link.closest('main');
                if (!queueRoot) return;
                var seenTickets = new Set();
                var urls = Array.from(queueRoot.querySelectorAll('a[href]')).filter(function (a) {
                    var u = safeUrl(a.href), key = ticketKey(a.href);
                    if (!a.getClientRects().length || !u || u.searchParams.get('page') !== 'ticket' || !key || seenTickets.has(key)) return false;
                    seenTickets.add(key); return true;
                }).map(function (a) { return a.href; });
                save(sessionStorage, queueKey, {url: location.href, scroll: window.scrollY, links: Array.from(new Set(urls)), restore: true});
            });
            return;
        }
        var meta = JSON.parse(surface.dataset.workflow);
        var copy = meta.copy;
        var form = document.getElementById('comment-form');
        var key = 'foxdesk_workflow_draft_v2_' + scope + '_' + meta.ticket_id;
        var draft = load(localStorage, key);
        function newNonce() { return window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2); }
        if (draft && draft.nonce === meta.draft_ack) {
            var sentInternal = (draft.values || []).some(function(value){return value.name === 'is_internal' && value.checked;});
            var remaining = sentInternal ? draft.content : draft.internal;
            if (remaining && (remaining.replace(/<[^>]*>/g,'').trim() || /<img\b/i.test(remaining))) {
                draft = {nonce:newNonce(),values:[{name:'is_internal',value:'on',checked:!sentInternal,type:'checkbox'}],content:sentInternal?remaining:'',internal:sentInternal?'':remaining,files:[],saved:Date.now()};
                save(localStorage,key,draft);
            } else { remove(localStorage, key); draft = null; }
        }
        var nonce = draft ? draft.nonce : newNonce();
        var live = document.createElement('p'); live.className = 'workflow-feedback'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
        form.prepend(live);
        function announce(message, error) { live.textContent = message; live.classList.toggle('workflow-feedback--error', !!error); if (error) live.scrollIntoView({block:'nearest'}); }
        function hidden(name, value) {
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.append(input); }
            input.value = value; return input;
        }
        hidden('expected_revision', meta.revision); hidden('workflow_draft', nonce);
        // Only a confirmed server acknowledgement removes a draft, including on retry.
        var ignore = ['csrf_token', 'expected_revision', 'workflow_draft', 'redirect_to'];
        function fields() { return Array.from(form.querySelectorAll('input[name], select[name], textarea[name]')).filter(function (el) { return el.type !== 'file' && ignore.indexOf(el.name) === -1; }); }
        var composing = false, timer, dirty = false;
        function persist() {
            if (composing || (!dirty && !draft)) return;
            var values = fields().map(function (el) { return {name:el.name, value:el.value, checked:el.checked, type:el.type}; });
            var content = window.commentEditor ? window.commentEditor.root.innerHTML : '';
            var internal = window.internalEditor ? window.internalEditor.root.innerHTML : '';
            var files = Array.from(form.querySelector('input[type="file"]')?.files || []).map(function (file) { return file.name; });
            save(localStorage, key, {nonce:nonce, values:values, content:content, internal:internal, files:files, saved:Date.now()});
        }
        function schedule() { dirty = true; clearTimeout(timer); timer = setTimeout(persist, 500); }
        form.addEventListener('compositionstart', function () { composing = true; });
        form.addEventListener('compositionend', function () { composing = false; schedule(); });
        form.addEventListener('input', schedule); form.addEventListener('change', schedule);
        [window.commentEditor, window.internalEditor].forEach(function (editor) { if (editor) editor.on('text-change', schedule); });
        window.addEventListener('pagehide', persist);
        document.addEventListener('click', function (event) { if (event.target.closest('a[href]')) persist(); }, true);

        function disclosure(label, className) {
            var box = document.createElement('details'); box.className = 'workflow-disclosure ' + (className || '');
            var summary = document.createElement('summary'); summary.textContent = label; box.append(summary); return box;
        }
        var more = disclosure(copy.more);
        var tools = form.querySelector('.ticket-composer-tools');
        if (tools) tools.after(more); else { var moreAnchor=form.querySelector('#manual-entry-row'); if(moreAnchor) moreAnchor.before(more); else form.append(more); }
        var date = form.querySelector('[name="comment_created_at"]'); if (date) more.append(date.parentElement);
        var status = form.querySelector('select[name="status_id"]');
        if (status) { status.setAttribute('aria-label', document.getElementById('ticket-header-status')?.labels?.[0]?.textContent || 'Status'); more.append(status); }
        var cc = document.getElementById('agent-cc-dropdown-container'); if (cc) more.append(cc);
        var notification = form.querySelector('[name="skip_notification"]')?.closest('label'); if (notification) more.append(notification);
        var manual = document.getElementById('manual-entry-row');
        var timeBox = null, exact = null;
        if (manual) {
            timeBox = disclosure(copy.add_time); manual.before(timeBox); timeBox.append(manual); manual.classList.remove('hidden');
            var oldToggle = document.getElementById('manual-toggle'); if (oldToggle) oldToggle.hidden = true;
            exact = disclosure(copy.exact_time); manual.append(exact);
            ['manual_start_time', 'manual_end_time'].forEach(function (name) { var el = form.querySelector('[name="' + name + '"]'); if (el) exact.append(el.parentElement); });
        }
        form.querySelectorAll('.form-label-sm').forEach(function (label, index) {
            var input = label.parentElement.querySelector('input');
            if (input) { input.id = input.id || 'workflow-input-' + index; label.htmlFor = input.id; }
        });
        document.querySelectorAll('.ticket-side-value').forEach(function (cell, index) {
            var label = cell.previousElementSibling; var input = cell.querySelector('select, input');
            if (label && input) { label.id = label.id || 'workflow-property-' + index; input.setAttribute('aria-labelledby', label.id); }
        });
        var modeButtons = form.querySelectorAll('[data-mode]');
        modeButtons.forEach(function (button) { button.querySelector('span').textContent = button.title; });
        var recipients = document.createElement('p'); recipients.className = 'workflow-recipients'; recipients.setAttribute('aria-live', 'polite');
        if (meta.allowed_actions.length) form.prepend(recipients);
        function recipientLabel() {
            var internal = !!document.getElementById('is_internal_toggle')?.checked;
            var skipped = !!form.querySelector('[name="skip_notification"]')?.checked;
            var emails = [...(meta.recipient_emails || [])];
            form.querySelectorAll('.agent-cc-checkbox:checked').forEach(function(el){if(el.dataset.email) emails.push(el.dataset.email);});
            recipients.replaceChildren(document.createTextNode(copy.recipients + ': '));
            var list = internal || skipped || !meta.email_enabled ? [] : Array.from(new Set(emails));
            if (!list.length) recipients.append(document.createTextNode('—'));
            list.forEach(function(email,index){if(index)recipients.append(document.createTextNode(', '));var bdi=document.createElement('bdi');bdi.textContent=email;recipients.append(bdi);});
        }
        var submit = document.getElementById('comment-submit-btn');
        var outcome = document.createElement('select'); outcome.name = 'workflow_outcome'; outcome.className = 'form-select workflow-outcome'; outcome.setAttribute('aria-label', copy.send);
        [['',copy.send],['waiting',copy.send_waiting],['done',copy.send_done]].forEach(function (entry) {
            if (entry[0] && (!meta.allowed_actions.length || !meta.targets[entry[0]])) return;
            var option = new Option(entry[1],entry[0]); outcome.add(option);
        });
        submit.before(outcome);
        function submitLabel() {
            recipientLabel();
            var internal = !!document.getElementById('is_internal_toggle')?.checked;
            submit.querySelector('.btn-text').textContent = internal && !outcome.value ? copy.save_note : outcome.selectedOptions[0].textContent;
            var stop = document.getElementById('stop-timer-toggle');
            var timerState = document.getElementById('btn-timer-action')?.dataset.state;
            if ((stop?.checked && !stop.disabled) || (outcome.value === 'done' && timerState && timerState !== 'stopped')) submit.querySelector('.btn-text').textContent += ' · ' + copy.stop_timer;
            modeButtons.forEach(function (b) { b.setAttribute('aria-pressed', String((b.dataset.mode === 'internal') === internal)); });
        }
        outcome.addEventListener('change', submitLabel);
        modeButtons.forEach(function (b) { b.addEventListener('click', function () { queueMicrotask(submitLabel); schedule(); }); });
        form.addEventListener('change', submitLabel);
        submitLabel();
        if (meta.status_group === 'done') {
            var timerButton = document.getElementById('btn-timer-action');
            if (timerButton && timerButton.dataset.state === 'stopped') { timerButton.disabled = true; timerButton.title = copy.reopen; document.getElementById('timer-controls').hidden = true; }
        }
        if (draft && Date.now() - draft.saved < 7 * 86400000) {
            (draft.values || []).forEach(function (saved) {
                fields().filter(function (el) { return el.name === saved.name && (el.type !== 'checkbox' || el.value === saved.value); }).forEach(function (el) {
                    if (el.type === 'checkbox' || el.type === 'radio') el.checked = saved.checked; else el.value = saved.value;
                });
            });
            if (window.commentEditor && draft.content) window.commentEditor.clipboard.dangerouslyPasteHTML(draft.content);
            if (window.internalEditor && draft.internal) window.internalEditor.clipboard.dangerouslyPasteHTML(draft.internal);
            var internalMode = document.getElementById('is_internal_toggle')?.checked;
            var modeButton = form.querySelector('[data-mode="' + (internalMode ? 'internal' : 'public') + '"]'); if (modeButton) modeButton.click();
            if (timeBox && form.querySelector('[name="manual_duration_minutes"]')?.value) timeBox.open = true;
            if (exact && form.querySelector('[name="manual_start_time"]')?.value) { timeBox.open = true; exact.open = true; }
            announce(copy.draft_restored + (draft.files?.length ? ' ' + copy.attachments_reselect + ': ' + draft.files.join(', ') : ''));
            submitLabel();
        }
        form.addEventListener('submit', function (event) {
            if (composing || event.isComposing) { event.preventDefault(); return; }
            persist(); hidden('expected_revision', meta.revision); hidden('workflow_draft', nonce);
            form.setAttribute('aria-busy', 'true');
        }, true);
        var pending = false;
        var queue = load(sessionStorage, queueKey);
        var links = queue?.links || [];
        var index = links.findIndex(function (url) { return [String(meta.ticket_id),meta.ticket_hash].includes(ticketKey(url)); });
        var next = index >= 0 && links[index+1] && safeUrl(links[index+1]);
        if (index >= 0 && queue && safeUrl(queue.url)) {
            var back = surface.querySelector('.ticket-back-link'); if (back) back.href = queue.url;
        }
        var nav = document.createElement('nav'); nav.className = 'workflow-queue'; nav.setAttribute('aria-label', copy.next);
        [[index-1,copy.previous],[index+1,copy.next]].forEach(function (entry) {
            if (index < 0 || !links[entry[0]] || !safeUrl(links[entry[0]])) return;
            var a = document.createElement('a'); a.href = links[entry[0]]; a.className = 'btn btn-secondary'; a.textContent = entry[1]; nav.append(a);
        });
        if (nav.childElementCount) surface.querySelector('.ticket-work-panel').append(nav);
        async function action(operation, statusId, target, expected) {
            if (pending || composing) return;
            pending = true; persist();
            var before = meta.status_id;
            surface.setAttribute('aria-busy','true');
            try {
                var response = await fetch('index.php?page=api&action=ticket-workflow', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':form.querySelector('[name="csrf_token"]').value},
                    body:JSON.stringify({ticket_id:meta.ticket_id,operation:operation,status_id:statusId,expected_revision:expected || meta.revision})
                });
                var data = await response.json(); if (!response.ok || !data.success) throw new Error(data.error || copy.save_failed);
                var result = data.data || data;
                if (result.workflow.status_id !== before && !expected) save(sessionStorage,key+'_undo',{status:before,revision:result.workflow.revision,expires:Date.now()+10000});
                // Assigning the same URL with a fragment only scrolls; it does not
                // reload the saved state/revision after completing or reopening.
                if (target) location.assign(target);
                else location.reload();
            } catch (error) { announce(error.message || copy.save_failed,true); var headerStatus = document.getElementById('ticket-header-status'); if (headerStatus) headerStatus.value = meta.status_id; }
            finally { pending = false; surface.removeAttribute('aria-busy'); }
        }
        surface.querySelectorAll('[data-workflow-operation]').forEach(function (el) {
            var operation = el.dataset.workflowOperation;
            if (!['status','complete','reopen','claim'].includes(operation)) return;
            if (el.tagName === 'FORM') el.addEventListener('submit',function(e){e.preventDefault();action(operation,Number(el.querySelector('[name="status_id"]').value));});
            else el.addEventListener('click',function(){action(operation);});
        });
        var assignLink = surface.querySelector('a[href="#ticket-side-panel"]');
        if (assignLink) assignLink.addEventListener('click',function(event){var control=surface.querySelector('select[onchange*="quick-assign"]');if(control){event.preventDefault();control.scrollIntoView({block:'center'});control.focus();if(control.showPicker){try{control.showPicker();}catch(_){}}}});
        var headerStatus = document.getElementById('ticket-header-status');
        if (headerStatus) headerStatus.addEventListener('change',function(){action('status',Number(headerStatus.value));});
        surface.querySelectorAll('.workflow-no-js').forEach(function(el){el.hidden=true;});
        if (next && meta.allowed_actions.includes('complete')) {
            var completeNext = document.createElement('button'); completeNext.type='button'; completeNext.className='btn btn-secondary'; completeNext.textContent=copy.complete_next;
            completeNext.addEventListener('click',function(){action('complete',null,next.href);}); nav.append(completeNext);
        }
        var undo = load(sessionStorage,key+'_undo'); remove(sessionStorage,key+'_undo');
        if (undo && undo.expires > Date.now() && undo.revision === meta.revision && window.showAppToast) {
            window.showAppToast(copy.saved,'success',{duration:undo.expires-Date.now(),actionLabel:copy.undo_status,onAction:function(){action('status',undo.status,null,undo.revision);}});
        }
        // Restore the displayed value after a failed quick edit; keep unrelated drafts.
        var controls = surface.querySelectorAll('.ticket-side-control');
        controls.forEach(function (el) { el.dataset.savedValue = el.value; });
        function handoffDialog() {
            return new Promise(function(resolve) {
                var dialog=document.createElement('dialog'); dialog.className='workflow-handoff';
                var title=document.createElement('h2');title.id='workflow-handoff-title';title.textContent=copy.assign;dialog.setAttribute('aria-labelledby',title.id);
                var label=document.createElement('label');label.htmlFor='workflow-handoff-note';label.textContent=copy.internal_note;
                var note=document.createElement('textarea');note.id=label.htmlFor;note.rows=3;note.className='form-input';
                var actions=document.createElement('div');actions.className='workflow-assignment-views';
                var cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-secondary';cancel.textContent=copy.cancel;
                var confirm=document.createElement('button');confirm.type='button';confirm.className='btn btn-primary';confirm.textContent=copy.assign;
                function finish(value){dialog.close();dialog.remove();resolve(value);}
                cancel.addEventListener('click',function(){finish(null);});confirm.addEventListener('click',function(){finish(note.value);});
                dialog.addEventListener('cancel',function(event){event.preventDefault();finish(null);});
                actions.append(cancel,confirm);dialog.append(title,label,note,actions);document.body.append(dialog);dialog.showModal();note.focus();
            });
        }
        window.quickEditField = async function (operation, values) {
            var control = Array.from(controls).find(function (el) { return (el.getAttribute('onchange') || '').includes(operation); });
            if (operation === 'quick-assign' && Number(values.assignee_id) > 0) {
                var handoff = await handoffDialog();
                if (handoff === null) {if(control){control.value=control.dataset.savedValue;control.focus();}return;}
                values={...values,operation:'assign',expected_revision:meta.revision,handoff_note:handoff}; operation='ticket-workflow';
            }
            var body = new FormData(); body.set('ticket_id',meta.ticket_id); Object.keys(values).forEach(function(k){body.set(k,values[k]);});
            if (control) control.disabled = true;
            try {
                var response = await fetch('index.php?page=api&action='+encodeURIComponent(operation),{method:'POST',headers:{'X-CSRF-TOKEN':form.querySelector('[name="csrf_token"]').value},body:body});
                var data = await response.json(); if (!response.ok || !data.success) throw new Error(data.error || copy.save_failed);
                if (control) control.dataset.savedValue = control.value;
                persist(); location.reload();
            } catch(error) { if(control) control.value=control.dataset.savedValue; announce(error.message,true); }
            finally { if(control) control.disabled=false; }
        };
        nav.title = copy.keyboard;
        document.addEventListener('keydown',function(event){
            if(event.isComposing || composing || event.ctrlKey || event.metaKey || event.altKey || event.target.closest('input,textarea,select,[contenteditable="true"]')) return;
            if(event.key === 'r' || event.key === 'R'){event.preventDefault();form.scrollIntoView({block:'start'});(document.getElementById('is_internal_toggle')?.checked ? window.internalEditor : window.commentEditor)?.focus();}
            if(event.key === ']' && next){persist();location.href=next.href;}
            if(event.key === '[' && index > 0 && safeUrl(links[index-1])){persist();location.href=links[index-1];}
        });
    });
})();
