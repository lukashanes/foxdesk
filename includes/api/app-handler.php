<?php
/**
 * API Handler: application shell contract.
 */

function api_app_contract_envelope(array $data, array $meta = []): array
{
    return [
        'data' => $data,
        'meta' => array_merge([
            'schema_version' => function_exists('app_contract_schema_version') ? app_contract_schema_version() : 1,
            'generated_at' => date('c'),
        ], $meta),
        'errors' => [],
    ];
}

function api_app_contract_success(array $data, array $meta = [], array $legacy = []): void
{
    api_success(array_merge($legacy, api_app_contract_envelope($data, $meta)));
}

function api_app_clamp_limit($value, int $default = 25, int $max = 100): int
{
    $limit = (int) ($value ?? $default);
    return max(1, min($max, $limit));
}

function api_app_require_write_auth(): void
{
    if (empty($GLOBALS['is_api_token_auth'])) {
        require_csrf_token(true);
    }
}

function api_app_require_timer_functions(): void
{
    if (!function_exists('get_active_ticket_timer') && defined('BASE_PATH') && file_exists(BASE_PATH . '/includes/ticket-time-functions.php')) {
        require_once BASE_PATH . '/includes/ticket-time-functions.php';
    }
}

function api_app_normalize_datetime_for_time($value, string $field): string
{
    if (!function_exists('foxdesk_normalize_backdated_datetime_input')) {
        api_error('Date normalization is not available.', 500);
    }

    $normalized = foxdesk_normalize_backdated_datetime_input($value);
    if ($normalized === false || $normalized === null) {
        api_error($field . ' is invalid.', 422);
    }

    return $normalized;
}

function api_app_normalize_worked_on($value): string
{
    $value = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0))) {
        api_error('worked_on must use YYYY-MM-DD.', 422);
    }
    return $date->format('Y-m-d');
}

function api_app_resolve_log_time_input(array $input): array
{
    $duration = (int) ($input['duration_minutes'] ?? 0);
    if ($duration < 1 || $duration > 1440) {
        api_error('duration_minutes must be between 1 and 1440.', 422);
    }

    $started_raw = trim((string) ($input['started_at'] ?? ''));
    $ended_raw = trim((string) ($input['ended_at'] ?? ''));
    $worked_on = trim((string) ($input['worked_on'] ?? ''));
    if ($worked_on !== '') {
        $worked_on = api_app_normalize_worked_on($worked_on);
    }
    $requested_precision = strtolower(trim((string) ($input['time_precision'] ?? '')));
    if ($requested_precision !== '' && !in_array($requested_precision, ['exact', 'duration_only', 'allocated'], true)) {
        api_error('time_precision must be exact, duration_only, or allocated.', 422);
    }
    $started_at = $started_raw !== '' ? api_app_normalize_datetime_for_time($started_raw, 'started_at') : null;
    $ended_at = $ended_raw !== '' ? api_app_normalize_datetime_for_time($ended_raw, 'ended_at') : null;

    if ($started_at !== null && $ended_at !== null) {
        $start_ts = strtotime($started_at);
        $end_ts = strtotime($ended_at);
        if (!$start_ts || !$end_ts || $end_ts <= $start_ts) {
            api_error('ended_at must be after started_at.', 422);
        }
        $computed_duration = max(1, (int) floor(($end_ts - $start_ts) / 60));
        if ($computed_duration !== $duration) {
            api_error('duration_minutes must match started_at and ended_at.', 422);
        }
    } elseif ($started_at !== null) {
        $ended_at = date('Y-m-d H:i:s', strtotime($started_at) + ($duration * 60));
    } elseif ($ended_at !== null) {
        $started_at = date('Y-m-d H:i:s', strtotime($ended_at) - ($duration * 60));
    } else {
        if ($requested_precision === 'exact') {
            api_error('time_precision exact requires started_at or ended_at.', 422);
        }
        if ($worked_on === '') {
            $worked_on = date('Y-m-d');
        }
        $ended_at = $worked_on . ' 12:00:00';
        $started_at = date('Y-m-d H:i:s', strtotime($ended_at) - ($duration * 60));
    }

    $precision = ($started_raw !== '' || $ended_raw !== '')
        ? 'exact'
        : ($requested_precision !== '' ? $requested_precision : 'duration_only');
    if ($worked_on === '') {
        $worked_on = date('Y-m-d', strtotime((string) $started_at));
    }

    return [
        'started_at' => $started_at,
        'ended_at' => $ended_at,
        'worked_on' => $worked_on,
        'time_precision' => $precision,
        'duration_minutes' => $duration,
    ];
}

