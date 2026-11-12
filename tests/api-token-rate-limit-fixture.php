<?php

/**
 * Shared bootstrap for tests/api-token-rate-limit-integration-test.php.
 *
 * Loads the real database and auth code against a disposable MariaDB
 * database. When served by `php -S`, it acts as a minimal API endpoint that
 * runs the real rate-limit check and audit logging, so response headers can
 * be verified over HTTP.
 */

date_default_timezone_set('Europe/Prague');

foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'foxdesk_rate_limit_test', 'DB_USER' => 'root', 'DB_PASS' => ''] as $name => $default) {
    if (!defined($name)) {
        $value = getenv($name);
        define($name, $value === false ? $default : $value);
    }
}

require_once dirname(__DIR__) . '/includes/database.php';

final class RateLimitFixtureApiError extends RuntimeException
{
    public array $details;

    public function __construct(string $message, int $code, array $details)
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }
}

// Mirrors includes/admin-crud-helper.php api_error(): every error, 429 included, is audit-logged.
function api_error($message, $code = 400, array $details = [])
{
    if (!empty($GLOBALS['is_api_token_auth']) && function_exists('api_token_log_action')) {
        api_token_log_action((string) ($GLOBALS['api_current_action'] ?? ''), ['error' => $message], (int) $code);
    }
    if (PHP_SAPI === 'cli-server') {
        http_response_code((int) $code);
        header('Content-Type: application/json');
        echo json_encode(array_merge($details, ['success' => false, 'error' => $message]));
        exit;
    }
    throw new RateLimitFixtureApiError((string) $message, (int) $code, $details);
}

require_once dirname(__DIR__) . '/includes/auth.php';

// The real current_user() reads the session user from the users table.
$_SESSION['user_id'] = 1;

function rate_limit_fixture_prepare_schema(): void
{
    $db = get_db();
    // The production stack runs MariaDB in UTC while PHP runs in Europe/Prague.
    $db->exec("SET time_zone = '+00:00'");
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role VARCHAR(20) NOT NULL DEFAULT \'admin\',
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB');
    $db->exec('INSERT IGNORE INTO users (id, role, is_active) VALUES (1, \'admin\', 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS api_token_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        token_id INT NULL,
        user_id INT NOT NULL,
        action VARCHAR(120) NOT NULL,
        method VARCHAR(10) NOT NULL,
        resource_type VARCHAR(80) NULL,
        resource_id INT NULL,
        status_code INT DEFAULT 200,
        request_id VARCHAR(64) NULL,
        idempotency_key VARCHAR(128) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token_created (token_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

if (PHP_SAPI === 'cli-server') {
    get_db()->exec("SET time_zone = '+00:00'");
    $GLOBALS['is_api_token_auth'] = true;
    $GLOBALS['api_token_row'] = ['id' => (int) ($_GET['token'] ?? 900), 'tenant_id' => null, 'user_id' => 1];
    $GLOBALS['api_current_action'] = 'agent-me';
    api_token_rate_limit_check('agent-me');
    api_token_log_action('agent-me', [], 200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
