# Claude Agent Instructions

Use this when connecting Claude Desktop, Claude Code, or a Claude Project to
FoxDesk through a scoped API key.

## Secret Setup

Store the key outside the prompt:

```bash
cp examples/agent-api/.env.example examples/agent-api/.env
```

Then edit `.env` and paste the key from **Profile -> API access**.

## Project Prompt

```text
You can operate FoxDesk only through the scripts in examples/agent-api.
Never print FOXDESK_API_TOKEN.
Before write actions, confirm the target ticket or client when it is ambiguous.
Use app-create-ticket for new work, app-add-comment for ticket updates,
app-log-time for time entries, and app-reporting-review for report drafts.
If the API returns 401 or 403, ask for a token with the required scope.
```

## Smoke Commands

```bash
sh examples/agent-api/create-ticket.sh
FOXDESK_TICKET_ID=123 sh examples/agent-api/log-time.sh
FOXDESK_ORGANIZATION_ID=1 sh examples/agent-api/prepare-report.sh
```
