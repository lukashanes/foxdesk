<?php
/**
 * Database Connection
 */

$db = null;

function create_pdo_connection($host)
{
    $dsn = "mysql:host=" . $host . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
}

function get_db()
{
    global $db;

    if ($db === null) {
        $primary_host = trim((string) DB_HOST);
        try {
            $db = create_pdo_connection($primary_host);
        } catch (PDOException $e) {
            $is_docker_placeholder = strtolower($primary_host) === 'db';
            $docker_host_unresolved = $is_docker_placeholder && gethostbyname('db') === 'db';

            // Recovery path for FTP/shared hosting when a docker-only DB host was deployed.
            if ($docker_host_unresolved) {
                try {
                    $db = create_pdo_connection('localhost');
                    return $db;
                } catch (PDOException $fallback_exception) {
                    error_log('Database connection failed (fallback): ' . $fallback_exception->getMessage());
                    die('Database connection error. Please check your configuration.');
                }
            }

            error_log('Database connection failed: ' . $e->getMessage());
            die('Database connection error. Please check your configuration.');
        }
    }

    return $db;
}

/**
 * Execute a query and return results
 */
function db_query($sql, $params = [])
{
    $db = get_db();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch all rows
 */
function db_fetch_all($sql, $params = [])
{
    return db_query($sql, $params)->fetchAll();
}

/**
 * Fetch single row
 */
function db_fetch_one($sql, $params = [])
{
    return db_query($sql, $params)->fetch();
}

/**
 * Validate a SQL identifier (table or column name) to prevent injection
 */
function validate_sql_identifier($name)
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new \InvalidArgumentException("Invalid SQL identifier: " . substr($name, 0, 50));
    }
}

/**
 * Insert and return last ID
 */
function db_insert($table, $data)
{
    validate_sql_identifier($table);
    foreach (array_keys($data) as $col) {
        validate_sql_identifier($col);
    }

    $db = get_db();
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));

    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($data));

    return $db->lastInsertId();
}

/**
 * Update rows
 */
function db_update($table, $data, $where, $where_params = [])
{
    validate_sql_identifier($table);
    foreach (array_keys($data) as $col) {
        validate_sql_identifier($col);
    }

    $db = get_db();
    $set = implode(' = ?, ', array_keys($data)) . ' = ?';

    $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $where_params));

    return $stmt->rowCount();
}

/**
 * Delete rows
 */
function db_delete($table, $where, $params = [])
{
    validate_sql_identifier($table);
    $sql = "DELETE FROM {$table} WHERE {$where}";
    return db_query($sql, $params)->rowCount();
}

/**
 * Execute a query (alias for db_query for compatibility)
 */
function db_execute($sql, $params = [])
{
    return db_query($sql, $params);
}

/**
 * Check if a database table exists (result is cached per table).
 *
 * @param string $table Table name (e.g. 'ticket_time_entries')
 * @return bool
 */
function table_exists($table)
{
    static $cache = [];
    validate_sql_identifier($table);

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $cache[$table] = (bool) db_fetch_one("SHOW TABLES LIKE '{$table}'");
    } catch (\Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

/**
 * Check if a column exists in a database table (result is cached per table+column).
 *
 * @param string $table  Table name (e.g. 'tickets')
 * @param string $column Column name (e.g. 'hash')
 * @return bool
 */
function column_exists($table, $column)
{
    static $cache = [];
    validate_sql_identifier($table);
    validate_sql_identifier($column);

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $cache[$key] = (bool) db_fetch_one("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    } catch (\Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}
