<?php
/**
 * Agent API Handler
 *
 * REST API endpoints for external agents (AI assistants, automation scripts).
 * Authenticated via Bearer token (see auth.php — authenticate_api_token()).
 *
 * All endpoints follow the standard response format:
 *   Success: {"success": true, ...data}
 *   Error:   {"success": false, "error": "message"}
 */

require_once dirname(__DIR__) . '/modules/agent/operating-instructions.php';
require_once dirname(__DIR__) . '/modules/agent/work-log-planner.php';

/**
 * Format a ticket code consistently with the rest of the app.
 */
function api_agent_ticket_code($ticket_id)
{
    $ticket_id = (int) $ticket_id;
    return function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : ('TK-' . $ticket_id);
}

/**
 * Apply the current agent's visibility scope to a ticket query filter set.
 */
function api_agent_apply_ticket_scope_filters(array &$filters, array $user): void
{
    if (($user['role'] ?? '') === 'admin') {
        return;
    }

    $permissions = get_user_permissions((int) $user['id']) ?? [];
    $scope = $permissions['ticket_scope'] ?? 'assigned';

    switch ($scope) {
        case 'all':
            break;
        case 'organization':
            $filters['current_user'] = $user;
            $filters['scope'] = 'organization';
            break;
        case 'assigned':
        default:
            $filters['agent_id'] = (int) $user['id'];
            break;
    }
}

function api_agent_reference_row_by_name(string $table, string $name): ?array
{
    $name = trim($name);
    if ($name === '' || !in_array($table, ['statuses', 'priorities'], true)) {
        return null;
    }

    $params = [$name];
    $sql = "SELECT * FROM {$table} WHERE LOWER(name) = LOWER(?)";
    if (function_exists('workflow_reference_sql_filter')) {
        $sql .= workflow_reference_sql_filter($table, $params);
    }
    $sql .= " LIMIT 1";

    $row = db_fetch_one($sql, $params);
    return $row ?: null;
}

/**
 * Resolve a ticket from request input and enforce access for the current agent.
 */
function api_agent_resolve_ticket(array $source, array $user, string $hash_key, string $id_key)
{
    $hash = trim((string) ($source[$hash_key] ?? ''));
    $ticket_id = (int) ($source[$id_key] ?? 0);
    $ticket = null;

    if ($hash !== '') {
        $ticket = get_ticket_by_hash($hash);
    } elseif ($ticket_id > 0) {
        $ticket = get_ticket($ticket_id);
    } else {
        api_error('Provide "' . $hash_key . '" or "' . $id_key . '"', 422);
    }

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        if (function_exists('log_security_event')) {
            log_security_event('agent_api_ticket_access_denied', (int) $user['id'], json_encode([
                'ticket_id' => (int) ($ticket['id'] ?? 0),
                'hash_key' => $hash_key,
                'id_key' => $id_key,
            ], JSON_UNESCAPED_UNICODE));
        }
        api_error('Forbidden', 403);
    }

    return $ticket;
}

