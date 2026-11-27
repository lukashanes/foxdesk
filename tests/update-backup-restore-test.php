<?php

declare(strict_types=1);

$fixture_root = $argv[1] ?? sys_get_temp_dir() . '/foxdesk-backup-test-' . bin2hex(random_bytes(8));
define('BASE_PATH', $fixture_root);
require dirname(__DIR__) . '/includes/update-functions.php';

function t(string $text, array $params = []): string
{
    return $text;
}

function get_setting(string $name, string $default): string
{
    return $default;
}

function save_setting(string $name, string $value): void
{
    // Settings are outside this file-only restore fixture.
}

if (isset($argv[1])) {
    // A successful rollback emits an interstitial and exits.
    $result = rollback_update('test-backup');
    throw new RuntimeException('Rollback failed: ' . implode(', ', $result['errors']));
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$check(mkdir($fixture_root), 'Could not create the isolated application directory.');
try {
    $backup = BACKUP_DIR . '/test-backup';
    $check(mkdir($backup, 0755, true), 'Could not create the backup directory.');
    $managed = [
        'index.php' => 'original entry point',
        'assets/example.css' => 'original style',
        'locales/catalogs/en.json' => '{"example":"original translation"}',
    ];
    $persistent = [
        'config.php' => 'configuration',
        'uploads/example.txt' => 'upload',
        'storage/tickets/example.txt' => 'attachment',
        'backups/older.txt' => 'older backup',
    ];
    $write = static function (string $relative, string $contents) use ($fixture_root, $check): void {
        $path = $fixture_root . '/' . $relative;
        $directory = dirname($path);
        $check(is_dir($directory) || mkdir($directory, 0755, true), 'Could not create fixture directory: ' . $relative);
        $check(file_put_contents($path, $contents) !== false, 'Could not write fixture: ' . $relative);
    };
    foreach ($managed + $persistent as $relative => $contents) {
        $write($relative, $contents);
    }

    $archive = $backup . '/files.zip';
    $check(create_files_backup($archive), 'Could not create the release file backup.');
    $zip = new ZipArchive();
    $check($zip->open($archive) === true, 'The created backup must be a readable ZIP.');
    try {
        foreach ($managed as $relative => $contents) {
            $check($zip->getFromName($relative) === $contents, 'Backup lost release file: ' . $relative);
        }
        foreach ($persistent as $relative => $contents) {
            $check($zip->locateName($relative) === false, 'Backup included persistent data: ' . $relative);
        }
    } finally {
        $zip->close();
    }
    echo "OK backup archives release files and excludes persistent data\n";

    $write('backups/test-backup/info.json', '{"version":"0.0.1"}');
    $write('index.php', 'updated entry point');
    $write('assets/added-after-backup.css', 'new style');
    $check(unlink($fixture_root . '/assets/example.css'), 'Could not remove the backed-up stylesheet from the fixture.');
    foreach ($persistent as $relative => $contents) {
        $write($relative, 'current ' . $contents);
    }

    $process = proc_open([PHP_BINARY, __FILE__, $fixture_root], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    $check(is_resource($process), 'Could not start the file-only rollback.');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $check(
        proc_close($process) === 0 && is_string($output) && str_contains($output, 'Rollback complete'),
        'The file-only rollback did not complete.'
    );

    clearstatcache();
    foreach ($managed as $relative => $contents) {
        $check(file_get_contents($fixture_root . '/' . $relative) === $contents, 'Rollback failed to restore: ' . $relative);
    }
    $check(!file_exists($fixture_root . '/assets/added-after-backup.css'), 'Rollback left a file from the newer release.');
    $check(!file_exists(MAINTENANCE_FILE), 'Successful rollback left maintenance mode enabled.');
    echo "OK rollback restores release files, removes new files and clears maintenance mode\n";

    foreach ($persistent as $relative => $contents) {
        $check(file_get_contents($fixture_root . '/' . $relative) === 'current ' . $contents, 'Rollback changed persistent data: ' . $relative);
    }
    echo "OK rollback preserves configuration, uploads, storage and existing backups\n";

    $write('backups/test-backup/files.zip', 'incomplete backup');
    $result = rollback_update('test-backup');
    $check(!$result['success'] && $result['errors'] !== [], 'An unreadable backup must report an error.');
    $check(!file_exists(MAINTENANCE_FILE), 'Failed rollback left maintenance mode enabled.');
    $check(file_get_contents($fixture_root . '/index.php') === $managed['index.php'], 'Failed rollback changed the application.');
    echo "OK an unreadable backup leaves the application and maintenance state intact\n";
} finally {
    $check(delete_directory($fixture_root), 'Could not clean up the backup fixture.');
}
