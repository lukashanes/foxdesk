<?php

/**
 * Apply a ticket status change and its timer side effects atomically.
 * Notifications are intentionally dispatched by the caller after commit.
 */
function ticket_transition_status(
    array $ticket,
    array $old_status,
    array $new_status,
    int $actor_id,
    array $options = []
): array {
    $ticket_id = (int) ($ticket['id'] ?? 0);
    $new_status_id = (int) ($new_status['id'] ?? 0);
    if ($ticket_id <= 0 || $new_status_id <= 0 || $actor_id <= 0) {
        throw new InvalidArgumentException('Ticket, status, and actor are required.');
    }

    $status_changed = $new_status_id !== (int) ($ticket['status_id'] ?? 0);
    $is_done = function_exists('ticket_status_group_from_status')
        ? ticket_status_group_from_status($new_status) === 'done'
        : !empty($new_status['is_closed']);
    $stop_timer = array_key_exists('stop_timer', $options)
        ? (bool) $options['stop_timer']
        : $is_done;
    $comment = trim((string) ($options['comment'] ?? ''));
    $db = get_db();
    $owns_transaction = false;
    $stopped_timer = null;
    $comment_id = null;

    try {
        $owns_transaction = db_begin_owned($db);

        if ($status_changed) {
            db_update('tickets', [
                'status_id' => $new_status_id,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$ticket_id]);
            log_activity(
                $ticket_id,
                $actor_id,
                'status_changed',
                "Status changed from '" . (string) ($old_status['name'] ?? '') . "' to '" . (string) ($new_status['name'] ?? '') . "'"
            );
        }

        if ($stop_timer && function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
            $stopped_timer = stop_active_ticket_timer($ticket_id, $actor_id);
        }

        if ($status_changed && $comment !== '') {
            $comment_id = (int) db_insert('comments', [
                'ticket_id' => $ticket_id,
                'user_id' => $actor_id,
                'content' => $comment,
                'is_internal' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            db_update('tickets', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$ticket_id]);
        }

        db_commit_owned($owns_transaction, $db);
    } catch (Throwable $error) {
        db_rollback_owned($owns_transaction, $db);
        throw $error;
    }

    return [
        'status_changed' => $status_changed,
        'timer_stopped' => $stopped_timer,
        'comment_id' => $comment_id,
    ];
}