function api_agent_base_url(): string
{
    $configured = defined('APP_URL') ? trim((string) APP_URL) : '';
    if ($configured === '') {
        $configured = trim((string) (getenv('APP_URL') ?: ''));
    }
    if ($configured === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $configured = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($configured, '/');
}

/** Return the canonical browser link, including for installs below a URL subpath. */
function api_agent_ticket_url(array $ticket): string
{
    $target = ticket_url($ticket);
    return preg_match('#^https?://#i', $target)
        ? $target
        : api_agent_base_url() . '/' . ltrim($target, '/');
}

function api_agent_endpoint_url(string $action): string
{
    return api_agent_base_url() . '/index.php?page=api&action=' . rawurlencode($action);
}

function api_agent_enforce_structured_temporal_text(array $input, array $fields): void
{
    $allow_temporal_text = foxdesk_agent_plan_bool($input, 'allow_temporal_text', false);
    if ($allow_temporal_text && trim((string) ($input['temporal_text_reason'] ?? '')) === '') {
        api_error('temporal_text_reason is required when allow_temporal_text is true.', 422);
    }
    try {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                foxdesk_agent_assert_structured_temporal_text(
                    (string) $input[$field],
                    $field,
                    $allow_temporal_text
                );
            }
        }
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

function api_agent_docs_tools(): array
{
    return [
        ['action' => 'agent-ticket-workflow', 'method' => 'POST', 'scopes' => ['tickets:read', 'tickets:write'],
         'writes' => true, 'query' => [],
         'body' => ['ticket_id' => 'integer, or ticket_hash', 'operation' => 'status|complete|reopen|claim|assign', 'expected_revision' => 'required string from ticket.workflow.revision', 'status_id' => 'integer for status', 'assignee_id' => 'integer for assign', 'handoff_note' => 'optional internal note saved with assignment', 'skip_notification' => 'optional boolean'],
         'description' => 'Apply status, complete, reopen, claim or assign using expected_revision from agent-get-ticket ticket.workflow. Requires tickets:read and tickets:write; stopping a timer also requires time:write. Returns refreshed actions, revision, timer and notification outcomes.'],

        ['action' => 'agent-docs', 'method' => 'GET', 'scopes' => [], 'description' => 'Read the live API contract and canonical operating instructions.'],
        ['action' => 'agent-me', 'method' => 'GET', 'scopes' => ['work:read'], 'description' => 'Verify the API-token identity.'],
        ['action' => 'agent-list-statuses', 'method' => 'GET', 'scopes' => ['tickets:read'], 'description' => 'List valid ticket statuses.'],
        ['action' => 'agent-list-priorities', 'method' => 'GET', 'scopes' => ['tickets:read'], 'description' => 'List valid ticket priorities.'],
        ['action' => 'agent-list-users', 'method' => 'GET', 'scopes' => ['users:read'], 'description' => 'List users visible to the token owner.'],
        ['action' => 'agent-list-tickets', 'method' => 'GET', 'scopes' => ['tickets:read'], 'description' => 'Search and list visible tickets.'],
        ['action' => 'agent-get-ticket', 'method' => 'GET', 'scopes' => ['tickets:read'], 'description' => 'Read one ticket with comments and time entries.'],
        ['action' => 'agent-create-ticket', 'method' => 'POST', 'scopes' => ['tickets:write'], 'description' => 'Create a ticket without a time entry.'],
        ['action' => 'agent-add-update', 'method' => 'POST', 'scopes' => ['tickets:read', 'comments:write'], 'description' => 'Add a public or internal comment without tracked time.'],
        ['action' => 'agent-add-work-entry', 'method' => 'POST', 'scopes' => ['tickets:read', 'comments:write', 'time:write'], 'description' => 'Atomically add a work-only comment and its linked structured work date/duration entry.'],
        ['action' => 'agent-delete-comment', 'method' => 'POST', 'scopes' => ['tickets:read', 'comments:write'], 'body' => ['comment_id' => 'required integer'], 'description' => 'Delete a comment the token owner can manage. Own comments can be deleted with comments:write; delete:write is required for broader administrative deletes.'],
        ['action' => 'agent-plan-work-log', 'method' => 'POST', 'scopes' => ['tickets:read', 'tickets:write', 'comments:write', 'time:write'], 'description' => 'Validate and preview a complete one-ticket or multi-ticket work-log plan without writing business records.', 'body' => ['structure' => 'required one_ticket or multiple_tickets', 'allocation_basis' => 'required actual or approved_total', 'total_minutes' => 'required exact total', 'tickets[].entries[]' => 'content, duration_minutes, time_precision, and either worked_on (times unknown) or known times as started_at + ended_at or manual_date + manual_start_time + manual_end_time', 'tickets[].entries[].time_precision' => 'exact or derived may carry start/end times (required for exact); estimated, allocated, and duration_only never carry times', 'allow_temporal_text' => 'optional boolean; requires temporal_text_reason'], 'time_rules' => ['Known start and end times are stored exactly as sent; invalid or conflicting times return 422 and are never dropped silently.', 'Entries without known times store only worked_on and duration_minutes. FoxDesk never invents clock times; started_at and ended_at are null in every response.']],
        ['action' => 'agent-apply-work-log-plan', 'method' => 'POST', 'scopes' => ['tickets:read', 'tickets:write', 'comments:write', 'time:write'], 'description' => 'Atomically apply an unchanged signed work-log preview after explicit user confirmation.'],
        ['action' => 'agent-update-work-entry', 'method' => 'POST', 'scopes' => ['tickets:read', 'time:write'], 'writes' => true, 'requires_idempotency_key' => true,
         'body' => ['time_entry_id' => 'required integer (time_entries[].id from agent-get-ticket)', 'started_at' => 'optional YYYY-MM-DD HH:MM; send started_at and ended_at as null together to remove known times', 'ended_at' => 'optional YYYY-MM-DD HH:MM', 'manual_date' => 'alternative YYYY-MM-DD with manual_start_time and manual_end_time', 'manual_start_time' => 'alternative HH:MM', 'manual_end_time' => 'alternative HH:MM (earlier than start means the next day)', 'duration_minutes' => 'optional 1-1440; must match start/end when both are sent', 'worked_on' => 'optional YYYY-MM-DD; must be the start day when times are known', 'time_precision' => 'optional exact, derived, estimated, allocated, or duration_only', 'is_billable' => 'optional boolean', 'time_summary' => 'optional short summary without dates or times; empty string clears it', 'expected_duration_minutes' => 'optional guard: 409 when the stored duration differs'],
         'headers' => ['Idempotency-Key' => 'required'],
         'description' => 'Correct one saved time entry: start, end, duration, date, precision, billable flag, or summary. Fields you omit keep their value. The change is recorded in the ticket history.'],
        ['action' => 'agent-update-status', 'method' => 'POST', 'scopes' => ['tickets:write'], 'description' => 'Change a ticket status.'],
        ['action' => 'agent-log-time', 'method' => 'POST', 'scopes' => ['time:write'], 'description' => 'Log a standalone time entry only when no comment belongs to the work.'],
        ['action' => 'agent-delete-ticket-preflight', 'method' => 'GET', 'scopes' => ['tickets:read'], 'description' => 'Preview the complete impact of a permanent ticket deletion.', 'requires_permanent_delete_permission' => true],
        ['action' => 'agent-delete-ticket-permanently', 'method' => 'POST', 'scopes' => ['tickets:read', 'delete:write'], 'description' => 'Permanently delete one complete ticket after exact code confirmation.', 'requires_permanent_delete_permission' => true],
    ];
}

/**
 * Normalize the scopes a documented tool requires. Tools may declare a list in
 * "scopes" or a single "scope"; a missing declaration means no scope is needed.
 */
function api_agent_docs_tool_scopes(array $tool): array
{
    if (isset($tool['scopes']) && is_array($tool['scopes'])) {
        return array_values(array_filter(array_map('strval', $tool['scopes']), static fn(string $scope): bool => $scope !== ''));
    }
    $scope = trim((string) ($tool['scope'] ?? ''));
    return $scope !== '' ? [$scope] : [];
}

function api_agent_docs_scope_allowed(array $scopes, ?array $token_row, array $user): bool
{
    if ($scopes === []) {
        return true;
    }
    if ($token_row) {
        foreach ($scopes as $scope) {
            if (!api_token_has_scope($scope)) {
                return false;
            }
        }
        return true;
    }

    $allowed = api_token_allowed_scopes_for_user($user);
    foreach ($scopes as $scope) {
        if (!in_array($scope, $allowed, true)) {
            return false;
        }
    }
    return true;
}

// =============================================================================
// AGENT-DOCS — live documentation for the current token
// =============================================================================

function api_agent_docs()
{
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $token_row = function_exists('api_token_current_row') ? api_token_current_row() : null;
    $token_scopes = $token_row
        ? api_token_scopes_from_row($token_row)
        : api_token_allowed_scopes_for_user($user);
    $instruction_language = foxdesk_agent_instruction_language($_GET['instruction_language'] ?? null, $user);
    $operating_instructions = foxdesk_agent_operating_instructions($instruction_language, $user);
    $actions = [];
    foreach (api_agent_docs_tools() as $tool) {
        $required_scopes = api_agent_docs_tool_scopes($tool);
        // Report only the scopes this token actually lacks.
        $missing_scopes = array_values(array_filter(
            $required_scopes,
            static fn(string $scope): bool => !api_agent_docs_scope_allowed([$scope], $token_row, $user)
        ));
        $available = $missing_scopes === [];
        if (!empty($tool['requires_permanent_delete_permission'])) {
            $available = $available && can_permanently_delete_tickets($user);
        }
        $actions[] = array_merge($tool, [
            'scopes' => $required_scopes,
            'url' => api_agent_endpoint_url($tool['action']),
            'available' => $available,
            'missing_scopes' => $missing_scopes,
            'requires_unique_idempotency_key' => $tool['method'] === 'POST',
        ]);
    }

    api_success([
        'documentation' => [
            'schema_version' => 2,
            'name' => 'FoxDesk Agent API',
            'base_url' => api_agent_base_url(),
            'api_action_base' => api_agent_base_url() . '/index.php?page=api&action=',
            'auth' => [
                'type' => 'Bearer',
                'header' => 'Authorization: Bearer $FOXDESK_API_TOKEN',
                'env' => 'FOXDESK_API_TOKEN',
                'browser_login_required' => false,
                'token_storage' => 'Keep the token in a private environment file or secret manager. Never paste it into chat, tickets, screenshots, or shared documents.',
            ],
            'current_identity' => [
                'user_id' => (int) $user['id'],
                'name' => trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')),
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? null,
                'is_ai_agent' => !empty($user['is_ai_agent']),
            ],
            'current_token' => [
                'authenticated_by_token' => (bool) $token_row,
                'token_prefix' => $token_row['token_prefix'] ?? null,
                'expires_at' => $token_row['expires_at'] ?? null,
                'scopes' => $token_scopes,
                'scope_catalog' => api_token_scope_catalog($user),
            ],
            'operating_instructions' => $operating_instructions,
            'operating_instructions_markdown' => foxdesk_agent_operating_instructions_markdown($instruction_language, $user),
            'actions' => $actions,
            'rate_limit' => [
                'limit_per_token' => function_exists('api_token_rate_limit_per_minute') ? api_token_rate_limit_per_minute() : 120,
                'window_seconds' => 60,
                'window' => 'sliding: calls counted in the last 60 seconds per token',
                'counts' => 'every API call of the token, reads and writes, successful or failed; rejected 429 calls are not counted',
                'response_headers' => [
                    'X-RateLimit-Limit' => 'calls allowed per 60 seconds',
                    'X-RateLimit-Remaining' => 'calls left in the current window after this one',
                    'Retry-After' => 'on 429 only: seconds to wait before the next call',
                ],
                'on_429' => 'Wait the number of seconds in the Retry-After header (also retry_after_seconds in the body), then retry once. Rejected 429 calls do not count toward the limit.',
                'guidance' => 'Pace bulk work below the limit, prefer agent-plan-work-log for many entries, and on 429 wait Retry-After seconds instead of retrying in a loop.',
            ],
            'temporal_text_check' => [
                'applies_to' => 'ticket titles and descriptions, comment content, and time summaries written by agent work-log actions',
                'rejects' => [
                    'date' => 'dates such as 2026-11-12 or 12.11.2026',
                    'clock_time' => 'clock times such as 9:30 or 14:05',
                    'duration' => 'a number followed by a time unit, e.g. 3 min, 45 minut, 2 h, 1,5 hod, 2 hours (this also matches media lengths such as "first cut 3 min 8 s")',
                ],
                'why' => 'Work dates and times belong in worked_on, started_at/ended_at, and duration_minutes so reports stay correct.',
                'when_text_must_contain_them' => 'If a time value is part of the work itself (for example a video length, a deadline quoted from the client, or a meeting agenda), send allow_temporal_text:true with temporal_text_reason explaining why, e.g. "video lengths describe the delivered cut, not worked time". The reason is required.',
            ],
            'examples' => [
                'read_docs' => [
                    'method' => 'GET',
                    'url' => api_agent_endpoint_url('agent-docs') . '&instruction_language=' . $instruction_language,
                    'headers' => ['Authorization' => 'Bearer $FOXDESK_API_TOKEN'],
                ],
                'verify_identity' => [
                    'method' => 'GET',
                    'url' => api_agent_endpoint_url('agent-me'),
                    'headers' => ['Authorization' => 'Bearer $FOXDESK_API_TOKEN'],
                ],
                'tracked_work_entry' => [
                    'method' => 'POST',
                    'url' => api_agent_endpoint_url('agent-add-work-entry'),
                    'headers' => [
                        'Authorization' => 'Bearer $FOXDESK_API_TOKEN',
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => 'agent-work-entry-unique-key',
                    ],
                    'body' => [
                        'ticket_hash' => 'ticket_hash_from_agent-get-ticket',
                        'content' => $operating_instructions['daily_entries']['example_html'],
                        'duration_minutes' => 27,
                        'worked_on' => '2026-07-13',
                        'time_precision' => 'duration_only',
                        'is_internal' => false,
                        'skip_notification' => true,
                    ],
                ],
                'multi_day_work' => [
                    'plan_action' => 'agent-plan-work-log',
                    'apply_action' => 'agent-apply-work-log-plan',
                    'rule' => 'Show the complete signed preview and apply the unchanged plan only after explicit user approval.',
                ],
            ],
        ],
    ]);
}

// =============================================================================
// AGENT-ME — current token's user info
// =============================================================================

function api_agent_me()
{
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    api_success([
        'user' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'language' => foxdesk_effective_user_language($user),
            'is_ai_agent' => !empty($user['is_ai_agent']),
            'ai_model' => $user['ai_model'] ?? null,
        ],
    ]);
}

