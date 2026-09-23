/* Explicit local-only HTTP integration test. Creates synthetic tickets. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.FOXDESK_TEST_URL || 'http://127.0.0.1:8096';
assert(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'This test may only run locally.');
const jar = {};
let csrf = '';
function pageCsrf(html) {
    const match=html.match(/name="csrf_token" value="([^"]+)"/) || html.match(/name="csrf-token" content="([^"]+)"/);
    assert(match,'Page CSRF token missing');
    return match[1];
}
async function request(path, options = {}) {
    const response = await fetch(new URL(path, base), {redirect:'manual', ...options, headers:{Cookie:Object.entries(jar).map(([k,v])=>`${k}=${v}`).join('; '), ...(options.headers || {})}});
    for (const cookie of response.headers.getSetCookie()) { const pair=cookie.split(';')[0]; const split=pair.indexOf('='); jar[pair.slice(0,split)]=pair.slice(split+1); }
    return response;
}
const decode = value => value.replace(/&quot;/g,'"').replace(/&#039;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
async function api(action, data, expectedStatus=200) {
    const response = await request('/index.php?page=api&action='+action, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(data)});
    const text=await response.text(); let value; try{value=JSON.parse(text);}catch{throw new Error(action+': '+text.slice(0,700));}
    assert.equal(response.status,expectedStatus,action+': '+JSON.stringify(value)); return value;
}
async function detail(id) {
    const response=await request('/index.php?page=ticket&id='+id);const html=await response.text();
    assert.equal(response.status,200,html.slice(0,400));
    assert(html.includes('id="comment-form"') && html.includes('</html>') && !html.includes('An unexpected error occurred.'), 'Ticket detail must render completely');
    const match=html.match(/data-workflow="([^"]+)"/);assert(match,'Workflow metadata missing: '+html.slice(-650));
    csrf=pageCsrf(html);
    return {meta:JSON.parse(decode(match[1])),html};
}
async function submit(id,values) {
    const response=await request('/index.php?page=ticket&id='+id,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:csrf,add_comment:'1',change_status_with_comment:'1',skip_notification:'1',...values})});
    assert.equal(response.status,302,(await response.text()).slice(0,700));return detail(id);
}
(async()=>{
    let response=await request('/index.php?page=login');let html=await response.text();
    csrf=pageCsrf(html);
    response=await request('/index.php?page=login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({email:'admin@example.test',password:'AdminPass123!',csrf_token:csrf})});
    assert.equal(response.status,302,'Local login failed');
    response=await request('/index.php?page=tickets');html=await response.text();
    csrf=pageCsrf(html);
    const created=await api('app-create-ticket',{title:'Workflow QA '+new Date().toISOString(),description:'Synthetic local workflow verification',skip_notification:true});
    const id=created.ticket_id || created.data?.ticket_id;assert(id,JSON.stringify(created));
    let {meta}=await detail(id);const original=meta.status_id;
    const changed=await api('ticket-workflow',{ticket_id:id,operation:'complete',expected_revision:meta.revision,skip_notification:true});
    assert.equal(changed.workflow.status_group,'done');
    await api('ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:meta.revision,skip_notification:true},409);
    ({meta}=await detail(id));assert.equal(meta.status_group,'done');assert(meta.allowed_actions.includes('reopen'));
    const reopened=await api('ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:meta.revision,skip_notification:true});
    assert.equal(reopened.workflow.status_group,'active');
    ({meta}=await detail(id));
    const claimed=await api('ticket-workflow',{ticket_id:id,operation:'claim',expected_revision:meta.revision,skip_notification:true});assert.equal(claimed.workflow.assignee_id,meta.user_id);
    ({meta}=await detail(id));const active=meta.status_id;
    // New reply time is part of the same form transaction, not a separate API write.
    const replyFields={reply_manual_time:'1',is_internal:'1',status_id:String(active)};
    const readRecords=async()=> (await request('/index.php?page=api&action=agent-get-ticket&id='+id)).json();
    const initialRecords=await readRecords();
    for (const invalid of [
        {comment:'',manual_date:'2026-09-15'},
        {comment:'Invalid work date',manual_date:'2026-02-30',manual_duration_minutes:'17'},
        {comment:'Missing range end',manual_date:'2026-09-15',manual_start_time:'09:15'},
        {comment:'Fractional duration',manual_date:'2026-09-15',manual_duration_minutes:'1.5'}
    ]) {
        await submit(id,{...replyFields,...invalid,expected_revision:meta.revision,workflow_draft:'reply-invalid-'+Math.random()});
        const records=await readRecords();
        assert.equal(records.comments.length,initialRecords.comments.length,'Invalid reply time created a comment');
        assert.equal(records.time_entries.length,initialRecords.time_entries.length,'Invalid reply time created time');
    }
    // Time-only Send must save standalone work, never an empty conversation reply.
    for (const [index,emptyContent] of ['', '<p><br></p>', '<p>&nbsp;</p>'].entries()) {
        ({meta}=await detail(id));
        const before=await readRecords(), duration=26+index, timeNonce='time-only-'+id+'-'+index;
        const timeResult=await submit(id,{...replyFields,expected_revision:meta.revision,workflow_draft:timeNonce,comment:emptyContent,manual_date:'2026-09-15',manual_duration_minutes:String(duration)});
        const after=await readRecords();
        assert.equal(after.comments.length,before.comments.length,'Time-only Send created an empty reply');
        assert.equal(after.time_entries.length,before.time_entries.length+1,'Time-only Send did not save the entry');
        const entry=after.time_entries.find(e=>!before.time_entries.some(old=>Number(old.id)===Number(e.id)));
        assert.equal(Number(entry.duration_minutes),duration);
        assert(!entry.comment_id,'Time-only work must not attach to an unrelated reply');
        assert.equal(entry.ended_at.slice(0,10),'2026-09-15','Quick work ends on the selected date');
        assert.equal(entry.worked_on,entry.started_at.slice(0,10),'Work date follows the actual interval start across midnight');
        assert.equal(entry.time_precision,'exact','Minutes must generate a visible clock interval');
        assert.equal((Date.parse(entry.ended_at)-Date.parse(entry.started_at))/60000,duration);
        assert.equal(timeResult.meta.draft_ack,timeNonce);
        await submit(id,{...replyFields,expected_revision:timeResult.meta.revision,workflow_draft:timeNonce,comment:emptyContent,manual_date:'2026-09-15',manual_duration_minutes:String(duration)});
        assert.equal((await readRecords()).time_entries.length,after.time_entries.length,'Retry duplicated time-only work');
    }
    ({meta}=await detail(id));
    const replyNonce='inline-reply-'+id;
    let replyResult=await submit(id,{...replyFields,expected_revision:meta.revision,workflow_draft:replyNonce,comment:'Inline duration-only reply',manual_date:'2026-09-15',manual_duration_minutes:'17'});
    let replyRecords=await readRecords();
    const reply=replyRecords.comments.find(c=>c.content.includes('Inline duration-only reply'));
    assert(reply,'Inline reply missing');
    const replyTime=replyRecords.time_entries.find(e=>Number(e.comment_id)===Number(reply.id));
    assert(replyTime,'Time must be linked to the new reply');
    assert.equal(Number(replyTime.duration_minutes),17);
    assert.equal(replyTime.ended_at.slice(0,10),'2026-09-15');
    assert.equal(replyTime.worked_on,replyTime.started_at.slice(0,10));
    assert.equal(replyTime.time_precision,'exact');
    assert.equal((Date.parse(replyTime.ended_at)-Date.parse(replyTime.started_at))/60000,17);
    await submit(id,{...replyFields,expected_revision:replyResult.meta.revision,workflow_draft:replyNonce,comment:'Inline duration-only reply',manual_date:'2026-09-15',manual_duration_minutes:'17'});
    replyRecords=await readRecords();
    assert.equal(replyRecords.comments.filter(c=>c.content.includes('Inline duration-only reply')).length,1,'Retry duplicated the reply');
    for (const range of [
        {label:'Inline exact reply',start:'09:15',end:'09:40',duration:25},
        {label:'Inline midnight reply',start:'23:45',end:'00:15',duration:30}
    ]) {
        ({meta}=await detail(id));
        await submit(id,{...replyFields,expected_revision:meta.revision,workflow_draft:range.label+'-'+id,comment:range.label,manual_date:'2026-09-15',manual_start_time:range.start,manual_end_time:range.end});
        replyRecords=await readRecords();
        const comment=replyRecords.comments.find(c=>c.content.includes(range.label));
        const time=replyRecords.time_entries.find(e=>Number(e.comment_id)===Number(comment?.id));
        assert.equal(Number(time?.duration_minutes),range.duration);
        assert.equal(time?.time_precision,'exact');
        assert.equal(time?.worked_on,'2026-09-15');
    }
    ({meta}=await detail(id));
    let result=await submit(id,{expected_revision:meta.revision,workflow_draft:'invalid-'+id,status_id:String(meta.targets.done),manual_duration_minutes:'1441',comment:'Invalid duration must not close the ticket.'});
    assert.equal(result.meta.status_id,active,'Invalid duration changed the status');assert(!result.html.includes('<p>Invalid duration must not close the ticket.</p>'));
    ({meta}=await detail(id));const nonce='success-'+id;
    result=await submit(id,{expected_revision:meta.revision,workflow_draft:nonce,status_id:String(meta.targets.done),manual_duration_minutes:'15',comment:'Verified atomic work entry.'});
    assert.equal(result.meta.status_group,'done');assert.equal(result.meta.draft_ack,nonce);
    const revision=result.meta.revision;
    result=await submit(id,{expected_revision:meta.revision,workflow_draft:nonce,status_id:String(active),manual_duration_minutes:'15',comment:'Duplicate must not be created.'});
    assert.equal(result.meta.revision,revision,'Retry changed the ticket');
    const statsResponse=await request('/index.php?page=api&action=agent-get-ticket&id='+id);const stats=await statsResponse.json();
    assert.equal(stats.comments.filter(c=>c.content.includes('Verified atomic work entry.')).length,1);
    assert.equal(stats.time_entries.filter(e=>Number(e.duration_minutes)===15).length,1);
    ({meta}=await detail(id));
    await api('ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:meta.revision,skip_notification:true});
    ({meta}=await detail(id));
    const work=await api('agent-add-work-entry',{ticket_id:id,content:'Agent completed the synthetic check.',duration_minutes:10,worked_on:new Date().toISOString().slice(0,10),time_precision:'duration_only',is_internal:true,skip_notification:true,status_id:meta.targets.done,expected_revision:meta.revision});
    assert.equal(work.workflow.status_group,'done');assert(work.comment_id && work.time_entry_id);
    const linked=await (await request('/index.php?page=api&action=agent-get-ticket&id='+id)).json();
    assert.equal(Number(linked.time_entries.find(e=>Number(e.id)===Number(work.time_entry_id))?.comment_id),Number(work.comment_id),'Agent work must remain linked to its comment');
    ({meta}=await detail(id));
    const handoff=await api('ticket-workflow',{ticket_id:id,operation:'assign',assignee_id:meta.user_id,handoff_note:'Internal handoff integration.',expected_revision:meta.revision,skip_notification:true});
    assert(handoff.handoff_comment_id,'Handoff note missing');
    const handoffRead=await (await request('/index.php?page=api&action=agent-get-ticket&id='+id)).json();
    assert.equal(Number(handoffRead.comments.find(c=>Number(c.id)===Number(handoff.handoff_comment_id))?.is_internal),1,'Handoff must stay internal');
    ({meta}=await detail(id));
    await api('ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:meta.revision,skip_notification:true});
    await api('app-ticket-timer-action',{ticket_id:id,action:'start'});
    ({meta}=await detail(id));
    const timerRevision=meta.revision;
    await api('agent-add-work-entry',{ticket_id:id,content:'Must reject ambiguous timer and manual time.',duration_minutes:5,worked_on:new Date().toISOString().slice(0,10),time_precision:'duration_only',is_internal:true,skip_notification:true,status_id:meta.targets.done,expected_revision:meta.revision},422);
    const invalidTimer=await submit(id,{expected_revision:meta.revision,workflow_draft:'timer-conflict-'+id,status_id:String(meta.targets.done),stop_timer:'1',manual_duration_minutes:'5',comment:'Ambiguous manual time must not save.'});
    assert.equal(invalidTimer.meta.status_group,'active');assert(invalidTimer.meta.timer,'Rejected save must preserve the timer');
    assert.equal(invalidTimer.meta.revision,timerRevision,'Rejected timer save mutated the ticket');
    const completedTimer=await api('ticket-workflow',{ticket_id:id,operation:'complete',expected_revision:invalidTimer.meta.revision,skip_notification:true});
    assert.equal(completedTimer.workflow.status_group,'done');assert.equal(completedTimer.timer_stopped,true);
    const editable=(await readRecords()).time_entries.find(entry=>entry.comment_id && Number(entry.duration_minutes)===25);
    assert(editable,'Exact reply time fixture missing');
    await api('app-update-time-entry',{ticket_id:id,time_entry_id:Number(editable.id),comment_id:Number(editable.comment_id),worked_on:'2026-09-15',started_at:'2026-09-15T09:15',duration_minutes:29,summary:'Verified existing time edit',time_precision:'exact',is_billable:true});
    const updated=(await readRecords()).time_entries.find(entry=>Number(entry.id)===Number(editable.id));
    assert.equal(Number(updated.duration_minutes),29,'Existing exact time edit must persist');
    assert.equal(Number(updated.comment_id),Number(editable.comment_id),'Editing time must preserve its reply association');
    const registry=JSON.parse(fs.readFileSync(require('node:path').join(__dirname,'../locales/registry.json'),'utf8'));
    const channel='self_hosted';
    const locales=registry.locales.filter(locale=>['stable','beta'].includes(locale.channels[channel])).map(locale=>locale.tag);
    for(const locale of locales) {
        for(const route of ['ticket&id='+id,'tickets&work_view=done']) {
            const localized=await request('/index.php?page='+route+'&lang='+encodeURIComponent(locale));
            const rendered=await localized.text();
            assert.equal(localized.status,200,locale+' '+route);
            assert(rendered.includes('</html>')&&!rendered.includes('An unexpected error occurred.'),locale+' incomplete render');
            assert(rendered.includes('lang="'+locale+'"'),locale+' language was not applied');
        }
    }
    const report={base,ticket_id:id,ticket_url:base+'/index.php?page=ticket&id='+id,rendered_locales:locales,checks:['complete','reopen','stale revision rejected','claim','invalid duration leaves state unchanged','atomic reply/time/done','successful draft acknowledged','retry does not duplicate','agent atomic work entry with status','agent time linked to comment','internal handoff saved atomically','ambiguous manual and timer write rejected in UI and API','completion stops active timer'],passed:true};
    if(process.env.FOXDESK_TEST_OUTPUT)fs.writeFileSync(process.env.FOXDESK_TEST_OUTPUT,JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
})().catch(error=>{console.error(error);process.exitCode=1;});
