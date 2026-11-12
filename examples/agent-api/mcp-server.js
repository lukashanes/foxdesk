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
  "name": "foxdesk_change_status",
  "description": "Status a ticket using the current workflow revision. Read foxdesk_get_ticket first.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "ticket_id": {
        "type": "integer",
        "minimum": 1
      },
      "ticket_hash": {
        "type": "string"
      },
      "expected_revision": {
        "type": "string"
      },
      "idempotency_key": {
        "type": "string"
      },
      "skip_notification": {
        "type": "boolean"
      },
      "dry_run": {
        "type": "boolean"
      },
      "confirm": {
        "type": "boolean"
      },
      "status_id": {
        "type": "integer",
        "minimum": 1
      }
    },
    "required": [
      "expected_revision",
      "idempotency_key",
      "status_id"
    ],
    "additionalProperties": false
  }
},
{
  "name": "foxdesk_complete_ticket",
  "description": "Complete a ticket using the current workflow revision. Read foxdesk_get_ticket first.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "ticket_id": {
        "type": "integer",
        "minimum": 1
      },
      "ticket_hash": {
        "type": "string"
      },
      "expected_revision": {
        "type": "string"
      },
      "idempotency_key": {
        "type": "string"
      },
      "skip_notification": {
        "type": "boolean"
      },
      "dry_run": {
        "type": "boolean"
      },
      "confirm": {
        "type": "boolean"
      }
    },
    "required": [
      "expected_revision",
      "idempotency_key"
    ],
    "additionalProperties": false
  }
},
{
  "name": "foxdesk_reopen_ticket",
  "description": "Reopen a ticket using the current workflow revision. Read foxdesk_get_ticket first.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "ticket_id": {
        "type": "integer",
        "minimum": 1
      },
      "ticket_hash": {
        "type": "string"
      },
      "expected_revision": {
        "type": "string"
      },
      "idempotency_key": {
        "type": "string"
      },
      "skip_notification": {
        "type": "boolean"
      },
      "dry_run": {
        "type": "boolean"
      },
      "confirm": {
        "type": "boolean"
      }
    },
    "required": [
      "expected_revision",
      "idempotency_key"
    ],
    "additionalProperties": false
  }
},
{
  "name": "foxdesk_claim_ticket",
  "description": "Claim a ticket using the current workflow revision. Read foxdesk_get_ticket first.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "ticket_id": {
        "type": "integer",
        "minimum": 1
      },
      "ticket_hash": {
        "type": "string"
      },
      "expected_revision": {
        "type": "string"
      },
      "idempotency_key": {
        "type": "string"
      },
      "skip_notification": {
        "type": "boolean"
      },
      "dry_run": {
        "type": "boolean"
      },
      "confirm": {
        "type": "boolean"
      }
    },
    "required": [
      "expected_revision",
      "idempotency_key"
    ],
    "additionalProperties": false
  }
},
{
  "name": "foxdesk_assign_ticket",
  "description": "Assign a ticket using the current workflow revision. Read foxdesk_get_ticket first.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "ticket_id": {
        "type": "integer",
        "minimum": 1
      },
      "ticket_hash": {
        "type": "string"
      },
      "expected_revision": {
        "type": "string"
      },
      "idempotency_key": {
        "type": "string"
      },
      "skip_notification": {
        "type": "boolean"
      },
      "dry_run": {
        "type": "boolean"
      },
      "confirm": {
        "type": "boolean"
      },
      "assignee_id": {
        "type": "integer",
        "minimum": 1
      }
    },
    "required": [
      "expected_revision",
      "idempotency_key",
      "assignee_id"
    ],
    "additionalProperties": false
  }
},
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
    name: 'foxdesk_agent_docs',
    description: 'Load live FoxDesk API documentation, current token scopes, available actions, and safety rules from the workspace.',
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
      "handoff_note": {"type": "string", "description": "Optional internal handoff note, saved atomically with assignment."},
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
        allow_temporal_text: { type: 'boolean' },
        temporal_text_reason: { type: 'string' },
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
        expected_revision: { type: 'string' },
        status_id: { type: 'integer', minimum: 1 },
        ticket_id: { type: 'integer', minimum: 1 },
        ticket_hash: { type: 'string' },
        content: { type: 'string' },
        is_internal: { type: 'boolean' },
        allow_temporal_text: { type: 'boolean' },
        temporal_text_reason: { type: 'string' },
        idempotency_key: { type: 'string' },
        dry_run: { type: 'boolean', description: 'Return the planned API request without writing.' },
        confirm: { type: 'boolean', description: 'Required to execute this write tool.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_add_work_entry',
    description: 'Atomically add a work-only comment and its linked structured date/duration entry to a FoxDesk ticket.',
    inputSchema: {
      type: 'object',
      required: ['content', 'duration_minutes'],
      properties: {
        expected_revision: { type: 'string' },
        status_id: { type: 'integer', minimum: 1 },
        ticket_id: { type: 'integer', minimum: 1 },
        ticket_hash: { type: 'string' },
        content: { type: 'string' },
        is_internal: { type: 'boolean' },
        skip_notification: { type: 'boolean' },
        allow_temporal_text: { type: 'boolean' },
        temporal_text_reason: { type: 'string' },
        duration_minutes: { type: 'integer', minimum: 1, maximum: 1440 },
        worked_on: { type: 'string', description: 'Work date in YYYY-MM-DD format.' },
        time_precision: { type: 'string', enum: ['exact', 'derived', 'estimated', 'allocated', 'duration_only'] },
        started_at: { type: 'string' },
        ended_at: { type: 'string' },
        manual_date: { type: 'string' },
        manual_start_time: { type: 'string' },
        manual_end_time: { type: 'string' },
        is_billable: { type: 'boolean' },
        idempotency_key: { type: 'string' },
        dry_run: { type: 'boolean', description: 'Return the planned API request without writing.' },
        confirm: { type: 'boolean', description: 'Required to execute this write tool.' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_plan_work_log',
    description: 'Validate a complete multi-day ticket/comment/time plan and return a signed user-facing preview without writing.',
    inputSchema: {
      type: 'object',
      required: ['structure', 'allocation_basis', 'total_minutes', 'tickets'],
      properties: {
        structure: { type: 'string', enum: ['one_ticket', 'multiple_tickets'] },
        allocation_basis: { type: 'string', enum: ['actual', 'approved_total'] },
        total_minutes: { type: 'integer', minimum: 1, maximum: 525600 },
        allow_temporal_text: { type: 'boolean' },
        temporal_text_reason: { type: 'string' },
        tickets: {
          type: 'array',
          minItems: 1,
          items: {
            type: 'object',
            required: ['title', 'entries'],
            properties: {
              title: { type: 'string' },
              description: { type: 'string' },
              organization_id: { type: 'integer', minimum: 1 },
              assignee_id: { type: 'integer', minimum: 1 },
              priority_id: { type: 'integer', minimum: 1 },
              status_id: { type: 'integer', minimum: 1 },
              entries: {
                type: 'array',
                minItems: 1,
                items: {
                  type: 'object',
                  required: ['content', 'worked_on', 'duration_minutes'],
                  properties: {
                    content: { type: 'string' },
                    worked_on: { type: 'string' },
                    duration_minutes: { type: 'integer', minimum: 1, maximum: 1440 },
                    time_precision: { type: 'string', enum: ['exact', 'derived', 'estimated', 'allocated', 'duration_only'] },
                    started_at: { type: 'string' },
                    ended_at: { type: 'string' },
                    time_summary: { type: 'string' },
                    is_billable: { type: 'boolean' },
                    is_internal: { type: 'boolean' },
                    skip_notification: { type: 'boolean' },
                  },
                  additionalProperties: false,
                },
              },
            },
            additionalProperties: false,
          },
        },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'foxdesk_apply_work_log_plan',
    description: 'Apply an unchanged signed work-log plan only after the user explicitly approves the complete preview.',
    inputSchema: {
      type: 'object',
      required: ['plan', 'plan_hash', 'confirm'],
      properties: {
        plan: { type: 'object' },
        plan_hash: { type: 'string' },
        confirm: { type: 'boolean', description: 'Must be true after explicit user approval.' },
        idempotency_key: { type: 'string' },
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
        duration_minutes: { type: 'integer', minimum: 1, maximum: 1440 },
        worked_on: { type: 'string' },
        time_precision: { type: 'string', enum: ['exact', 'derived', 'estimated', 'allocated', 'duration_only'] },
        started_at: { type: 'string' },
        ended_at: { type: 'string' },
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
    name: 'foxdesk_update_work_entry',
    description: 'Correct one saved FoxDesk time entry (start, end, duration, day, precision, billable flag, summary). Omitted fields keep their value.',
    inputSchema: {
      type: 'object',
      required: ['time_entry_id'],
      properties: {
        time_entry_id: { type: 'integer', minimum: 1 },
        started_at: { type: ['string', 'null'], description: 'YYYY-MM-DD HH:MM; null together with ended_at removes known times.' },
        ended_at: { type: ['string', 'null'], description: 'YYYY-MM-DD HH:MM' },
        manual_date: { type: 'string', description: 'YYYY-MM-DD with manual_start_time and manual_end_time' },
        manual_start_time: { type: 'string', description: 'HH:MM' },
        manual_end_time: { type: 'string', description: 'HH:MM' },
        duration_minutes: { type: 'integer', minimum: 1, maximum: 1440 },
        worked_on: { type: 'string', description: 'YYYY-MM-DD' },
        time_precision: { type: 'string', enum: ['exact', 'derived', 'estimated', 'allocated', 'duration_only'] },
        is_billable: { type: 'boolean' },
        time_summary: { type: 'string' },
        allow_temporal_text: { type: 'boolean' },
        temporal_text_reason: { type: 'string' },
        expected_duration_minutes: { type: 'integer', minimum: 1 },
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
  foxdesk_agent_docs: {
    action: 'agent-docs',
    method: 'GET',
    scopes: [],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_list_tickets: {
    action: 'agent-list-tickets',
    method: 'GET',
    scopes: ['tickets:read'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_get_ticket: {
    action: 'agent-get-ticket',
    method: 'GET',
    scopes: ['tickets:read'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_create_ticket: {
    action: 'agent-create-ticket',
    method: 'POST',
    scopes: ['tickets:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_add_comment: {
    action: 'agent-add-update',
    method: 'POST',
    scopes: ['tickets:read', 'comments:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_add_work_entry: {
    action: 'agent-add-work-entry',
    method: 'POST',
    scopes: ['tickets:read', 'comments:write', 'time:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_plan_work_log: {
    action: 'agent-plan-work-log',
    method: 'POST',
    scopes: ['tickets:read', 'tickets:write', 'comments:write', 'time:write'],
    writes: false,
    supportsDryRun: false,
    requiresConfirmation: false,
  },
  foxdesk_apply_work_log_plan: {
    action: 'agent-apply-work-log-plan',
    method: 'POST',
    scopes: ['tickets:read', 'tickets:write', 'comments:write', 'time:write'],
    writes: true,
    supportsDryRun: false,
    requiresConfirmation: true,
  },
  foxdesk_log_time: {
    action: 'agent-log-time',
    method: 'POST',
    scopes: ['time:write'],
    writes: true,
    supportsDryRun: true,
    requiresConfirmation: true,
  },
  foxdesk_update_work_entry: {
    action: 'agent-update-work-entry',
    method: 'POST',
    scopes: ['tickets:read', 'time:write'],
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
  foxdesk_agent_docs: () => apiGet('agent-docs'),
  foxdesk_list_tickets: (args) => apiGet('agent-list-tickets', pickDefined(args, ['search', 'limit', 'offset'])),
  foxdesk_get_ticket: (args) => {
    requireTicketSelector(args);
    return apiGet('agent-get-ticket', {
      ...(args.ticket_id ? { id: args.ticket_id } : {}),
      ...(args.ticket_hash ? { hash: args.ticket_hash } : {}),
    });
  },
  foxdesk_change_status: (args) => {
    requireTicketSelector(args);
    requireString(args.expected_revision, 'expected_revision');
    requireString(args.idempotency_key, 'idempotency_key');
    return apiWriteTool('foxdesk_change_status', 'agent-ticket-workflow', {...pickDefined(args, ['ticket_id','ticket_hash','status_id','assignee_id','handoff_note','expected_revision','skip_notification']), operation: 'status'}, args);
  },
  foxdesk_complete_ticket: (args) => {
    requireTicketSelector(args);
    requireString(args.expected_revision, 'expected_revision');
    requireString(args.idempotency_key, 'idempotency_key');
    return apiWriteTool('foxdesk_complete_ticket', 'agent-ticket-workflow', {...pickDefined(args, ['ticket_id','ticket_hash','status_id','assignee_id','handoff_note','expected_revision','skip_notification']), operation: 'complete'}, args);
  },
  foxdesk_reopen_ticket: (args) => {
    requireTicketSelector(args);
    requireString(args.expected_revision, 'expected_revision');
    requireString(args.idempotency_key, 'idempotency_key');
    return apiWriteTool('foxdesk_reopen_ticket', 'agent-ticket-workflow', {...pickDefined(args, ['ticket_id','ticket_hash','status_id','assignee_id','handoff_note','expected_revision','skip_notification']), operation: 'reopen'}, args);
  },
  foxdesk_claim_ticket: (args) => {
    requireTicketSelector(args);
    requireString(args.expected_revision, 'expected_revision');
    requireString(args.idempotency_key, 'idempotency_key');
    return apiWriteTool('foxdesk_claim_ticket', 'agent-ticket-workflow', {...pickDefined(args, ['ticket_id','ticket_hash','status_id','assignee_id','handoff_note','expected_revision','skip_notification']), operation: 'claim'}, args);
  },
  foxdesk_assign_ticket: (args) => {
    requireTicketSelector(args);
    requireString(args.expected_revision, 'expected_revision');
    requireString(args.idempotency_key, 'idempotency_key');
    return apiWriteTool('foxdesk_assign_ticket', 'agent-ticket-workflow', {...pickDefined(args, ['ticket_id','ticket_hash','status_id','assignee_id','handoff_note','expected_revision','skip_notification']), operation: 'assign'}, args);
  },
  foxdesk_create_ticket: (args) => {
    requireString(args.title, 'title');
    return apiWriteTool('foxdesk_create_ticket', 'agent-create-ticket', pickDefined(args, [
      'title',
      'description',
      'organization_id',
      'assignee_id',
      'priority_id',
      'status_id',
      'allow_temporal_text',
      'temporal_text_reason',
    ]), args);
  },
  foxdesk_add_comment: (args) => {
    requireTicketSelector(args);
    requireString(args.content, 'content');
    return apiWriteTool('foxdesk_add_comment', 'agent-add-update', pickDefined(args, [
      'ticket_id',
      'expected_revision', 'status_id',
      'ticket_hash',
      'content',
      'is_internal',
      'allow_temporal_text',
      'temporal_text_reason',
    ]), args);
  },
  foxdesk_add_work_entry: (args) => {
    requireTicketSelector(args);
    requireString(args.content, 'content');
    return apiWriteTool('foxdesk_add_work_entry', 'agent-add-work-entry', pickDefined(args, [
      'ticket_id',
      'expected_revision', 'status_id',
      'ticket_hash',
      'content',
      'is_internal',
      'skip_notification',
      'allow_temporal_text',
      'temporal_text_reason',
      'duration_minutes',
      'worked_on',
      'time_precision',
      'started_at',
      'ended_at',
      'manual_date',
      'manual_start_time',
      'manual_end_time',
      'is_billable',
    ]), args);
  },
  foxdesk_plan_work_log: (args) => apiPost('agent-plan-work-log', pickDefined(args, [
    'structure',
    'allocation_basis',
    'total_minutes',
    'allow_temporal_text',
    'temporal_text_reason',
    'tickets',
  ]), args.idempotency_key),
  foxdesk_apply_work_log_plan: (args) => apiWriteTool(
    'foxdesk_apply_work_log_plan',
    'agent-apply-work-log-plan',
    pickDefined(args, ['plan', 'plan_hash', 'confirm']),
    args
  ),
  foxdesk_log_time: (args) => {
    requireTicketSelector(args);
    return apiWriteTool('foxdesk_log_time', 'agent-log-time', pickDefined(args, [
      'ticket_id',
      'ticket_hash',
      'duration_minutes',
      'worked_on',
      'time_precision',
      'started_at',
      'ended_at',
      'summary',
      'is_billable',
    ]), args);
  },
  foxdesk_update_work_entry: (args) => {
    const payload = pickDefined(args, [
      'time_entry_id',
      'manual_date',
      'manual_start_time',
      'manual_end_time',
      'duration_minutes',
      'worked_on',
      'time_precision',
      'is_billable',
      'allow_temporal_text',
      'temporal_text_reason',
      'expected_duration_minutes',
    ]);
    // Explicit null (remove known times) and empty summary (clear it) must reach the API.
    for (const key of ['started_at', 'ended_at', 'time_summary']) {
      if (args[key] !== undefined) payload[key] = args[key];
    }
    return apiWriteTool('foxdesk_update_work_entry', 'agent-update-work-entry', payload, args);
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
