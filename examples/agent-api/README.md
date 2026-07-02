# FoxDesk Agent API Examples

These examples let Codex, Claude, or a local CLI script operate FoxDesk through
the same scoped API permissions as the user who created the token.

## 1. Create a key

Open **Profile -> API access**, create a key, and select only the scopes the
assistant needs. For the examples below, use:

- `tickets:write`
- `comments:write`
- `time:write`
- `reports:read`

Add `tickets:read`, `clients:read`, `notifications:read`, and
`attachments:read/write` as needed.

## 2. Configure local env

```bash
cp examples/agent-api/.env.example examples/agent-api/.env
```

Edit `examples/agent-api/.env` and paste the token. The real `.env` file is
ignored by git.

Do not open the FoxDesk login page when using these examples. The URL in
`FOXDESK_BASE_URL` is the API host, and every request must include
`Authorization: Bearer $FOXDESK_API_TOKEN`.

## 3. Run examples

```bash
sh examples/agent-api/create-ticket.sh
FOXDESK_TICKET_ID=123 sh examples/agent-api/add-comment.sh
FOXDESK_TICKET_ID=123 sh examples/agent-api/log-time.sh
FOXDESK_ORGANIZATION_ID=1 sh examples/agent-api/prepare-report.sh
```

## Raw curl shape

```bash
curl -fsS -X POST "$FOXDESK_BASE_URL/index.php?page=api&action=app-create-ticket" \
  -H "Authorization: Bearer $FOXDESK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: manual-create-ticket-1" \
  --data '{"title":"Printer issue","description":"Created from curl."}'
```

## MCP server

Agents with MCP support can use the local stdio wrapper:

```bash
npm run agent:mcp
```

See `docs/AGENT_MCP_SERVER.md` for the client config and tool list.

Write tools support `dry_run:true` and require `confirm:true` before execution.
The machine-readable tool manifest is in `examples/agent-api/agent-tools.json`.

## Staging/production smoke

Use this after you create a scoped test key for a real FoxDesk workspace:

```bash
npm run agent:prod-smoke
```

By default the smoke is read-only. It verifies MCP initialization, the tool
manifest, live ticket listing, and ticket detail when at least one ticket is
visible to the token.

Optional checks are opt-in:

```bash
FOXDESK_AGENT_PROD_REPORT=1 npm run agent:prod-smoke
FOXDESK_AGENT_PROD_WRITE=1 npm run agent:prod-smoke
```

`FOXDESK_AGENT_PROD_WRITE=1` creates a smoke ticket, adds an internal comment,
and logs one non-billable minute. Use it only in a test workspace.
