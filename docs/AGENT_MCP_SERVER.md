# FoxDesk MCP Server

FoxDesk includes a small stdio MCP server for local agents. It does not add a
new permission model. Every call still goes through the same scoped FoxDesk API
token, user role, organization visibility, idempotency, and audit logging.

## Start

Create a local env file first:

```bash
cp examples/agent-api/.env.example examples/agent-api/.env
```

Paste a token from **Profile -> API access** into that file, then start the
server:

```bash
npm run agent:mcp
```

Use `FOXDESK_AGENT_ENV` when the secret lives somewhere else:

```bash
FOXDESK_AGENT_ENV=/secure/path/foxdesk.env node examples/agent-api/mcp-server.js
```

## MCP Client Config

Use absolute paths in real client configs:

```json
{
  "mcpServers": {
    "foxdesk": {
      "command": "node",
      "args": ["/absolute/path/to/examples/agent-api/mcp-server.js"],
      "env": {
        "FOXDESK_AGENT_ENV": "/absolute/path/to/examples/agent-api/.env"
      }
    }
  }
}
```

## Tools

- `foxdesk_agent_manifest` describes available tools, scopes, and safety rules.
- `foxdesk_list_tickets` reads visible tickets.
- `foxdesk_get_ticket` reads one ticket by id or hash.
- `foxdesk_create_ticket` creates a ticket.
- `foxdesk_add_comment` adds a public or internal comment.
- `foxdesk_log_time` logs manual time.
- `foxdesk_prepare_report` prepares a report review.

## Scopes

- `tickets:read` for listing and reading tickets.
- `tickets:write` for creating tickets.
- `comments:write` for comments.
- `time:write` for time entries.
- `reports:read` for report review.

401 means the key is missing or invalid. 403 means the key is valid but the
user or token scope cannot perform that action.

Never print `FOXDESK_API_TOKEN`. Rotate the key from **Profile -> API access**
if it is exposed.

## Write Safety

Write tools do not execute unless the tool call includes `confirm:true`.
Use `dry_run:true` first to inspect the exact planned action and payload without
calling FoxDesk:

```json
{
  "title": "Printer issue",
  "description": "Office printer is offline.",
  "dry_run": true
}
```

Then repeat the call with `confirm:true` to execute it. Set
`FOXDESK_AGENT_DRY_RUN=1` to force all write tools into dry-run mode. Set
`FOXDESK_AGENT_CONFIRM_WRITES=0` only in a tightly controlled automation.

## Verification

```bash
npm run test:agent-mcp
npm run test:agent-mcp-smoke
```
