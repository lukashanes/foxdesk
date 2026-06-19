#!/usr/bin/env node
'use strict';

const assert = require('assert');
const { handleRequest, agentManifest } = require('./mcp-server');

let nextId = 1;

function requireEnv() {
  const missing = [];
  if (!process.env.FOXDESK_BASE_URL) {
    missing.push('FOXDESK_BASE_URL');
  }
  if (!process.env.FOXDESK_API_TOKEN) {
    missing.push('FOXDESK_API_TOKEN');
  }
  if (missing.length > 0) {
    throw new Error(`Set ${missing.join(' and ')} in examples/agent-api/.env or FOXDESK_AGENT_ENV.`);
  }
}

function redact(value) {
  const token = process.env.FOXDESK_API_TOKEN || '';
  if (!token) {
    return value;
  }
  return String(value).split(token).join('[redacted FOXDESK_API_TOKEN]');
}

async function rpc(method, params = {}) {
  return handleRequest({
    jsonrpc: '2.0',
    id: nextId++,
    method,
    params,
  });
}

async function callTool(name, args = {}) {
  const response = await rpc('tools/call', { name, arguments: args });
  if (response.error) {
    throw new Error(response.error.message || `MCP ${name} failed.`);
  }

  const result = response.result || {};
  const content = Array.isArray(result.content) ? result.content : [];
  const text = content[0] && content[0].text ? String(content[0].text) : '';
  if (result.isError) {
    throw new Error(`${name} failed: ${text}`);
  }

  try {
    return JSON.parse(text);
  } catch (error) {
    throw new Error(`${name} returned non-JSON content: ${text.slice(0, 200)}`);
  }
}

function expectSuccess(payload, label) {
  assert(payload && typeof payload === 'object', `${label} must return an object.`);
  assert.strictEqual(payload.success, true, `${label} must return success:true.`);
  assert(payload.data && typeof payload.data === 'object', `${label} must include a data object.`);
}

function firstTicketFromList(payload) {
  const data = payload.data || {};
  const tickets = Array.isArray(data.tickets) ? data.tickets : [];
  return tickets[0] || null;
}

function writeSmokeEnabled() {
  return process.env.FOXDESK_AGENT_PROD_WRITE === '1';
}

function reportSmokeEnabled() {
  return process.env.FOXDESK_AGENT_PROD_REPORT === '1';
}

async function runReadSmoke(checks) {
  const tickets = await callTool('foxdesk_list_tickets', {
    view: process.env.FOXDESK_AGENT_PROD_VIEW || 'all',
    limit: 1,
    offset: 0,
  });
  expectSuccess(tickets, 'foxdesk_list_tickets');
  const listData = tickets.data || {};
  assert(Array.isArray(listData.tickets), 'foxdesk_list_tickets must return data.tickets.');
  assert(listData.pagination && typeof listData.pagination === 'object', 'foxdesk_list_tickets must return data.pagination.');
  checks.push(`ticket list OK (${listData.pagination.total} visible)`);

  const ticket = firstTicketFromList(tickets);
  if (ticket) {
    const detail = await callTool('foxdesk_get_ticket', {
      ticket_id: Number(ticket.id),
      include_internal: false,
    });
    expectSuccess(detail, 'foxdesk_get_ticket');
    assert(detail.data.ticket && Number(detail.data.ticket.id) === Number(ticket.id), 'foxdesk_get_ticket must return the requested ticket.');
    checks.push(`ticket detail OK (${ticket.ticket_code || ticket.id})`);
  } else {
    checks.push('ticket detail skipped (no visible tickets)');
  }

  if (reportSmokeEnabled()) {
    const report = await callTool('foxdesk_prepare_report', {
      time_range: process.env.FOXDESK_AGENT_PROD_TIME_RANGE || 'this_month',
      limit: Number(process.env.FOXDESK_AGENT_PROD_REPORT_LIMIT || 5),
    });
    expectSuccess(report, 'foxdesk_prepare_report');
    checks.push('reporting review OK');
  } else {
    checks.push('reporting review skipped (set FOXDESK_AGENT_PROD_REPORT=1)');
  }
}

async function runWriteSmoke(checks) {
  const stamp = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
  const title = process.env.FOXDESK_AGENT_PROD_TICKET_TITLE || `[Agent smoke] ${stamp}`;
  const description = process.env.FOXDESK_AGENT_PROD_TICKET_DESCRIPTION
    || 'Created by the FoxDesk agent production smoke test. Safe to close.';
  const idempotencyKey = process.env.FOXDESK_AGENT_PROD_IDEMPOTENCY_KEY || `agent-prod-smoke-${Date.now()}`;

  const created = await callTool('foxdesk_create_ticket', {
    title,
    description,
    idempotency_key: idempotencyKey,
    confirm: true,
  });
  expectSuccess(created, 'foxdesk_create_ticket');
  const ticketId = Number(created.ticket_id || (created.data && created.data.ticket_id));
  assert(ticketId > 0, 'foxdesk_create_ticket must return a ticket id.');
  checks.push(`ticket create OK (${created.ticket_code || ticketId})`);

  const comment = await callTool('foxdesk_add_comment', {
    ticket_id: ticketId,
    content: 'Internal agent smoke comment.',
    is_internal: true,
    idempotency_key: `${idempotencyKey}-comment`,
    confirm: true,
  });
  expectSuccess(comment, 'foxdesk_add_comment');
  assert(Number(comment.comment_id || (comment.data && comment.data.comment_id)) > 0, 'foxdesk_add_comment must return a comment id.');
  checks.push('internal comment OK');

  const time = await callTool('foxdesk_log_time', {
    ticket_id: ticketId,
    duration_minutes: 1,
    summary: 'Agent smoke time entry.',
    is_billable: false,
    idempotency_key: `${idempotencyKey}-time`,
    confirm: true,
  });
  expectSuccess(time, 'foxdesk_log_time');
  assert(Number(time.time_entry_id || (time.data && time.data.time_entry_id)) > 0, 'foxdesk_log_time must return a time entry id.');
  checks.push('time log OK');
}

(async () => {
  requireEnv();

  const checks = [];
  const initialize = await rpc('initialize', { protocolVersion: '2025-11-25' });
  assert.strictEqual(initialize.result.serverInfo.name, 'foxdesk-agent-api');
  checks.push('initialize OK');

  const list = await rpc('tools/list');
  const names = list.result.tools.map((tool) => tool.name);
  for (const name of ['foxdesk_agent_manifest', 'foxdesk_list_tickets', 'foxdesk_get_ticket']) {
    assert(names.includes(name), `Missing MCP tool ${name}.`);
  }
  checks.push(`tools/list OK (${names.length} tools)`);

  const manifest = agentManifest();
  assert.strictEqual(manifest.safety.writesRequireConfirmation, true);
  assert(manifest.tools.some((tool) => tool.name === 'foxdesk_create_ticket' && tool.policy.writes === true));
  checks.push('manifest OK');

  await runReadSmoke(checks);

  if (writeSmokeEnabled()) {
    await runWriteSmoke(checks);
  } else {
    checks.push('write smoke skipped (set FOXDESK_AGENT_PROD_WRITE=1)');
  }

  console.log(JSON.stringify({
    ok: true,
    base_url: process.env.FOXDESK_BASE_URL.replace(/\/+$/, ''),
    mode: writeSmokeEnabled() ? 'read-write' : 'read-only',
    checks,
  }, null, 2));
})().catch((error) => {
  console.error(redact(error.stack || error.message));
  process.exit(1);
});
