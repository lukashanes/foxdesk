<?php
/** Shared permission check for inbound replies and outbound recipients. */
function ticket_conversation_user_can_access(int $ticket_id, int $user_id): bool
{
    if ($ticket_id <= 0 || $user_id <= 0) {
        return false;
    }
    try {
        require_once __DIR__ . '/user-functions.php';
        require_once __DIR__ . '/ticket-access-functions.php';
        $ticket = db_fetch_one('SELECT * FROM tickets WHERE id = ? LIMIT 1', [$ticket_id]);
        $user = db_fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$user_id]);
        if (!$ticket || !$user || empty($user['is_active']) || !empty($user['deleted_at'])) {
            return false;
        }
        return can_see_ticket($ticket, $user);
    } catch (Throwable $e) {
        return false;
    }
}