// =============================================================================
// LIST STATUSES
// =============================================================================

function api_agent_list_statuses()
{
    $statuses = get_statuses();
    $out = [];
    foreach ($statuses as $s) {
        $out[] = [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'color' => $s['color'] ?? null,
            'is_default' => !empty($s['is_default']),
        ];
    }
    api_success(['statuses' => $out]);
}

// =============================================================================
// LIST PRIORITIES
// =============================================================================

function api_agent_list_priorities()
{
    $priorities = get_priorities();
    $out = [];
    foreach ($priorities as $p) {
        $out[] = [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'color' => $p['color'] ?? null,
        ];
    }
    api_success(['priorities' => $out]);
}

// =============================================================================
// LIST USERS
// =============================================================================

function api_agent_list_users()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $current = current_user();
    $role_filter = $_GET['role'] ?? '';
    if ($role_filter === 'user') {
        $users = get_clients();
    } else {
        $users = get_all_users();
    }

    $exclude_ai = !empty($_GET['exclude_ai']);
    $allowed_orgs = ($current && !is_admin()) ? get_user_organization_ids((int) $current['id']) : [];
    $permissions = ($current && !is_admin()) ? (get_user_permissions((int) $current['id']) ?? []) : [];
    $can_list_all = (($permissions['ticket_scope'] ?? 'own') === 'all');
    $out = [];
    foreach ($users as $u) {
        if (!empty($role_filter) && $role_filter !== 'user' && $u['role'] !== $role_filter) {
            continue;
        }
        if (!is_admin() && !$can_list_all) {
            $role = (string) ($u['role'] ?? '');
            $same_user = (int) ($u['id'] ?? 0) === (int) ($current['id'] ?? 0);
            $is_staff = in_array($role, ['admin', 'agent'], true);
            $user_orgs = get_user_organization_ids((int) ($u['id'] ?? 0));
            $same_org = !empty(array_intersect($allowed_orgs, $user_orgs));

            if (!$same_user && !$is_staff && !$same_org) {
                continue;
            }
        }
        $is_ai = !empty($u['is_ai_agent']);
        if ($exclude_ai && $is_ai) {
            continue;
        }
        $out[] = [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'role' => $u['role'],
            'organization_id' => $u['organization_id'] ? (int) $u['organization_id'] : null,
            'is_ai_agent' => $is_ai,
            'ai_model' => $u['ai_model'] ?? null,
        ];
    }
    api_success(['users' => $out]);
}

// =============================================================================
// CREATE TICKET
// =============================================================================

