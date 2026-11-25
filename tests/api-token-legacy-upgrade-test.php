<?php
// Execute the actual legacy upgrade block without connecting to a database.
$exists = false; $columns = []; $writes = []; $messages = [];
function column_exists($table, $column) { return isset($GLOBALS['columns'][$column]); }
function db_fetch_one($sql, $params = []) { return $GLOBALS['exists'] ? ['api_tokens' => true] : null; }
function db_query($sql) {
    $GLOBALS['writes'][] = $sql;
    if (str_contains($sql, 'CREATE TABLE api_tokens')) {
        $GLOBALS['exists'] = true;
        if (str_contains($sql, 'auth_version INT UNSIGNED NOT NULL DEFAULT 0')) $GLOBALS['columns']['auth_version'] = true;
    }
    if (preg_match('/ADD COLUMN (\w+)/', $sql, $match)) $GLOBALS['columns'][$match[1]] = true;
}
$source = file_get_contents(dirname(__DIR__) . '/includes/upgrade-functions.php');
$start = strpos($source, '    // Create api_tokens table');
$end = strpos($source, '    $check = db_fetch_one("SHOW TABLES LIKE \'api_token_audit_logs\'")', $start);
$upgrade = substr($source, $start, $end - $start);
foreach ([false, true] as $exists) {
    $columns = []; $writes = [];
    eval($upgrade);
    if (empty($columns['auth_version'])) throw new RuntimeException('Legacy API table lacks auth_version.');
    if (!$exists) throw new RuntimeException('API table was not created.');
    $count = count($writes);
    eval($upgrade);
    if (count($writes) !== $count) throw new RuntimeException('Repeated API column upgrade wrote again.');
}
echo "Late API table creation and idempotent legacy upgrade OK (SQL substitutes)\n";
