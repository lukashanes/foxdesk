# Agent API Quickstart

This quickstart connects Codex, Claude, or a CLI script to FoxDesk without
giving the assistant more access than the user who created the key.

## Steps

1. Sign in as the user whose permissions the assistant should inherit.
2. Open **Profile -> API access**.
3. Create a scoped key with a clear name, for example `Codex local assistant`.
4. Select only the scopes the assistant needs.
5. Copy the key once.

Do not use **Admin -> Users -> AI agents** for normal Codex/Claude access. That
admin area manages AI-agent records and cost/rate metadata. Scoped API keys for
external assistants live in **Profile -> API access** because the key must inherit
the permissions of the person creating it.

6. Configure the local example env:

```bash
cp examples/agent-api/.env.example examples/agent-api/.env
```

7. Edit `examples/agent-api/.env` and paste the token.
8. Run a smoke command:

```bash
sh examples/agent-api/create-ticket.sh
```

## Important: API access, not browser login

When you receive a FoxDesk URL and an API token, do not open the web app or
`/index.php?page=login`. The token is not a password and it will not create a
browser session.

Treat the URL as the API host and call FoxDesk with an `Authorization: Bearer`
header:

```bash
FOXDESK_BASE_URL=https://helpdesk.example.com
FOXDESK_API_TOKEN=fdx_replace_with_token_from_profile

curl -fsS "$FOXDESK_BASE_URL/index.php?page=api&action=agent-me" \
  -H "Authorization: Bearer $FOXDESK_API_TOKEN"
```

If a browser request redirects to login, switch back to the API endpoint. It
means the request was missing the bearer header or was sent to the web UI
instead of `/index.php?page=api&action=...`.

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

Use read-only scopes first when testing. Add write scopes only after the agent's
workflow is clear.

401 means the key is missing or invalid. 403 means the key is valid but the user
or token scope cannot perform that action.