function api_app_resolve_ticket(array $source, array $user)
{
    $hash = trim((string) ($source['hash'] ?? $source['ticket_hash'] ?? ''));
    $ticket_id = (int) ($source['id'] ?? $source['ticket_id'] ?? 0);
    if ($hash !== '') {
        $ticket = get_ticket_by_hash($hash);
    } elseif ($ticket_id > 0) {
        $ticket = get_ticket($ticket_id);
    } else {
        api_error('Provide ticket hash or id.', 422);
    }

    if (!$ticket) {
        api_error('Ticket not found.', 404);
    }
    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    return $ticket;
}

function api_app_shell()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('app_shell_payload')) {
        api_error('App shell is not available.', 500);
    }

    $app_shell = app_shell_payload($user);
    api_app_contract_success(
        ['app_shell' => $app_shell],
        ['resource' => 'app_shell'],
        ['app_shell' => $app_shell]
    );
}

function api_app_home()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('app_shell_payload') || !function_exists('app_feed_payload')) {
        api_error('App home is not available.', 500);
    }

    $limit = (int) ($_GET['limit'] ?? 5);

    $app_shell = app_shell_payload($user);
    $home = app_feed_payload($user, $limit);

    api_app_contract_success(
        ['app_shell' => $app_shell, 'home' => $home],
        ['resource' => 'app_home'],
        ['app_shell' => $app_shell, 'home' => $home]
    );
}

function api_app_ticket_list()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!function_exists('get_tickets') || !function_exists('ticket_list_view_apply_filters')) {
        api_error('Ticket list contract is not available.', 500);
    }

    $limit = api_app_clamp_limit($_GET['limit'] ?? null, 25, 100);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $view = ticket_list_view_normalize($_GET['view'] ?? $_GET['work_view'] ?? 'open', true);
    $base_filters = app_contract_ticket_filters_from_request($_GET, $user, $limit, $offset);
    $filters = ticket_list_view_apply_filters($base_filters, $view);

    $count_filters = $filters;
    unset($count_filters['limit'], $count_filters['offset']);

    $count_base_filters = $base_filters;
    unset($count_base_filters['limit'], $count_base_filters['offset']);

    $tickets = array_map('app_contract_ticket_list_item', get_tickets($filters));
    $total = function_exists('get_tickets_count') ? get_tickets_count($count_filters) : count($tickets);
    $counts = function_exists('ticket_list_view_counts') ? ticket_list_view_counts($count_base_filters, true) : [];
    $views = function_exists('ticket_list_view_definitions') ? ticket_list_view_definitions(true) : [];

    api_app_contract_success([
        'tickets' => $tickets,
        'view' => $view,
        'views' => $views,
        'counts' => $counts,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ],
        'filters' => [
            'search' => (string) ($_GET['search'] ?? ''),
            'sort' => (string) ($base_filters['sort'] ?? 'last_updated'),
        ],
    ], ['resource' => 'ticket_list']);
}

function api_app_ticket_actions()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $ticket = api_app_resolve_ticket($_GET, $user);
    api_app_contract_success([
        'ticket' => app_contract_ticket_payload($ticket),
        'actions' => app_contract_ticket_actions($ticket, $user),
    ], ['resource' => 'ticket_actions']);
}

