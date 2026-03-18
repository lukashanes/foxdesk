<?php
/**
 * Push Notification API Handlers
 *
 * Endpoints for managing Web Push subscriptions and fetching
 * notification data for the service worker.
 */

require_once BASE_PATH . '/includes/web-push.php';

/**
 * Subscribe to push notifications.
 * POST: { endpoint, p256dh, auth }
 */
function api_push_subscribe(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $endpoint = trim($input['endpoint'] ?? '');
    $p256dh = trim($input['p256dh'] ?? '');
    $auth = trim($input['auth'] ?? '');

    if (empty($endpoint)) {
        api_error('Missing endpoint', 400);
    }

    $success = save_push_subscription((int) $user['id'], $endpoint, $p256dh, $auth);

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

/**
 * Unsubscribe from push notifications.
 * POST: { endpoint }
 */
function api_push_unsubscribe(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $endpoint = trim($input['endpoint'] ?? '');

    if (empty($endpoint)) {
        api_error('Missing endpoint', 400);
    }

    $success = remove_push_subscription((int) $user['id'], $endpoint);

    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

/**
 * Get VAPID public key for subscription.
 * GET
 */
function api_push_vapid_key(): void
{
    $vapid = get_vapid_keys();

    header('Content-Type: application/json');
    echo json_encode(['publicKey' => $vapid['public']]);
    exit;
}

/**
 * Get latest unread notifications for service worker push display.
 * GET (called by service worker on push event)
 */
function api_push_notifications(): void
{
    $user = current_user();
    if (!$user) {
        header('Content-Type: application/json');
        echo json_encode(['notifications' => []]);
        exit;
    }

    $notifications = [];

    try {
        $rows = db_fetch_all(
            "SELECT n.*, n.data as extra_data
             FROM notifications n
             WHERE n.user_id = ? AND n.is_read = 0
             ORDER BY n.created_at DESC
             LIMIT 3",
            [(int) $user['id']]
        );

        $app_url = function_exists('get_app_url') ? get_app_url() : '';

        foreach ($rows as $row) {
            $data = json_decode($row['extra_data'] ?? '{}', true) ?: [];
            $ticket_subject = $data['ticket_subject'] ?? '';
            $actor_name = $data['actor_name'] ?? '';

            // Build title and body based on notification type
            $title = defined('APP_NAME') ? APP_NAME : 'FoxDesk';
            $body = '';
            $url = $app_url . '/index.php?page=notifications';

            switch ($row['type']) {
                case 'new_ticket':
                    $title = 'New Ticket';
                    $body = $actor_name ? "{$actor_name}: {$ticket_subject}" : $ticket_subject;
                    break;
                case 'new_comment':
                    $title = 'New Comment';
                    $body = $actor_name ? "{$actor_name} commented on: {$ticket_subject}" : "Comment on: {$ticket_subject}";
                    break;
                case 'status_changed':
                    $title = 'Status Changed';
                    $new_status = $data['new_status'] ?? '';
                    $body = "{$ticket_subject} → {$new_status}";
                    break;
                case 'assigned_to_you':
                    $title = 'Ticket Assigned';
                    $body = $actor_name ? "{$actor_name} assigned you: {$ticket_subject}" : "Assigned: {$ticket_subject}";
                    break;
                case 'priority_changed':
                    $title = 'Priority Changed';
                    $body = $ticket_subject;
                    break;
                case 'mentioned':
                    $title = 'You were mentioned';
                    $body = $actor_name ? "{$actor_name} mentioned you in: {$ticket_subject}" : "Mentioned in: {$ticket_subject}";
                    break;
                case 'due_date_reminder':
                    $title = 'Due Date Reminder';
                    $body = $ticket_subject;
                    break;
                default:
                    $body = $ticket_subject ?: 'New notification';
            }

            if ($row['ticket_id']) {
                $url = $app_url . '/index.php?page=ticket&id=' . $row['ticket_id'];
            }

            $notifications[] = [
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'tag'   => 'foxdesk-' . $row['id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[push-handler] Error fetching notifications: ' . $e->getMessage());
    }

    header('Content-Type: application/json');
    echo json_encode(['notifications' => $notifications]);
    exit;
}
