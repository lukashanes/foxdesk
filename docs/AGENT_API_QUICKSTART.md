# Agent API Quickstart

This quickstart connects Codex, Claude, or a CLI script to FoxDesk without
giving the assistant more access than the user who created the key.

## Steps

1. Open **Profile -> API access**.
2. Create a scoped key with a clear name, for example `Codex local assistant`.
3. Copy the key once.
4. Configure the local example env:

```bash
cp examples/agent-api/.env.example examples/agent-api/.env
```

5. Edit `examples/agent-api/.env` and paste the token.
6. Run a smoke command:

```bash
sh examples/agent-api/create-ticket.sh
```

## Examples

Create a ticket:

```bash
FOXDESK_TICKET_TITLE="Printer issue" \
FOXDESK_TICKET_DESCRIPTION="The office printer is offline." \
sh examples/agent-api/create-ticket.sh
```

Add time to an existing ticket:

```bash
FOXDESK_TICKET_ID=123 \
FOXDESK_DURATION_MINUTES=45 \
FOXDESK_TIME_SUMMARY="Diagnosed printer network settings." \
sh examples/agent-api/log-time.sh
```

Prepare a report review:

```bash
FOXDESK_ORGANIZATION_ID=1 \
FOXDESK_TIME_RANGE=this_month \
sh examples/agent-api/prepare-report.sh
```

## Agent Instructions

- Codex: `examples/agent-api/codex-instructions.md`
- Claude: `examples/agent-api/claude-instructions.md`
- MCP server: `docs/AGENT_MCP_SERVER.md`

Run the local MCP wrapper when your agent supports MCP:

```bash
npm run agent:mcp
```

## Required scopes

- Create ticket: `tickets:write`
- Add comment: `comments:write`
- Log time: `time:write`
- Prepare report review: `reports:read`
- Upload attachment: `attachments:write`

401 means the key is missing or invalid. 403 means the key is valid but the user
or token scope cannot perform that action.
