<?php
/** Shared, permission-checked workflow for the web UI and agent API. */
function ticket_workflow_revision(int $ticket_id): string
{
    // Locking reads must see current committed data, even under REPEATABLE READ.
    $lock = get_db()->inTransaction() ? ' FOR UPDATE' : '';
    try {
    $row = db_fetch_one('SELECT * FROM tickets WHERE id = ?' . $lock, [$ticket_id]);
    $activity = db_fetch_one("SELECT id AS revision FROM activity_log WHERE ticket_id = ? AND action NOT LIKE 'time\\_%' ORDER BY id DESC LIMIT 1" . $lock, [$ticket_id]);
    $comment = db_fetch_one('SELECT id AS revision FROM comments WHERE ticket_id = ? ORDER BY id DESC LIMIT 1' . $lock, [$ticket_id]);
    return hash('sha256', json_encode([$row, $activity['revision'] ?? 0, $comment['revision'] ?? 0]));
    } catch (PDOException $error) {
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1020, 1205, 1213], true)) throw new DomainException(t('workflow.conflict'), 409, $error);
        throw $error;
    }
}

function ticket_workflow_lock(array $ticket, array $user, string $expected = ''): array
{
    if (!get_db()->inTransaction()) {
        throw new LogicException('Workflow lock requires a transaction.');
    }
    if (!can_see_ticket($ticket, $user)) {
        throw new DomainException('Forbidden', 403);
    }
    $locked = db_fetch_one('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [(int) $ticket['id']]);
    $fresh = $locked ? array_merge($ticket, $locked) : null;
    if (!$fresh || !can_see_ticket($fresh, $user)) {
        throw new DomainException('Forbidden', 403);
    }
    if ($expected !== '' && !hash_equals(ticket_workflow_revision((int) $ticket['id']), $expected)) {
        throw new DomainException(t('workflow.conflict'), 409);
    }
    return $fresh;
}

function ticket_workflow_target(array $statuses, string $group): ?int
{
    if ($group === 'done') return ticket_detail_first_done_status_id($statuses);
    $fallback = null;
    foreach ($statuses as $status) {
        if (ticket_status_group_from_status($status) !== $group || !empty($status['is_closed'])) continue;
        $fallback = $fallback ?? (int) $status['id'];
        if (!empty($status['is_default'])) return (int) $status['id'];
    }
    return $fallback;
}

function ticket_workflow_metadata(array $ticket, array $user): array
{
    $editable = in_array($user['role'] ?? '', ['admin', 'agent'], true) && can_edit_ticket($ticket, $user);
    if (!empty($GLOBALS['is_api_token_auth'])) {
        $editable = $editable && api_token_has_scope('tickets:write');
    }
    $statuses = get_statuses();
    $timer = function_exists('ticket_time_table_exists') && ticket_time_table_exists()
        ? get_active_ticket_timer((int) $ticket['id'], (int) $user['id']) : null;
    $targets = [];
    foreach (['active', 'waiting', 'done'] as $group) $targets[$group] = ticket_workflow_target($statuses, $group);
    return [
        'revision' => ticket_workflow_revision((int) $ticket['id']),
        'ticket_id' => (int) $ticket['id'],
        'ticket_hash' => $ticket['hash'] ?? null,
        'status_id' => (int) $ticket['status_id'],
        'assignee_id' => !empty($ticket['assignee_id']) ? (int) $ticket['assignee_id'] : null,
        'status_group' => ticket_status_group_for_status_id((int) $ticket['status_id']),
        'executor' => ['user_id' => (int) $user['id'], 'is_ai' => !empty($user['is_ai_agent'])],
        'timer' => $timer ? ['entry_id' => (int) $timer['id'], 'state' => !empty($timer['paused_at']) ? 'paused' : 'running'] : null,
        'targets' => $targets,
        'allowed_actions' => $editable && empty($ticket['is_archived']) ? array_values(array_filter([
            'status', 'claim', 'assign',
            $targets['done'] && (!ticket_detail_is_done($ticket) || $timer) ? 'complete' : null,
            $targets['active'] && ticket_detail_is_done($ticket) ? 'reopen' : null,
        ])) : [],
        'statuses' => array_map(static function ($status) {
            return ['id' => (int) $status['id'], 'name' => $status['name'], 'group' => ticket_status_group_from_status($status)];
        }, $editable ? $statuses : []),
    ];
}

function ticket_workflow_notify(array $before, array $after, array $user, bool $email = true): array
{
    if (get_db()->inTransaction()) {
        ticket_permanent_delete_register_after_commit(static function () use ($before, $after, $user, $email) {
            ticket_workflow_notify($before, $after, $user, $email);
        });
        return ['dispatch' => 'after_commit'];
    }
    $changed = (int) $before['status_id'] !== (int) $after['status_id'];
    $result = ['in_app' => 'not_requested', 'email' => 'not_requested'];
    if (!$changed) {
        if (!empty($after['assignee_id']) && (int) ($before['assignee_id'] ?? 0) !== (int) $after['assignee_id']) {
            try {
                ticket_event_dispatch_in_app('ticket.assigned', (int) $after['id'], (int) $user['id'], ['assignee_id' => (int) $after['assignee_id']]);
                $result['in_app'] = 'processed';
                if ($email && (int) $after['assignee_id'] !== (int) $user['id']) {
                    require_once BASE_PATH . '/includes/mailer.php';
                    $sent = send_ticket_assignment_notification($after, get_user((int) $after['assignee_id']), $user);
                    $result['email'] = $sent === false ? 'not_sent' : 'processed';
                }
            } catch (Throwable $error) { error_log('Assignment notification failed: ' . $error->getMessage()); $result['error'] = 'notification_failed'; }
        }
        return $result;
    }
    $old = get_status((int) $before['status_id']);
    $new = get_status((int) $after['status_id']);
    try {
        if (function_exists('ticket_event_dispatch_in_app')) {
            ticket_event_dispatch_in_app('ticket.status_changed', (int) $after['id'], (int) $user['id'], [
                'old_status' => $old['name'] ?? '', 'new_status' => $new['name'] ?? '',
            ]);
            $result['in_app'] = 'processed';
        }
        if ($email) {
            require_once BASE_PATH . '/includes/mailer.php';
            $sent = send_status_change_notification($after, $old, $new);
            $result['email'] = $sent === false ? 'not_sent' : 'processed';
        }
        if (ticket_detail_is_done($after) && function_exists('resolve_action_notifications')) resolve_action_notifications((int) $after['id']);
    } catch (Throwable $error) {
        error_log('Workflow notification failed: ' . $error->getMessage());
        $result['error'] = 'notification_failed';
    }
    return $result;
}

function ticket_workflow_apply(array $ticket, array $user, array $input): array
{
    $action = (string) ($input['operation'] ?? 'status');
    $meta = ticket_workflow_metadata($ticket, $user);
    $expected = (string) ($input['expected_revision'] ?? '');
    if ($expected === '' || !hash_equals($meta['revision'], $expected)) throw new DomainException(t('workflow.conflict'), 409);
    if (!in_array($action, $meta['allowed_actions'], true)) throw new DomainException('Forbidden', 403);
    $status_id = $action === 'complete' ? $meta['targets']['done'] : ($action === 'reopen' ? $meta['targets']['active'] : (int) ($input['status_id'] ?? 0));
    $status = in_array($action, ['status', 'complete', 'reopen'], true) && $status_id ? get_status($status_id) : null;
    if (in_array($action, ['status', 'complete', 'reopen'], true) && !$status) throw new DomainException('Status not found', 422);
    if ($status && ticket_status_group_from_status($status) === 'done' && $meta['timer']
        && !empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('time:write')) throw new DomainException('Missing required scope: time:write', 403);
    if (trim((string) ($input['handoff_note'] ?? '')) !== '' && !empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope('comments:write')) throw new DomainException('Missing required scope: comments:write', 403);
    $assignee = null;
    if (in_array($action, ['claim', 'assign'], true)) {
        $assignee = $action === 'claim' ? $user : get_user((int) ($input['assignee_id'] ?? 0));
        if (!$assignee || empty($assignee['is_active']) || !in_array($assignee['role'] ?? '', ['agent', 'admin'], true)
            || (!empty($user['tenant_id']) && (int) ($assignee['tenant_id'] ?? 0) !== (int) $user['tenant_id'])) throw new DomainException('User not found', 422);
        if (function_exists('can_user_assign_to_staff') && !can_user_assign_to_staff($assignee, $user)) throw new DomainException('Forbidden', 403);
    }
    $db = get_db();
    $owns_transaction = db_begin_owned($db);
    try {
        $before = ticket_workflow_lock($ticket, $user, $expected);
        if (!can_edit_ticket($before, $user) || !empty($before['is_archived'])) throw new DomainException('Forbidden', 403);
        $transition = [];
        if ($status) $transition = ticket_transition_status($before, get_status((int) $before['status_id']), $status, (int) $user['id']);
        if ($assignee && (int) ($before['assignee_id'] ?? 0) !== (int) $assignee['id']) {
            db_update('tickets', ['assignee_id' => (int) $assignee['id'], 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $ticket['id']]);
            log_activity((int) $ticket['id'], (int) $user['id'], 'assigned', 'Assigned to user ' . (int) $assignee['id']);
            if (function_exists('log_ticket_history')) log_ticket_history((int) $ticket['id'], (int) $user['id'], 'assignee_changed', $before['assignee_id'] ?? null, (int) $assignee['id']);
        }
        $handoff_comment_id = null;
        $handoff_note = trim((string) ($input['handoff_note'] ?? ''));
        if ($assignee && $handoff_note !== '') {
            $handoff_comment_id = add_comment((int) $ticket['id'], (int) $user['id'], safe_html($handoff_note), 1);
            if (!$handoff_comment_id) throw new RuntimeException('Failed to save handoff note.');
            log_activity((int) $ticket['id'], (int) $user['id'], 'commented', 'Internal handoff note added');
        }
        $after = get_ticket((int) $ticket['id']);
        $result = ['handoff_comment_id' => $handoff_comment_id, 'workflow' => ticket_workflow_metadata($after, $user), 'timer_stopped' => !empty($transition['timer_stopped']), 'previous_status_id' => (int) $before['status_id']];
        db_commit_owned($owns_transaction, $db);
    } catch (Throwable $error) {
        db_rollback_owned($owns_transaction, $db);
        throw $error;
    }
    $result['notifications'] = ticket_workflow_notify($before, $after, $user, empty($input['skip_notification']));
    return $result;
}

/** Delivery follows commit; an email failure must not undo an already saved reply. */
function ticket_workflow_comment_notify(array $ticket, array $comment, array $user, int $comment_id, array $attachments = [], array $cc = [], bool $email = true): array
{
    if (get_db()->inTransaction()) {
        ticket_permanent_delete_register_after_commit(static function () use ($ticket, $comment, $user, $comment_id, $attachments, $cc, $email) {
            ticket_workflow_comment_notify($ticket, $comment, $user, $comment_id, $attachments, $cc, $email);
        });
        return ['dispatch' => 'after_commit'];
    }
    $result = ['in_app' => 'not_sent', 'email' => 'not_requested'];
    try {
        ticket_event_dispatch_in_app(ticket_event_comment_name($user, false), (int) $ticket['id'], (int) $user['id'], [
            'comment_preview' => mb_substr(strip_tags($comment['content'] ?? ''), 0, 80), 'comment_id' => $comment_id,
        ]);
        $result['in_app'] = 'processed';
    } catch (Throwable $error) { error_log('Comment in-app notification failed: ' . $error->getMessage()); }
    if ($email) {
        try {
            require_once BASE_PATH . '/includes/mailer.php';
            $result['email'] = send_new_comment_notification($ticket, $comment, $user, $comment_id, $attachments, $cc) === false ? 'not_sent' : 'processed';
        } catch (Throwable $error) { error_log('Comment email failed: ' . $error->getMessage()); $result['email'] = 'not_sent'; }
    }
    return $result;
}

function ticket_workflow_api_error(array $ticket, array $user, DomainException $error): void
{
    $details = [];
    if ((int) $error->getCode() === 409) {
        if (!empty($GLOBALS['is_api_token_auth'])) api_idempotency_release_pending();
        $fresh = get_ticket((int) $ticket['id']);
        if ($fresh && can_see_ticket($fresh, $user)) $details['workflow'] = ticket_workflow_metadata($fresh, $user);
    }
    api_error($error->getMessage(), (int) $error->getCode() ?: 422, $details);
}