function api_app_ticket_comments(int $ticket_id, bool $include_internal): array
{
    $comments = [];
    foreach (function_exists('get_ticket_comments') ? get_ticket_comments($ticket_id) : [] as $comment) {
        if (!$include_internal && !empty($comment['is_internal'])) {
            continue;
        }

        $comments[] = [
            'id' => (int) ($comment['id'] ?? 0),
            'user_id' => (int) ($comment['user_id'] ?? 0),
            'author_name' => trim((string) (($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''))),
            'author_email' => $comment['email'] ?? null,
            'content_html' => (string) ($comment['content'] ?? ''),
            'content_text' => trim(strip_tags((string) ($comment['content'] ?? ''))),
            'is_internal' => !empty($comment['is_internal']),
            'created_at' => $comment['created_at'] ?? null,
        ];
    }

    return $comments;
}

function api_app_ticket_attachments(int $ticket_id, array $visible_comments): array
{
    $visible_comment_ids = [];
    foreach ($visible_comments as $comment) {
        $visible_comment_ids[(int) ($comment['id'] ?? 0)] = true;
    }

    $attachments = [];
    foreach (function_exists('get_ticket_attachments') ? get_ticket_attachments($ticket_id) : [] as $attachment) {
        $comment_id = (int) ($attachment['comment_id'] ?? 0);
        if ($comment_id > 0 && !isset($visible_comment_ids[$comment_id])) {
            continue;
        }

        $attachments[] = function_exists('app_contract_attachment_payload')
            ? app_contract_attachment_payload($attachment)
            : [
                'id' => (int) ($attachment['id'] ?? 0),
                'comment_id' => $comment_id ?: null,
                'filename' => (string) ($attachment['original_name'] ?? $attachment['filename'] ?? ''),
                'mime_type' => $attachment['mime_type'] ?? null,
                'file_size' => isset($attachment['file_size']) ? (int) $attachment['file_size'] : null,
                'download_url' => function_exists('attachment_download_url') ? attachment_download_url($attachment) : '',
                'created_at' => $attachment['created_at'] ?? null,
            ];
    }

    return $attachments;
}

function api_app_resolve_attachment(array $source, array $user): array
{
    $attachment_id = (int) ($source['attachment_id'] ?? $source['id'] ?? 0);
    if ($attachment_id <= 0) {
        api_error('Missing attachment_id.', 422);
    }

    if (!function_exists('get_attachment') || !function_exists('attachment_user_can_access')) {
        api_error('Attachment metadata is not available.', 500);
    }

    $attachment = get_attachment($attachment_id);
    if (!$attachment) {
        api_error('Attachment not found.', 404);
    }

    if (!empty($attachment['comment_id']) && !array_key_exists('comment_is_internal', $attachment)) {
        try {
            $comment = db_fetch_one('SELECT is_internal FROM comments WHERE id = ? LIMIT 1', [(int) $attachment['comment_id']]);
            $attachment['comment_is_internal'] = !empty($comment['is_internal']);
        } catch (Throwable $e) {
            $attachment['comment_is_internal'] = false;
        }
    }

    if (!attachment_user_can_access($attachment, $user)) {
        if (function_exists('log_security_event')) {
            log_security_event('app_attachment_access_denied', (int) ($user['id'] ?? 0), json_encode([
                'attachment_id' => $attachment_id,
                'ticket_id' => (int) ($attachment['ticket_id'] ?? 0),
            ]));
        }
        api_error('Forbidden', 403);
    }

    return $attachment;
}

function api_app_attachment_metadata()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $attachment = api_app_resolve_attachment($_GET, $user);

    api_app_contract_success([
        'attachment' => app_contract_attachment_payload($attachment),
    ], ['resource' => 'attachment_metadata']);
}