function api_agent_create_ticket()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    if (empty($input['title'])) {
        api_error('Field "title" is required', 422);
    }
    api_agent_enforce_structured_temporal_text($input, ['title', 'description']);
    foreach (['duration_minutes', 'started_at', 'ended_at', 'manual_date', 'manual_start_time', 'manual_end_time', 'time_summary'] as $time_field) {
        if (array_key_exists($time_field, $input)) {
            api_error('Ticket creation does not accept time fields. Create the ticket first, then use agent-add-work-entry.', 422);
        }
    }

    $user = current_user();
    $owner_id = !empty($input['user_id']) ? (int) $input['user_id'] : (int) $user['id'];
    $owner = get_user($owner_id);
    if (!$owner || !can_user_create_ticket_for($owner, $user)) {
        api_error('Forbidden — invalid ticket owner for this token', 403);
    }

    $data = [
        'title' => trim($input['title']),
        'description' => function_exists('safe_html') ? safe_html((string) ($input['description'] ?? '')) : (string) ($input['description'] ?? ''),
        'user_id' => $owner_id,
        'type' => $input['type'] ?? 'general',
    ];

    if (!empty($input['status_id'])) {
        if (!get_status((int) $input['status_id'])) {
            api_error('Status not found', 404);
        }
        $data['status_id'] = (int) $input['status_id'];
    }
    if (!empty($input['priority_id'])) {
        if (!get_priority((int) $input['priority_id'])) {
            api_error('Priority not found', 404);
        }
        $data['priority_id'] = (int) $input['priority_id'];
    }
    if (array_key_exists('organization_id', $input)) {
        $organization_id = $input['organization_id'] ? (int) $input['organization_id'] : null;
        if ($organization_id !== null) {
            $org = function_exists('get_organization') ? get_organization($organization_id) : null;
            if (!$org || !can_user_use_organization($organization_id, $user)) {
                api_error('Forbidden — invalid organization for this token', 403);
            }
        }
        $data['organization_id'] = $organization_id;
    }
    if (!empty($input['due_date'])) {
        $normalized_due_date = normalize_due_date_input($input['due_date']);
        if ($normalized_due_date === false) {
            api_error('Field "due_date" is invalid', 422);
        }
        $data['due_date'] = $normalized_due_date;
    }
    if (!empty($input['tags'])) {
        $data['tags'] = function_exists('normalize_ticket_tags')
            ? normalize_ticket_tags((string) $input['tags'])
            : (string) $input['tags'];
    }
    if (!empty($input['assignee_id'])) {
        $assignee = get_user((int) $input['assignee_id']);
        if (!$assignee || !can_user_assign_to_staff($assignee, $user)) {
            api_error('Forbidden — invalid assignee for this token', 403);
        }
        $data['assignee_id'] = (int) $input['assignee_id'];
    }

    $ticket_id = create_ticket($data);
    if (!$ticket_id) {
        api_error('Failed to create ticket', 500);
    }

    // Fetch the created ticket for the response
    $ticket = db_fetch_one("SELECT id, hash, title FROM tickets WHERE id = ?", [$ticket_id]);

    $response = [
        'ticket_id' => (int) $ticket_id,
        'ticket_hash' => $ticket['hash'] ?? null,
        'ticket_code' => api_agent_ticket_code($ticket_id),
    ];

    // In-app notifications
    if (!empty($transition['status_changed']) && function_exists('dispatch_ticket_notifications')) {
        $desc_text = strip_tags($input['description'] ?? '');
        $desc_preview = mb_strlen($desc_text) > 80 ? mb_substr($desc_text, 0, 77) . '...' : $desc_text;
        dispatch_ticket_notifications('new_ticket', $ticket_id, $user['id'], [
            'comment_preview' => $desc_preview,
        ]);
        if (!empty($input['assignee_id'])) {
            dispatch_ticket_notifications('assigned_to_you', $ticket_id, $user['id'], [
                'assignee_id' => (int) $input['assignee_id'],
            ]);
        }
    }

    api_success($response);
}

// =============================================================================
// LIST / SEARCH TICKETS
// =============================================================================

