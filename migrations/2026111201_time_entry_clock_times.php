<?php

/**
 * Mark which time entries carry real clock times.
 *
 * Until now only time_precision = 'exact' had real from-to times; every other
 * precision stored a 12:00 date anchor. The flag lets 'derived' entries keep
 * real times while entries without known times are never shown with 12:00.
 */
return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };
    $columnExists = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$tableExists('ticket_time_entries')) {
        return;
    }

    if (!$columnExists('ticket_time_entries', 'has_clock_times')) {
        $db->exec('ALTER TABLE ticket_time_entries ADD COLUMN has_clock_times TINYINT(1) NOT NULL DEFAULT 1');

        if ($columnExists('ticket_time_entries', 'time_precision')) {
            // Every non-exact entry written so far stored a 12:00 anchor, not real times.
            $db->exec("UPDATE ticket_time_entries SET has_clock_times = 0 WHERE time_precision <> 'exact'");
        }
    }
};