function api_app_ticket_time_entries(int $ticket_id): array
{
    if (!is_agent() || !function_exists('get_ticket_time_entries')) {
        return [];
    }

    $entries = [];
    foreach (get_ticket_time_entries($ticket_id) as $entry) {
        $public_timestamps = function_exists('ticket_time_entry_public_timestamps')
            ? ticket_time_entry_public_timestamps($entry)
            : ['started_at' => $entry['started_at'] ?? null, 'ended_at' => $entry['ended_at'] ?? null];
        $entries[] = [
            'id' => (int) ($entry['id'] ?? 0),
            'comment_id' => !empty($entry['comment_id']) ? (int) $entry['comment_id'] : null,
            'user_name' => trim((string) (($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''))),
            'worked_on' => $entry['worked_on'] ?? (!empty($entry['started_at']) ? date('Y-m-d', strtotime((string) $entry['started_at'])) : null),
            'time_precision' => function_exists('ticket_time_entry_precision') ? ticket_time_entry_precision($entry) : 'exact',
            'started_at' => $public_timestamps['started_at'],
            'ended_at' => $public_timestamps['ended_at'],
            'duration_minutes' => (int) ($entry['duration_minutes'] ?? 0),
            'summary' => $entry['summary'] ?? null,
            'is_billable' => !empty($entry['is_billable']),
        ];
    }

    return $entries;
}

function api_app_ticket_detail()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $ticket = api_app_resolve_ticket($_GET, $user);
    $ticket_id = (int) $ticket['id'];
    $comments = api_app_ticket_comments($ticket_id, is_agent());

    $payload = [
        'ticket' => app_contract_ticket_payload($ticket),
        'comments' => $comments,
        'attachments' => api_app_ticket_attachments($ticket_id, $comments),
        'time_entries' => api_app_ticket_time_entries($ticket_id),
        'actions' => app_contract_ticket_actions($ticket, $user),
    ];

    api_app_contract_success($payload, ['resource' => 'ticket_detail'], $payload);
}

function api_app_create_ticket()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    api_app_require_write_auth();

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = get_json_input();
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        api_error('Ticket title is required.', 422);
    }

    $owner_id = isset($input['user_id']) ? (int) $input['user_id'] : (int) $user['id'];
    $owner = get_user($owner_id);
    if (!$owner || !can_user_create_ticket_for($owner, $user)) {
        api_error('Forbidden', 403);
    }

    $data = [
        'title' => $title,
        'description' => (string) ($input['description'] ?? ''),
        'user_id' => $owner_id,
        'type' => (string) ($input['type'] ?? 'general'),
    ];

    if (!empty($input['status_id'])) {
        if (!get_status((int) $input['status_id'])) {
            api_error('Status not found.', 404);
        }
        $data['status_id'] = (int) $input['status_id'];
    }
    if (!empty($input['priority_id'])) {
        if (!get_priority((int) $input['priority_id'])) {
            api_error('Priority not found.', 404);
        }
        $data['priority_id'] = (int) $input['priority_id'];
    }
    if (array_key_exists('organization_id', $input)) {
        $organization_id = $input['organization_id'] ? (int) $input['organization_id'] : null;
        if ($organization_id !== null && !can_user_use_organization($organization_id, $user)) {
            api_error('Forbidden', 403);
        }
        $data['organization_id'] = $organization_id;
    }
    if (!empty($input['assignee_id'])) {
        $assignee = get_user((int) $input['assignee_id']);
        if (!$assignee || !can_user_assign_to_staff($assignee, $user)) {
            api_error('Forbidden', 403);
        }
        $data['assignee_id'] = (int) $input['assignee_id'];
    }
    if (!empty($input['due_date'])) {
        $due_date = normalize_due_date_input($input['due_date']);
        if ($due_date === false) {
            api_error('Due date is invalid.', 422);
        }
        $data['due_date'] = $due_date;
    }
    if (!empty($input['tags'])) {
        $data['tags'] = function_exists('normalize_ticket_tags')
            ? normalize_ticket_tags((string) $input['tags'])
            : (string) $input['tags'];
    }

    $ticket_id = create_ticket($data);
    if (!$ticket_id) {
        api_error('Failed to create ticket.', 500);
    }

    $ticket = get_ticket((int) $ticket_id);
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.created', (int) $ticket_id, (int) $user['id'], [
            'comment_preview' => mb_substr(trim(strip_tags((string) ($input['description'] ?? ''))), 0, 80),
        ]);
    }

    $response = [
        'ticket_id' => (int) $ticket_id,
        'ticket_hash' => $ticket['hash'] ?? null,
        'ticket_code' => function_exists('get_ticket_code') ? get_ticket_code((int) $ticket_id) : ('TK-' . (int) $ticket_id),
        'ticket' => $ticket ? app_contract_ticket_payload($ticket) : null,
    ];

    api_app_contract_success($response, ['resource' => 'create_ticket'], $response);
}

