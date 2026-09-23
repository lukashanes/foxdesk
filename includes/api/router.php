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
require_once __DIR__ . '/ticket-workflow-handler.php';
require_once __DIR__ . '/user-handler.php';
require_once __DIR__ . '/smtp-handler.php';
require_once __DIR__ . '/agent-handler.php';
require_once __DIR__ . '/update-api.php';
require_once __DIR__ . '/notification-handler.php';
require_once __DIR__ . '/allowed-senders-handler.php';
require_once __DIR__ . '/push-handler.php';
require_once __DIR__ . '/app-handler.php';
require_once __DIR__ . '/compact-workspace-handler.php';

/**
 * Route API requests to appropriate handlers
 */
function route_api_request($action) {
    api_validate_utf8_values($_GET);
    api_validate_utf8_values($_POST);
    $GLOBALS['api_current_action'] = (string) $action;

    // Define public actions that don't require authentication
    $public_actions = [];

    // --- Bearer token authentication (for agent/external API access) ---
    // Token auth always takes priority for agent-* endpoints, even if a browser session exists.
    // This ensures API calls are attributed to the token owner (e.g. AI agent), not the logged-in admin.
    $GLOBALS['is_api_token_auth'] = false;

    if (is_api_token_request()) {
        $is_agent_endpoint = str_starts_with($action, 'agent-');
        $is_app_endpoint = str_starts_with($action, 'app-');
        if (!is_logged_in() || $is_agent_endpoint || $is_app_endpoint || $action === 'upload') {
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

    if (!in_array($action, $public_actions, true) && function_exists('ticket_undo_finalize_expired')) {
        ticket_undo_finalize_expired();
    }

    if (!empty($GLOBALS['is_api_token_auth'])) {
        api_token_enforce_action_scope($action);
        api_token_rate_limit_check($action);
        api_idempotency_replay_if_available($action);
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
        'ticket-workflow' => 'api_ticket_workflow',
        'agent-ticket-workflow' => 'api_ticket_workflow',
        'change-status' => 'api_change_status',
        'quick-start' => 'api_quick_start',
        'start-timer' => 'api_start_timer',
        'pause-timer' => 'api_pause_timer',
        'resume-timer' => 'api_resume_timer',
        'stop-timer' => 'api_stop_timer',
        'discard-timer' => 'api_discard_timer',
        'cancel-ticket' => 'api_cancel_ticket',
        'delete-time-entry' => 'api_delete_time_entry',
        'restore-time-entry' => 'api_restore_time_entry',
        'update-time-inline' => 'api_update_time_inline',
        'quick-log-time' => 'api_quick_log_time',
        'edit-comment' => 'api_edit_comment',
        'delete-comment' => 'api_delete_comment',
        'restore-comment' => 'api_restore_comment',
        'delete-attachment' => 'api_delete_attachment',
        'restore-attachment' => 'api_restore_attachment',
        'permanent-delete-ticket-preflight' => 'api_permanent_delete_ticket_preflight',
        'permanent-delete-ticket' => 'api_permanent_delete_ticket',

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
        'global-search' => 'api_global_search',

        // App shell contract for web/native clients
        'app-shell' => 'api_app_shell',
        'app-home' => 'api_app_home',
        'app-ticket-list' => 'api_app_ticket_list',
        'app-ticket-detail' => 'api_app_ticket_detail',
        'app-ticket-actions' => 'api_app_ticket_actions',
        'app-create-ticket' => 'api_app_create_ticket',
        'app-add-comment' => 'api_app_add_comment',
        'app-attachment-metadata' => 'api_app_attachment_metadata',
        'app-ticket-timer' => 'api_app_ticket_timer',
        'app-ticket-timer-action' => 'api_app_ticket_timer_action',
        'app-log-time' => 'api_app_log_time',
        'app-client-overview' => 'api_app_client_overview',
        'app-reporting-review' => 'api_app_reporting_review',
        'app-notifications' => 'api_app_notifications',
        'app-notifications-summary' => 'api_app_notifications_summary',
        'app-notification-read-state' => 'api_app_notification_read_state',
        'app-notification-review' => 'api_app_notification_review',
        'app-update-time-entry' => 'api_app_update_time_entry',

        // Dashboard layout
        'save-dashboard-layout' => 'api_save_dashboard_layout',

        // SMTP handler
        'test-smtp' => 'api_test_smtp',

        // --- Agent / external API endpoints ---
        'agent-docs' => 'api_agent_docs',
        'agent-me' => 'api_agent_me',
        'agent-list-statuses' => 'api_agent_list_statuses',
        'agent-list-priorities' => 'api_agent_list_priorities',
        'agent-list-users' => 'api_agent_list_users',
        'agent-create-ticket' => 'api_agent_create_ticket',
        'agent-list-tickets' => 'api_agent_list_tickets',
        'agent-get-ticket' => 'api_agent_get_ticket',
        'agent-add-comment' => 'api_agent_add_comment',
        'agent-add-update' => 'api_agent_add_comment',
        'agent-add-work-entry' => 'api_agent_add_comment',
        'agent-plan-work-log' => 'api_agent_plan_work_log',
        'agent-apply-work-log-plan' => 'api_agent_apply_work_log_plan',
        'agent-update-status' => 'api_agent_update_status',
        'agent-log-time' => 'api_agent_log_time',
        'agent-delete-ticket-preflight' => 'api_agent_delete_ticket_preflight',
        'agent-delete-ticket-permanently' => 'api_agent_delete_ticket_permanently',

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