function api_agent_list_tickets()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $user = current_user();
    $filters = [];

    if (!empty($_GET['status'])) {
        // Accept status name or ID
        $status_val = $_GET['status'];
        if (is_numeric($status_val)) {
            $filters['status_id'] = (int) $status_val;
        } else {
            $status_row = api_agent_reference_row_by_name('statuses', (string) $status_val);
            if ($status_row) {
                $filters['status_id'] = (int) $status_row['id'];
            }
        }
    }
    if (!empty($_GET['priority'])) {
        $prio_val = $_GET['priority'];
        if (is_numeric($prio_val)) {
            $filters['priority_id'] = (int) $prio_val;
        } else {
            $prio_row = api_agent_reference_row_by_name('priorities', (string) $prio_val);
            if ($prio_row) {
                $filters['priority_id'] = (int) $prio_row['id'];
            }
        }
    }
    if (!empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }
    if (!empty($_GET['user_id'])) {
        $filters['user_id'] = (int) $_GET['user_id'];
    }
    if (!empty($_GET['assignee_id'])) {
        $filters['assignee_id'] = (int) $_GET['assignee_id'];
    }
    if (!empty($_GET['sort'])) {
        $filters['sort'] = $_GET['sort'];
    }

    api_agent_apply_ticket_scope_filters($filters, $user);

    $limit = max(1, min((int) ($_GET['limit'] ?? 50), 200));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $total = get_tickets_count($filters);
    $filters['limit'] = $limit;
    $filters['offset'] = $offset;
    $tickets = get_tickets($filters);

    $out = [];
    foreach ($tickets as $t) {
        $out[] = [
            'id' => (int) $t['id'],
            'hash' => $t['hash'] ?? null,
            'ticket_code' => api_agent_ticket_code($t['id']),
            'title' => $t['title'],
            'description' => mb_substr($t['description'] ?? '', 0, 300),
            'status' => $t['status_name'] ?? null,
            'status_color' => $t['status_color'] ?? null,
            'priority' => $t['priority_name'] ?? null,
            'priority_color' => $t['priority_color'] ?? null,
            'user' => trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')),
            'organization' => $t['organization_name'] ?? null,
            'created_at' => $t['created_at'] ?? null,
            'updated_at' => $t['updated_at'] ?? null,
            'due_date' => $t['due_date'] ?? null,
            'tags' => $t['tags'] ?? null,
        ];
    }

    api_success([
        'tickets' => $out,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

// =============================================================================
// GET SINGLE TICKET
// =============================================================================

function api_agent_get_ticket()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $user = current_user();
    $ticket = api_agent_resolve_ticket($_GET, $user, 'hash', 'id');

    try {
        $activity = ticket_detail_activity_data((int) $ticket['id'], true);
    } catch (Throwable $e) {
        api_error('Ticket activity could not be loaded.', 500);
    }
    $time_breakdown = $activity['time_breakdown'];

    $comments = [];
    foreach ($activity['comments'] as $c) {
            $comments[] = [
                'id' => (int) $c['id'],
                'content' => $c['content'],
                'is_internal' => !empty($c['is_internal']),
                'user' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                'user_email' => $c['email'] ?? null,
                'is_ai_author' => function_exists('is_ai_user') ? is_ai_user((int) ($c['user_id'] ?? 0)) : false,
                'created_at' => $c['created_at'],
            ];
    }

    $time_entries = [];
    foreach ($activity['time_entries'] as $te) {
            $public_timestamps = function_exists('ticket_time_entry_public_timestamps')
                ? ticket_time_entry_public_timestamps($te)
                : ['started_at' => $te['started_at'] ?? null, 'ended_at' => $te['ended_at'] ?? null];
            $time_entries[] = [
                'id' => (int) $te['id'],
                'comment_id' => !empty($te['comment_id']) ? (int) $te['comment_id'] : null,
                'user' => trim(($te['first_name'] ?? '') . ' ' . ($te['last_name'] ?? '')),
                'worked_on' => $te['worked_on'] ?? (!empty($te['started_at']) ? date('Y-m-d', strtotime((string) $te['started_at'])) : null),
                'time_precision' => function_exists('ticket_time_entry_precision') ? ticket_time_entry_precision($te) : 'exact',
                'started_at' => $public_timestamps['started_at'],
                'ended_at' => $public_timestamps['ended_at'],
                'duration_minutes' => (int) ($te['duration_minutes'] ?? 0),
                'summary' => $te['summary'] ?? null,
                'is_billable' => !empty($te['is_billable']),
                'billable_rate' => (float) ($te['billable_rate'] ?? 0),
                'cost_rate' => (float) ($te['cost_rate'] ?? 0),
                'source' => function_exists('get_time_entry_source') ? get_time_entry_source($te) : (!empty($te['is_manual']) ? 'manual' : 'timer'),
                'is_ai_user' => function_exists('is_ai_user') ? is_ai_user((int) ($te['user_id'] ?? 0)) : false,
            ];
    }

    api_success([
        'ticket' => [
            'id' => (int) $ticket['id'],
            'hash' => $ticket['hash'] ?? null,
            'ticket_code' => api_agent_ticket_code($ticket['id']),
            'title' => $ticket['title'],
            'description' => $ticket['description'] ?? '',
            'type' => $ticket['type'] ?? 'general',
            'status' => $ticket['status_name'] ?? null,
        'workflow' => ticket_workflow_metadata($ticket, $user),
            'status_id' => (int) ($ticket['status_id'] ?? 0),
            'status_color' => $ticket['status_color'] ?? null,
            'priority' => $ticket['priority_name'] ?? null,
            'priority_id' => (int) ($ticket['priority_id'] ?? 0),
            'priority_color' => $ticket['priority_color'] ?? null,
            'user' => trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')),
            'user_id' => (int) $ticket['user_id'],
            'assignee' => trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')),
            'assignee_id' => $ticket['assignee_id'] ? (int) $ticket['assignee_id'] : null,
            'organization' => $ticket['organization_name'] ?? null,
            'organization_id' => $ticket['organization_id'] ? (int) $ticket['organization_id'] : null,
            'due_date' => $ticket['due_date'] ?? null,
            'tags' => $ticket['tags'] ?? null,
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at'] ?? null,
            'total_time_minutes' => $time_breakdown['total'],
            'human_time_minutes' => $time_breakdown['human'],
            'ai_time_minutes' => $time_breakdown['ai'],
        ],
        'comments' => $comments,
        'time_entries' => $time_entries,
    ]);
}

// =============================================================================
// ADD COMMENT
// =============================================================================

/**
 * Resolve the time fields of one new agent work entry.
 *
 * Accepts started_at/ended_at (one of them may be derived from the duration)
 * or manual_date + manual_start_time + manual_end_time. Known times are stored
 * exactly; entries without times get an internal date anchor flagged with
 * has_clock_times = 0 that is never shown or returned as a time.
 *
 * @throws InvalidArgumentException client-facing 422 message
 */
function api_agent_resolve_entry_time(array $input, int $duration): array
{
    $entry = $input;
    $start = trim((string) ($input['started_at'] ?? ''));
    $end = trim((string) ($input['ended_at'] ?? ''));
    if ($start !== '' && $end === '') {
        $entry['ended_at'] = date('Y-m-d H:i:s', strtotime(foxdesk_agent_plan_datetime($start, 'started_at')) + $duration * 60);
    } elseif ($end !== '' && $start === '') {
        $entry['started_at'] = date('Y-m-d H:i:s', strtotime(foxdesk_agent_plan_datetime($end, 'ended_at')) - $duration * 60);
    }
    $precision = strtolower(trim((string) ($input['time_precision'] ?? '')));
    if ($precision === '') {
        $precision = foxdesk_agent_plan_entry_has_clock_input($entry) ? 'exact' : 'duration_only';
    }
    if (!in_array($precision, ['exact', 'derived', 'estimated', 'allocated', 'duration_only'], true)) {
        throw new InvalidArgumentException('time_precision must be exact, derived, estimated, allocated, or duration_only.');
    }
    [$started_at, $ended_at, $worked_on] = foxdesk_agent_plan_entry_clock_times($entry, $precision, $duration, 'entry');
    $has_clock_times = $started_at !== null;
    if (!$has_clock_times) {
        $anchor = ticket_time_date_anchor($worked_on, $duration);
        $started_at = $anchor['started_at'];
        $ended_at = $anchor['ended_at'];
    }

    return [
        'started_at' => $started_at,
        'ended_at' => $ended_at,
        'worked_on' => $worked_on,
        'time_precision' => $precision,
        'has_clock_times' => $has_clock_times ? 1 : 0,
    ];
}

function api_agent_add_comment()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();
    if (empty($input['content'])) {
        api_error('Field "content" is required', 422);
    }
    api_agent_enforce_structured_temporal_text($input, ['content', 'time_summary', 'summary']);
    $action = (string) ($GLOBALS['api_current_action'] ?? ($_GET['action'] ?? 'agent-add-comment'));
    $time_fields = ['duration_minutes', 'worked_on', 'time_precision', 'started_at', 'ended_at', 'manual_date', 'manual_start_time', 'manual_end_time'];
    $has_time_fields = false;
    foreach ($time_fields as $time_field) {
        $has_time_fields = $has_time_fields || array_key_exists($time_field, $input);
    }
    if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('tickets:read')) {
        api_error('Missing required scope: tickets:read', 403);
    }
    if ($action !== 'agent-add-work-entry' && $has_time_fields) {
        api_error('agent-add-update is comment-only. Use agent-add-work-entry to save a linked comment and time entry.', 422);
    }
    if ($action === 'agent-add-work-entry' && !$has_time_fields) {
        api_error('agent-add-work-entry requires duration_minutes or an explicit start/end time.', 422);
    }
    if ($action === 'agent-add-work-entry' && (int) ($input['duration_minutes'] ?? 0) < 1) {
        api_error('agent-add-work-entry requires a positive duration_minutes value.', 422);
    }
    if ($action === 'agent-add-work-entry') {
        $has_exact_range = trim((string) ($input['started_at'] ?? '')) !== ''
            && trim((string) ($input['ended_at'] ?? '')) !== '';
        $has_manual_range = trim((string) ($input['manual_date'] ?? '')) !== ''
            && trim((string) ($input['manual_start_time'] ?? '')) !== ''
            && trim((string) ($input['manual_end_time'] ?? '')) !== '';
        if (!$has_exact_range && !$has_manual_range && trim((string) ($input['worked_on'] ?? '')) === '') {
            api_error('worked_on is required when exact work times are not known.', 422);
        }
    }
    if ($action === 'agent-add-work-entry' && !empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('time:write')) {
        api_error('Missing required scope: time:write', 403);
    }
    $input['content'] = function_exists('safe_html') ? safe_html((string) $input['content']) : (string) $input['content'];

    $user = current_user();
    $ticket = api_agent_resolve_ticket($input, $user, 'ticket_hash', 'ticket_id');
    $ticket_id = (int) $ticket['id'];
    $is_internal = !empty($input['is_internal']) ? 1 : 0;

    $duration = (int) ($input['duration_minutes'] ?? 0);
    $workflow_status = !empty($input['status_id']) ? get_status((int) $input['status_id']) : null;
    if (isset($input['status_id']) && (!$workflow_status || !can_edit_ticket($ticket, $user)
        || (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('tickets:write')))) api_error('Status change is not permitted', 403);
    if ($workflow_status && empty($input['expected_revision'])) api_error(t('workflow.conflict'), 409);
    $workflow_before = $ticket;
    $workflow_transition = [];
    $comment_id = null;
    $time_entry_id = null;
    $db = get_db();
    $owns_transaction = false;
    try {
        $owns_transaction = db_begin_owned($db);
        $ticket = ticket_workflow_lock($ticket, $user, (string) ($input['expected_revision'] ?? ''));
        if ($workflow_status && ticket_status_group_from_status($workflow_status) === 'done' && get_active_ticket_timer($ticket_id, (int) $user['id'])) {
            if ($duration > 0) throw new DomainException('Stop or discard the active timer before completing with a manual work entry.', 422);
            if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('time:write')) throw new DomainException('Missing required scope: time:write', 403);
        }
        if ($workflow_status) $workflow_transition = ticket_transition_status($ticket, get_status((int) $ticket['status_id']), $workflow_status, (int) $user['id']);
        $comment_id = add_comment($ticket_id, $user['id'], $input['content'], $is_internal);
        if (!$comment_id) {
            throw new RuntimeException('Failed to add comment');
        }
        if ($duration > 0 && function_exists('add_manual_time_entry')) {
            $time = api_agent_resolve_entry_time($input, $duration);
            $worked_on = $time['worked_on'];
            $precision = $time['time_precision'];
            $started_at = $time['started_at'];
            $ended_at = $time['ended_at'];
            $source = (function_exists('is_ai_user') && is_ai_user($user['id'])) ? 'ai' : 'manual';
            $time_entry_id = add_manual_time_entry($ticket_id, $user['id'], [
                'comment_id' => (int) $comment_id,
                'worked_on' => $worked_on,
                'time_precision' => $precision,
                'has_clock_times' => $time['has_clock_times'],
                'started_at' => $started_at,
                'ended_at' => $ended_at,
                'duration_minutes' => $duration,
                'summary' => $input['time_summary'] ?? null,
                'is_billable' => isset($input['is_billable']) ? (int) !empty($input['is_billable']) : 1,
                'source' => $source,
            ]);
            if (!$time_entry_id) {
                throw new RuntimeException('Failed to add linked time entry');
            }
            db_update('comments', ['time_spent' => $duration], 'id = ?', [(int) $comment_id]);
        }
        db_commit_owned($owns_transaction, $db);
    } catch (InvalidArgumentException $e) {
        db_rollback_owned($owns_transaction, $db);
        api_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        db_rollback_owned($owns_transaction, $db);
        if ($e instanceof DomainException) ticket_workflow_api_error($ticket, $user, $e);
        api_error($duration > 0 ? 'Failed to add comment with time' : 'Failed to add comment', 500);
    }

    $response = ['ticket_id' => $ticket_id, 'comment_id' => (int) $comment_id];
    if ($time_entry_id) {
        $response['time_entry_id'] = (int) $time_entry_id;
        $response['duration_minutes'] = $duration;
        $response['worked_on'] = $worked_on;
        $response['time_precision'] = $precision;
        $response['started_at'] = !empty($time['has_clock_times']) ? $started_at : null;
        $response['ended_at'] = !empty($time['has_clock_times']) ? $ended_at : null;
    }

    $fresh = get_ticket($ticket_id);
    $response['workflow'] = ticket_workflow_metadata($fresh, $user);
    $response['timer_stopped'] = !empty($workflow_transition['timer_stopped']);
    $response['notifications'] = ticket_workflow_notify($workflow_before, $fresh, $user, !empty($is_internal) && empty($input['skip_notification']));
    if (!$is_internal) $response['notifications']['comment'] = ticket_workflow_comment_notify($fresh, ['content' => $input['content'], 'time_spent' => (int) $duration], $user, (int) $comment_id, [], [], empty($input['skip_notification']));
    api_success($response);
}

