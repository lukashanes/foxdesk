<?php
// PDO substitutes only: no database driver or connection is used.
class VersionMigrationStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
}
class VersionMigrationDatabase extends PDO {
    public array $tables = ['users' => true, 'api_tokens' => true];
    public array $columns = [];
    public array $writes = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $m)) return new VersionMigrationStatement($this->tables[$m[1]] ?? false);
        if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $query, $m)) return new VersionMigrationStatement($this->columns[$m[1]] ?? false);
        throw new LogicException('Unexpected migration query.');
    }
    public function exec(string $statement): int|false {
        $this->writes[] = $statement;
        preg_match('/ALTER TABLE `([^`]+)`/', $statement, $m);
        $this->columns[$m[1]] = true;
        return 0;
    }
}
$migration = require dirname(__DIR__) . '/migrations/2026112401_auth_version.php';
$db = new VersionMigrationDatabase();
$migration($db); $migration($db);
if (count($db->writes) !== 2) throw new RuntimeException('Migration must add each epoch once.');
$db = new VersionMigrationDatabase(); unset($db->tables['api_tokens']);
$migration($db);
if (count($db->writes) !== 1) throw new RuntimeException('Missing optional API table must be supported.');
$db = new VersionMigrationDatabase(); unset($db->tables['users']);
try { $migration($db); throw new LogicException('Missing users table was ignored.'); }
catch (RuntimeException $e) { if ($db->writes !== []) throw new LogicException('Missing schema caused a write.'); }
echo "Authentication epoch migration regression OK (PDO substitutes)\n";
