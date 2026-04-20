<?php
/**
 * Session bootstrap helpers.
 *
 * FoxDesk prefers database-backed sessions because they survive PHP,
 * Apache and container restarts. When the database is not available yet
 * (installer, broken config, etc.), it falls back to a private on-disk
 * session directory inside the app.
 */

if (!function_exists('foxdesk_get_session_lifetime')) {
    function foxdesk_get_session_lifetime(): int
    {
        return defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 2592000;
    }
}

if (!function_exists('foxdesk_request_is_https')) {
    function foxdesk_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $forwarded_proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwarded_proto === 'https') {
            return true;
        }

        $forwarded_ssl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if ($forwarded_ssl === 'on') {
            return true;
        }

        $cf_visitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
        if ($cf_visitor !== '' && stripos($cf_visitor, '"scheme":"https"') !== false) {
            return true;
        }

        return false;
    }
}

if (!function_exists('foxdesk_request_scheme')) {
    function foxdesk_request_scheme(): string
    {
        return foxdesk_request_is_https() ? 'https' : 'http';
    }
}

if (!function_exists('foxdesk_request_host')) {
    function foxdesk_request_host(): string
    {
        $forwarded_host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($forwarded_host !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $forwarded_host))));
            if (!empty($parts[0])) {
                return $parts[0];
            }
        }

        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
}

if (!function_exists('foxdesk_get_request_base_url')) {
    function foxdesk_get_request_base_url(?string $path_hint = null): string
    {
        $path_hint = $path_hint ?? ($_SERVER['SCRIPT_NAME'] ?? '');
        $path = (string) parse_url($path_hint, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        $dir = rtrim(dirname($path), '/\\');
        $base = foxdesk_request_scheme() . '://' . foxdesk_request_host();

        if ($dir === '' || $dir === '.') {
            return $base;
        }

        return $base . $dir;
    }
}

if (!function_exists('foxdesk_prepare_private_runtime_dir')) {
    function foxdesk_prepare_private_runtime_dir(string $absolute_dir): bool
    {
        if (!is_dir($absolute_dir) && !@mkdir($absolute_dir, 0755, true) && !is_dir($absolute_dir)) {
            return false;
        }

        $htaccess = $absolute_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# Deny direct access to runtime files.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n";
            @file_put_contents($htaccess, $rules);
        }

        $index = $absolute_dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents(
                $index,
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head><body><h1>Forbidden</h1></body></html>'
            );
        }

        return is_writable($absolute_dir);
    }
}

