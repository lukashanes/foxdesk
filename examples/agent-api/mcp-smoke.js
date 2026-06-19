#!/usr/bin/env node
'use strict';

const assert = require('assert');
const { handleRequest, agentManifest } = require('./mcp-server');

process.env.FOXDESK_BASE_URL = process.env.FOXDESK_BASE_URL || 'https://foxdesk.invalid';

async function call(id, method, params = {}) {
  return handleRequest({ jsonrpc: '2.0', id, method, params });
}

(async () => {
  const initialize = await call(1, 'initialize', { protocolVersion: '2025-11-25' });
  assert.strictEqual(initialize.result.serverInfo.name, 'foxdesk-agent-api');

  const list = await call(2, 'tools/list');
  const toolNames = list.result.tools.map((tool) => tool.name);
  for (const name of [
    'foxdesk_agent_manifest',
    'foxdesk_list_tickets',
    'foxdesk_get_ticket',
    'foxdesk_create_ticket',
    'foxdesk_add_comment',
    'foxdesk_log_time',
    'foxdesk_prepare_report',
  ]) {
    assert(toolNames.includes(name), `Missing tool ${name}`);
  }

  const manifest = agentManifest();
  assert.strictEqual(manifest.safety.writesRequireConfirmation, true);
  assert(manifest.tools.some((tool) => tool.name === 'foxdesk_create_ticket' && tool.policy.requiresConfirmation));

  const dryRun = await call(3, 'tools/call', {
    name: 'foxdesk_create_ticket',
    arguments: {
      title: 'Dry run ticket',
      description: 'This must not call FoxDesk.',
      dry_run: true,
    },
  });
  const dryRunText = dryRun.result.content[0].text;
  assert(dryRunText.includes('"dry_run": true'));
  assert(dryRunText.includes('"would_write": true'));

  const blockedWrite = await call(4, 'tools/call', {
    name: 'foxdesk_create_ticket',
    arguments: {
      title: 'Blocked ticket',
    },
  });
  assert.strictEqual(blockedWrite.result.isError, true);
  assert(blockedWrite.result.content[0].text.includes('confirm:true'));

  process.env.FOXDESK_API_TOKEN = 'fdx_test_secret_should_be_redacted';
  const redacted = await call(5, 'tools/call', {
    name: 'foxdesk_create_ticket',
    arguments: {
      title: 'Redaction dry run',
      description: 'fdx_test_secret_should_be_redacted',
      dry_run: true,
    },
  });
  assert(!redacted.result.content[0].text.includes('fdx_test_secret_should_be_redacted'));
  assert(redacted.result.content[0].text.includes('[redacted FOXDESK_API_TOKEN]'));

  console.log('Agent MCP smoke OK');
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});
