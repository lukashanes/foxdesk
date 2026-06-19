#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { URL, URLSearchParams } = require('url');

const SCRIPT_DIR = __dirname;
const DEFAULT_ENV_FILE = path.join(SCRIPT_DIR, '.env');
const SERVER_VERSION = '0.1.0';
const PROTOCOL_VERSION = '2025-11-25';
const WRITE_TOOLS_REQUIRE_CONFIRMATION = process.env.FOXDESK_AGENT_CONFIRM_WRITES !== '0';

function loadEnvFile(filePath) {
  if (!filePath || !fs.existsSync(filePath)) {
    return;
  }

  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const separator = trimmed.indexOf('=');
    if (separator === -1) {
      continue;
    }

    const key = trimmed.slice(0, separator).trim();
    let value = trimmed.slice(separator + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }

    if (key && process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

loadEnvFile(process.env.FOXDESK_AGENT_ENV || DEFAULT_ENV_FILE);

const TOOLS = [
  {
    name: 'foxdesk_agent_manifest',
    description: 'Describe FoxDesk agent tools, required scopes, and safety rules.',
    inputSchema: {
      type: 'object',
      properties: {},
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_list_tickets',
    description: 'List FoxDesk tickets visible to the API-token user.',
    inputSchema: {
      type: 'object',
      properties: {
        view: {
          type: 'string',
          enum: ['open', 'waiting', 'done', 'archive', 'all'],
          description: 'Ticket registry view to list.',
        },
        search: { type: 'string', description: 'Search text.' },
        limit: { type: 'integer', minimum: 1, maximum: 100 },
        offset: { type: 'integer', minimum: 0 },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_get_ticket',
    description: 'Read one FoxDesk ticket by id or hash.',
    inputSchema: {
      type: 'object',
      properties: {
        ticket_id: { type: 'integer', minimum: 1 },
        ticket_hash: { type: 'string' },
        include_internal: { type: 'boolean' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_create_ticket',
    description: 'Create a FoxDesk ticket using the token user permissions.',
    inputSchema: {
      type: 'object',
      required: ['title'],
      properties: {
        title: { type: 'string' },
        description: { type: 'string' },
        organization_id: { type: 'integer', minimum: 1 },
        assignee_id: { type: 'integer', minimum: 1 },
        priority_id: { type: 'integer', minimum: 1 },
        status_id: { type: 'integer', minimum: 1 },
        idempotency_key: { type: 'string' },
        dry_run: { type: 'boolean', description: 'Return the planned API request without writing.' },
        confirm: { type: 'boolean', description: 'Required to execute this write tool.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_add_comment',
    description: 'Add a public or internal comment to a FoxDesk ticket.',
    inputSchema: {
      type: 'object',
      required: ['content'],
      properties: {
        ticket_id: { type: 'integer', minimum: 1 },
        ticket_hash: { type: 'string' },
        content: { type: 'string' },
        is_internal: { type: 'boolean' },
        idempotency_key: { type: 'string' },
        dry_run: { type: 'boolean', description: 'Return the planned API request without writing.' },
        confirm: { type: 'boolean', description: 'Required to execute this write tool.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_log_time',
    description: 'Add a manual time entry to a FoxDesk ticket.',
    inputSchema: {
      type: 'object',
      required: ['duration_minutes'],
      properties: {
        ticket_id: { type: 'integer', minimum: 1 },
        ticket_hash: { type: 'string' },
        duration_minutes: { type: 'integer', minimum: 1 },
        summary: { type: 'string' },
        is_billable: { type: 'boolean' },
        idempotency_key: { type: 'string' },
        dry_run: { type: 'boolean', description: 'Return the planned API request without writing.' },
        confirm: { type: 'boolean', description: 'Required to execute this write tool.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_prepare_report',
    description: 'Prepare a report review for visible billable work.',
    inputSchema: {
      type: 'object',
      properties: {
        organization_id: { type: 'integer', minimum: 1 },
        time_range: {
          type: 'string',
          description: 'Report range, for example this_month or last_month.',
        },
        limit: { type: 'integer', minimum: 1, maximum: 100 },
      },
      additionalProperties: false,
    },
  },
];

const TOOL_POLICY = {
  foxdesk_agent_manifest: {
    action: null,
    method: 'local',
    scopes: [],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_list_tickets: {
    action: 'app-ticket-list',
    method: 'GET',
    scopes: ['tickets:read'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_get_ticket: {
    action: 'app-ticket-detail',
    method: 'GET',
    scopes: ['tickets:read'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_create_ticket: {
    action: 'app-create-ticket',
    method: 'POST',
    scopes: ['tickets:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_add_comment: {
    action: 'app-add-comment',
    method: 'POST',
    scopes: ['comments:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_log_time: {
    action: 'app-log-time',
    method: 'POST',
    scopes: ['time:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_prepare_report: {
    action: 'app-reporting-review',
    method: 'GET',
    scopes: ['reports:read'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
};

const TOOL_MANIFEST = TOOLS.map((tool) => ({
  name: tool.name,
  description: tool.description,
  inputSchema: tool.inputSchema,
  policy: TOOL_POLICY[tool.name],
}));

const TOOL_HANDLERS = {
  foxdesk_agent_manifest: () => agentManifest(),
  foxdesk_list_tickets: (args) => apiGet('app-ticket-list', pickDefined(args, ['view', 'search', 'limit', 'offset'])),
  foxdesk_get_ticket: (args) => {
    requireTicketSelector(args);
    return apiGet('app-ticket-detail', pickDefined(args, ['ticket_id', 'ticket_hash', 'include_internal']));
  },
  foxdesk_create_ticket: (args) => {
    requireString(args.title, 'title');
    return apiWriteTool('foxdesk_create_ticket', 'app-create-ticket', pickDefined(args, [
      'title',
      'description',
      'organization_id',
      'assignee_id',
      'priority_id',
      'status_id',
    ]), args);
  },
  foxdesk_add_comment: (args) => {
    requireTicketSelector(args);
    requireString(args.content, 'content');
    return apiWriteTool('foxdesk_add_comment', 'app-add-comment', pickDefined(args, [
      'ticket_id',
      'ticket_hash',
      'content',
      'is_internal',
    ]), args);
  },
  foxdesk_log_time: (args) => {
    requireTicketSelector(args);
    return apiWriteTool('foxdesk_log_time', 'app-log-time', pickDefined(args, [
      'ticket_id',
      'ticket_hash',
      'duration_minutes',
      'summary',
      'is_billable',
    ]), args);
  },
  foxdesk_prepare_report: (args) => apiGet('app-reporting-review', pickDefined(args, [
    'organization_id',
    'time_range',
    'limit',
  ])),
};

function agentManifest() {
  return {
    schema_version: 1,
    server: {
      name: 'foxdesk-agent-api',
      version: SERVER_VERSION,
      protocolVersion: PROTOCOL_VERSION,
      transport: 'stdio',
    },
    auth: {
      type: 'bearer',
      env: 'FOXDESK_API_TOKEN',
      baseUrlEnv: 'FOXDESK_BASE_URL',
      inheritsUserPermissions: true,
    },
    safety: {
      writesRequireConfirmation: WRITE_TOOLS_REQUIRE_CONFIRMATION,
      dryRunArgument: 'dry_run',
      confirmationArgument: 'confirm',
      idempotencyArgument: 'idempotency_key',
      tokenRedaction: true,
    },
    tools: TOOL_MANIFEST,
  };
}

function requireConfig() {
  if (!process.env.FOXDESK_BASE_URL) {
    throw new Error('Set FOXDESK_BASE_URL in examples/agent-api/.env or FOXDESK_AGENT_ENV.');
  }
  if (!process.env.FOXDESK_API_TOKEN) {
    throw new Error('Set FOXDESK_API_TOKEN in examples/agent-api/.env or FOXDESK_AGENT_ENV.');
  }
  if (typeof fetch !== 'function') {
    throw new Error('Node.js 18 or newer is required for the built-in fetch API.');
  }
}

function pickDefined(source, keys) {
  const result = {};
  for (const key of keys) {
    if (source[key] !== undefined && source[key] !== null && source[key] !== '') {
      result[key] = source[key];
    }
  }
  return result;
}

function requireString(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`${name} is required.`);
  }
}

function requireTicketSelector(args) {
  if (!args.ticket_id && !args.ticket_hash) {
    throw new Error('ticket_id or ticket_hash is required.');
  }
}

function foxdeskUrl(action, query = {}) {
  const base = new URL(process.env.FOXDESK_BASE_URL.replace(/\/+$/, '') + '/index.php');
  base.searchParams.set('page', 'api');
  base.searchParams.set('action', action);

  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') {
      base.searchParams.set(key, String(value));
    }
  }

  return base;
}

async function apiGet(action, query = {}) {
  requireConfig();
  return apiRequest(foxdeskUrl(action, query), {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${process.env.FOXDESK_API_TOKEN}`,
    },
  });
}

async function apiPost(action, payload, idempotencyKey) {
  requireConfig();
  return apiRequest(foxdeskUrl(action), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${process.env.FOXDESK_API_TOKEN}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': idempotencyKey || defaultIdempotencyKey(action),
    },
    body: JSON.stringify(payload),
  });
}

async function apiWriteTool(toolName, action, payload, args) {
  if (agentDryRunRequested(args)) {
    return writeDryRunPlan(toolName, action, payload, args);
  }

  requireWriteConfirmation(toolName, args);
  return apiPost(action, payload, args.idempotency_key);
}

function agentDryRunRequested(args) {
  return args.dry_run === true || process.env.FOXDESK_AGENT_DRY_RUN === '1';
}

function requireWriteConfirmation(toolName, args) {
  if (!WRITE_TOOLS_REQUIRE_CONFIRMATION || args.confirm === true) {
    return;
  }

  throw new Error(`${toolName} is a write tool. Call it with dry_run:true first, then confirm:true to execute.`);
}

function writeDryRunPlan(toolName, action, payload, args) {
  return {
    dry_run: true,
    tool: toolName,
    action,
    method: 'POST',
    url: foxdeskUrl(action).toString(),
    payload,
    idempotency_key: args.idempotency_key || defaultIdempotencyKey(action),
    would_write: true,
  };
}

function defaultIdempotencyKey(action) {
  return `mcp-${action}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

async function apiRequest(url, options) {
  const response = await fetch(url, options);
  const text = await response.text();
  let parsed = null;

  if (text.trim() !== '') {
    try {
      parsed = JSON.parse(text);
    } catch (error) {
      parsed = { raw: text };
    }
  }

  if (!response.ok) {
    const message = parsed && (parsed.message || parsed.error)
      ? (parsed.message || parsed.error)
      : text.slice(0, 300);
    throw new Error(`FoxDesk API ${response.status}: ${message}`);
  }

  return parsed || {};
}

function redactSecrets(value) {
  // Never print FOXDESK_API_TOKEN; all tool and protocol errors go through this redaction path.
  const token = process.env.FOXDESK_API_TOKEN;
  if (!token || typeof value !== 'string') {
    return value;
  }
  return value.split(token).join('[redacted FOXDESK_API_TOKEN]');
}

function toolResult(data) {
  return {
    content: [
      {
        type: 'text',
        text: redactSecrets(JSON.stringify(data, null, 2)),
      },
    ],
  };
}

function toolError(error) {
  return {
    isError: true,
    content: [
      {
        type: 'text',
        text: redactSecrets(error instanceof Error ? error.message : String(error)),
      },
    ],
  };
}

function rpcResponse(id, result) {
  return { jsonrpc: '2.0', id, result };
}

function rpcError(id, code, message) {
  return { jsonrpc: '2.0', id, error: { code, message } };
}

function writeMessage(message) {
  process.stdout.write(JSON.stringify(message) + '\n');
}

async function handleRequest(message) {
  const id = message.id;
  const method = message.method;
  const params = message.params || {};

  if (id === undefined || id === null) {
    return null;
  }

  if (method === 'initialize') {
    return rpcResponse(id, {
      protocolVersion: params.protocolVersion || PROTOCOL_VERSION,
      capabilities: { tools: {} },
      serverInfo: { name: 'foxdesk-agent-api', version: SERVER_VERSION },
    });
  }

  if (method === 'ping') {
    return rpcResponse(id, {});
  }

  if (method === 'tools/list') {
    return rpcResponse(id, { tools: TOOLS });
  }

  if (method === 'tools/call') {
    const name = params.name;
    const args = params.arguments || {};
    const handler = TOOL_HANDLERS[name];
    if (!handler) {
      return rpcError(id, -32601, `Unknown tool: ${name}`);
    }

    try {
      return rpcResponse(id, toolResult(await handler(args)));
    } catch (error) {
      return rpcResponse(id, toolError(error));
    }
  }

  if (method === 'resources/list') {
    return rpcResponse(id, { resources: [] });
  }

  if (method === 'prompts/list') {
    return rpcResponse(id, { prompts: [] });
  }

  return rpcError(id, -32601, `Method not found: ${method}`);
}

async function processLine(line) {
  const trimmed = line.trim();
  if (!trimmed) {
    return;
  }

  let message;
  try {
    message = JSON.parse(trimmed);
  } catch (error) {
    writeMessage(rpcError(null, -32700, 'Parse error'));
    return;
  }

  try {
    const response = await handleRequest(message);
    if (response) {
      writeMessage(response);
    }
  } catch (error) {
    writeMessage(rpcError(message.id ?? null, -32603, redactSecrets(error.message)));
  }
}

function main() {
  let buffer = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (chunk) => {
    buffer += chunk;
    const lines = buffer.split(/\r?\n/);
    buffer = lines.pop() || '';
    for (const line of lines) {
      void processLine(line);
    }
  });
  process.stdin.on('end', () => {
    if (buffer.trim()) {
      void processLine(buffer);
    }
  });
}

if (require.main === module) {
  main();
}

module.exports = {
  TOOLS,
  TOOL_MANIFEST,
  TOOL_HANDLERS,
  agentManifest,
  handleRequest,
};
