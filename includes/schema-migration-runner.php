<?php
/** Versioned database migration runner used only by install/update tooling. */

function foxdesk_ensure_schema_migrations_table(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS foxdesk_schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(191) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_foxdesk_schema_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function foxdesk_migration_files(string $directory): array
{
    $files = array_merge(
        glob(rtrim($directory, '/') . '/*.sql') ?: [],
        glob(rtrim($directory, '/') . '/*.php') ?: []
    );
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Checksums of a migration file with LF and with CRLF line endings. Release
 * packages built on different systems may differ only in line endings, which
 * must not count as a changed migration.
 */
function foxdesk_migration_line_ending_checksums(string $file): array
{
    $content = (string) file_get_contents($file);
    $lf = str_replace("\r\n", "\n", $content);
    $crlf = str_replace("\n", "\r\n", $lf);
    return array_values(array_unique([hash('sha256', $lf), hash('sha256', $crlf)]));
}

function foxdesk_run_versioned_migrations(string $directory): array
{
    $result = ['success' => true, 'messages' => [], 'errors' => []];
    if (!is_dir($directory)) {
        return $result;
    }

    $db = get_db();
    foxdesk_ensure_schema_migrations_table($db);
    foreach (foxdesk_migration_files($directory) as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        $applied = db_fetch_one(
            'SELECT checksum FROM foxdesk_schema_migrations WHERE migration = ? LIMIT 1',
            [$name]
        );
        if ($applied) {
            $recorded = (string) $applied['checksum'];
            if (hash_equals($recorded, (string) $checksum)) {
                $result['messages'][] = "Migration {$name} already applied.";
            } elseif (in_array($recorded, foxdesk_migration_line_ending_checksums($file), true)) {
                // Same migration with other line endings (for example a package built on
                // Windows); record the checksum of the file that is installed now.
                db_update('foxdesk_schema_migrations', ['checksum' => $checksum], 'migration = ?', [$name]);
                $result['messages'][] = "Migration {$name} already applied.";
            } else {
                $result['success'] = false;
                $result['errors'][] = "Migration {$name} changed after it was applied.";
            }
            continue;
        }

        try {
            if (str_ends_with($name, '.php')) {
                $restoreEmulatedPrepares = null;
                try {
                    $restoreEmulatedPrepares = (bool) $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
                    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                } catch (Throwable $e) {
                    $restoreEmulatedPrepares = null;
                }
                try {
                    $migration = require $file;
                    if (!is_callable($migration)) {
                        throw new RuntimeException("PHP migration {$name} must return a callable.");
                    }
                    $migration($db);
                } finally {
                    if ($restoreEmulatedPrepares !== null) {
                        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $restoreEmulatedPrepares);
                    }
                }
            } else {
                foreach (split_sql_statements((string) file_get_contents($file)) as $statement) {
                    if (trim((string) $statement) !== '') {
                        $db->exec($statement);
                    }
                }
            }
            db_insert('foxdesk_schema_migrations', [
                'migration' => $name,
                'checksum' => $checksum,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $result['messages'][] = "Migration {$name} applied.";
        } catch (Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = "Migration {$name} failed: " . $e->getMessage();
            break;
        }
    }
    return $result;
}