// =============================================================================
// DELETE COMMENT
// =============================================================================

function api_agent_delete_comment(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }
    if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('tickets:read')) {
        api_error('Missing required scope: tickets:read', 403);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = get_json_input();
    $comment_id = (int) ($input['comment_id'] ?? $input['id'] ?? 0);
    if ($comment_id <= 0) {
        api_error('comment_id is required.', 422);
    }

    $comment = db_fetch_one("SELECT * FROM comments WHERE id = ? LIMIT 1", [$comment_id]);
    if (!$comment) {
        api_success([
            'comment_id' => $comment_id,
            'deleted' => true,
            'already_deleted' => true,
        ]);
    }

    $ticket = get_ticket((int) $comment['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    $is_admin_delete = !empty($GLOBALS['is_api_token_auth']) && api_token_has_scope('delete:write');
    if (!can_manage_comment($comment, $user) && !$is_admin_delete) {
        api_error('Forbidden', 403);
    }

    $linked_attachments = db_fetch_all(
        "SELECT id FROM attachments WHERE comment_id = ?",
        [$comment_id]
    );
    $linked_time_entries = db_fetch_all(
        "SELECT id FROM ticket_time_entries WHERE comment_id = ?",
        [$comment_id]
    );

    $db = get_db();
    $owns_transaction = db_begin_owned($db);
    try {
        $undo_token = ticket_undo_stage('comment', $comment_id, (int) $comment['ticket_id'], (int) $user['id'], [
            'comment' => $comment,
            'attachment_ids' => array_values(array_map(static fn(array $row): int => (int) $row['id'], $linked_attachments)),
            'time_entry_ids' => array_values(array_map(static fn(array $row): int => (int) $row['id'], $linked_time_entries)),
        ]);
        db_delete('comments', 'id = ?', [$comment_id]);
        db_commit_owned($owns_transaction, $db);
        log_activity((int) $comment['ticket_id'], (int) $user['id'], 'comment_deleted', 'Comment deleted through Agent API');
    } catch (Throwable $e) {
        db_rollback_owned($owns_transaction, $db);
        api_error('Failed to delete comment.', 500);
    }

    api_success([
        'ticket_id' => (int) $comment['ticket_id'],
        'comment_id' => $comment_id,
        'deleted' => true,
        'undo_token' => $undo_token,
        'undo_seconds' => FOXDESK_TICKET_UNDO_SECONDS,
    ]);
}

// =============================================================================
// UPDATE STATUS
// =============================================================================

function api_agent_update_status()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Method not allowed', 405);
    require_csrf_token(true);
    $input = get_json_input();
    $user = current_user();
    if (!$user || !in_array($user['role'] ?? '', ['admin', 'agent'], true)) api_error('Forbidden', 403);
    if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('tickets:read')) api_error('Forbidden', 403);
    $ticket = api_agent_resolve_ticket($input, $user, 'ticket_hash', 'ticket_id');
    $status = !empty($input['status_id']) ? get_status((int) $input['status_id']) : api_agent_reference_row_by_name('statuses', (string) ($input['status'] ?? ''));
    if (!$status) api_error('Status not found', 422);
    try {
        $result = ticket_workflow_apply($ticket, $user, array_merge($input, [
            'operation' => 'status', 'status_id' => (int) $status['id'],
            'expected_revision' => $input['expected_revision'] ?? ticket_workflow_revision((int) $ticket['id']),
        ]));
    } catch (DomainException $error) {
        api_error($error->getMessage(), (int) $error->getCode() ?: 422);
    }
    api_success(array_merge($result, ['ticket_id' => (int) $ticket['id'], 'status_id' => (int) $status['id'], 'status' => $status['name']]));
}