function api_app_add_comment()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    api_app_require_write_auth();

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = get_json_input();
    $content = trim((string) ($input['content'] ?? ''));
    if ($content === '') {
        api_error('Comment content is required.', 422);
    }

    $ticket = api_app_resolve_ticket($input, $user);
    $is_internal = !empty($input['is_internal']) && is_agent();
    $comment_id = add_comment((int) $ticket['id'], (int) $user['id'], $content, $is_internal ? 1 : 0);
    if (!$comment_id) {
        api_error('Failed to add comment.', 500);
    }

    api_app_contract_success(['comment_id' => (int) $comment_id], ['resource' => 'ticket_comment'], ['comment_id' => (int) $comment_id]);
}

function api_app_log_time()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    api_app_require_write_auth();

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!is_agent() && !is_admin()) {
        api_error('Forbidden', 403);
    }

    $input = get_json_input();
    $time_input = api_app_resolve_log_time_input($input);
    $duration = (int) $time_input['duration_minutes'];

    $ticket = api_app_resolve_ticket($input, $user);
    if (!function_exists('add_manual_time_entry')) {
        api_error('Time tracking is not available.', 400);
    }

    $entry_id = add_manual_time_entry((int) $ticket['id'], (int) $user['id'], [
        'comment_id' => api_app_time_comment_id($input, (int) $ticket['id']),
        'worked_on' => $time_input['worked_on'],
        'time_precision' => $time_input['time_precision'],
        'started_at' => $time_input['started_at'],
        'ended_at' => $time_input['ended_at'],
        'duration_minutes' => $duration,
        'summary' => $input['summary'] ?? null,
        'is_billable' => isset($input['is_billable']) ? (!empty($input['is_billable']) ? 1 : 0) : 1,
        'source' => (function_exists('is_ai_user') && is_ai_user((int) $user['id'])) ? 'ai' : 'manual',
    ]);

    if (!$entry_id) {
        api_error('Failed to log time.', 500);
    }

    $response = [
        'ticket' => app_contract_ticket_payload($ticket),
        'time_entry_id' => (int) $entry_id,
        'duration_minutes' => $duration,
        'worked_on' => $time_input['worked_on'],
        'time_precision' => $time_input['time_precision'],
        'started_at' => $time_input['time_precision'] === 'exact' ? $time_input['started_at'] : null,
        'ended_at' => $time_input['time_precision'] === 'exact' ? $time_input['ended_at'] : null,
    ];

    api_app_contract_success($response, ['resource' => 'log_time'], $response);
}

function api_app_ticket_timer()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!is_agent() && !is_admin()) {
        api_error('Forbidden', 403);
    }

    api_app_require_timer_functions();
    $ticket = api_app_resolve_ticket($_GET, $user);

    api_app_contract_success([
        'ticket' => app_contract_ticket_payload($ticket),
        'timer' => app_contract_ticket_timer((int) $ticket['id'], $user),
    ], ['resource' => 'ticket_timer']);
}

