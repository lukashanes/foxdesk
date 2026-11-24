<?php
return static function (PDO $db): void {
    foreach (['users', 'api_tokens'] as $table) {
        if (!$db->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            if ($table === 'users') {
                throw new RuntimeException('The users table is required before migrating authentication credentials.');
            }
            continue;
        }
        if (!$db->query("SHOW COLUMNS FROM `{$table}` LIKE 'auth_version'")->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0");
        }
    }
};