// =============================================================================
// LOG TIME
// =============================================================================

function api_agent_log_time()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    // Duration is required
    if (empty($input['duration_minutes']) || (int) $input['duration_minutes'] < 1) {
        api_error('Field "duration_minutes" is required (positive integer)', 422);
    }

    $user = current_user();
    $ticket = api_agent_resolve_ticket($input, $user, 'ticket_hash', 'ticket_id');
    $ticket_id = (int) $ticket['id'];
    $duration = (int) $input['duration_minutes'];
    if (trim((string) ($input['worked_on'] ?? '')) === '' && !foxdesk_agent_plan_entry_has_clock_input($input)
        && trim((string) ($input['manual_date'] ?? '')) === '') {
        api_error('worked_on is required when exact work times are not known.', 422);
    }
    try {
        $time = api_agent_resolve_entry_time($input, $duration);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    $worked_on = $time['worked_on'];
    $precision = $time['time_precision'];
    $started_at = $time['started_at'];
    $ended_at = $time['ended_at'];
    $now = date('Y-m-d H:i:s');

    // Determine source: AI agent token defaults to 'ai', human token to 'manual'
    $default_source = (function_exists('is_ai_user') && is_ai_user($user['id'])) ? 'ai' : 'manual';
    $source = $input['source'] ?? $default_source;
    if (!in_array($source, ['timer', 'manual', 'ai'], true)) {
        $source = $default_source;
    }

    $data = [
        'worked_on' => $worked_on,
        'time_precision' => $precision,
        'has_clock_times' => $time['has_clock_times'],
        'started_at' => $started_at,
        'ended_at' => $ended_at,
        'duration_minutes' => $duration,
        'summary' => $input['summary'] ?? null,
        'is_billable' => isset($input['is_billable']) ? ($input['is_billable'] ? 1 : 0) : 1,
        'source' => $source,
    ];

    // Only admins may override billing rates through the API. Agent tokens use server-side rate rules.
    if (($user['role'] ?? '') === 'admin' && isset($input['billable_rate'])) {
        $data['billable_rate'] = (float) $input['billable_rate'];
    }

    if (function_exists('add_manual_time_entry')) {
        $entry_id = add_manual_time_entry($ticket_id, $user['id'], $data);
    } else {
        // Fallback direct insert
        $entry_id = db_insert('ticket_time_entries', array_merge($data, [
            'ticket_id' => $ticket_id,
            'user_id' => $user['id'],
            'is_manual' => ($source === 'timer') ? 0 : 1,
            'created_at' => $now,
        ]));
    }

    if (!$entry_id) {
        api_error('Failed to log time entry', 500);
    }

    api_success([
        'time_entry_id' => (int) $entry_id,
        'duration_minutes' => $duration,
        'worked_on' => $worked_on,
        'time_precision' => $precision,
        'started_at' => !empty($time['has_clock_times']) ? $started_at : null,
        'ended_at' => !empty($time['has_clock_times']) ? $ended_at : null,
        'source' => $source,
    ]);
}

/** One-line, human-readable description of a time entry for ticket history. */
function api_agent_time_entry_history_label(array $entry): string
{
    $label = (string) ($entry['worked_on'] ?? substr((string) ($entry['started_at'] ?? ''), 0, 10));
    if (function_exists('ticket_time_entry_has_clock_times') && ticket_time_entry_has_clock_times($entry)
        && !empty($entry['started_at']) && !empty($entry['ended_at'])) {
        $label .= ' ' . date('H:i', strtotime((string) $entry['started_at']))
            . '–' . date('H:i', strtotime((string) $entry['ended_at']));
    }
    $label .= ' · ' . (int) ($entry['duration_minutes'] ?? 0) . ' min';
    $label .= ' · ' . (string) ($entry['time_precision'] ?? 'exact');
    if (array_key_exists('is_billable', $entry) && empty($entry['is_billable'])) {
        $label .= ' · non-billable';
    }
    $summary = trim((string) ($entry['summary'] ?? ''));
    if ($summary !== '') {
        $label .= ' · ' . $summary;
    }
    return $label;
}

/**
 * Correct one saved time entry (start, end, duration, date, precision,
 * billable flag, summary) with the same validation as new work entries.
 */