function api_app_ticket_timer_action()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    api_app_require_write_auth();

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!is_agent() && !is_admin()) {
        api_error('Forbidden', 403);
    }
    api_app_require_timer_functions();
    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        api_error('Time tracking is not available.', 400);
    }

    $input = get_json_input();
    $ticket = api_app_resolve_ticket($input, $user);
    $ticket_id = (int) $ticket['id'];
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $result = ['success' => false];

    if ($action === 'start') {
        if (ticket_detail_is_done($ticket) || !empty($ticket['is_archived'])) api_error(t('workflow.reopen'), 409);
        $active = get_active_ticket_timer($ticket_id, (int) $user['id']);
        if ($active) {
            api_error('Timer is already running.', 400);
        }

        $ticket_billable_rate = function_exists('get_ticket_effective_billable_rate')
            ? get_ticket_effective_billable_rate($ticket, (int) $user['id'])
            : 0.0;
        $entry_id = (int) db_insert('ticket_time_entries', [
            'ticket_id' => $ticket_id,
            'user_id' => (int) $user['id'],
            'started_at' => date('Y-m-d H:i:s'),
            'ended_at' => null,
            'duration_minutes' => 0,
            'is_billable' => 1,
            'billable_rate' => $ticket_billable_rate,
            'cost_rate' => (float) ($user['cost_rate'] ?? 0),
            'is_manual' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        log_activity($ticket_id, (int) $user['id'], 'time_started', 'Timer started');
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, (int) $user['id'], 'timer_started', null, date('Y-m-d H:i:s'));
        }
        $result = ['success' => true, 'entry_id' => $entry_id];
    } elseif ($action === 'pause') {
        $result = pause_ticket_timer($ticket_id, (int) $user['id']);
        if (!empty($result['success'])) {
            log_activity($ticket_id, (int) $user['id'], 'time_paused', 'Timer paused');
        }
    } elseif ($action === 'resume') {
        $result = resume_ticket_timer($ticket_id, (int) $user['id']);
        if (!empty($result['success'])) {
            log_activity($ticket_id, (int) $user['id'], 'time_resumed', 'Timer resumed');
        }
    } elseif ($action === 'stop') {
        $active = get_active_ticket_timer($ticket_id, (int) $user['id']);
        if (!$active) {
            api_error('No active timer found.', 400);
        }
        $elapsed = calculate_timer_elapsed($active);
        $duration = max(1, (int) floor($elapsed / 60));
        db_update('ticket_time_entries', [
            'ended_at' => date('Y-m-d H:i:s'),
            'duration_minutes' => $duration,
            'paused_at' => null,
        ], 'id = ?', [(int) $active['id']]);
        log_activity($ticket_id, (int) $user['id'], 'time_stopped', "Timer stopped ({$duration} min)");
        notify_saved_time_entry((int) $active['id'], (int) $user['id']);
        $result = [
            'success' => true,
            'entry_id' => (int) $active['id'],
            'duration_minutes' => $duration,
        ];
    } elseif ($action === 'discard') {
        $result = discard_ticket_timer($ticket_id, (int) $user['id']);
        if (!empty($result['success'])) {
            log_activity($ticket_id, (int) $user['id'], 'time_discarded', 'Timer discarded');
        }
    } else {
        api_error('Unsupported timer action.', 422);
    }

    if (empty($result['success'])) {
        api_error((string) ($result['error'] ?? 'Timer action failed.'), 400);
    }

    api_app_contract_success([
        'ticket' => app_contract_ticket_payload($ticket),
        'timer' => app_contract_ticket_timer($ticket_id, $user),
        'action' => $action,
        'result' => $result,
    ], ['resource' => 'timer_action']);
}

