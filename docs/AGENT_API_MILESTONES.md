# Agent API Milestones

## Milník 4 — Tool Manifest

Status: done.

KPI:

- Agent tools are described in `examples/agent-api/agent-tools.json`.
- The MCP server exposes `foxdesk_agent_manifest`.
- Every tool declares action, method, required scopes, and write behavior.

## Milník 5 — Write Safety

Status: done.

KPI:

- MCP write tools support `dry_run:true`.
- MCP write tools require `confirm:true` before real execution.
- Write calls keep idempotency keys.
- Tool responses redact `FOXDESK_API_TOKEN`.

## Milník 6 — Agent Smoke Gate

Status: done.

KPI:

- `npm run test:agent-mcp-smoke` validates MCP initialize, tools/list,
  manifest, dry-run, blocked unsafe writes, and token redaction.
- `npm run test:agent-mcp` runs JS syntax, contract, and smoke checks.
- `npm run test:agent-api-control` includes the MCP contract.