function api_agent_update_work_entry(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }
    foreach (['tickets:read', 'time:write'] as $scope) {
        if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope($scope)) {
            api_error("Missing required scope: {$scope}", 403);
        }
    }
    if (!empty($GLOBALS['is_api_token_auth']) && function_exists('api_token_idempotency_key')
        && api_token_idempotency_key() === '') {
        api_error('Idempotency-Key header is required.', 422);
    }
    if (!function_exists('ticket_time_entry_resolve_update') || !ticket_time_table_exists()) {
        api_error('Time tracking is not available.', 400);
    }

    $input = get_json_input();
    api_agent_enforce_structured_temporal_text($input, ['time_summary', 'summary']);

    $entry_id = (int) ($input['time_entry_id'] ?? $input['entry_id'] ?? 0);
    if ($entry_id <= 0) {
        api_error('time_entry_id is required.', 422);
    }

    $user = current_user();
    $entry = db_fetch_one('SELECT * FROM ticket_time_entries WHERE id = ? LIMIT 1', [$entry_id]);
    if (!$entry) {
        api_error('Time entry not found.', 404);
    }
    $ticket = get_ticket((int) $entry['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Time entry not found.', 404);
    }
    if (!can_manage_time_entry($entry, $user)) {
        api_error('Forbidden', 403);
    }
    if (empty($entry['ended_at'])) {
        api_error('This time entry is a running timer. Stop the timer before correcting it.', 422);
    }
    if (array_key_exists('expected_duration_minutes', $input)
        && (int) $input['expected_duration_minutes'] !== (int) ($entry['duration_minutes'] ?? 0)) {
        api_error('The time entry changed since you read it. Read it again and retry.', 409, [
            'current_duration_minutes' => (int) ($entry['duration_minutes'] ?? 0),
        ]);
    }

    try {
        $updates = ticket_time_entry_resolve_update($entry, $input);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    if (array_key_exists('is_billable', $input)) {
        $updates['is_billable'] = foxdesk_agent_plan_bool($input, 'is_billable', true) ? 1 : 0;
    }
    $summary_key = array_key_exists('time_summary', $input) ? 'time_summary' : (array_key_exists('summary', $input) ? 'summary' : null);
    if ($summary_key !== null) {
        $summary = trim((string) ($input[$summary_key] ?? ''));
        $updates['summary'] = $summary !== '' ? mb_substr($summary, 0, 255) : null;
    }
    if (!ticket_time_work_metadata_columns_exist()) {
        unset($updates['worked_on'], $updates['time_precision']);
    }

    $changed = [];
    foreach ($updates as $field => $value) {
        $old = $entry[$field] ?? null;
        if ($field === 'has_clock_times') {
            $old = ticket_time_entry_has_clock_times($entry) ? 1 : 0;
        }
        if ((string) $old !== (string) $value) {
            $changed[] = $field;
        }
    }

    $after = array_merge($entry, $updates);
    $public = ticket_time_entry_public_timestamps($after);
    $response_entry = [
        'id' => $entry_id,
        'worked_on' => $after['worked_on'] ?? null,
        'time_precision' => ticket_time_entry_precision($after),
        'started_at' => $public['started_at'],
        'ended_at' => $public['ended_at'],
        'duration_minutes' => (int) $after['duration_minutes'],
        'is_billable' => !empty($after['is_billable']),
        'summary' => $after['summary'] ?? null,
    ];
    $ticket_id = (int) $entry['ticket_id'];

    if ($changed === []) {
        api_success([
            'ticket_id' => $ticket_id,
            'time_entry_id' => $entry_id,
            'updated' => false,
            'changed_fields' => [],
            'time_entry' => $response_entry,
        ]);
    }

    $db = get_db();
    $owns_transaction = false;
    try {
        $owns_transaction = db_begin_owned($db);
        if (!update_time_entry($entry_id, $updates)) {
            throw new RuntimeException('Failed to update time entry.');
        }
        if (!empty($entry['comment_id']) && in_array('duration_minutes', $changed, true)) {
            db_update('comments', ['time_spent' => (int) $updates['duration_minutes']], 'id = ? AND ticket_id = ?', [(int) $entry['comment_id'], $ticket_id]);
        }
        db_update('tickets', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$ticket_id]);
        if (function_exists('log_ticket_history')) {
            log_ticket_history(
                $ticket_id,
                (int) $user['id'],
                'time_entry',
                api_agent_time_entry_history_label($entry),
                api_agent_time_entry_history_label($after)
            );
        }
        log_activity($ticket_id, (int) $user['id'], 'time_updated', 'Time entry #' . $entry_id . ' corrected through the agent API');
        db_commit_owned($owns_transaction, $db);
    } catch (Throwable $e) {
        db_rollback_owned($owns_transaction, $db);
        api_error('Failed to update time entry.', 500);
    }

    api_success([
        'ticket_id' => $ticket_id,
        'time_entry_id' => $entry_id,
        'updated' => true,
        'changed_fields' => array_values(array_diff($changed, ['has_clock_times'])),
        'time_entry' => $response_entry,
    ]);
}

function api_agent_delete_ticket_preflight(): void
{
    $user = current_user();
    if (!$user || !can_permanently_delete_tickets($user)) {
        api_error('Forbidden', 403);
    }

    $ticket = api_agent_resolve_ticket($_GET, $user, 'hash', 'ticket_id');
    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    $preflight = ticket_permanent_delete_preflight((int) $ticket['id']);
    if (!$preflight) {
        api_error('Ticket not found', 404);
    }
    api_success(['preflight' => $preflight]);
}

function api_agent_delete_ticket_permanently(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user || !can_permanently_delete_tickets($user)) {
        api_error('Forbidden', 403);
    }
    if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('tickets:read')) {
        api_error('Missing required scope: tickets:read', 403);
    }
    if (function_exists('api_token_idempotency_key') && api_token_idempotency_key() === '') {
        api_error('Idempotency-Key header is required.', 422);
    }

    $input = get_json_input();
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    $confirmation = trim((string) ($input['confirmation'] ?? ''));
    if ($ticket_id <= 0 || $confirmation === '') {
        api_error('ticket_id and confirmation are required.', 422);
    }
    foreach (['delete_comments', 'delete_time_entries', 'delete_attachments'] as $flag) {
        if (array_key_exists($flag, $input) && $input[$flag] !== true && $input[$flag] !== 1) {
            api_error('Partial ticket deletion is not supported.', 422);
        }
    }

    $ticket = get_ticket($ticket_id);
    if ($ticket && !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    try {
        $result = ticket_permanent_delete(
            $ticket_id,
            $confirmation,
            $user,
            function_exists('api_token_request_id') ? api_token_request_id() : null
        );
        api_success($result);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), $e->getCode() ?: 422);
    } catch (RuntimeException $e) {
        $code = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 500;
        api_error($code === 500 ? 'Ticket could not be deleted safely.' : $e->getMessage(), $code);
    }
}