function api_app_client_overview()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!is_admin() && !is_agent()) {
        api_error('Forbidden', 403);
    }
    if (!function_exists('client_overview')) {
        api_error('Client overview is not available.', 500);
    }

    $organization_id = (int) ($_GET['organization_id'] ?? $_GET['client_id'] ?? 0);
    if ($organization_id <= 0) {
        api_error('Missing organization_id', 422);
    }
    if (function_exists('can_user_use_organization') && !can_user_use_organization($organization_id, $user)) {
        api_error('Forbidden', 403);
    }

    $view = function_exists('ticket_list_view_normalize')
        ? ticket_list_view_normalize($_GET['view'] ?? 'open', true)
        : 'open';
    $overview = client_overview($organization_id, $view);
    if (!$overview) {
        api_error('Client not found.', 404);
    }

    api_app_contract_success(
        app_contract_client_overview_payload($overview, $view),
        ['resource' => 'client_overview']
    );
}

function api_app_reporting_review()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!is_admin() && (!function_exists('can_view_time') || !can_view_time($user))) {
        api_error('Forbidden', 403);
    }
    if (!function_exists('billing_review_payload') || !function_exists('billing_review_filters_from_request')) {
        api_error('Billing review is not available.', 500);
    }

    $limit = api_app_clamp_limit($_GET['limit'] ?? null, 100, 250);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $filters = billing_review_filters_from_request($_GET);
    $payload = billing_review_payload($filters, $user, $limit, $offset);

    api_app_contract_success($payload, ['resource' => 'reporting_review']);
}

function api_app_notifications_summary()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $limit = api_app_clamp_limit($_GET['limit'] ?? null, 10, 25);
    $result = function_exists('get_user_notifications')
        ? get_user_notifications((int) $user['id'], $limit, 0, true)
        : ['notifications' => [], 'unread_count' => 0];

    api_app_contract_success([
        'unread_count' => (int) ($result['unread_count'] ?? 0),
        'items' => array_map('app_contract_notification_summary_item', $result['notifications'] ?? []),
    ], ['resource' => 'notifications_summary']);
}

function api_app_notifications()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $limit = api_app_clamp_limit($_GET['limit'] ?? null, 25, 100);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $include_resolved = (int) ($_GET['include_resolved'] ?? 0) === 1;
    $result = function_exists('get_user_notifications')
        ? get_user_notifications((int) $user['id'], $limit, $offset, !$include_resolved)
        : ['notifications' => [], 'unread_count' => 0];
    $items = array_map('app_contract_notification_summary_item', $result['notifications'] ?? []);

    api_app_contract_success([
        'unread_count' => (int) ($result['unread_count'] ?? 0),
        'items' => $items,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => count($items) === $limit,
        ],
    ], ['resource' => 'notifications']);
}

function api_app_notification_read_state()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    api_app_require_write_auth();

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = get_json_input();
    $scope = strtolower(trim((string) ($input['scope'] ?? 'notification')));
    $is_read = array_key_exists('is_read', $input) ? (bool) $input['is_read'] : true;
    $updated = false;

    if ($scope === 'notification') {
        $notification_id = (int) ($input['notification_id'] ?? $input['id'] ?? 0);
        if ($notification_id <= 0) {
            api_error('Missing notification_id.', 422);
        }
        $updated = $is_read
            ? mark_notification_read($notification_id, (int) $user['id'])
            : (function_exists('mark_notification_unread') && mark_notification_unread($notification_id, (int) $user['id']));
    } elseif ($scope === 'ticket') {
        if (!$is_read) {
            api_error('Ticket notification groups can only be marked read.', 422);
        }
        $ticket_id = (int) ($input['ticket_id'] ?? 0);
        if ($ticket_id <= 0) {
            api_error('Missing ticket_id.', 422);
        }
        $updated = mark_ticket_notifications_read($ticket_id, (int) $user['id']);
    } elseif ($scope === 'all') {
        if (!$is_read) {
            api_error('All notifications can only be marked read.', 422);
        }
        $updated = mark_all_notifications_read((int) $user['id']);
    } else {
        api_error('Unsupported notification scope.', 422);
    }

    api_app_contract_success([
        'unread_count' => function_exists('get_unread_notification_count')
            ? get_unread_notification_count((int) $user['id'])
            : 0,
        'updated' => (bool) $updated,
    ], ['resource' => 'notification_read_state']);
}
