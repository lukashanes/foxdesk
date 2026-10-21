const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const base = process.env.FOXDESK_TEST_URL || 'http://127.0.0.1:8096';
const container = process.env.FOXDESK_TEST_CONTAINER || 'foxdesk-redesign-cloud-app';
assert(['127.0.0.1','localhost'].includes(new URL(base).hostname));
assert(['foxdesk-redesign-cloud-app','foxdesk-redesign-self-app'].includes(container));
const fixture = JSON.parse(execFileSync('docker',['exec','-e','FOXDESK_LOCAL_TESTS=1',container,'php','tests/workflow-token-fixture.php'],{encoding:'utf8'}));
async function api(action, payload, options={}) {
    const response=await fetch(base+'/index.php?page=api&action='+action,{method:payload?'POST':'GET',headers:{Authorization:'Bearer '+(options.read ? fixture.read.token : options.writeOnly ? fixture.write_only.token : fixture.full.token),'Content-Type':'application/json',...(options.key?{'Idempotency-Key':options.key}:{})},...(payload?{body:JSON.stringify(payload)}:{})});
    const body=await response.text(); let data;try{data=JSON.parse(body);}catch{throw new Error('Non-JSON '+response.status+' '+body.slice(0,250));}
    assert.equal(response.status,options.status||200,JSON.stringify(data));return data;
}
(async()=>{
    const created=await api('agent-create-ticket',{title:'Token workflow QA '+Date.now(),description:'Local synthetic API verification.'},{key:'create-'+Date.now()});
    const id=created.ticket_id;
    assert(id,JSON.stringify(created));
    const ticket=await api('agent-get-ticket&id='+id);
    const meta=ticket.workflow || ticket.ticket?.workflow;assert(meta,JSON.stringify(ticket));
    const payload={ticket_id:id,operation:'complete',expected_revision:meta.revision,skip_notification:true};
    const key='complete-'+Date.now();
    const first=await api('agent-ticket-workflow',payload,{key});
    const repeated=await api('agent-ticket-workflow',payload,{key});
    assert.deepEqual(repeated,first,'Idempotent replay must return the stored result');
    await api('agent-ticket-workflow',{ticket_id:id,operation:'assign',assignee_id:meta.executor.user_id,handoff_note:'Must not save without comment scope.',expected_revision:first.workflow.revision},{writeOnly:true,key:'note-scope-'+Date.now(),status:403});
    const read=await api('agent-get-ticket&id='+id,null,{read:true});
    assert.deepEqual((read.workflow || read.ticket?.workflow).allowed_actions,[]);
    await api('agent-ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:first.workflow.revision},{read:true,key:'forbidden-'+Date.now(),status:403});
    const conflict=await api('agent-ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:meta.revision},{key:'stale-'+Date.now(),status:409});
    assert.equal(conflict.workflow.revision,first.workflow.revision,'Conflict must include the current revision');
    const reopened=await api('agent-ticket-workflow',{ticket_id:id,operation:'reopen',expected_revision:first.workflow.revision,skip_notification:true},{key:'reopen-'+Date.now()});
    assert.equal(reopened.workflow.status_group,'active');
    const workPayload={ticket_id:id,content:'Verified token transaction.',duration_minutes:12,worked_on:new Date().toISOString().slice(0,10),time_precision:'duration_only',is_internal:true,skip_notification:true,status_id:reopened.workflow.targets.done,expected_revision:reopened.workflow.revision};
    const workKey='work-'+Date.now();const work=await api('agent-add-work-entry',workPayload,{key:workKey});
    assert.equal(work.workflow.status_group,'done');assert(work.comment_id && work.time_entry_id);
    assert.deepEqual(await api('agent-add-work-entry',workPayload,{key:workKey}),work);
    const racing = {ticket_id:id,operation:'reopen',expected_revision:work.workflow.revision,skip_notification:true};
    const race = await Promise.all([1,2].map(async n => {
        const result=await fetch(base+'/index.php?page=api&action=agent-ticket-workflow',{method:'POST',headers:{Authorization:'Bearer '+fixture.full.token,'Content-Type':'application/json','Idempotency-Key':'race-'+Date.now()+'-'+n},body:JSON.stringify(racing)});
        return result.status;
    }));
    assert.deepEqual(race.sort(),[200,409],'Only one concurrent edit may succeed');
    console.log(JSON.stringify({base,ticket_id:id,checks:['token auth without session','atomic idempotency transaction','exact replay','read-only scope denied','handoff requires comments:write','read-only actions hidden','stale revision denied','guarded work entry with status','work entry replay','concurrent edits: one success, one conflict'],passed:true}));
})().catch(error=>{console.error(error.message);process.exitCode=1;}).finally(()=>{
    execFileSync('docker',['exec','-e','FOXDESK_LOCAL_TESTS=1',container,'php','tests/workflow-token-fixture.php','revoke',String(fixture.full.id),String(fixture.read.id),String(fixture.write_only.id),...(fixture.delete?.id?[String(fixture.delete.id)]:[])],{stdio:'pipe'});
});
