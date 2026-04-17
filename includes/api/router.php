<?php
/**
 * API Router
 *
 * Main dispatcher for API endpoints. Routes requests to appropriate handlers.
 */

// Include the admin CRUD helper for utility functions
require_once __DIR__ . '/../admin-crud-helper.php';

// Include all API handlers
require_once __DIR__ . '/reorder-handler.php';
require_once __DIR__ . '/upload-handler.php';
require_once __DIR__ . '/ticket-handler.php';
require_once __DIR__ . '/user-handler.php';
require_once __DIR__ . '/smtp-handler.php';
require_once __DIR__ . '/agent-handler.php';
require_once __DIR__ . '/update-api.php';
require_once __DIR__ . '/notification-handler.php';
require_once __DIR__ . '/allowed-senders-handler.php';
require_once __DIR__ . '/push-handler.php';

/**
 * Route API requests to appropriate handlers
 */
function route_api_request($action) {
    // Define public actions that don't require authentication
    $public_actions = [];

    // --- Bearer token authentication (for agent/external API access) ---
    // Token auth always takes priority for agent-* endpoints, even if a browser session exists.
    // This ensures API calls are attributed to the token owner (e.g. AI agent), not the logged-in admin.
    $GLOBALS['is_api_token_auth'] = false;

    if (is_api_token_request()) {
        $is_agent_endpoint = str_starts_with($action, 'agent-');
        if (!is_logged_in() || $is_agent_endpoint) {
            $token_user = authenticate_api_token();
            if ($token_user) {
                $GLOBALS['is_api_token_auth'] = true;
            }
        }
    }

    // Check authentication for non-public endpoints
    if (!in_array($action, $public_actions) && !is_logged_in()) {
        api_error('Unauthorized', 401);
    }

    // Route to appropriate handler
    $routes = [
        // Reorder handlers
        'reorder-statuses' => 'api_reorder_statuses',
        'move-status-up' => 'api_move_status_up',
        'move-status-down' => 'api_move_status_down',
        'reorder-priorities' => 'api_reorder_priorities',
        'move-priority-up' => 'api_move_priority_up',
        'move-priority-down' => 'api_move_priority_down',
        'reorder-ticket-types' => 'api_reorder_ticket_types',

        // Upload handler
        'upload' => 'api_upload',

        // Ticket handlers
        'change-status' => 'api_change_status',
        'quick-start' => 'api_quick_start',
        'start-timer' => 'api_start_timer',
        'pause-timer' => 'api_pause_timer',
        'resume-timer' => 'api_resume_timer',
        'stop-timer' => 'api_stop_timer',
        'discard-timer' => 'api_discard_timer',
        'cancel-ticket' => 'api_cancel_ticket',
        'delete-time-entry' => 'api_delete_time_entry',
        'update-time-inline' => 'api_update_time_inline',
        'quick-log-time' => 'api_quick_log_time',
        'edit-comment' => 'api_edit_comment',
        'delete-comment' => 'api_delete_comment',

        // Quick-edit (AJAX, no page reload)
        'quick-assign' => 'api_quick_assign',
        'quick-behalf' => 'api_quick_behalf',
        'quick-due-date' => 'api_quick_due_date',
        'quick-priority' => 'api_quick_priority',
        'quick-type' => 'api_quick_type',
        'quick-company' => 'api_quick_company',
        'quick-subject' => 'api_quick_subject',
        'quick-create-ticket' => 'api_quick_create_ticket',

        // User handlers
        'search_users' => 'api_search_users',
        'get_user_tickets' => 'api_get_user_tickets',
        'get_active_timer' => 'api_get_active_timer',
        'get_active_timers' => 'api_get_active_timers',

        // Organization member handlers
        'org-add-user' => 'api_org_add_user',
        'org-remove-user' => 'api_org_remove_user',

        // Tags
        'get-tags' => 'api_get_tags',
        'update-tags' => 'api_update_tags',

        // Search (command palette)
        'search-tickets' => 'api_search_tickets',

        // Dashboard layout
        'save-dashboard-layout' => 'api_save_dashboard_layout',

        // SMTP handler
        'test-smtp' => 'api_test_smtp',

        // --- Agent / external API endpoints ---
        'agent-me' => 'api_agent_me',
        'agent-list-statuses' => 'api_agent_list_statuses',
        'agent-list-priorities' => 'api_agent_list_priorities',
        'agent-list-users' => 'api_agent_list_users',
        'agent-create-ticket' => 'api_agent_create_ticket',
        'agent-list-tickets' => 'api_agent_list_tickets',
        'agent-get-ticket' => 'api_agent_get_ticket',
        'agent-add-comment' => 'api_agent_add_comment',
        'agent-update-status' => 'api_agent_update_status',
        'agent-log-time' => 'api_agent_log_time',

        // --- Timeline endpoint ---
        'get-timeline' => 'api_get_timeline',

        // --- Notification endpoints ---
        'get-notifications' => 'api_get_notifications',
        'get-notification-count' => 'api_get_notification_count',
        'mark-notification-read' => 'api_mark_notification_read',
        'mark-ticket-notifications-read' => 'api_mark_ticket_notifications_read',
        'mark-all-notifications-read' => 'api_mark_all_notifications_read',

        // --- Update check endpoints (admin only) ---
        'check-for-updates' => 'api_check_for_updates',
        'download-remote-update' => 'api_download_remote_update',
        'dismiss-update-notice' => 'api_dismiss_update_notice',

        // --- Allowed senders (admin only) ---
        'allowed-senders-list' => 'api_allowed_senders_list',
        'allowed-senders-add' => 'api_allowed_senders_add',
        'allowed-senders-delete' => 'api_allowed_senders_delete',
        'allowed-senders-toggle' => 'api_allowed_senders_toggle',

        // --- Push notification endpoints ---
        'push-subscribe' => 'api_push_subscribe',
        'push-unsubscribe' => 'api_push_unsubscribe',
        'push-vapid-key' => 'api_push_vapid_key',
        'push-notifications' => 'api_push_notifications',
    ];

    if (isset($routes[$action])) {
        $handler = $routes[$action];
        if (function_exists($handler)) {
            $handler();
            return;
        }
    }

    api_error('Unknown action', 404);
}