if (!function_exists('foxdesk_configure_session_cookie')) {
    function foxdesk_configure_session_cookie(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $is_https = foxdesk_request_is_https();
        $session_lifetime = foxdesk_get_session_lifetime();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_lifetime', (string) $session_lifetime);
        ini_set('session.gc_maxlifetime', (string) $session_lifetime);
        if ($is_https) {
            ini_set('session.cookie_secure', '1');
        }

        session_set_cookie_params([
            'lifetime' => $session_lifetime,
            'path' => '/',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('foxdesk_get_session_storage_candidates')) {
    function foxdesk_get_session_storage_candidates(): array
    {
        $base_path = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $candidates = [];
        $env_session_dir = trim((string) getenv('FOXDESK_SESSION_DIR'));

        if ($env_session_dir !== '') {
            $candidates[] = str_starts_with($env_session_dir, '/')
                ? $env_session_dir
                : ($base_path . '/' . ltrim($env_session_dir, '/\\'));
        }

        $candidates[] = $base_path . '/storage/sessions';
        $candidates[] = $base_path . '/uploads/.sessions';

        return array_values(array_unique($candidates));
    }
}

if (!class_exists('FoxDeskDatabaseSessionHandler')) {
    class FoxDeskDatabaseSessionHandler implements SessionHandlerInterface
    {
        private PDO $pdo;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function open($path, $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read($id): string
        {
            $stmt = $this->pdo->prepare(
                'SELECT session_data, last_activity FROM app_sessions WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return '';
            }

            $max_lifetime = foxdesk_get_session_lifetime();
            if (((int) ($row['last_activity'] ?? 0)) < (time() - $max_lifetime)) {
                $this->destroy($id);
                return '';
            }

            $data = $row['session_data'] ?? '';
            if (is_resource($data)) {
                $data = stream_get_contents($data) ?: '';
            }

            return is_string($data) ? $data : '';
        }

        public function write($id, $data): bool
        {
            $stmt = $this->pdo->prepare(
                'INSERT INTO app_sessions (id, session_data, last_activity, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    session_data = VALUES(session_data),
                    last_activity = VALUES(last_activity),
                    updated_at = NOW()'
            );

            return $stmt->execute([$id, $data, time()]);
        }

        public function destroy($id): bool
        {
            $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE id = ?');
            return $stmt->execute([$id]);
        }

        public function gc($max_lifetime): int|false
        {
            $stmt = $this->pdo->prepare(
                'DELETE FROM app_sessions WHERE last_activity < ?'
            );
            $stmt->execute([time() - (int) $max_lifetime]);
            return $stmt->rowCount();
        }
    }
}

if (!function_exists('foxdesk_create_session_pdo')) {
    function foxdesk_create_session_pdo(): ?PDO
    {
        static $pdo = false;

        if ($pdo !== false) {
            return $pdo instanceof PDO ? $pdo : null;
        }

        if (
            !class_exists('PDO')
            || !defined('DB_HOST')
            || !defined('DB_PORT')
            || !defined('DB_NAME')
            || !defined('DB_USER')
            || !defined('DB_PASS')
        ) {
            $pdo = null;
            return null;
        }

        $hosts = [trim((string) DB_HOST)];
        if (strtolower((string) DB_HOST) === 'db' && gethostbyname('db') === 'db') {
            $hosts[] = 'localhost';
        }

        $hosts = array_values(array_unique(array_filter($hosts, static function ($host) {
            return trim((string) $host) !== '';
        })));

        foreach ($hosts as $host) {
            try {
                $dsn = "mysql:host={$host};port=" . DB_PORT . ";dbname=" . DB_NAME . ';charset=utf8mb4';
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return $pdo;
            } catch (Throwable $e) {
                error_log('FoxDesk session DB connection failed for host ' . $host . ': ' . $e->getMessage());
            }
        }

        $pdo = null;
        return null;
    }
}

if (!function_exists('foxdesk_ensure_app_sessions_table')) {
    function foxdesk_ensure_app_sessions_table(PDO $pdo): bool
    {
        static $checked = false;

        if ($checked) {
            return true;
        }

        try {
            $exists = $pdo->query("SHOW TABLES LIKE 'app_sessions'")->fetchColumn();
            if (!$exists) {
                $pdo->exec(
                    "CREATE TABLE app_sessions (
                        id VARCHAR(128) NOT NULL PRIMARY KEY,
                        session_data MEDIUMBLOB NOT NULL,
                        last_activity INT UNSIGNED NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_last_activity (last_activity)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }

            $checked = true;
            return true;
        } catch (Throwable $e) {
            error_log('FoxDesk could not prepare app_sessions table: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('foxdesk_bootstrap_database_session')) {
    function foxdesk_bootstrap_database_session(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $pdo = foxdesk_create_session_pdo();
        if (!$pdo || !foxdesk_ensure_app_sessions_table($pdo)) {
            return false;
        }

        foxdesk_configure_session_cookie();
        $handler = new FoxDeskDatabaseSessionHandler($pdo);
        session_set_save_handler($handler, true);
        session_start();

        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('foxdesk_bootstrap_filesystem_session')) {
    function foxdesk_bootstrap_filesystem_session(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        foxdesk_configure_session_cookie();

        foreach (foxdesk_get_session_storage_candidates() as $session_storage_dir) {
            if (foxdesk_prepare_private_runtime_dir($session_storage_dir)) {
                ini_set('session.save_path', $session_storage_dir);
                break;
            }
        }

        session_start();

        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('foxdesk_bootstrap_session')) {
    function foxdesk_bootstrap_session(bool $prefer_database = true): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if ($prefer_database && foxdesk_bootstrap_database_session()) {
            return true;
        }

        return foxdesk_bootstrap_filesystem_session();
    }
}
