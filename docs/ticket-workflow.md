# Ticket workflow

The ticket header exposes the current status, Complete / Reopen, and Take over. Reopening preserves assignment, history and logged time and does not start a timer. Assignment can include an internal handoff note.

The composer saves comment, optional time and target status together after validation. Manual time cannot be submitted while the same action stops an active timer; stop or discard that timer first. Historical manual entries remain available. Public and internal editor drafts survive navigation and server errors. The server acknowledges successful submissions before their drafts are cleared. An unsent draft in the other editor is preserved separately; attachment names are remembered, but files must be reselected after a reload.

Ticket lists retain their URL, scroll position and visible queue in the current browser tab. Previous, Next and Complete and next use that queue. R focuses the current editor; [ and ] navigate the queue outside editable fields and IME composition. Assignment shortcuts filter the current list view.

## REST and MCP

Read `agent-get-ticket` and use `ticket.workflow.revision`, `allowed_actions`, `targets`, `executor` and `timer`. POST `agent-ticket-workflow` with `ticket_id` or `ticket_hash`, `operation`, and `expected_revision`. Operations: `status` (+ `status_id`), `complete`, `reopen`, `claim`, `assign` (+ `assignee_id`, optional `handoff_note`). Assignment notes are always internal and require comments:write in addition to ticket scopes. Completion that stops an active timer requires `time:write` in addition to ticket scopes.

`agent-add-work-entry` accepts `status_id` and `expected_revision` with its comment and time payload. All database changes participate in the existing idempotency transaction. Reuse one Idempotency-Key only when retrying the same logical operation and payload. A stale change returns HTTP 409 and current workflow metadata; read/reconcile it before retrying. Do not overwrite a newer human change automatically.

MCP adds `foxdesk_change_status`, `foxdesk_complete_ticket`, `foxdesk_reopen_ticket`, `foxdesk_claim_ticket` and `foxdesk_assign_ticket`. Existing dry-run, confirmation, permission and idempotency policies remain enforced. Get the exact schemas through tools/list.

Notifications run after a successful database commit. `processed` means notification dispatch ran; it does not prove delivery to a mailbox. `dispatch: after_commit` indicates a registered post-commit dispatch. This uses the existing callback mechanism, not a durable mail retry queue. Internal note content is never sent as a public comment notification.

## Local verification

`node tests/ticket-workflow-integration.test.js` creates synthetic localhost fixtures and checks complete/reopen, validation rollback, draft acknowledgement, retry protection, linked work, internal handoff and timer behavior. It also renders list and detail for all published locales from the registry.

`node tests/ticket-workflow-token.test.js` additionally exercises bearer scopes, exact idempotent replay and parallel conflicts using short-lived local test tokens; tokens are revoked in finally. Both scripts reject non-local test URLs. Set FOXDESK_TEST_URL and FOXDESK_TEST_CONTAINER for the self-hosted Docker fixture. Never run these fixture scripts against production.

`node tests/api-idempotency-transaction.test.js` sends keyed writes through the handlers that run inside the Idempotency-Key transaction (`agent-create-ticket`, `agent-add-work-entry`, `app-add-comment`, `agent-ticket-workflow`, `agent-update-status`, `agent-apply-work-log-plan`, `agent-delete-ticket-permanently`) and checks that each succeeds, replays byte-identically, does not duplicate rows, and that a failed keyed write releases its reservation. API requests that carry an `Idempotency-Key` already run inside one transaction opened by `api_idempotency_replay_if_available()`; handlers and modules must join it through `db_begin_owned()` / `db_commit_owned()` / `db_rollback_owned()` from `includes/database.php` instead of calling `beginTransaction()` directly, otherwise PDO throws "There is already an active transaction".
