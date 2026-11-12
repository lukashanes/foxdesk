# Agent API Control

Goal: let a trusted assistant control FoxDesk through a scoped API key without
giving it more power than the person who created the key.

## Status

Implemented v1:

- Settings API key creation/revocation with scoped permissions and optional expiry.
- Bearer-token authentication for agent/app/upload API actions.
- Scope enforcement, rate limiting metadata, idempotency keys for write retries,
  and write audit logging.
- Instance-bound token storage and audit/idempotency tables.
- Full self-hosted app read surface parity for Work/app shell, ticket list,
  ticket detail, ticket actions, attachment metadata, timers, client overview,
  reporting review, notifications, plus write actions for creating tickets,
  adding comments, logging time, timer actions, and uploads.
- Practical quickstart examples for Codex, Claude, and curl live in
  `docs/AGENT_API_QUICKSTART.md` and `examples/agent-api/`.
- The authenticated `agent-docs` endpoint exposes the canonical ticket workflow
  in English, Czech, German, Spanish, and Italian.
- A local stdio MCP server wrapper lives in `examples/agent-api/mcp-server.js`
  and is documented in `docs/AGENT_MCP_SERVER.md`.
- Agent tool manifest, MCP write dry-run/confirmation, and local agent smoke
  gates are tracked in `docs/AGENT_API_MILESTONES.md`.

Permanent ticket deletion is available only with `tickets:read`, `delete:write`,
the creator's permanent-delete permission, preflight, and an exact ticket-code
confirmation. Partial and bulk permanent deletion are intentionally unsupported.

## User Flow

1. A user opens **Settings -> API & agents**.
2. The user creates an API key for an assistant, CLI, or MCP client.
3. The user chooses an expiry and optional scopes such as tickets, comments,
   time entries, reports, clients, attachments, or read-only access.
4. The key is copied once and stored as a local secret, for example:

```bash
FOXDESK_BASE_URL=https://helpdesk.example.com
FOXDESK_API_TOKEN=fdx_...
```

5. Codex, Claude, a CLI script, or a future MCP server uses the key through the
   public API. Pasting a key into a conversation can work for short tests, but a
   local environment secret is the safer default.
6. The agent must treat the FoxDesk URL as an API host, not as a browser login
   page. It must not open `/index.php?page=login` or wait for cookies; every
   request uses `Authorization: Bearer $FOXDESK_API_TOKEN`.

The same **API & agents** page is the single setup path for Codex, Claude, curl,
MCP clients, automations, and tracked AI-agent identities.

## Permission Model

- The token inherits the creator's role, organization visibility, and permission
  map.
- A token cannot request a scope the creator does not have.
- The owner can change the scopes of an existing key in Profile → API access →
  Permissions without issuing a new key. Only scopes the owner may grant are
  stored, and the change is written to the security log.
- Disabling the user disables the user's tokens.
- Revoking the token immediately blocks future requests.
- Self-hosted tokens are instance-bound.
- Exported tokens migrate to SaaS only as inactive credentials.

## Agent Actions

- Read app shell, Work queues, ticket lists, ticket detail, clients, and reports.
- Search open and done tickets.
- Create tickets.
- Add public or internal comments.
- Add manual time entries to a ticket.
- Correct a saved time entry with `agent-update-work-entry` (scope
  `time:write`): start, end, duration, day, precision, billable flag, and
  summary. It needs an `Idempotency-Key`, uses the same validation as new
  entries, and records the old and new value in the ticket history.
- Start, pause, resume, stop, or discard a timer.
- Attach files when the user can attach files.
- Prepare report drafts and export/share reports when the user can access
  reports.
- Read notifications and mark them read.

## Safety Rules

- Every write creates an audit entry with token id, token label, user id,
  action, target resource, timestamp, IP/user agent when available, and request
  id.
- Write endpoints accept idempotency keys to avoid duplicate ticket/comment/time
  creation when an agent retries.
- A concurrent request with the same key returns `409` and `Retry-After`; the
  agent must retry the unchanged request instead of generating another key.
- MCP write actions require `confirm:true` and support `dry_run:true`.
- Destructive actions need explicit scopes and should support dry-run before
  they are exposed.
- Tokens have rate limits and last-used metadata. The limit is 120 calls per
  token in a sliding 60-second window (`FOXDESK_API_TOKEN_RATE_LIMIT_PER_MINUTE`).
  Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a
  `429` also carries `Retry-After` (seconds) and `retry_after_seconds` in the
  body. Agents wait that long and retry once. Rejected `429` calls are logged
  for audit but do not count toward the limit, so retries never extend a block.
  The window is computed with the PHP clock that writes the audit rows, never
  with MySQL `NOW()`, because the database may run in UTC.
- Work-log time rules: known start and end times (`started_at`/`ended_at` or
  `manual_date` + `manual_start_time` + `manual_end_time`) are stored exactly
  or rejected with `422`; they are never dropped. `exact` and `derived`
  precision may carry times; `estimated`, `allocated`, and `duration_only` never
  do. An entry without known times stores `worked_on` and the duration only:
  `has_clock_times = 0` marks the internal date anchor, and no view, export, or
  API response shows it as a clock time.
- The temporal-text check rejects dates, clock times, and durations (a number
  followed by min/h/hod/hours, which also matches media lengths such as
  "3 min 8 s") inside titles, descriptions, comments, and time summaries.
  When such text is part of the work itself, send `allow_temporal_text: true`
  with a `temporal_text_reason`.
- Tokens are stored hashed; raw token values are shown only once.

## Acceptance Criteria

- A token cannot see a ticket outside the creator's organization scope.
- A read-only token cannot create tickets, comments, time entries, or reports.
- A user without report permission cannot create a report through an agent.
- A disabled user or revoked token cannot call the API.
- Agent-created tickets, comments, time entries, attachments, and reports appear
  in the same UI as manually created work.
- Agent writes are visible in the activity/audit log.
- Repeated retries with the same idempotency key do not create duplicates.
- Contract tests cover token permission inheritance, organization isolation,
  write audit entries, and idempotency.
