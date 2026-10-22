<?php
/**
 * A migration recorded from a package with other line endings (for example a
 * release built on Windows) is the same migration, not a changed one.
 */
require dirname(__DIR__) . '/includes/schema-migration-runner.php';

class MigrationTestPdo extends PDO
{
    public function exec(string $statement): int|false
    {
        return 0;
    }
}

$recorded = [];
$updates = [];
function get_db() { return new MigrationTestPdo('sqlite::memory:'); }
function db_fetch_one($sql, $params = []) { return isset($GLOBALS['recorded'][$params[0]]) ? ['checksum' => $GLOBALS['recorded'][$params[0]]] : null; }
function db_update($table, $data, $where, $params) { $GLOBALS['updates'][] = [$data, $params]; }
function db_insert($table, $data) { $GLOBALS['recorded'][$data['migration']] = $data['checksum']; return 1; }
function split_sql_statements($sql) { return []; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$dir = sys_get_temp_dir() . '/foxdesk-migration-eol-' . getmypid();
@mkdir($dir);
$lf = "CREATE TABLE IF NOT EXISTS example (\n    id INT\n);\n";
file_put_contents($dir . '/2026010101_example.sql', $lf);

// Recorded from a CRLF copy of the same file: accepted and re-recorded.
$recorded['2026010101_example.sql'] = hash('sha256', str_replace("\n", "\r\n", $lf));
$result = foxdesk_run_versioned_migrations($dir);
$assert($result['success'] && !$result['errors'], 'Line-ending-only differences must not fail the upgrade.');
$assert(count($updates) === 1 && $updates[0][0]['checksum'] === hash('sha256', $lf), 'The installed file checksum must be recorded.');

// A real content change is still reported.
$updates = [];
$recorded['2026010101_example.sql'] = hash('sha256', "DROP TABLE example;\n");
$result = foxdesk_run_versioned_migrations($dir);
$assert(!$result['success'] && $result['errors'] === ['Migration 2026010101_example.sql changed after it was applied.'], 'Changed migrations must still be rejected.');
$assert($updates === [], 'A changed migration must not be re-recorded.');

unlink($dir . '/2026010101_example.sql');
rmdir($dir);
echo "Migration line-ending checksum test passed\n";
