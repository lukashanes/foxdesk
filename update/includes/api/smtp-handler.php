<?php
/**
 * API Handler: SMTP Operations
 *
 * Handles SMTP testing for email configuration.
 */

/**
 * Handle SMTP test
 */
function api_test_smtp() {
    require_admin_post();

    $input = get_json_input();

    require_once BASE_PATH . '/includes/mailer.php';

    $result = test_smtp_connection([
        'host' => $input['host'] ?? '',
        'port' => $input['port'] ?? 587,
        'user' => $input['user'] ?? '',
        'pass' => $input['pass'] ?? '',
        'encryption' => $input['encryption'] ?? 'tls'
    ]);

    // test_smtp_connection returns {success:bool, message:string}
    // Pass through via api_success — array_merge preserves the original success flag
    api_success($result);
}


