# Agent API Control

Goal: let a trusted assistant control FoxDesk through a scoped API key without
giving it more power than the person who created the key.

## Status

Implemented v1:

- Profile API key creation/revocation with scoped permissions and expiry.
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
- A local stdio MCP server wrapper lives in `examples/agent-api/mcp-server.js`
  and is documented in `docs/AGENT_MCP_SERVER.md`.
- Agent tool manifest, MCP write dry-run/confirmation, and local agent smoke
  gates are tracked in `docs/AGENT_API_MILESTONES.md`.

Still future-facing:

- Destructive write endpoints beyond ticket/comment/time workflows.

## User Flow

1. A user opens **Profile -> API access**.
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

## Permission Model

- The token inherits the creator's role, organization visibility, and permission
  map.
- A token cannot request a scope the creator does not have.
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
- MCP write actions require `confirm:true` and support `dry_run:true`.
- Destructive actions need explicit scopes and should support dry-run before
  they are exposed.
- Tokens have rate limits and last-used metadata.
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
