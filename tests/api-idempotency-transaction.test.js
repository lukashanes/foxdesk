/*
 * Explicit local-only HTTP integration test for API handlers that run inside
 * the Idempotency-Key transaction. API requests that carry an Idempotency-Key
 * are wrapped in one database transaction by api_idempotency_replay_if_available();
 * handlers and modules must join it through db_begin_owned() / db_commit_owned()
 * / db_rollback_owned() instead of opening a nested transaction, otherwise PDO
 * throws "There is already an active transaction" and the request returns 500.
 *
 * Creates synthetic tickets on a local Docker fixture and revokes its tokens in
 * finally. Never run it against production.
 *
 *   FOXDESK_TEST_URL=http://127.0.0.1:8090 FOXDESK_TEST_CONTAINER=foxdesk-e2e-web \
 *     node tests/api-idempotency-transaction.test.js
 */
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');

const base = (process.env.FOXDESK_TEST_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
const container = process.env.FOXDESK_TEST_CONTAINER || 'foxdesk-e2e-web';
assert(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'This test may only run locally.');
assert(/^foxdesk-[a-z0-9-]+$/.test(container), 'Fixture container must be a local FoxDesk container.');

const fixture = JSON.parse(execFileSync('docker', ['exec', '-e', 'FOXDESK_LOCAL_TESTS=1', container, 'php', 'tests/workflow-token-fixture.php'], {encoding: 'utf8'}));
assert(fixture.full?.token && fixture.delete?.token, 'Fixture must provide full and delete:write tokens.');

const nonce = Date.now() + '-' + Math.random().toString(36).slice(2, 8);
const today = new Date().toISOString().slice(0, 10);
const checks = [];

async function call(action, payload, options = {}) {
    const token = options.delete ? fixture.delete.token : fixture.full.token;
    const headers = {Authorization: 'Bearer ' + token, 'Content-Type': 'application/json'};
    if (options.key) headers['Idempotency-Key'] = options.key;
    const response = await fetch(base + '/index.php?page=api&action=' + action, {
        method: payload ? 'POST' : 'GET',
        headers,
        ...(payload ? {body: JSON.stringify(payload)} : {}),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { throw new Error(action + ': non-JSON ' + response.status + ' ' + text.slice(0, 300)); }
    assert.equal(response.status, options.status || 200, action + ': ' + JSON.stringify(data).slice(0, 400));
    return {data, replay: response.headers.get('x-idempotent-replay') === 'true'};
}

/** Send a keyed write twice: first must execute, second must be a byte-identical stored replay. */
async function idempotent(label, action, payload, options = {}) {
    const key = label + '-' + nonce;
    const first = await call(action, payload, {...options, key});
    assert.equal(first.replay, false, action + ': first call must not be a replay');
    const second = await call(action, payload, {...options, key});
    assert.equal(second.replay, true, action + ': retry must be served from the stored response');
    assert.deepEqual(second.data, first.data, action + ': replay must match the original response');
    checks.push(label);
    return first.data;
}

async function ticketState(ticketId) {
    const {data} = await call('agent-get-ticket&id=' + ticketId);
    const ticket = data.ticket || data;
    return {
        ticket,
        comments: data.comments || ticket.comments || [],
        time_entries: data.time_entries || ticket.time_entries || [],
    };
}

(async () => {
    const created = await idempotent('create-ticket', 'agent-create-ticket', {
        title: 'Idempotent transaction QA ' + nonce,
        description: 'Local synthetic verification of handlers inside the Idempotency-Key transaction.',
    });
    const ticketId = created.ticket_id;
    assert(ticketId, JSON.stringify(created));
    const baseline = await ticketState(ticketId);

    // Comment + linked time in one request, replayed without duplicating rows.
    const withTime = await idempotent('agent-add-work-entry', 'agent-add-work-entry', {
        ticket_id: ticketId,
        content: '<p>Checked the synthetic fixture inside one transaction.</p>',
        duration_minutes: 15,
        worked_on: today,
        time_precision: 'duration_only',
        is_internal: true,
        skip_notification: true,
    });
    assert(withTime.comment_id, JSON.stringify(withTime));
    let now = await ticketState(ticketId);
    assert.equal(now.comments.length, baseline.comments.length + 1, 'Replay must not duplicate the comment');
    assert.equal(now.time_entries.length, baseline.time_entries.length + 1, 'Replay must not duplicate the time entry');

    const plain = await idempotent('app-add-comment', 'app-add-comment', {
        ticket_id: ticketId,
        content: 'Second comment through the app contract.',
        is_internal: true,
        skip_notification: true,
    });
    assert(plain.comment_id || plain.comment?.id, JSON.stringify(plain).slice(0, 300));

    // A keyed request that fails inside the handler must release its reservation so the
    // retry gets the same validation error again instead of 409 "already in progress".
    const failingKey = 'work-entry-invalid-' + nonce;
    const invalidEntry = {
        ticket_id: ticketId,
        content: '<p>Invalid precision must roll back.</p>',
        duration_minutes: 5,
        worked_on: today,
        time_precision: 'invalid-precision',
        skip_notification: true,
    };
    await call('agent-add-work-entry', invalidEntry, {key: failingKey, status: 422});
    await call('agent-add-work-entry', invalidEntry, {key: failingKey, status: 422});
    now = await ticketState(ticketId);
    assert.equal(now.comments.length, baseline.comments.length + 2, 'Failed keyed write must not leave a comment behind');
    checks.push('failed keyed write releases reservation');

    // Workflow and status transition helpers join the outer transaction.
    const revision = (now.ticket.workflow || {}).revision;
    assert(revision, 'agent-get-ticket must expose ticket.workflow.revision');
    const completed = await idempotent('agent-ticket-workflow', 'agent-ticket-workflow', {
        ticket_id: ticketId,
        operation: 'complete',
        expected_revision: revision,
        skip_notification: true,
    });
    assert.equal(completed.workflow?.status_group, 'done', JSON.stringify(completed).slice(0, 300));

    const statuses = (await call('agent-list-statuses')).data.statuses || [];
    const open = statuses.find(status => !Number(status.is_closed));
    assert(open, 'An open status is required for the status transition check.');
    await idempotent('agent-update-status', 'agent-update-status', {ticket_id: ticketId, status_id: Number(open.id), skip_notification: true});

    // Signed work-log plan applied inside the idempotency transaction.
    const plan = (await call('agent-plan-work-log', {
        structure: 'one_ticket',
        allocation_basis: 'actual',
        total_minutes: 12,
        tickets: [{
            title: 'Idempotent work-log QA ' + nonce,
            description: 'Synthetic planned ticket.',
            entries: [{worked_on: today, content: 'Reviewed the synthetic plan.', duration_minutes: 12, time_precision: 'duration_only'}],
        }],
    }, {key: 'plan-' + nonce})).data;
    assert(plan.plan && plan.plan_hash, JSON.stringify(plan).slice(0, 300));
    const applied = await idempotent('agent-apply-work-log-plan', 'agent-apply-work-log-plan', {plan: plan.plan, plan_hash: plan.plan_hash, confirm: true});
    assert.equal(applied.applied, true, JSON.stringify(applied).slice(0, 300));

    // Permanent deletion joins the outer transaction and runs storage cleanup after commit.
    for (const id of [ticketId, applied.tickets[0].ticket_id]) {
        const preflight = (await call('agent-delete-ticket-preflight&ticket_id=' + id, null, {delete: true})).data.preflight;
        assert(preflight?.ticket_code, JSON.stringify(preflight));
        await idempotent('agent-delete-ticket-permanently-' + id, 'agent-delete-ticket-permanently', {ticket_id: id, confirmation: preflight.ticket_code}, {delete: true});
    }

    console.log(JSON.stringify({base, ticket_id: ticketId, checks, passed: true}));
})().catch(error => {
    console.error(error.message);
    process.exitCode = 1;
}).finally(() => {
    execFileSync('docker', ['exec', '-e', 'FOXDESK_LOCAL_TESTS=1', container, 'php', 'tests/workflow-token-fixture.php', 'revoke',
        String(fixture.full.id), String(fixture.read.id), String(fixture.write_only.id), String(fixture.delete.id)], {stdio: 'pipe'});
});
